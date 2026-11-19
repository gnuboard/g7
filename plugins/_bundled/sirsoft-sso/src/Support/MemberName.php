<?php

namespace Plugins\Sirsoft\Sso\Support;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameResolver;
use App\Models\User;

/**
 * SSO 플러그인이 기록과 알림에 회원 이름을 실을 때 쓰는 한 곳.
 *
 * 회원 이름은 `users.name` 을 직접 읽지 않고 코어 표시 이름 계산기에게 묻는다.
 *
 * | 자리 | 영역 |
 * | --- | --- |
 * | 새로 남기는 활동 로그의 회원 이름 | 관리자 영역(`core:admin`). 활동 로그는 관리자 화면의 기록물이다 |
 * | 알림 본문의 사건 회원 이름(`{user_name}`, 받는 사람이 아닌 회원) | 알림 영역(`core:notification`) |
 *
 * 알림의 받는 사람 이름(`{name}`)은 여기서 계산하지 않는다. 자리표시 `{recipient_name}` 을 실으면 코어가 받는 사람과 채널마다 푼다.
 * 이미 저장된 활동 로그의 이름은 바꾸지 않는다.
 *
 * @since 1.0.0
 */
final class MemberName
{
    /**
     * 활동 로그에 남길 회원 이름을 반환합니다 (그 시점의 관리자 영역 표시 이름).
     *
     * @param  User|null  $user  회원
     * @return string 이름 (회원이 없거나 보일 값이 없으면 빈 문자열)
     */
    public static function forRecord(?User $user): string
    {
        return self::resolve($user, DisplayNameAreaRegistry::AREA_ADMIN);
    }

    /**
     * 알림 본문에 실을 사건 회원 이름을 반환합니다 (알림 영역 표시 이름).
     *
     * @param  User|null  $user  회원 (받는 사람이 아닌 회원)
     * @return string 이름 (회원이 없거나 보일 값이 없으면 빈 문자열)
     */
    public static function forNotification(?User $user): string
    {
        return self::resolve($user, DisplayNameAreaRegistry::AREA_NOTIFICATION);
    }

    /**
     * 영역의 표시 이름을 문자열로 반환합니다.
     *
     * @param  User|null  $user  회원
     * @param  string  $area  영역 키
     * @return string 이름 (없으면 빈 문자열)
     */
    private static function resolve(?User $user, string $area): string
    {
        if ($user === null) {
            return '';
        }

        return (string) (app(DisplayNameResolver::class)->resolve($user, $area) ?? '');
    }
}
