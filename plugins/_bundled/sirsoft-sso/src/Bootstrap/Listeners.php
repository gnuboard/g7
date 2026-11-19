<?php

namespace Plugins\Sirsoft\Sso\Bootstrap;

use Plugins\Sirsoft\Sso\Bootstrap\Listeners\Admin;
use Plugins\Sirsoft\Sso\Bootstrap\Listeners\CoreFlow;
use Plugins\Sirsoft\Sso\Bootstrap\Listeners\Ldap;
use Plugins\Sirsoft\Sso\Bootstrap\Listeners\Oidc;
use Plugins\Sirsoft\Sso\Bootstrap\Listeners\Policy;
use Plugins\Sirsoft\Sso\Bootstrap\Listeners\Provisioning;
use Plugins\Sirsoft\Sso\Bootstrap\Listeners\Saml;

/**
 * 훅 리스너 목록을 영역별 파일에서 모읍니다 (`Plugin::getHookListeners()` 가 부른다).
 *
 * 영역마다 파일을 따로 두는 이유: 영역(흐름, 프로토콜, 프로비저닝, 정책, 관리자 화면)을 서로 다른 작업자가 맡아도 같은 파일을
 * 고치지 않게 한다. 영역을 더할 때는 `AREAS` 에 클래스 한 줄만 더한다.
 *
 * @since 1.0.0
 */
final class Listeners
{
    /** @var array<int, class-string> 영역 목록 (등록 순서) */
    public const AREAS = [
        CoreFlow::class,
        Oidc::class,
        Saml::class,
        Ldap::class,
        Provisioning::class,
        Policy::class,
        Admin::class,
    ];

    /**
     * 모든 영역의 리스너를 중복 없이 합칩니다.
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public static function all(): array
    {
        $listeners = [];
        foreach (self::AREAS as $area) {
            foreach ($area::all() as $listener) {
                $listeners[] = $listener;
            }
        }

        return array_values(array_unique($listeners));
    }
}
