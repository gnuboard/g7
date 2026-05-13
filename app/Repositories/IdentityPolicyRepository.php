<?php

namespace App\Repositories;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Models\IdentityPolicy;
use Illuminate\Support\Collection;

/**
 * identity_policies Repository 구현체.
 *
 * @since 7.0.0-beta.4
 */
class IdentityPolicyRepository implements IdentityPolicyRepositoryInterface
{
    public function __construct(
        protected CacheInterface $cache,
    ) {}

    /**
     * 정책 키로 정책을 조회합니다.
     *
     * @param  string  $key  정책 키
     * @return IdentityPolicy|null 조회된 정책 또는 null
     */
    public function findByKey(string $key): ?IdentityPolicy
    {
        return IdentityPolicy::query()->where('key', $key)->first();
    }

    /**
     * ID로 정책을 조회합니다.
     *
     * @param  int  $id  정책 ID
     * @return IdentityPolicy|null 조회된 정책 또는 null
     */
    public function findById(int $id): ?IdentityPolicy
    {
        return IdentityPolicy::find($id);
    }

    /**
     * scope/target 기준으로 활성화된 정책 컬렉션을 우선순위 내림차순으로 반환합니다.
     *
     * @param  string  $scope  정책 scope
     * @param  string  $target  정책 target
     * @return Collection 정책 컬렉션
     */
    public function resolveByScopeTarget(string $scope, string $target): Collection
    {
        return IdentityPolicy::query()
            ->where('scope', $scope)
            ->where('target', $target)
            ->where('enabled', true)
            ->orderByDesc('priority')
            ->get();
    }

    /**
     * 정책 키로 upsert 합니다 (존재 시 업데이트, 미존재 시 생성).
     *
     * @param  array  $attributes  정책 속성 (key 포함)
     * @return IdentityPolicy upsert 된 정책
     */
    public function upsertByKey(array $attributes): IdentityPolicy
    {
        $key = (string) ($attributes['key'] ?? '');
        $model = IdentityPolicy::query()->where('key', $key)->first();

        if ($model) {
            $model->fill($attributes);
            $model->save();

            return $model;
        }

        return IdentityPolicy::create($attributes);
    }

    /**
     * 정책 키로 정책을 업데이트합니다.
     *
     * @param  string  $key  정책 키
     * @param  array  $attributes  업데이트할 속성
     * @param  array  $overridesFields  user_overrides 에 추가할 필드 목록
     * @return bool 성공 여부
     */
    public function updateByKey(string $key, array $attributes, array $overridesFields = []): bool
    {
        $model = $this->findByKey($key);
        if (! $model) {
            return false;
        }

        $model->fill($attributes);

        if (! empty($overridesFields)) {
            $current = $model->user_overrides ?? [];
            $merged = array_values(array_unique(array_merge($current, $overridesFields)));
            $model->user_overrides = $merged;
        }

        return $model->save();
    }

    /**
     * admin source 정책을 키 기준으로 삭제합니다.
     *
     * @param  string  $key  정책 키
     * @return bool 성공 여부
     */
    public function deleteByKey(string $key): bool
    {
        $model = IdentityPolicy::query()
            ->where('key', $key)
            ->where('source_type', 'admin')
            ->first();

        return $model ? (bool) $model->delete() : false;
    }

    /**
     * 정책 모델을 영속화합니다 (Eloquent save 위임).
     *
     * @param  IdentityPolicy  $policy  영속화할 모델
     * @return bool 저장 성공 여부
     */
    public function save(IdentityPolicy $policy): bool
    {
        return (bool) $policy->save();
    }

    /**
     * 특정 소스의 정책 개수를 반환합니다.
     *
     * @param  string  $sourceType  소스 타입
     * @param  string  $sourceIdentifier  소스 식별자
     * @return int 정책 개수
     */
    public function countBySource(string $sourceType, string $sourceIdentifier): int
    {
        return IdentityPolicy::query()
            ->where('source_type', $sourceType)
            ->where('source_identifier', $sourceIdentifier)
            ->count();
    }

