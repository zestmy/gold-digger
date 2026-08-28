<?php

namespace App\Observers;

use App\Models\AdminAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what an administrator changed on somebody else's account.
 *
 * ## It is silent until there is somebody to be silent about
 *
 * Three things all have to be true before a row is written: somebody is authenticated, they
 * are an administrator, and the record they touched belongs to a different user. On a
 * single-operator deployment the third is never true, so this writes nothing and costs a
 * boolean comparison per save. It begins recording the moment there is a second tenant,
 * which is exactly when it begins to matter.
 *
 * That also means it does not audit the console's own housekeeping - an operator editing
 * their own strategy is using the application, not exercising a privilege.
 *
 * ## Redaction is not optional
 *
 * `broker_accounts.account_number` and `telegram_accounts.session` are encrypted at rest,
 * and a Telegram session can read every chat on somebody's account and post as them. An
 * audit log that captured the plaintext of the secrets it audits would be a worse leak than
 * the one it exists to detect.
 *
 * So anything the model hides, anything on the deny-list below, and anything that looks like
 * a credential is stored as `[redacted]`. That the column changed is the auditable fact; its
 * value is not.
 *
 * ## Recording never breaks the action
 *
 * A failure here is logged and swallowed. An audit trail that could prevent a support fix
 * from saving would be traded away the first time it did so, and then there would be no
 * audit trail at all.
 */
class AdminActionObserver
{
    /**
     * Attribute names never stored, whatever the model says about them.
     *
     * @var array<int, string>
     */
    private const NEVER_STORE = [
        'password', 'remember_token', 'token_hash', 'session',
        'account_number', 'login_phone', 'telegram_chat_id',
    ];

    public function created(Model $model): void
    {
        $this->record($model, AdminAction::CREATED, $this->redact($model, $model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        // What actually changed, not the whole row. A diff is what somebody investigating
        // wants, and storing every column of every save would bury it.
        $this->record($model, AdminAction::UPDATED, $this->redact($model, $model->getChanges()));
    }

    public function deleted(Model $model): void
    {
        // No columns. The auditable fact is that the row is gone; reproducing its contents
        // here would rebuild the record the deletion removed.
        $this->record($model, AdminAction::DELETED, null);
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private function record(Model $model, string $action, ?array $changes): void
    {
        try {
            $admin = Auth::user();

            if ($admin === null || ! $admin->is_admin) {
                return;
            }

            $owner = $model->getAttribute('user_id');

            // Their own data is not a privileged act. Only reach for the audit table when
            // an administrator has stepped outside their own account.
            if ($owner === null || (int) $owner === (int) $admin->getKey()) {
                return;
            }

            AdminAction::create([
                'admin_user_id' => $admin->getKey(),
                'subject_user_id' => (int) $owner,
                'action' => $action,
                'subject_type' => $model::class,
                'subject_id' => $model->getKey(),
                'changes' => $changes === [] ? null : $changes,
                'ip' => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Admin action could not be recorded.', [
                'model' => $model::class,
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Strip anything that should not sit in an audit table in plaintext.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(Model $model, array $attributes): array
    {
        // Whatever the model already refuses to serialise is a credential by the model's
        // own account, so the deny-list does not have to be exhaustive to be safe.
        $hidden = $model->getHidden();

        foreach ($attributes as $key => $value) {
            if (in_array($key, self::NEVER_STORE, true) || in_array($key, $hidden, true)) {
                $attributes[$key] = '[redacted]';
            }
        }

        // Timestamps are noise in a diff - every save changes updated_at, and a reader
        // scanning for what an administrator altered does not need telling twice.
        unset($attributes['updated_at'], $attributes['created_at']);

        return $attributes;
    }
}
