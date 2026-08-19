<?php

namespace App\Support;

use App\Exceptions\CannotModifySuperAdminException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * 사용자 등급 상한(rank ceiling) 가드 (SSoT)
 *
 * 슈퍼 관리자 보호는 그동안 삭제/탈퇴 경로에만 있었고 수정·상태변경·권한부여
 * 경로에는 없어 비대칭이었습니다(KVE-2026-1919). 이 가드는 "비-슈퍼관리자
 * 액터는 슈퍼 관리자를 수정할 수 없다"는 규칙을 단일 지점에서 강제해 모든
 * 쓰기 경로가 동일한 상한을 공유하도록 합니다.
 *
 * 인증된 액터가 없는 경우(Artisan, 내부 시더/호출)는 신뢰 경로로 간주해
 * 가드를 적용하지 않습니다. HTTP 관리 경로는 인증·권한 미들웨어를 통과하므로
 * 이 지점에서는 항상 액터가 존재합니다.
 */
class UserGradeGuard
{
    /**
     * 액터가 대상 사용자를 수정할 수 있는지 판정합니다.
     *
     * @param  User  $target  수정 대상 사용자
     * @param  User|null  $actor  행위자(미지정 시 현재 인증 사용자)
     * @return bool 수정 가능 여부
     */
    public static function mayModify(User $target, ?User $actor = null): bool
    {
        $actor ??= Auth::user();

        // 인증 액터 부재 = 내부/Artisan 신뢰 경로 → 가드 미적용
        if (! $actor instanceof User) {
            return true;
        }

        // 대상이 슈퍼 관리자가 아니면 등급 상한과 무관
        if (! $target->isSuperAdmin()) {
            return true;
        }

        // 슈퍼 관리자 대상은 슈퍼 관리자 액터만 수정 가능
        return $actor->isSuperAdmin();
    }

    /**
     * 액터가 대상을 수정할 수 없으면 예외를 던집니다.
     *
     * @param  User  $target  수정 대상 사용자
     * @param  User|null  $actor  행위자(미지정 시 현재 인증 사용자)
     *
     * @throws CannotModifySuperAdminException 상한 위반 시
     */
    public static function assertActorMayModify(User $target, ?User $actor = null): void
    {
        if (! self::mayModify($target, $actor)) {
            throw new CannotModifySuperAdminException;
        }
    }

    /**
     * 액터가 수정 가능한 대상만 남긴 배열을 반환합니다(일괄 작업용).
     *
     * @param  iterable<User>  $targets  대상 사용자 목록
     * @param  User|null  $actor  행위자(미지정 시 현재 인증 사용자)
     * @return array<User> 수정 가능한 대상 목록
     */
    public static function filterModifiable(iterable $targets, ?User $actor = null): array
    {
        $actor ??= Auth::user();

        $allowed = [];
        foreach ($targets as $target) {
            if (self::mayModify($target, $actor)) {
                $allowed[] = $target;
            }
        }

        return $allowed;
    }
}
