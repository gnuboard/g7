<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Listeners;

use Plugins\Sirsoft\Sso\Listeners\Provisioning\CleanProvisioningDataOnUserLeave;
use Plugins\Sirsoft\Sso\Listeners\Provisioning\LockedFieldsListener;
use Plugins\Sirsoft\Sso\Listeners\Provisioning\PrefillConsentFieldsListener;
use Plugins\Sirsoft\Sso\Listeners\Provisioning\SsoSessionListener;
use Plugins\Sirsoft\Sso\Listeners\Provisioning\SyncOnAccountResolvedListener;

/**
 * 훅 리스너 — 프로비저닝(SCIM, 주기 점검, 퇴사), 역할, 속성, 세션 영역.
 *
 * `Bootstrap\Listeners::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Provisioning
{
    /**
     * 이 영역의 훅 리스너 목록을 반환합니다.
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public static function all(): array
    {
        return [
            SsoSessionListener::class,
            SyncOnAccountResolvedListener::class,
            LockedFieldsListener::class,
            PrefillConsentFieldsListener::class,
            CleanProvisioningDataOnUserLeave::class,
        ];
    }
}
