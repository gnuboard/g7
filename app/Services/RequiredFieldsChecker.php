<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 회원의 비어 있는 필수 항목 판정.
 *
 * 판정 대상은 회원 항목 집합에서 사용 중이고 필수이며 본인이 고칠 수 있는 항목이다. 회원마다 둘로 갈린다.
 *
 * | 회원 | 대상 항목 |
 * | --- | --- |
 * | 보통 회원 | 「기존 회원에게도 입력 요구」를 켠 항목만 |
 * | 필수 항목을 건너뛰고 만들어진 회원 (`required_fields_deferred_at` 이 있음) | 사용 중이고 필수이며 본인 수정 범위인 모든 항목 |
 *
 * 그 회원이 고칠 수 없게 잠긴 칸은 판정에서 뺀다(채울 방법이 없는 칸 때문에 회원이 갇히지 않게).
 *
 * 회원 한 명의 판정(`pendingKeys()`)은 캐시한다. 키는 `fields:pending:{회원 ID}:{정의 버전}` 이라 운영자가 항목을 바꾸면
 * 새 키로 다시 판정한다. 회원 값이 저장되거나 `required_fields_deferred_at` 이 바뀌면 `forget()` 으로 지운다.
 * 캐시에는 잠금을 빼기 전의 키만 넣고 잠금은 읽을 때마다 뺀다 — 잠금은 확장 필터가 그때그때 정하기 때문이다.
 *
 * @since 7.2.0
 */
class RequiredFieldsChecker
{
    /** 판정 캐시 키 접두 */
    public const CACHE_PREFIX = 'fields:pending:';

    /** 판정 캐시 보관 시간(초) — 훅을 거치지 않고 값이 바뀐 경우에도 이 시간 뒤에는 다시 판정한다 */
    public const CACHE_TTL = 600;

    /**
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldValueService  $values  항목 값 Service
     * @param  UserLockedFieldsService  $lockedFields  회원 칸 수정 잠금
     * @param  CacheInterface  $cache  판정 캐시
     * @param  UserRepositoryInterface  $users  회원 저장소 (건너뛴 표시 지우기)
     */
    public function __construct(
        protected FieldRegistry $registry,
        protected FieldValueService $values,
        protected UserLockedFieldsService $lockedFields,
        protected CacheInterface $cache,
        protected UserRepositoryInterface $users,
    ) {}

    /**
     * 판정 대상이 될 수 있는 항목(사용 중, 필수, 본인 수정 범위)을 반환합니다.
     *
     * @return Collection<int, FieldDefinition> 항목 정의 목록 (표시 순서)
     */
    public function candidates(): Collection
    {
        return $this->registry->active(FieldSet::CORE_USER)
            ->filter(fn (FieldDefinition $definition) => $definition->required
                && $definition->edit_scope === FieldDefinition::SCOPE_SELF)
            ->values();
    }

    /**
     * 회원 한 명의 비어 있는 필수 항목 키를 반환합니다.
     *
     * 캐시된 판정을 쓴다. `$fresh` 를 켜면 캐시를 버리고 다시 판정한다 — 쓰기 요청을 거부하기 직전처럼
     * 낡은 판정으로 회원을 막으면 안 되는 자리에서 쓴다.
     *
     * @param  User  $user  회원
     * @param  bool  $fresh  캐시를 쓰지 않고 다시 판정할지
     * @return array<int, string> 항목 키 목록 (없으면 빈 배열)
     */
    public function pendingKeys(User $user, bool $fresh = false): array
    {
        if (! $user->exists) {
            return [];
        }

        $cacheKey = $this->cacheKey($user);
        $keys = $fresh ? null : $this->cache->get($cacheKey);

        if (! is_array($keys)) {
            // 캐시에는 잠금을 빼기 전의 키를 넣는다.
            $keys = $this->pendingFor(new Collection([$user]), excludeLocked: false)[$user->getKey()] ?? [];
            $this->cache->put($cacheKey, $keys, self::CACHE_TTL);
        }

        if ($keys === []) {
            return [];
        }

        $locked = $this->lockedFor($user);

        return array_values(array_filter($keys, fn ($key) => is_string($key) && ! in_array($key, $locked, true)));
    }

    /**
     * 회원 한 명의 판정 캐시를 지웁니다 (회원 값이 저장되거나 `required_fields_deferred_at` 이 바뀐 뒤에 부른다).
     *
     * @param  User|int|string  $user  회원 또는 회원 ID
     * @return void 반환값 없음
     */
    public function forget(User|int|string $user): void
    {
        $this->cache->forget($this->cacheKey($user));
    }

    /**
     * 회원 값이 저장된 뒤의 상태를 정리하고 남은 미입력 항목 키를 반환합니다.
     *
     * 판정 캐시를 버리고 다시 판정한다. 필수 항목을 건너뛰고 만들어진 회원(`required_fields_deferred_at`)이
     * 미입력을 모두 채웠으면 그 표시를 지운다 — 그 뒤로는 보통 회원과 같은 기준으로 판정한다.
     *
     * @param  User  $user  회원
     * @return array<int, string> 남은 미입력 항목 키 (없으면 빈 배열)
     */
    public function settle(User $user): array
    {
        if (! $user->exists) {
            return [];
        }

        $pending = $this->pendingKeys($user, fresh: true);

        if ($pending === [] && $user->required_fields_deferred_at !== null) {
            $this->users->update($user, ['required_fields_deferred_at' => null]);
            $this->forget($user);
        }

        return $pending;
    }

