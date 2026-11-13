<?php

namespace App\DisplayName;

use App\DisplayName\Kinds\FieldValueKind;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 표시 이름 계산기 — 「이 회원을 이 자리에서 어떤 이름으로 보일지」를 정하는 한 곳.
 *
 * 순서:
 *
 * 1. 회원이 없으면(비회원) 문맥의 `guest_name` 을 그대로 쓴다. 없으면 null.
 * 2. 탈퇴한 회원은 종류와 무관하게 「탈퇴한 사용자」 문구.
 * 3. 영역의 종류를 정한다 — 인스턴스 설정(게시판마다 등) → 영역 설정 → 부모 영역 → … → `core:site`.
 *    방식이 「회원이 고른 값」이면 회원이 고른 종류(허용 목록 안일 때), 아니면 회원 기본 종류.
 * 4. 그 종류로 계산하고, 값이 없으면 종류의 대체 순서를 따라간다.
 * 5. 필터 `core.display_name.filter_resolved` 를 태운다.
 *
 * 응답에 이름을 실을 때는 `users.name` 을 직접 읽지 말고 이 계산기를 쓴다. 회원 관계를 컬럼 제한으로 읽을 때는
 * `User::displayColumns()` 를 쓰고, 여러 회원을 그릴 때는 `preload()` 또는 `resolveMany()` 로 추가 항목 값을 한 번에 읽는다.
 *
 * @since 7.2.0
 */
class DisplayNameResolver
{
    /**
     * @param  DisplayNameKindRegistry  $kinds  종류 등록소
     * @param  DisplayNameAreaRegistry  $areas  영역 등록소
     * @param  DisplayNameSettings  $settings  표시 이름 설정
     * @param  DisplayNameFieldValues  $fieldValues  표시 이름용 추가 항목 값 메모
     */
    public function __construct(
        protected DisplayNameKindRegistry $kinds,
        protected DisplayNameAreaRegistry $areas,
        protected DisplayNameSettings $settings,
        protected DisplayNameFieldValues $fieldValues,
    ) {}

    /**
     * 회원의 표시 이름을 반환합니다.
     *
     * @param  User|null  $user  회원 (비회원이면 null)
     * @param  string  $area  영역 키 (기본: 사이트 기본)
     * @param  array<string, mixed>  $context  호출 문맥 — `guest_name`(비회원 이름), 영역이 정한 인스턴스 키(예: `board`), `field_values`(미리보기용)
     * @return string|null 표시 이름 (보일 값이 없으면 null)
     */
    public function resolve(?User $user, string $area = DisplayNameAreaRegistry::AREA_SITE, array $context = []): ?string
    {
        if ($user === null) {
            $guest = $context['guest_name'] ?? null;
            $resolved = is_string($guest) && trim($guest) !== '' ? $guest : null;
            $kind = null;
        } elseif ($this->isWithdrawn($user)) {
            $resolved = __('user.withdrawn_user');
            $kind = null;
        } else {
            $kind = $this->effectiveKind($user, $area, $context);
            $resolved = $this->computeAs($user, $kind, ['area' => $area] + $context);
        }

        $filtered = HookManager::applyFilters('core.display_name.filter_resolved', $resolved, $user, $area, ['kind' => $kind] + $context);

        // 필터가 글자가 아닌 값을 돌려주면 계산한 값을 그대로 쓴다.
        return is_string($filtered) && $filtered !== '' ? $filtered : $resolved;
    }

    /**
     * 회원 여럿의 표시 이름을 한 번에 반환합니다.
     *
     * 추가 항목 종류가 쓰이는 영역이면 값을 쿼리 한 번으로 읽는다.
     *
     * @param  iterable<int, User|null>  $users  회원 목록
     * @param  string  $area  영역 키
     * @param  array<string, mixed>  $context  호출 문맥
     * @return array<int|string, string|null> 회원 ID => 표시 이름
     */
    public function resolveMany(iterable $users, string $area = DisplayNameAreaRegistry::AREA_SITE, array $context = []): array
    {
        $list = [];
        foreach ($users as $user) {
            if ($user instanceof User) {
                $list[$user->getKey()] = $user;
            }
        }

        $this->preload($list, $area, $context);

        $names = [];
        foreach ($list as $id => $user) {
            $names[$id] = $this->resolve($user, $area, $context);
        }

        return $names;
    }

