<?php

namespace App\Repositories;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\IdentityPolicy;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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
     * key + source_type 조합으로 정책 존재 여부를 확인합니다.
     *
     * @param  string  $key  정책 키
     * @param  string  $sourceType  'core' | 'module' | 'plugin' | 'admin'
     * @return bool 해당 조합의 정책 존재 여부
     */
    public function existsByKeyAndSourceType(string $key, string $sourceType): bool
    {
        return IdentityPolicy::query()
            ->where('key', $key)
            ->where('source_type', $sourceType)
            ->exists();
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
        $query = IdentityPolicy::query()
            ->where('scope', $scope)
            ->where('target', $target)
            ->where('enabled', true);

        // 비활성 모듈/플러그인이 선언한 정책은 enforce 대상에서 제외
        $this->applyActiveExtensionScope($query);

        // priority 동률 시 결정적 순서 보장 — id 2차 정렬키가 없으면 MySQL 이 동점 행을
        // 보장되지 않는 순서로 반환해, 같은 scope+target 에 동률 정책이 둘 이상일 때 어느 정책이
        // enforce 되는지(어떤 purpose 로 challenge 되는지)가 비결정적이 된다. 운영자는 우선순위
        // 입력칸으로 순서를 정할 수 있으나, 같은 값을 넣어 동률이 된 경우에도 항상 동일한 정책이
        // 적용되도록 id 오름차순(먼저 생성된 정책 우선)을 2차 키로 고정한다.
        return $query
            ->orderByDesc('priority')
            ->orderBy('id')
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
     * 현재 키 목록에 없는 stale 정책 모델을 조회합니다.
     *
     * 호출 측이 per-model delete()(deleted 이벤트 발화 — 라우트 스코프 캐시 flush)와
     * 로깅을 수행할 수 있도록 모델 인스턴스를 반환한다.
     *
     * @param  string  $sourceType  소스 타입
     * @param  string  $sourceIdentifier  소스 식별자
     * @param  array  $currentKeys  유지할 키 목록
     * @return Collection<int, IdentityPolicy> stale 정책 목록
     */
    public function findStale(string $sourceType, string $sourceIdentifier, array $currentKeys): Collection
    {
        $query = IdentityPolicy::query()
            ->where('source_type', $sourceType)
            ->where('source_identifier', $sourceIdentifier);

        if (! empty($currentKeys)) {
            $query->whereNotIn('key', $currentKeys);
        }

        return $query->get();
    }

    /**
     * 필터 기반 정책 페이지네이션 결과를 반환합니다.
     *
     * @param  array  $filters  검색 필터
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이터
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
                $query = IdentityPolicy::query()
                    ->where('scope', 'route')
                    ->where('enabled', true);

                // 비활성 모듈/플러그인이 선언한 정책은 enforce 대상에서 제외
                $this->applyActiveExtensionScope($query);

                // resolveByScopeTarget 과 동일한 결정적 정렬 — priority 동률 시 id 오름차순.
                // 미들웨어가 이 인덱스 순서대로 enforce 하므로, 2차 키가 없으면 동률 정책의
                // 적용 순서가 캐시 재빌드/실행계획에 따라 달라진다.
                $policies = $query
                    ->orderByDesc('priority')
                    ->orderBy('id')
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
            // 설치 완료 상태에서는 Schema introspection 을 건너뜀 (매 요청 information_schema 쿼리 제거).
            // 인스톨러 이전/마이그레이션 전 환경에서는 기존 hasTable 폴백으로 안전하게 빈 배열 반환.
            if (! config('app.installer_completed') && ! Schema::hasTable('identity_policies')) {
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

    /**
     * 비활성 모듈/플러그인이 선언한 정책을 enforce 대상에서 제외하는 쿼리 scope 를 적용합니다.
     *
     * 정책의 source_type/source_identifier 귀속 정보를 기준으로:
     *   - core / admin 정책: 항상 포함 (확장 비활성 개념 없음)
     *   - module 정책: source_identifier 가 활성 모듈일 때만 포함
     *   - plugin 정책: source_identifier 가 활성 플러그인일 때만 포함
     *
     * `enabled` 컬럼(운영자 토글)은 그대로 보존하며, 확장이 재활성화되면 정책도 자동으로
     * 다시 enforce 된다. modules/plugins 테이블 부재(부팅/마이그레이션 전) 시에는
     * 필터를 적용하지 않아 기존 동작을 보존한다.
     *
     * @param  Builder  $query  대상 쿼리 빌더
     * @return Builder 활성 확장 필터가 적용된 쿼리 빌더
     */
    protected function applyActiveExtensionScope(Builder $query): Builder
    {
        $activeModules = $this->activeExtensionIdentifiers(Module::class);
        $activePlugins = $this->activeExtensionIdentifiers(Plugin::class);

        // 테이블 부재 등으로 활성 목록 자체를 조회하지 못한 경우(null) 필터 미적용 — 기존 동작 보존
        if ($activeModules === null && $activePlugins === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($activeModules, $activePlugins): void {
            $q->whereIn('source_type', ['core', 'admin'])
                ->orWhere(function (Builder $sub) use ($activeModules): void {
                    $sub->where('source_type', 'module')
                        ->whereIn('source_identifier', $activeModules ?? []);
                })
                ->orWhere(function (Builder $sub) use ($activePlugins): void {
                    $sub->where('source_type', 'plugin')
                        ->whereIn('source_identifier', $activePlugins ?? []);
                });
        });
    }

    /**
     * 활성 상태(ExtensionStatus::Active)인 확장 식별자 목록을 반환합니다.
     *
     * @param  class-string<Module|Plugin>  $modelClass  modules 또는 plugins 모델 클래스
     * @return list<string>|null 활성 식별자 목록, 테이블 부재/조회 실패 시 null
     */
    protected function activeExtensionIdentifiers(string $modelClass): ?array
    {
        try {
            $table = (new $modelClass)->getTable();
            // 설치 완료 상태에서는 Schema introspection 을 건너뜀 (매 요청 information_schema 쿼리 제거).
            // 인스톨러 이전/마이그레이션 전 환경에서는 기존 hasTable 폴백으로 null 반환(필터 미적용).
            if (! config('app.installer_completed') && ! Schema::hasTable($table)) {
                return null;
            }

            return $modelClass::query()
                ->where('status', ExtensionStatus::Active->value)
                ->pluck('identifier')
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return null;
        }
    }
}
