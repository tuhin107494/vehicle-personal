<?php

namespace App\Models\Gateway;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a super admin did to billing or gateway data: who, when, which record, which fields (never a secret's value). */
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<int, array{field:string, from?:mixed, to?:mixed}>  $changes
     */
    public static function record(User $user, string $action, Model $subject, array $changes = [], ?string $ip = null): self
    {
        return self::create([
            'user_id' => $user->id, 'action' => $action, 'subject_type' => $subject->getTable(), 'subject_id' => $subject->getKey(),
            'changes' => $changes ?: null, 'ip' => $ip,
        ]);
    }
}