    /**
     * 현재 키 목록에 없는 stale 정책을 일괄 삭제합니다.
     *
     * @param  string  $sourceType  소스 타입
     * @param  string  $sourceIdentifier  소스 식별자
     * @param  array  $currentKeys  유지할 키 목록
     * @return int 삭제된 행 수
     */
    public function cleanupStale(string $sourceType, string $sourceIdentifier, array $currentKeys): int
    {
        $query = IdentityPolicy::query()
            ->where('source_type', $sourceType)
            ->where('source_identifier', $sourceIdentifier);

        if (! empty($currentKeys)) {
            $query->whereNotIn('key', $currentKeys);
        }

        return (int) $query->delete();
    }

    /**
     * 필터 기반 정책 페이지네이션 결과를 반환합니다.
     *
     * @param  array  $filters  검색 필터
     * @param  int  $perPage  페이지당 항목 수
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 페이지네이터
     */
    public function search(array $filters, int $perPage = 20)
    {
        $query = IdentityPolicy::query();

        foreach (['scope', 'purpose', 'source_type', 'source_identifier', 'applies_to', 'fail_mode'] as $exact) {
            if (! empty($filters[$exact])) {
                $query->where($exact, $filters[$exact]);
            }
        }

        if (isset($filters['enabled']) && $filters['enabled'] !== '') {
            $query->where('enabled', (bool) $filters['enabled']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('key', 'like', $term)
                    ->orWhere('target', 'like', $term);
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * 활성화된 모든 정책을 반환합니다.
     *
     * @return Collection 활성 정책 컬렉션
     */
    public function allEnabled(): Collection
    {
        return IdentityPolicy::query()->where('enabled', true)->get();
    }

    /**
     * route scope 정책의 라우트명 인덱스를 캐시 기반으로 반환합니다.
     *
     * @return array<string, Collection> 라우트명 => 정책 컬렉션 매핑
     */
    public function getRouteScopeIndex(): array
    {
        $ttl = (int) g7_core_settings('cache.identity_policy_ttl', 3600);

        return $this->cache->remember(
            IdentityPolicy::ROUTE_SCOPE_CACHE_KEY,
            function (): array {
                $policies = IdentityPolicy::query()
                    ->where('scope', 'route')
                    ->where('enabled', true)
                    ->orderByDesc('priority')
                    ->get();

                $index = [];
                foreach ($policies as $policy) {
                    foreach ($this->expandTargetBraces((string) $policy->target) as $routeName) {
                        if ($routeName === '') {
                            continue;
                        }
                        $bucket = $index[$routeName] ?? new Collection;
                        $bucket->push($policy);
                        $index[$routeName] = $bucket;
                    }
                }

                return $index;
            },
            $ttl,
            [IdentityPolicy::ROUTE_SCOPE_CACHE_TAG],
        );
    }

    /**
     * brace expansion — 'api.admin.{modules,plugins}.uninstall' → ['api.admin.modules.uninstall', 'api.admin.plugins.uninstall'].
     * 단일 그룹만 지원 (중첩/다중 그룹은 정책 키를 분리해서 정의하는 편이 명확).
     *
     * @param  string  $target
     * @return list<string>
     */
    protected function expandTargetBraces(string $target): array
    {
        if (! preg_match('/\{([^{}]+)\}/', $target, $matches)) {
            return [$target];
        }
        $options = array_map('trim', explode(',', $matches[1]));

        return array_map(
            static fn (string $opt) => preg_replace('/\{[^{}]+\}/', $opt, $target, 1),
            $options,
        );
    }

    /**
     * scope='hook' 활성 정책의 target 목록(중복 제거)을 반환합니다.
     *
     * 마이그레이션 전이거나 DB 미연결 환경에서는 빈 배열을 반환해 부팅을 보호합니다.
     *
     * @return list<string> 동적 hook target 목록
     */
    public function listHookTargets(): array
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('identity_policies')) {
                return [];
            }

            return IdentityPolicy::query()
                ->where('scope', 'hook')
                ->distinct()
                ->pluck('target')
                ->filter(fn ($t) => is_string($t) && $t !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
