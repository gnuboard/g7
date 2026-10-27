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

        foreach ($this->lockedFor($user, $surface) as $field) {
            $existing = $rules[$field] ?? [];
            $existing = is_string($existing) ? explode('|', $existing) : (array) $existing;
            $existing[] = $this->unchangedRule($user, $field);
            $rules[$field] = $existing;
        }

        return $rules;
    }

    /**
     * 현재 값과 같아야 하는 규칙을 만듭니다 (빈 문자열과 null 은 같은 값으로 본다).
     *
     * @param  User  $user  대상 회원
     * @param  string  $field  칸 이름
     * @return Closure 검증 규칙
     */
    private function unchangedRule(User $user, string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($user, $field): void {
            $current = $user->getAttribute($field);
            $normalize = fn (mixed $v) => $v === null || $v === '' ? null : (string) $v;

            if ($normalize($value) !== $normalize($current)) {
                $fail(__('user.field_locked'));
            }
        };
    }
}
