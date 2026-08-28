<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an administrator did to somebody else's data.
 *
 * See the migration for why this exists and why it stays empty on a single-operator
 * deployment. Deliberately not `BelongsToTenant`: the whole point is that it records
 * cross-tenant action, and scoping it to the acting tenant would hide exactly the rows it
 * was written to keep.
 */
class AdminAction extends Model
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    protected $fillable = [
        'admin_user_id',
        'subject_user_id',
        'action',
        'subject_type',
        'subject_id',
        'changes',
        'ip',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * The model class without its namespace, for a table somebody reads.
     */
    public function subjectLabel(): string
    {
        return class_basename($this->subject_type).($this->subject_id === null ? '' : ' #'.$this->subject_id);
    }
}
