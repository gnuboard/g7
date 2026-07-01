<?php

namespace Plugins\Sirsoft\Gdpr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GDPR 사용자 현재 동의 상태 모델 (mutable status)
 *
 * 사용자별·항목별 현재 동의 상태를 1행으로 유지합니다.
 * 변경 이력은 GdprUserConsentHistory에 별도 append-only로 기록됩니다.
 *
 * @property int $id
 * @property int $user_id
 * @property string $consent_key
 * @property string|null $consent_category
 * @property bool $is_consented
 * @property \Carbon\Carbon|null $consented_at
 * @property \Carbon\Carbon|null $revoked_at
 * @property int $consent_count
 * @property string|null $policy_version
 * @property string|null $last_source
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read User $user
 */
class GdprUserConsent extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'gdpr_user_consents';

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'consent_key',
        'consent_category',
        'is_consented',
        'consented_at',
        'revoked_at',
        'consent_count',
        'policy_version',
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
