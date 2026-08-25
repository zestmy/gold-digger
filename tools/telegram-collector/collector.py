#!/usr/bin/env python3
"""
Gold Digger Telegram collector.

Reads signal channels with a real Telegram account and posts what it sees to the
dashboard.

Why this is a separate program
------------------------------
A Telegram *bot* can only see chats it has been added to. Provider channels do not add
your bot, so the Bot API cannot read them - no setting changes that, it is what the Bot
API is. Reading them means MTProto, logged in as a user account.

That login produces a session file which is a full account credential: it can read every
chat the account has and post as them. Keeping it on the dashboard's web server would
make a website compromise into a Telegram account takeover, so it stays here, wherever
you choose to run this, and the dashboard only ever receives message text over a
revocable bearer token.

This is the same shape as the MetaTrader Expert Advisor: an outside process that observes
something the dashboard cannot reach, authenticated by a token, feeding the same
pipeline.

What it does not do
-------------------
It does not join channels, send messages, or read anything the dashboard has not been
told to watch. The watch list comes from the dashboard and is filtered here, so chats you
have not enabled are never posted to a web server at all.

Usage
-----
    python collector.py login       # once, interactively - phone, code, 2FA
    python collector.py announce    # tell the dashboard what this account can see
    python collector.py run         # watch enabled channels and forward them

Configuration is by environment variable; see .env.example.
"""

from __future__ import annotations

import asyncio
import base64
import json
import os
import sys
from pathlib import Path

import requests
from telethon import TelegramClient, events
from telethon.errors import SessionPasswordNeededError
from telethon.tl.types import Channel, Chat, User

HERE = Path(__file__).resolve().parent
STATE_PATH = Path(os.environ.get("GD_STATE_FILE", HERE / "state.json"))
SESSION = os.environ.get("GD_SESSION_FILE", str(HERE / "gold-digger"))

API_ID = os.environ.get("TG_API_ID", "")
API_HASH = os.environ.get("TG_API_HASH", "")
BASE_URL = os.environ.get("GD_BASE_URL", "").rstrip("/")
TOKEN = os.environ.get("GD_TOKEN", "")

# How often to re-read the watch list, so enabling a channel in the dashboard takes
# effect without a restart.
REFRESH_SECONDS = int(os.environ.get("GD_REFRESH_SECONDS", "60"))

# Messages older than this are not back-filled on a first run. A signal from last month
# is not a trade, and forwarding a channel's entire history would fill the pipeline with
# things that can only ever be declined.
BACKFILL_LIMIT = int(os.environ.get("GD_BACKFILL_LIMIT", "20"))

# Beyond this a picture is a chart to look at rather than a signal card, and forwarding it
# costs more than it can be worth.
MAX_IMAGE_BYTES = int(os.environ.get("GD_MAX_IMAGE_BYTES", "4000000"))


def require_config() -> None:
    missing = [
        name
        for name, value in (
            ("TG_API_ID", API_ID),
            ("TG_API_HASH", API_HASH),
            ("GD_BASE_URL", BASE_URL),
            ("GD_TOKEN", TOKEN),
        )
        if not value
    ]

    if missing:
        sys.exit(
            "Missing configuration: "
            + ", ".join(missing)
            + "\nCopy .env.example to .env and fill it in, then source it."
        )


# ---------------------------------------------------------------------------
# Dashboard API
# ---------------------------------------------------------------------------


def api(method: str, path: str, payload: dict | None = None) -> dict:
    response = requests.request(
        method,
        f"{BASE_URL}/api/v1/telegram/{path}",
        json=payload,
        headers={
            "Authorization": f"Bearer {TOKEN}",
            "Accept": "application/json",
        },
        timeout=30,
    )
    response.raise_for_status()

    return response.json()


def load_state() -> dict:
    if STATE_PATH.exists():
        try:
            return json.loads(STATE_PATH.read_text())
        except json.JSONDecodeError:
            pass

    return {"seen": {}}


def save_state(state: dict) -> None:
    STATE_PATH.write_text(json.dumps(state, indent=2))


# ---------------------------------------------------------------------------
# Telegram
# ---------------------------------------------------------------------------


def client() -> TelegramClient:
    return TelegramClient(SESSION, int(API_ID), API_HASH)


def chat_id_of(entity) -> str:
    """
    The id the dashboard stores.

    Telethon exposes a channel's raw id; Bot API style prefixes supergroups with -100.
    We store Telethon's own form consistently so the two never have to be reconciled -
    the dashboard treats the id as an opaque string, and the pair (source, chat_id) is
    what makes it unique.
    """
    return str(entity.id)


