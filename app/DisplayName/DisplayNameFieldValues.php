<?php

namespace App\DisplayName;

use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 표시 이름으로 쓰는 추가 항목 값의 요청 단위 메모.
 *
 * 추가 항목 값은 회원 행이 아니라 값 테이블에 있어, 회원마다 읽으면 목록에서 N+1 이 된다.
 * `preload()` 로 회원 여럿의 값을 쿼리 한 번으로 읽어 두고 종류(`FieldValueKind`)가 여기서 꺼낸다.
 *
 * @since 7.2.0
 */
class DisplayNameFieldValues
{
    /**
     * 읽어 둔 값 — 회원 ID => (항목 키 => 보기용 문자열 또는 null).
     *
     * @var array<int, array<string, string|null>>
     */
    protected array $memo = [];

    /**
     * @param  FieldValueService  $values  항목 값 Service
     */
    public function __construct(
        protected FieldValueService $values,
    ) {}

    /**
     * 회원 여럿의 항목 값을 한 번에 읽어 둡니다.
     *
     * 이미 읽어 둔 회원과 항목은 다시 읽지 않는다.
     *
     * @param  iterable<int, User|null>  $users  회원 목록 (null 과 저장하지 않은 회원은 건너뛴다)
     * @param  Collection<int, FieldDefinition>  $definitions  읽을 항목
     * @return void 반환값 없음
     */
    public function preload(iterable $users, Collection $definitions): void
    {
        $definitions = $definitions->filter(fn ($definition) => $definition instanceof FieldDefinition && $definition->exists)->values();
        if ($definitions->isEmpty()) {
            return;
        }

        $keys = $definitions->map(fn (FieldDefinition $definition) => $definition->key)->all();
        $missing = new Collection;
        foreach ($users as $user) {
            if (! $user instanceof User || ! $user->exists || $missing->has($user->getKey())) {
                continue;
            }
            if (array_diff($keys, array_keys($this->memo[$user->getKey()] ?? [])) !== []) {
                $missing->put($user->getKey(), $user);
            }
        }
        if ($missing->isEmpty()) {
            return;
        }

        $loaded = $this->values->preload($missing->values(), FieldSet::CORE_USER, $definitions);
        foreach ($missing as $id => $user) {
            foreach ($definitions as $definition) {
                $value = $loaded[$id][$definition->key] ?? null;
                $this->memo[$id][$definition->key] = $this->values->isEmpty($definition, $value)
                    ? null
                    : $this->values->display($definition, $value);
            }
        }
    }

    /**
     * 회원의 항목 값(보기용 문자열)을 반환합니다.
     *
     * 읽어 둔 값이 없으면 그 회원 한 명만 읽는다.
     *
     * @param  User  $user  회원
     * @param  FieldDefinition  $definition  항목 정의
     * @return string|null 보기용 문자열 (값이 없거나 저장하지 않은 회원이면 null)
     */
    public function get(User $user, FieldDefinition $definition): ?string
    {
        if (! $user->exists || ! $definition->exists) {
            return null;
        }

        $id = $user->getKey();
        if (! array_key_exists($definition->key, $this->memo[$id] ?? [])) {
            $this->preload([$user], new Collection([$definition]));
        }

        return $this->memo[$id][$definition->key] ?? null;
    }

    /**
     * 읽어 둔 값을 버립니다 (회원의 항목 값이 바뀐 뒤와 테스트용).
     *
     * @param  int|null  $userId  회원 ID (null 이면 전부)
     * @return void 반환값 없음
     */
    public function forget(?int $userId = null): void
    {
        if ($userId === null) {
            $this->memo = [];

            return;
        }

        unset($this->memo[$userId]);
    }
}
