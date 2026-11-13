<?php

namespace App\DisplayName;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\PermissionType;
use App\Models\User;

/**
 * 회원이 표시 이름 종류를 직접 고를 수 있는지 판정하는 한 곳.
 *
 * 고를 수 있으려면 두 가지가 모두 맞아야 한다.
 *
 * 1. 역할 권한 `core.profile.display_name.choose` 가 있다. 가입 화면(비로그인)은 가입하면 받게 될 `user` 역할이
 *    그 권한을 가졌는지로 판정한다.
 * 2. 운영자가 정한 「회원이 고를 수 있는 종류」가 둘 이상이다(하나뿐이면 고를 것이 없다).
 *
 * 요청 규칙, 항목 API 의 `display_name.can_choose`, 회원 응답의 `can_choose_display_name`, 저장 서비스가 같은 판정을 쓴다.
 *
 * @since 7.2.0
 */
class DisplayNameChoicePolicy
{
    /** 표시 이름 종류를 직접 고르는 권한 */
    public const PERMISSION = 'core.profile.display_name.choose';

    /** 가입한 회원이 받는 역할 */
    public const SIGNUP_ROLE = 'user';

    /** 거절 사유: 권한이 없다 */
    public const REASON_NOT_PERMITTED = 'not_permitted';

    /** 거절 사유: 고를 수 있는 종류가 아니다 */
    public const REASON_NOT_SELECTABLE = 'not_selectable';

    /**
     * @param  DisplayNameSettings  $settings  표시 이름 설정
     * @param  RoleRepositoryInterface  $roles  역할 저장소
     */
    public function __construct(
        protected DisplayNameSettings $settings,
        protected RoleRepositoryInterface $roles,
    ) {}

    /**
     * 회원이 고를 수 있는 종류 키를 반환합니다.
     *
     * @return array<int, string> 종류 키 목록
     */
    public function selectableKinds(): array
    {
        return $this->settings->selectableKinds();
    }

    /**
     * 가입 화면에서 종류를 고를 권한이 있는지 반환합니다 (가입하면 받는 역할 기준).
     *
     * @return bool 권한이 있으면 true
     */
    public function permittedForSignup(): bool
    {
        return $this->roles->activeRoleHasPermission(self::SIGNUP_ROLE, self::PERMISSION);
    }

    /**
     * 회원에게 종류를 고를 권한이 있는지 반환합니다.
     *
     * @param  User  $user  회원
     * @return bool 권한이 있으면 true
     */
    public function permittedFor(User $user): bool
    {
        return $user->hasPermission(self::PERMISSION, PermissionType::User);
    }

    /**
     * 가입 화면에 종류 선택 칸을 보일지 반환합니다.
     *
     * @return bool 권한이 있고 고를 종류가 둘 이상이면 true
     */
    public function allowedForSignup(): bool
    {
        return count($this->selectableKinds()) > 1 && $this->permittedForSignup();
    }

    /**
     * 회원에게 종류 선택 칸을 보일지 반환합니다.
     *
     * @param  User  $user  회원
     * @return bool 권한이 있고 고를 종류가 둘 이상이면 true
     */
    public function allowedFor(User $user): bool
    {
        return count($this->selectableKinds()) > 1 && $this->permittedFor($user);
    }

    /**
     * 종류 선택을 받아들일 수 있는지 판정합니다.
     *
     * @param  User|null  $user  회원 (가입이면 null)
     * @param  string  $kind  고른 종류 키
     * @return string|null 거절 사유(REASON_*) 또는 받아들이면 null
     */
    public function rejection(?User $user, string $kind): ?string
    {
        $permitted = $user !== null ? $this->permittedFor($user) : $this->permittedForSignup();
        if (! $permitted) {
            return self::REASON_NOT_PERMITTED;
        }

        return in_array($kind, $this->selectableKinds(), true) ? null : self::REASON_NOT_SELECTABLE;
    }
}