    /**
     * 회원 여럿의 추가 항목 값을 미리 읽어 둡니다 (목록에서 N+1 방지).
     *
     * 영역을 주면 그 영역에서 쓰일 수 있는 추가 항목 종류만, 주지 않으면 등록된 추가 항목 종류 전부를 읽는다.
     * 추가 항목 종류가 쓰이지 않으면 쿼리하지 않는다.
     *
     * @param  iterable<int, User|null>  $users  회원 목록
     * @param  string|null  $area  영역 키 (null 이면 모든 영역 대비)
     * @param  array<string, mixed>  $context  호출 문맥
     * @return void 반환값 없음
     */
    public function preload(iterable $users, ?string $area = null, array $context = []): void
    {
        $candidates = $area === null ? array_keys($this->kinds->fieldKinds()) : $this->candidateKinds($area, $context);

        $definitions = new Collection;
        foreach ($candidates as $key) {
            foreach ($this->kinds->chain($key) as $kind) {
                if ($kind instanceof FieldValueKind) {
                    $definitions->put($kind->key(), $kind->definition());
                }
            }
        }

        if ($definitions->isNotEmpty()) {
            $this->fieldValues->preload($users, $definitions->values());
        }
    }

    /**
     * 이 회원이 이 영역에서 보이는 종류 키를 반환합니다.
     *
     * @param  User|null  $user  회원 (null 이면 회원 기본 종류로 판정)
     * @param  string  $area  영역 키
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string 종류 키
     */
    public function effectiveKind(?User $user, string $area = DisplayNameAreaRegistry::AREA_SITE, array $context = []): string
    {
        $rule = $this->areaRule($area, $context);

        return $rule['mode'] === DisplayNameArea::MODE_MEMBER ? $this->memberKind($user) : (string) $rule['kind'];
    }

    /**
     * 영역에 최종 적용되는 방식과 종류를 반환합니다 (상속을 다 따라간 결과).
     *
     * @param  string  $area  영역 키 (등록되지 않은 키면 사이트 기본)
     * @param  array<string, mixed>  $context  호출 문맥 (인스턴스 판정용)
     * @return array{mode: string, kind: string|null, source: string} 방식(fixed|member), 종류(fixed 일 때), 그 값을 정한 영역 키
     */
    public function areaRule(string $area = DisplayNameAreaRegistry::AREA_SITE, array $context = []): array
    {
        $lineage = $this->areas->lineage($area);

        // 인스턴스 설정은 요청한 영역 자신에게만 묻는다(게시판 설정이 부모 영역 설정보다 먼저다).
        $first = $lineage[0] ?? null;
        if ($first !== null && $first->key === $area) {
            $instance = $first->instanceSetting($context);
            $instance = $instance !== null ? $this->settings->normalize($instance) : null;
            if ($instance !== null && $instance['mode'] !== DisplayNameArea::MODE_INHERIT) {
                return [...$instance, 'source' => $first->key.':instance'];
            }
        }

        foreach ($lineage as $candidate) {
            $setting = $this->settings->areaSetting($candidate);
            if ($setting['mode'] !== DisplayNameArea::MODE_INHERIT) {
                return [...$setting, 'source' => $candidate->key];
            }
        }

        return ['mode' => DisplayNameArea::MODE_FIXED, 'kind' => DisplayNameSettings::FALLBACK_KIND, 'source' => DisplayNameAreaRegistry::AREA_SITE];
    }

