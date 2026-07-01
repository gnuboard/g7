<?php

namespace Plugins\Sirsoft\Gdpr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType;

/**
 * GDPR 정책 버전 발행 이력 모델 (immutable append-only)
 *
 * 운영자가 GDPR 설정을 Material 하게 변경할 때마다 새 row 가 발행됩니다.
 * Art.7(1) 동의 입증 책임 + Art.30 처리 기록 의무를 충족합니다.
 *
 * @property int $id
 * @property int $version
 * @property GdprPolicyChangeType $change_type
 * @property string|null $memo
 * @property array $snapshot
 * @property int|null $created_by
 * @property \Carbon\Carbon|null $created_at
 * @property-read User|null $createdBy
 */
class GdprPolicyVersion extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'gdpr_policy_versions';

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
        'version',
        'change_type',
        'memo',
        'snapshot',
        'created_by',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'change_type' => GdprPolicyChangeType::class,
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 최신 버전 1건만 조회하는 스코프 (version DESC 정렬)
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->orderByDesc('version')->limit(1);
    }

    /**
     * 발행자(운영자) 관계
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
