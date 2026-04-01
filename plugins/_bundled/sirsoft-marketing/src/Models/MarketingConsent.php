<?php

namespace Plugins\Sirsoft\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 사용자 마케팅 동의 상태 모델 (EAV 구조)
 *
 * 각 레코드는 특정 사용자의 특정 동의 항목(consent_key) 상태를 나타냅니다.
 * 사용자당 동의 항목별로 1개의 레코드를 유지합니다.
 *
 * @property int $id
 * @property int $user_id
 * @property string $consent_key
 * @property bool $is_consented
 * @property \Carbon\Carbon|null $consented_at
 * @property \Carbon\Carbon|null $revoked_at
 * @property int $consent_count
 * @property string|null $last_source
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read User $user
 */
class MarketingConsent extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'user_marketing_consents';

    /**
     * 법적 필수 동의 키 목록 (고정, 채널과 무관하게 항상 존재)
     */
    public const LEGAL_KEYS = [
        'third_party_consent',
        'info_disclosure',
    ];

    /**
     * 마케팅 동의 마스터 키 (채널 전체 동의/철회 제어)
     */
    public const MASTER_KEY = 'marketing_consent';

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'consent_key',
        'is_consented',
        'consented_at',
        'revoked_at',
        'consent_count',
        'last_source',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_consented' => 'boolean',
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
            'consent_count' => 'integer',
        ];
    }

    /**
     * 동의 상태인 레코드만 조회하는 스코프
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeConsented(Builder $query): Builder
    {
        return $query->where('is_consented', true);
    }

    /**
     * 특정 동의 항목 키로 조회하는 스코프
     *
     * @param Builder $query
     * @param string $key 동의 항목 키
     * @return Builder
     */
    public function scopeByConsentKey(Builder $query, string $key): Builder
    {
        return $query->where('consent_key', $key);
    }

    /**
     * 사용자 관계
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
