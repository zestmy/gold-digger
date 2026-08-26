#!/usr/bin/env python3
"""
FXSignalPro hosted Telegram session worker.

Signs tenants in and reads their channels, so that adding a Telegram account is something
a person does in a browser rather than by installing Python on a machine of their own.

What this costs, stated plainly
-------------------------------
The self-hosted collector next door keeps the session where the reading happens, and that
is the safer arrangement: a dashboard compromise cannot become a Telegram account
takeover if the dashboard never holds a session. This program is the deliberate trade of
that property for an onboarding flow a customer will actually complete.

So the sessions it holds can read every chat on a tenant's account and post as them. They
are encrypted at rest with the application key, never serialised towards a browser, and
reachable only with an infrastructure credential that is not issued through the dashboard.
That is mitigation, not equivalence, and it should not be described as anything else.

One implementation, two callers
-------------------------------
The sign-in conversation, the message shaping and the catch-up logic are imported from the
collector rather than reimplemented here. Two implementations of one state machine is how
you get a sign-in that works self-hosted and hangs hosted, and two implementations of
message shaping is how a signal parses in one mode and not the other. What differs between
the programs is only which account is being spoken for, so that is the only thing this
overrides: a `call` seam that rewrites the collector's endpoint paths onto the worker's
account-scoped ones.

Usage
-----
    python worker.py

Configuration is by environment variable, set by whoever deploys the platform. A tenant
configures nothing.
"""

from __future__ import annotations

import asyncio
import os
import sys
from pathlib import Path

import requests
from telethon import TelegramClient, events
from telethon.sessions import StringSession

# The collector is a sibling tool rather than an installed package. Imported by path so
# there is exactly one copy of the logic both programs run.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "telegram-collector"))

from collector import (  # noqa: E402
    announce,
    catch_up,
    forward,
    resolve_named,
    serve_login,
)

BASE_URL = os.environ.get("GD_BASE_URL", "").rstrip("/")
TOKEN = os.environ.get("TELEGRAM_WORKER_TOKEN", "")
API_ID = os.environ.get("TELEGRAM_APP_ID", "")
API_HASH = os.environ.get("TELEGRAM_APP_HASH", "")

# How often the fleet is reconciled against the dashboard: accounts added, removed, or
# waiting on a sign-in step.
POLL_SECONDS = int(os.environ.get("TELEGRAM_WORKER_POLL", "10"))

# How often each signed-in account re-reads its watch list, so enabling a channel takes
# effect without a restart.
REFRESH_SECONDS = int(os.environ.get("GD_REFRESH_SECONDS", "60"))


def require_config() -> None:
    missing = [
        name
        for name, value in (
            ("GD_BASE_URL", BASE_URL),
            ("TELEGRAM_WORKER_TOKEN", TOKEN),
            ("TELEGRAM_APP_ID", API_ID),
            ("TELEGRAM_APP_HASH", API_HASH),
        )
        if not value
    ]

    if missing:
        sys.exit(
            "Missing configuration: "
            + ", ".join(missing)
            + "\nThese are set by the operator, not by a tenant. See config/telegram.php."
        )


def worker_api(method: str, path: str, payload: dict | None = None) -> dict:
    response = requests.request(
        method,
        f"{BASE_URL}/api/v1/telegram/worker/{path}",
        json=payload,
        headers={
            "Authorization": f"Bearer {TOKEN}",
            "Accept": "application/json",
        },
        timeout=30,
    )
    response.raise_for_status()

    return response.json()


