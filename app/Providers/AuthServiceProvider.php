<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * guest role 캐시
     */
    protected static ?Role $guestRoleCache = null;

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // 회원 권한 체크 (비회원은 Gate::before가 호출되지 않으므로 checkPermission() 사용)
        // admin 역할도 시더에서 모든 리프 권한을 명시 할당받으므로 별도 바이패스 불필요
        Gate::before(function ($user, $ability) {
            if ($user) {
                // 회원: 기존 hasPermission() 메서드 활용
                return $user->hasPermission($ability) ? true : null;
            }

            // 비회원: Laravel Gate는 guest에 대해 before 콜백을 호출하지 않으므로
            // 여기에 도달하지 않음. 대신 checkPermission() 정적 메서드 사용
            return null;
        });
    }

    /**
     * 회원/비회원 통합 권한 체크 메서드
     *
     * Laravel Gate::before가 guest에게 호출되지 않는 문제를 해결하기 위해
     * Gate와 직접 guest 권한 체크를 조합합니다.
     *
     * @param  string  $ability  권한 식별자
     * @param  User|null  $user  사용자 (null이면 현재 인증된 사용자 또는 guest)
     * @return bool 권한 허용 여부
     */
    public static function checkPermission(string $ability, ?User $user = null): bool
    {
        // 사용자 결정: 전달된 사용자 > 현재 인증 사용자 > guest
        $user = $user ?? Auth::user();

        if ($user) {
            // 인증된 사용자: Gate 사용 (before 콜백 포함)
            return Gate::forUser($user)->allows($ability);
        }

        // 비회원: 직접 guest role 권한 체크
        return self::checkGuestPermission($ability) === true;
    }

    /**
     * 여러 권한을 한 번에 체크합니다. (AND 조건)
     *
     * @param  array<string>  $abilities  권한 식별자 배열
     * @param  User|null  $user  사용자 (null이면 현재 인증된 사용자 또는 guest)
     * @return bool 모든 권한이 허용되면 true
     */
    public static function checkPermissions(array $abilities, ?User $user = null): bool
    {
        foreach ($abilities as $ability) {
            if (! self::checkPermission($ability, $user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 구조화된 권한 로직(OR/AND)을 지원하는 권한 체크 메서드
     *
     * 지원 형식:
     * - flat array: ["a", "b"] → AND (기존 호환)
     * - OR 객체: {"or": ["a", "b"]} → a 또는 b
     * - AND 객체: {"and": ["a", "b"]} → a 그리고 b
     * - 중첩: {"and": ["a", {"or": ["b", "c"]}]} → a + (b 또는 c)
     *
     * @param  array|object  $permissions  권한 구조 (flat array 또는 구조화 객체)
     * @param  User|null  $user  사용자 (null이면 현재 인증된 사용자 또는 guest)
     * @param  int  $depth  현재 재귀 깊이 (내부용)
     * @return bool 권한 충족 여부
     */
    public static function checkPermissionsWithLogic(array|object $permissions, ?User $user = null, int $depth = 1): bool
    {
        // 최대 중첩 깊이 초과 시 거부
        if ($depth > 3) {
            Log::warning('권한 구조 최대 중첩 깊이(3) 초과', [
                'permissions' => $permissions,
                'depth' => $depth,
            ]);

            return false;
        }

        // 배열 또는 객체를 배열로 변환
        $permissions = (array) $permissions;

        // 빈 배열 → 권한 불필요
        if (empty($permissions)) {
            return true;
        }

        // flat array (기존 호환): 모든 문자열 → AND 로직
        if (array_is_list($permissions)) {
            return self::evaluateList($permissions, $user, $depth);
        }

        // 구조화 객체: {"or": [...]} 또는 {"and": [...]}
        $keys = array_keys($permissions);

        if (count($keys) !== 1 || ! in_array($keys[0], ['or', 'and'])) {
            Log::warning('잘못된 권한 구조 연산자', [
                'keys' => $keys,
                'permissions' => $permissions,
            ]);

            return false;
        }

        $operator = $keys[0];
        $items = $permissions[$operator];

        if (! is_array($items) || ! array_is_list($items)) {
            return false;
        }

        if ($operator === 'or') {
            return self::evaluateOr($items, $user, $depth);
        }

        return self::evaluateAnd($items, $user, $depth);
    }

    /**
     * 리스트 내 항목을 AND 로직으로 평가합니다.
     *
     * @param  array  $items  권한 항목 배열 (문자열 또는 중첩 구조)
     * @param  User|null  $user  사용자
     * @param  int  $depth  현재 재귀 깊이
     * @return bool 모든 항목이 충족되면 true
     */
    private static function evaluateList(array $items, ?User $user, int $depth): bool
    {
        foreach ($items as $item) {
            if (! self::evaluateItem($item, $user, $depth)) {
                return false;
            }
        }

        return true;
    }

    /**
     * OR 로직으로 평가합니다.
     *
     * @param  array  $items  권한 항목 배열
     * @param  User|null  $user  사용자
     * @param  int  $depth  현재 재귀 깊이
     * @return bool 하나라도 충족되면 true
     */
    private static function evaluateOr(array $items, ?User $user, int $depth): bool
    {
        foreach ($items as $item) {
            if (self::evaluateItem($item, $user, $depth)) {
                return true;
            }
        }

        return false;
    }

    /**
     * AND 로직으로 평가합니다.
     *
     * @param  array  $items  권한 항목 배열
     * @param  User|null  $user  사용자
     * @param  int  $depth  현재 재귀 깊이
     * @return bool 모든 항목이 충족되면 true
     */
    private static function evaluateAnd(array $items, ?User $user, int $depth): bool
    {
        foreach ($items as $item) {
            if (! self::evaluateItem($item, $user, $depth)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 단일 항목을 평가합니다.
     *
     * 문자열이면 직접 권한 체크, 배열이면 재귀적으로 구조 평가.
     *
     * @param  mixed  $item  권한 항목 (문자열 또는 중첩 구조)
     * @param  User|null  $user  사용자
     * @param  int  $depth  현재 재귀 깊이
     * @return bool 권한 충족 여부
     */
    private static function evaluateItem(mixed $item, ?User $user, int $depth): bool
    {
        if (is_string($item)) {
            return self::checkPermission($item, $user);
        }

        if (is_array($item)) {
            return self::checkPermissionsWithLogic($item, $user, $depth + 1);
        }

        return false;
    }

    /**
     * 비회원(guest) role의 권한을 확인합니다.
     *
     * @param  string  $ability  권한 식별자
     * @return bool|null 권한 있으면 true, 없으면 null (다음 Gate로 위임)
     */
    public static function checkGuestPermission(string $ability): ?bool
    {
        $guestRole = self::getGuestRole();

        if (! $guestRole) {
            return null; // guest role 없으면 다음 Gate 정의로 위임
        }

        return $guestRole->permissions()
            ->where('identifier', $ability)
            ->exists() ? true : null;
    }

    /**
     * guest role을 캐싱하여 조회합니다.
     */
    protected static function getGuestRole(): ?Role
    {
        if (self::$guestRoleCache === null) {
            self::$guestRoleCache = Role::where('identifier', 'guest')
                ->with('permissions')
                ->first();
        }

        return self::$guestRoleCache;
    }

    /**
     * guest role 캐시를 초기화합니다. (테스트용)
     */
    public static function clearGuestRoleCache(): void
    {
        self::$guestRoleCache = null;
    }

    /**
     * 테스트용: 비회원 권한 체크 결과를 직접 확인합니다.
     *
     * @param  string  $ability  권한 식별자
     * @return array{result: bool|null, guestRole: Role|null, hasPermission: bool}
     */
    public static function debugCheckGuestPermission(string $ability): array
    {
        $guestRole = self::getGuestRole();
        $hasPermission = false;

        if ($guestRole) {
            $hasPermission = $guestRole->permissions()
                ->where('identifier', $ability)
                ->exists();
        }

        return [
            'result' => self::checkGuestPermission($ability),
            'guestRole' => $guestRole,
            'hasPermission' => $hasPermission,
            'cachedRoleId' => self::$guestRoleCache?->id,
        ];
    }
}
