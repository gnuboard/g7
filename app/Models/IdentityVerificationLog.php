<?php

namespace App\Models;

use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 본인인증 Challenge 감사 로그.
 *
 * Challenge 생명주기 전체(발송/재시도/검증/만료/취소/정책 위반)를 기록하는 append-only 테이블.
 * activity_logs 와 역할을 분리 — 이쪽은 기술 이벤트 감사, activity_logs 는 사용자 관점 서사.
 *
 * @since 7.0.0-beta.4
 *
 * @property string $id
 * @property string $provider_id
 * @property string $purpose
 * @property string $channel
 * @property int|null $user_id
 * @property string $target_hash
 * @property string $status
 * @property string|null $render_hint
 * @property int $attempts
 * @property int $max_attempts
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $origin_type
 * @property string|null $origin_identifier
 * @property string|null $origin_policy_key
 * @property array|null $properties
 * @property array|null $metadata
 * @property string|null $verification_token
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $verified_at
 * @property \Carbon\Carbon|null $consumed_at
 */
class IdentityVerificationLog extends Model
{
    use HasUuids;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SENT = 'sent';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_POLICY_VIOLATION_LOGGED = 'policy_violation_logged';

    protected $table = 'identity_verification_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'provider_id',
        'purpose',
        'channel',
        'user_id',
        'target_hash',
        'status',
        'render_hint',
        'attempts',
        'max_attempts',
        'ip_address',
        'user_agent',
        'origin_type',
        'origin_identifier',
        'origin_policy_key',
        'properties',
        'metadata',
        'verification_token',
        'expires_at',
        'verified_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'status' => IdentityVerificationStatus::class,
            'origin_type' => IdentityOriginType::class,
            'properties' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->status === IdentityVerificationStatus::Verified;
    }
}
