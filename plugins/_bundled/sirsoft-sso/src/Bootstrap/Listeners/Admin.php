<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Listeners;

use Plugins\Sirsoft\Sso\Listeners\Admin\ActivityLogListener;
use Plugins\Sirsoft\Sso\Listeners\Admin\IdpFailureMonitorListener;
use Plugins\Sirsoft\Sso\Listeners\Admin\MemberDetailSsoListener;
use Plugins\Sirsoft\Sso\Listeners\Admin\MemberListSsoListener;
use Plugins\Sirsoft\Sso\Listeners\Admin\NotificationDataListener;

/**
 * 훅 리스너 — 관리자 화면 주입과 알림, 활동 로그 영역.
 *
 * `Bootstrap\Listeners::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Admin
{
    /**
     * 이 영역의 훅 리스너 목록을 반환합니다.
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public static function all(): array
    {
        return [
            ActivityLogListener::class,
            NotificationDataListener::class,
            IdpFailureMonitorListener::class,
            MemberDetailSsoListener::class,
            MemberListSsoListener::class,
        ];
    }
}
