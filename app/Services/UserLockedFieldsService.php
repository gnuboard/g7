<?php

namespace App\Services;

use App\Extension\HookManager;
use App\Models\User;
use Closure;

/**
 * 회원 칸 수정 잠금 — 외부(회사 IdP 등)가 관리하는 칸을 화면 수정에서 막습니다.
 *
 * 확장이 필터 `core.user.locked_fields(array $fields, User $user, string $surface)` 로 잠글 칸 이름을 돌려준다. 허용 목록 안의
 * 이름만 남긴다. 잠긴 칸은 요청 검증에서 「현재 값과 같아야 함」이고(요청에 없으면 통과), 서비스를 직접 부르는 확장의 동기화는
 * 요청을 거치지 않으므로 막히지 않는다(의도).
 *
 * @since 7.2.0
 */
class UserLockedFieldsService
{
    /** 잠글 수 있는 칸 */
    public const LOCKABLE = ['name', 'nickname', 'mobile', 'phone', 'language', 'email'];

    /** 마이페이지 표면 */
    public const SURFACE_PROFILE = 'profile';

    /** 관리자 화면 표면 */
    public const SURFACE_ADMIN = 'admin';

    /** 잠근 쪽이 사유를 주지 않았을 때의 일반 사유 */
    public const REASON_DEFAULT = 'locked';

    /** 본인인증 값으로 채워져 잠긴 칸의 사유 */
    public const REASON_IDENTITY = 'identity';

    /**
     * 그 회원·표면의 잠긴 칸을 돌려줍니다.
     *
     * @param  User  $user  대상 회원
     * @param  string  $surface  표면 (`profile` | `admin`)
     * @return array<int, string> 잠긴 칸 이름 (허용 목록 순서)
     */
    public function lockedFor(User $user, string $surface): array
    {
        $fields = HookManager::applyFilters('core.user.locked_fields', [], $user, $surface);

        if (! is_array($fields)) {
            return [];
        }

        $wanted = array_flip(array_filter($fields, 'is_string'));

        return array_values(array_filter(self::LOCKABLE, fn (string $field) => isset($wanted[$field])));
    }

    /**
     * 그 회원·표면의 잠긴 칸마다 잠근 사유를 돌려줍니다.
     *
     * 화면이 사유별 안내 문구를 고르는 데 쓴다. 확장이 필터 `core.user.locked_field_reasons(array $reasons, User $user, string $surface)`
     * 로 `칸 이름 => 사유` 를 준다(예: 회사 계정 연동은 `sso`, 본인인증은 `identity`). 잠근 쪽이 사유를 주지 않은 칸은
     * 일반 사유 `locked` 로 본다. 잠기지 않은 칸의 사유는 버린다.
     *
     * @param  User  $user  대상 회원
     * @param  string  $surface  표면 (`profile` | `admin`)
     * @return array<string, string> 잠긴 칸 이름 => 사유
     */
    public function reasonsFor(User $user, string $surface): array
    {
        $locked = $this->lockedFor($user, $surface);
        if ($locked === []) {
            return [];
        }

        $given = HookManager::applyFilters('core.user.locked_field_reasons', [], $user, $surface);
        $given = is_array($given) ? $given : [];

        $reasons = [];
        foreach ($locked as $field) {
            $reason = $given[$field] ?? null;
            $reasons[$field] = is_string($reason) && $reason !== '' ? $reason : self::REASON_DEFAULT;
        }

        return $reasons;
    }

    /**
     * 잠긴 칸에 「현재 값과 같아야 함」 규칙을 덧붙인 검증 규칙을 돌려줍니다.
     *
     * @param  array<string, mixed>  $rules  기존 규칙
     * @param  User|null  $user  대상 회원 (없으면 그대로)
     * @param  string  $surface  표면
     * @return array<string, mixed> 규칙
     */
    public function applyRules(array $rules, ?User $user, string $surface): array
    {
        if ($user === null) {
            return $rules;
        }

        foreach ($this->reasonsFor($user, $surface) as $field => $reason) {
            $existing = $rules[$field] ?? [];
            $existing = is_string($existing) ? explode('|', $existing) : (array) $existing;
            $existing[] = $this->unchangedRule($user, $field, $reason);
            $rules[$field] = $existing;
        }

        return $rules;
    }

    /**
     * 현재 값과 같아야 하는 규칙을 만듭니다 (빈 문자열과 null 은 같은 값으로 본다).
     *
     * @param  User  $user  대상 회원
     * @param  string  $field  칸 이름
     * @param  string  $reason  잠근 사유 (본인인증으로 잠긴 칸은 그 사유의 문구로 안내한다)
     * @return Closure 검증 규칙
     */
    private function unchangedRule(User $user, string $field, string $reason = self::REASON_DEFAULT): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($user, $field, $reason): void {
            $current = $user->getAttribute($field);
            $normalize = fn (mixed $v) => $v === null || $v === '' ? null : (string) $v;

            if ($normalize($value) !== $normalize($current)) {
                $fail($reason === self::REASON_IDENTITY ? __('fields.validation.locked_identity') : __('user.field_locked'));
            }
        };
    }
}