    /**
     * 회원의 판정 캐시 키를 만듭니다 (`fields:pending:{회원 ID}:{정의 버전}`).
     *
     * @param  User|int|string  $user  회원 또는 회원 ID
     * @return string 캐시 키
     */
    public function cacheKey(User|int|string $user): string
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return self::CACHE_PREFIX.$id.':'.$this->registry->version(FieldSet::CORE_USER);
    }

    /**
     * 회원 여럿의 비어 있는 필수 항목 키를 한 번에 판정합니다 (값 테이블은 쿼리 한 번).
     *
     * 목록 화면처럼 회원이 여럿이면 `$excludeLocked` 를 끈다 — 잠긴 칸은 회원마다 필터 훅으로 알아내므로
     * 행마다 훅을 부르지 않기 위해서다. 그때는 잠긴 채 비어 있는 칸도 미입력으로 센다.
     *
     * @param  Collection<int, User>  $users  회원 목록
     * @param  bool  $excludeLocked  그 회원에게 잠긴 칸을 판정에서 뺄지
     * @return array<int|string, array<int, string>> 회원 ID => 비어 있는 항목 키 목록 (미입력이 없는 회원은 싣지 않는다)
     */
    public function pendingFor(Collection $users, bool $excludeLocked = true): array
    {
        $definitions = $this->candidates();
        $users = $users->filter(fn ($user) => $user instanceof User && $user->exists)->values();
        if ($definitions->isEmpty() || $users->isEmpty()) {
            return [];
        }

        $values = $this->values->preload($users, FieldSet::CORE_USER, $definitions);
        $result = [];

        foreach ($users as $user) {
            $deferred = $user->required_fields_deferred_at !== null;
            $locked = null;
            $pending = [];

            foreach ($definitions as $definition) {
                if (! $deferred && ! $definition->require_existing_members) {
                    continue;
                }
                if (! $this->values->isEmpty($definition, $values[$user->getKey()][$definition->key] ?? null)) {
                    continue;
                }
                if ($excludeLocked) {
                    $locked ??= $this->lockedFor($user);
                    if (in_array($definition->key, $locked, true)) {
                        continue;
                    }
                }
                $pending[] = $definition->key;
            }

            if ($pending !== []) {
                $result[$user->getKey()] = $pending;
            }
        }

        return $result;
    }

    /**
     * 항목 키 목록을 화면에 보일 `{key, label}` 목록으로 바꿉니다.
     *
     * @param  array<int, string>  $keys  항목 키 목록
     * @return array<int, array{key: string, label: string}> 키와 이름
     */
    public function describe(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $labels = [];
        foreach ($this->candidates() as $definition) {
            $labels[$definition->key] = $definition->localized('name') ?? $definition->key;
        }

        return array_map(fn (string $key) => ['key' => $key, 'label' => $labels[$key] ?? $key], array_values($keys));
    }

    /**
     * 「필수 항목 미입력」 목록 필터가 쓸 조회 조건의 재료를 반환합니다.
     *
     * 저장소가 SQL 로 가를 수 있는 항목만 싣는다 — 회원 컬럼 항목은 컬럼명, 값 테이블 항목은 항목 ID.
     * 확장 핸들러가 값을 가진 항목과 파일 항목은 SQL 로 비었는지 알 수 없어 뺀다(행의 미입력 배지는 그 항목까지 센다).
     *
     * @return array{existing: array{columns: array<int, string>, definition_ids: array<int, int>}, deferred: array{columns: array<int, string>, definition_ids: array<int, int>}} 보통 회원 대상과 건너뛴 회원 대상
     */
    public function querySpec(): array
    {
        $spec = [
            'existing' => ['columns' => [], 'definition_ids' => []],
            'deferred' => ['columns' => [], 'definition_ids' => []],
        ];

        foreach ($this->candidates() as $definition) {
            $groups = $definition->require_existing_members ? ['existing', 'deferred'] : ['deferred'];

            foreach ($groups as $group) {
                if ($definition->storage === FieldDefinition::STORAGE_COLUMN && is_string($definition->storage_target) && $definition->storage_target !== '') {
                    $spec[$group]['columns'][] = $definition->storage_target;
                } elseif ($definition->storage === FieldDefinition::STORAGE_VALUE && $definition->exists && ! $this->values->isFileField($definition)) {
                    $spec[$group]['definition_ids'][] = (int) $definition->id;
                }
            }
        }

        return $spec;
    }

    /**
     * 그 회원이 본인 화면에서 고칠 수 없는 칸을 반환합니다.
     *
     * @param  User  $user  회원
     * @return array<int, string> 잠긴 칸 이름
     */
    protected function lockedFor(User $user): array
    {
        return $this->lockedFields->lockedFor($user, UserLockedFieldsService::SURFACE_PROFILE);
    }
}
