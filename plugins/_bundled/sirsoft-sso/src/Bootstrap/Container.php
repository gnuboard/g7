<?php

namespace Plugins\Sirsoft\Sso\Bootstrap;

use Plugins\Sirsoft\Sso\Bootstrap\Container\Core;
use Plugins\Sirsoft\Sso\Bootstrap\Container\Ldap;
use Plugins\Sirsoft\Sso\Bootstrap\Container\Policy;
use Plugins\Sirsoft\Sso\Bootstrap\Container\Provisioning;
use Plugins\Sirsoft\Sso\Bootstrap\Container\Saml;

/**
 * 컨테이너 바인딩(저장소 인터페이스, 플러그인 캐시가 필요한 서비스, 요청 단위 싱글턴)을 영역별 파일에서 모읍니다.
 *
 * `SsoServiceProvider` 가 부른다. 영역마다 `repositories()`, `cacheServices()`, `scoped()` 를 둔다.
 *
 * @since 1.0.0
 */
final class Container
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
     * 저장소 인터페이스 ↔ 구현체 바인딩을 합칩니다.
     *
     * @return array<class-string, class-string> 인터페이스 → 구현체
     */
    public static function repositories(): array
    {
        $bindings = [];
        foreach (self::AREAS as $area) {
            $bindings += $area::repositories();
        }

        return $bindings;
    }

    /**
     * 플러그인 캐시(CacheInterface)를 주입받을 서비스를 합칩니다.
     *
     * @return array<int, class-string> 서비스 클래스
     */
    public static function cacheServices(): array
    {
        return self::collect('cacheServices');
    }

    /**
     * 요청 단위 싱글턴(`scoped`)으로 둘 서비스를 합칩니다.
     *
     * @return array<int, class-string> 서비스 클래스
     */
    public static function scoped(): array
    {
        return self::collect('scoped');
    }

    /**
     * 영역마다 같은 이름의 목록을 중복 없이 합칩니다.
     *
     * @param  string  $method  영역 메서드 이름
     * @return array<int, class-string> 합친 목록
     */
    private static function collect(string $method): array
    {
        $items = [];
        foreach (self::AREAS as $area) {
            foreach ($area::$method() as $item) {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }
}
