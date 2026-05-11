<?php

namespace App\Models;

use App\Enums\IdentityPolicyAppliesTo;
use App\Enums\IdentityPolicyFailMode;
use App\Enums\IdentityPolicyScope;
use App\Enums\IdentityPolicySourceType;
use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Model;

/**
 * 본인인증 정책 모델.
 *
 * scope(route/hook/custom) x target(라우트명/훅명/커스텀키) 매칭으로
 * 특정 지점에 IDV 를 강제합니다. 선언형(Seeder) + 운영자 편집(user_overrides 보존) 혼합 관리.
 *
 * @since 7.0.0-beta.4
 *
 * @property int $id
 * @property string $key
 * @property string $scope
 * @property string $target
 * @property string $purpose
 * @property string|null $provider_id
 * @property int $grace_minutes
 * @property bool $enabled
 * @property int $priority
 * @property array|null $conditions
 * @property string $source_type
 * @property string $source_identifier
 * @property string $applies_to
 * @property string $fail_mode
 * @property array|null $user_overrides
 */
class IdentityPolicy extends Model
{
    use HasUserOverrides;

    protected $table = 'identity_policies';

    protected $fillable = [
        'key',
        'scope',
        'target',
        'purpose',
        'provider_id',
        'grace_minutes',
        'enabled',
        'priority',
        'conditions',
        'source_type',
        'source_identifier',
        'applies_to',
        'fail_mode',
        'user_overrides',
    ];

    /**
     * ActivityLog 추적 필드 — 관리자 CRUD 대상 전체를 감사 대상으로.
     */
    protected array $activityLogFields = [
        'key', 'scope', 'target', 'purpose', 'provider_id',
        'grace_minutes', 'enabled', 'priority', 'applies_to', 'fail_mode',
    ];

    /**
     * HasUserOverrides — 운영자가 수정 가능한 필드.
     * Seeder 재실행 시 user_overrides 에 기록된 필드는 운영자 수정값을 보존한다.
     */
    protected array $trackableFields = [
        'enabled', 'grace_minutes', 'provider_id', 'fail_mode', 'conditions',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'grace_minutes' => 'integer',
            'priority' => 'integer',
            'scope' => IdentityPolicyScope::class,
            'fail_mode' => IdentityPolicyFailMode::class,
            'applies_to' => IdentityPolicyAppliesTo::class,
            'source_type' => IdentityPolicySourceType::class,
            'conditions' => 'array',
            'user_overrides' => 'array',
        ];
    }

    /**
     * Route scope 자동 매핑 캐시 키 — Repository::getRouteScopeIndex 가 사용.
     * 정책 CRUD 시 saved/deleted 이벤트로 즉시 invalidate 되어 다음 요청부터 새 정책 반영.
     *
     * 캐시 백엔드는 CoreCacheDriver(접두사 g7:core:) 를 통해 G7 표준 격리 적용.
     */
    public const ROUTE_SCOPE_CACHE_KEY = 'identity_policies.route_scope_index';

    /**
     * 캐시 무효화 태그 — Repository::getRouteScopeIndex 가 같은 태그로 등록하므로
     * flushTags 한 번에 일괄 정리됨 (단일 키 forget 보다 향후 다중 캐시 키 확장에 안전).
     */
    public const ROUTE_SCOPE_CACHE_TAG = 'identity_policy';

    protected static function booted(): void
    {
        static::saved(static::flushRouteScopeCache(...));
        static::deleted(static::flushRouteScopeCache(...));
    }

    public static function flushRouteScopeCache(): void
    {
        $cache = app(\App\Contracts\Extension\CacheInterface::class);
        if ($cache->supportsTags()) {
            $cache->flushTags([self::ROUTE_SCOPE_CACHE_TAG]);

            return;
        }
        $cache->forget(self::ROUTE_SCOPE_CACHE_KEY);
    }
}