class Account:
    """
    One tenant's Telegram account, from "waiting to be signed in" to "reading channels".

    Holds its own client, checkpoints and watch list. Nothing is module-level here, which
    is the difference between this and the collector: that program is one account by
    construction, and this one is however many have signed up.
    """

    def __init__(self, record: dict) -> None:
        self.id = record["id"]
        self.label = record["label"]
        self.session = record.get("session")
        self.state = record.get("ingest_state") or {"seen": {}}
        self.watch: set[str] = set()
        self.tg: TelegramClient | None = None

    # -- the seam -----------------------------------------------------------
    #
    # The collector's functions ask for "messages", "channels", "login". Those are the
    # right names; they simply live under this account's path when the worker is the one
    # asking. Rewriting here is what lets the logic be shared unmodified.

    def call(self, method: str, path: str, payload: dict | None = None) -> dict:
        return worker_api(method, f"accounts/{self.id}/{path}", payload)

    def save(self, state: dict) -> None:
        """Checkpoints go to the dashboard: a hosted worker has no disk that survives."""
        worker_api("PUT", f"accounts/{self.id}/state", {"ingest_state": state})

    def remember_session(self) -> None:
        """
        Persist the session the moment it exists.

        A StringSession lives in memory. If this process stops between a successful
        sign-in and the next write, the tenant is asked for a code they have already
        given - which reads as the product losing their account.
        """
        current = self.tg.session.save()

        if current and current != self.session:
            worker_api("PUT", f"accounts/{self.id}/session", {"session": current})
            self.session = current

    # -- lifecycle ----------------------------------------------------------

    async def run(self) -> None:
        self.tg = TelegramClient(StringSession(self.session or None), int(API_ID), API_HASH)

        await self.tg.connect()

        # Not signed in is the normal starting state for a new tenant, not an error. The
        # dashboard drives the conversation; this waits for it.
        while not await self.tg.is_user_authorized():
            if await serve_login(self.tg, call=self.call):
                break

            await asyncio.sleep(5)

        self.remember_session()

        print(f"[{self.id}] {self.label}: authorised.")

        # Every start, not only after a sign-in performed here. Cheap, idempotent, and it
        # means a channel joined since the last run is selectable without anyone acting.
        try:
            await announce(self.tg, call=self.call)
        except requests.RequestException as error:
            print(f"[{self.id}] could not report channels: {error}")

        self.tg.add_event_handler(self._on_message, events.NewMessage())
        # A provider correcting themselves. Down the same path: the dashboard keys on
        # chat plus message id, so an edit updates the signal rather than becoming a
        # second one.
        self.tg.add_event_handler(self._on_message, events.MessageEdited())

        await asyncio.gather(self._refresh(), self.tg.run_until_disconnected())

    async def _refresh(self) -> None:
        """Re-read the watch list, so the dashboard's switch takes effect while running."""
        while True:
            try:
                listing = self.call("GET", "channels")
                current = set(listing["watch"])

                # Private chats this tenant named. Only a signed-in client can turn a
                # username into an id, and this account is the one that is signed in.
                if listing.get("resolve"):
                    await resolve_named(self.tg, listing["resolve"], call=self.call)

                if current != self.watch:
                    added = current - self.watch
                    self.watch = current
                    print(f"[{self.id}] watching {len(self.watch)} channel(s).")

                    if added:
                        await catch_up(
                            self.tg, self.state, sorted(added),
                            call=self.call, save=self.save,
                        )
            except requests.RequestException as error:
                print(f"[{self.id}] could not reach the dashboard: {error}")

            await asyncio.sleep(REFRESH_SECONDS)

    async def _on_message(self, event) -> None:
        chat_id = str(event.chat_id).removeprefix("-100").lstrip("-")

        # Filtered here rather than at the dashboard: chats a tenant has not enabled are
        # never posted to a web server at all, hosted or not.
        if chat_id not in self.watch:
            return

        try:
            await forward(
                self.tg, self.state, chat_id, [event.message],
                call=self.call, save=self.save,
            )
        except requests.RequestException as error:
            # Deliberately not advancing the checkpoint; catch_up re-sends it.
            print(f"[{self.id}][{chat_id}] post failed, will retry on catch-up: {error}")


async def supervise() -> None:
    """
    Keep one runner per hosted account, and no more.

    Reconciled against the dashboard rather than driven by it: an account added five
    seconds ago and one that has been signed in for a month need the same treatment, and
    a worker that only learned about accounts through events would drift after any
    restart it did not witness.
    """
    running: dict[int, asyncio.Task] = {}

    while True:
        try:
            accounts = worker_api("GET", "accounts")["accounts"]
        except requests.RequestException as error:
            print(f"could not reach the dashboard: {error}")
            await asyncio.sleep(POLL_SECONDS)
            continue

        wanted = {record["id"]: record for record in accounts}

        # Removed, or no longer hosted. Stopping the task drops the connection, which is
        # what "remove this account" has to mean.
        for account_id in list(running):
            if account_id not in wanted or running[account_id].done():
                running.pop(account_id).cancel()

        for account_id, record in wanted.items():
            if account_id not in running:
                print(f"[{account_id}] starting {record['label']}.")
                running[account_id] = asyncio.create_task(Account(record).run())

        await asyncio.sleep(POLL_SECONDS)


def main() -> None:
    require_config()

    print(f"Worker up against {BASE_URL}.")

    try:
        asyncio.run(supervise())
    except KeyboardInterrupt:
        print("Stopped.")


if __name__ == "__main__":
    main()
