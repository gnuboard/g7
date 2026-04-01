<?php

namespace App\Models;

use App\Enums\ConsentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserConsent extends Model
{
    /**
     * updated_at 컬럼을 사용하지 않습니다 (불변 레코드).
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'consent_type',
        'agreed_at',
        'revoked_at',
        'ip_address',
    ];

    /**
     * 속성 캐스팅을 정의합니다.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consent_type' => ConsentType::class,
            'agreed_at'    => 'datetime',
            'revoked_at'   => 'datetime',
            'created_at'   => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // 관계 (Relationships)
    // ──────────────────────────────────────

    /**
     * 동의한 사용자를 반환합니다.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
