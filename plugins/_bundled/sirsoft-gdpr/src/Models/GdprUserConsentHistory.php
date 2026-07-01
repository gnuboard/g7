<?php

namespace Plugins\Sirsoft\Gdpr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GDPR 동의 변경 이력 모델 (immutable append-only)
 *
 * 회원·게스트 모두의 모든 동의 변경(부여·철회)을 INSERT 전용으로 기록합니다.
 * GDPR Art.7(1) "동의 입증 책임" 영구 보존용.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property string $consent_key
 * @property string $action
 * @property string $source
 * @property string|null $policy_version
 * @property array|null $categories
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon|null $created_at
 * @property-read User|null $user
 */
class GdprUserConsentHistory extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'gdpr_user_consent_histories';

    /**
     * updated_at 컬럼 비활성화 (불변 append-only)
     *
     * @var bool
     */
    public const UPDATED_AT = null;

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'session_id',
        'consent_key',
        'action',
        'source',
        'policy_version',
        'categories',
        'ip_address',
        'user_agent',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 특정 사용자의 이력만 조회하는 스코프
     *
     * @param Builder $query
     * @param int $userId 사용자 ID
     * @return Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 특정 게스트 세션의 이력만 조회하는 스코프
     *
     * @param Builder $query
     * @param string $sessionId 게스트 세션 ID
     * @return Builder
     */
    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
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
