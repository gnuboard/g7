<?php

namespace Plugins\Sirsoft\Sso\Bootstrap;

use Plugins\Sirsoft\Sso\Bootstrap\Commands\Core;
use Plugins\Sirsoft\Sso\Bootstrap\Commands\Ldap;
use Plugins\Sirsoft\Sso\Bootstrap\Commands\Policy;
use Plugins\Sirsoft\Sso\Bootstrap\Commands\Provisioning;
use Plugins\Sirsoft\Sso\Bootstrap\Commands\Saml;

/**
 * artisan 명령 목록을 영역별 파일에서 모읍니다 (`SsoServiceProvider` 가 콘솔에서 등록한다).
 *
 * @since 1.0.0
 */
final class Commands
{
    /** @var array<int, class-string> 영역 목록 */
    public const AREAS = [
        Core::class,
        Saml::class,
        Ldap::class,
        Provisioning::class,
        Policy::class,
    ];

    /**
     * 모든 영역의 명령을 중복 없이 합칩니다.
     *
     * @return array<int, class-string> 명령 클래스 목록
     */
    public static function all(): array
    {
        $commands = [];
        foreach (self::AREAS as $area) {
            foreach ($area::all() as $command) {
                $commands[] = $command;
            }
        }

        return array_values(array_unique($commands));
    }
}