def reply_target(message) -> int | None:
    """
    The message id this one replies to, across Telethon's shapes.

    Newer Telethon exposes `message.reply_to.reply_to_msg_id`; the flat
    `message.reply_to_msg_id` remains as a shortcut and is None for a top-level post. A
    reply inside a forum topic also carries a thread id, which is deliberately not used -
    that points at the topic's root, not at the signal being managed.
    """
    reply = getattr(message, "reply_to", None)

    if reply is not None and getattr(reply, "reply_to_msg_id", None):
        return int(reply.reply_to_msg_id)

    flat = getattr(message, "reply_to_msg_id", None)

    return int(flat) if flat else None


def title_of(entity) -> str | None:
    if isinstance(entity, (Channel, Chat)):
        return entity.title

    if isinstance(entity, User):
        return " ".join(filter(None, [entity.first_name, entity.last_name])) or None

    return None


async def cmd_login() -> None:
    """Interactive, once. Telethon prompts for phone, code, and 2FA password."""
    async with client() as tg:
        me = await tg.get_me()
        print(f"Signed in as {me.first_name} (@{me.username}).")
        print(f"Session stored at {SESSION}.session - treat it as a password.")


async def cmd_announce() -> None:
    """Report every dialog this account can see, so channels can be picked from a list."""
    async with client() as tg:
        channels = []

        async for dialog in tg.iter_dialogs():
            entity = dialog.entity

            # Only broadcast channels and groups. Private conversations with people are
            # not signal sources and there is no reason to name them to a web server.
            if not isinstance(entity, (Channel, Chat)):
                continue

            channels.append(
                {
                    "chat_id": chat_id_of(entity),
                    "title": title_of(entity),
                    "username": getattr(entity, "username", None),
                }
            )

        if not channels:
            print("This account is not in any channels or groups.")
            return

        me = await tg.get_me()

        result = api("POST", "channels", {
            "channels": channels,
            # So the dashboard can show which account this is rather than a row of labels
            # somebody typed. It identifies, it does not authenticate - the token does that.
            "me": {
                "username": getattr(me, "username", None),
                "name": " ".join(filter(None, [me.first_name, me.last_name])) or None,
            },
        })
        print(f"Reported {result['registered']} channels.")
        print("Enable the ones you want in the dashboard: Signals -> Channels.")


async def forward(tg: TelegramClient, state: dict, chat_id: str, messages) -> int:
    """Post a batch and advance the checkpoint only if it landed."""
    batch = []

    for message in messages:
        text = message.message or ""

        # A chart screenshot with the levels written on it, and often no caption at all.
        # Downloaded here rather than linked: the dashboard reads it inline, so the picture
        # never has to be published anywhere to be read.
        image = None

        if getattr(message, "photo", None) is not None:
            try:
                blob = await message.download_media(file=bytes)
                if blob and len(blob) <= MAX_IMAGE_BYTES:
                    image = base64.b64encode(blob).decode()
            except Exception as error:  # noqa: BLE001 - a bad image must not stop the batch
                print(f"[{chat_id}] could not download image: {error}")

        if not text.strip() and image is None:
            continue

        entity = await message.get_chat()

        batch.append(
            {
                "chat_id": chat_id,
                "message_id": message.id,
                "text": text[:4000],
                "chat_title": title_of(entity),
                "username": getattr(entity, "username", None),
                "date": int(message.date.timestamp()) if message.date else None,
                # What this is a reply to, which is the whole of what makes "secure half"
                # interpretable. Without it the dashboard sees an instruction with no
                # subject and can only record it.
                "reply_to_message_id": reply_target(message),
                "image": image,
                "image_mime": "image/jpeg" if image else None,
            }
        )

    if not batch:
        return 0

    result = api("POST", "messages", {"messages": batch})

    # Only now. The dashboard is idempotent on chat + message id, so a message posted
    # twice is harmless; one never posted because the checkpoint moved first is lost.
    state["seen"][chat_id] = max(m["message_id"] for m in batch)
    save_state(state)

    print(f"[{chat_id}] forwarded {result['stored']}, parsed {result['parsed']}")

    return result["stored"]


async def resolve(tg: TelegramClient, watch: set[str]) -> dict:
    """
    Map watched ids back to entities by walking the dialog list.

    Rather than `get_entity(id)`, which needs an access hash Telethon may not have cached
    and fails on a fresh process for exactly the channels this exists to read. Iterating
    dialogs fetches the hashes as a side effect, so this both resolves and warms the cache.
    """
    found = {}

    async for dialog in tg.iter_dialogs():
        chat_id = chat_id_of(dialog.entity)

        if chat_id in watch:
            found[chat_id] = dialog.entity

    for missing in watch - found.keys():
        print(f"[{missing}] enabled in the dashboard, but this account is not in it.")

    return found


async def catch_up(tg: TelegramClient, state: dict, watch: list[str]) -> None:
    """Fetch what arrived while this was not running."""
    entities = await resolve(tg, set(watch))

    for chat_id, entity in entities.items():
        last = state["seen"].get(chat_id)

        messages = [
            message
            async for message in tg.iter_messages(
                entity,
                limit=BACKFILL_LIMIT,
                min_id=last or 0,
            )
        ]

        if messages:
            await forward(tg, state, chat_id, reversed(messages))


