<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Listeners;

use Plugins\Sirsoft\Sso\Listeners\Policy\AutoRedirectListener;
use Plugins\Sirsoft\Sso\Listeners\Policy\DiscoveryListener;
use Plugins\Sirsoft\Sso\Listeners\Policy\EmergencyLoginListener;
use Plugins\Sirsoft\Sso\Listeners\Policy\EnforcePasswordPolicyListener;
use Plugins\Sirsoft\Sso\Listeners\Policy\PasswordResetBlockedNotifyListener;
use Plugins\Sirsoft\Sso\Listeners\Policy\SelfRegistrationListener;
use Plugins\Sirsoft\Sso\Listeners\Policy\TwoFactorContextListener;

/**
 * 훅 리스너 — 정책(강제, 진입, 비상 계정) 영역.
 *
 * `Bootstrap\Listeners::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Policy
{
    /**
     * 이 영역의 훅 리스너 목록을 반환합니다.
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public static function all(): array
    {
        return [
            EnforcePasswordPolicyListener::class,
            DiscoveryListener::class,
            AutoRedirectListener::class,
            SelfRegistrationListener::class,
            TwoFactorContextListener::class,
            EmergencyLoginListener::class,
            PasswordResetBlockedNotifyListener::class,
        ];
    }
}