    /**
     * 「회원이 고른 값」 방식에서 이 회원에게 적용되는 종류 키를 반환합니다.
     *
     * 회원이 고른 종류가 지금 고를 수 있는 종류에 있으면 그것, 아니면(고르지 않았거나 허용에서 빠졌으면) 회원 기본 종류.
     *
     * @param  User|null  $user  회원
     * @return string 종류 키
     */
    public function memberKind(?User $user): string
    {
        $chosen = $user?->getAttribute('display_name_kind');

        return is_string($chosen) && $chosen !== '' && in_array($chosen, $this->settings->selectableKinds(), true)
            ? $chosen
            : $this->settings->memberDefaultKind();
    }

    /**
     * 회원을 지정한 종류로 계산합니다 (값이 없으면 대체 순서를 따라간다).
     *
     * 영역 설정을 보지 않는다. 선택지 미리보기처럼 「이 종류를 고르면 어떻게 보이는지」를 구할 때 쓴다.
     *
     * @param  User  $user  회원 (저장하지 않은 회원도 된다)
     * @param  string  $kind  종류 키
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 표시 이름 (대체 순서 끝까지 값이 없으면 null)
     */
    public function computeAs(User $user, string $kind, array $context = []): ?string
    {
        $chain = $this->kinds->chain($kind);
        if ($chain === []) {
            // 지금 없는 종류(꺼진 확장, 지워진 항목)는 「이름」의 대체 순서로 계산한다.
            $chain = $this->kinds->chain(DisplayNameSettings::FALLBACK_KIND);
        }

        foreach ($chain as $candidate) {
            $value = $candidate->compute($user, $context);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * 영역에서 표시 이름을 계산할 때 필요한 users 컬럼을 반환합니다.
     *
     * `User::displayColumns()` 가 코어 종류 전부를 덮으므로 보통은 그쪽을 쓴다. 확장 종류가 다른 컬럼을 쓸 때 이 메서드로 더한다.
     *
     * @param  string  $area  영역 키
     * @param  array<string, mixed>  $context  호출 문맥
     * @return array<int, string> 컬럼명 목록 (`User::DISPLAY_COLUMNS` 포함)
     */
    public function columnsFor(string $area = DisplayNameAreaRegistry::AREA_SITE, array $context = []): array
    {
        $columns = User::DISPLAY_COLUMNS;
        foreach ($this->candidateKinds($area, $context) as $key) {
            foreach ($this->kinds->chain($key) as $kind) {
                $columns = [...$columns, ...$kind->columns()];
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * 영역에서 회원에 따라 쓰일 수 있는 종류 키를 반환합니다 (검색, 정렬, 선적재가 쓴다).
     *
     * @param  string  $area  영역 키
     * @param  array<string, mixed>  $context  호출 문맥
     * @return array<int, string> 종류 키 목록 (고정이면 하나, 회원이 고른 값이면 고를 수 있는 종류와 회원 기본 종류)
     */
    public function candidateKinds(string $area = DisplayNameAreaRegistry::AREA_SITE, array $context = []): array
    {
        $rule = $this->areaRule($area, $context);
        if ($rule['mode'] !== DisplayNameArea::MODE_MEMBER) {
            return [(string) $rule['kind']];
        }

        return array_values(array_unique([...$this->settings->selectableKinds(), $this->settings->memberDefaultKind()]));
    }

    /**
     * 읽어 둔 추가 항목 값을 버립니다 (회원의 항목 값이 바뀐 뒤).
     *
     * @param  User|null  $user  회원 (null 이면 전부)
     * @return void 반환값 없음
     */
    public function forget(?User $user = null): void
    {
        $this->fieldValues->forget($user?->getKey());
    }

    /**
     * 탈퇴한 회원인지 반환합니다.
     *
     * @param  User  $user  회원
     * @return bool 탈퇴했으면 true
     */
    protected function isWithdrawn(User $user): bool
    {
        $status = $user->getAttribute('status');
        $status = $status instanceof \BackedEnum ? $status->value : $status;

        return $status === UserStatus::Withdrawn->value;
    }
}