async def report(state: str, message: str | None = None, me=None) -> None:
    """Tell the dashboard how the sign-in went."""
    payload = {"state": state, "message": message}

    if me is not None:
        payload["username"] = getattr(me, "username", None)
        payload["name"] = " ".join(filter(None, [me.first_name, me.last_name])) or None

    try:
        api("POST", "login", payload)
    except requests.RequestException as error:
        print(f"could not report login state: {error}")


async def serve_login(tg: TelegramClient) -> bool:
    """
    Carry out whatever step of a sign-in the dashboard is waiting on.

    The dashboard holds the conversation and none of its outcome: it relays a phone number
    and a code, and the session that results is written here, on this machine. Returns True
    once the account is authorised.

    Secrets are delivered exactly once - asking again returns nothing - so a step that
    fails has to be restarted from the dashboard rather than retried blindly with a code
    that has already been spent.
    """
    try:
        work = api("GET", "login")
    except requests.RequestException:
        return await tg.is_user_authorized()

    action = work.get("action", "none")

    if action == "none":
        return await tg.is_user_authorized()

    try:
        if action == "send_code":
            await tg.send_code_request(work["phone"])
            await report("code_sent")
            print("Code requested; waiting for it to be entered in the dashboard.")

        elif action == "sign_in":
            code = work.get("code")

            if not code:
                await report("failed", "The code was not delivered. Start again.")
                return False

            await tg.sign_in(phone=work["phone"], code=code)
            await report("active", me=await tg.get_me())
            print("Signed in.")
            return True

        elif action == "password":
            password = work.get("password")

            if not password:
                await report("failed", "The password was not delivered. Start again.")
                return False

            await tg.sign_in(password=password)
            await report("active", me=await tg.get_me())
            print("Signed in.")
            return True

    except SessionPasswordNeededError:
        # Two-step verification. Asked for separately so the password is only ever
        # requested when it is genuinely required.
        await report("password_needed")
        print("Two-step verification is on; waiting for the password.")

    except Exception as error:  # noqa: BLE001 - Telegram's message is the useful part
        # Reported verbatim: "wrong code" and "this number is banned" need entirely
        # different responses, and "failed" sends people to the wrong one.
        await report("failed", str(error)[:200])
        print(f"Sign-in failed: {error}")

    return False


async def cmd_run() -> None:
    state = load_state()
    watch: set[str] = set()

    tg = client()
    await tg.connect()

    # Not signed in is no longer fatal. The dashboard can drive a sign-in through this
    # process, which is the whole point: adding an account should not mean opening a
    # terminal on this machine.
    while not await tg.is_user_authorized():
        if await serve_login(tg):
            break

        await asyncio.sleep(5)

    print("Authorised.")

    async def refresh() -> None:
        """Re-read the watch list, so the dashboard's switch takes effect while running."""
        nonlocal watch

        while True:
            try:
                current = set(api("GET", "channels")["watch"])

                if current != watch:
                    added = current - watch
                    watch = current
                    print(f"Watching {len(watch)} channel(s).")

                    if added:
                        await catch_up(tg, state, sorted(added))
            except requests.RequestException as error:
                print(f"Could not reach the dashboard: {error}")

            await asyncio.sleep(REFRESH_SECONDS)

    @tg.on(events.MessageEdited())
    async def on_edit(event) -> None:
        """
        A provider correcting themselves.

        Forwarded down the same path as a new message: the dashboard keys on chat plus
        message id, so an edit updates the signal it belongs to rather than becoming a
        second one. What it does with it depends on whether that signal has traded, which
        is a question this has no business answering.
        """
        await on_message(event)

    @tg.on(events.NewMessage())
    async def on_message(event) -> None:
        chat_id = str(event.chat_id).removeprefix("-100").lstrip("-")

        # Filtered here rather than at the dashboard: chats you have not enabled are
        # never posted to a web server at all.
        if chat_id not in watch:
            return

        try:
            await forward(tg, state, chat_id, [event.message])
        except requests.RequestException as error:
            # Deliberately not advancing the checkpoint; catch_up re-sends it.
            print(f"[{chat_id}] post failed, will retry on next catch-up: {error}")

    print("Collector running. Ctrl-C to stop.")

    await asyncio.gather(refresh(), tg.run_until_disconnected())


COMMANDS = {"login": cmd_login, "announce": cmd_announce, "run": cmd_run}


def main() -> None:
    command = sys.argv[1] if len(sys.argv) > 1 else "run"

    if command not in COMMANDS:
        sys.exit(f"Unknown command '{command}'. One of: {', '.join(COMMANDS)}")

    require_config()

    try:
        asyncio.run(COMMANDS[command]())
    except KeyboardInterrupt:
        print("\nStopped.")


if __name__ == "__main__":
    main()
