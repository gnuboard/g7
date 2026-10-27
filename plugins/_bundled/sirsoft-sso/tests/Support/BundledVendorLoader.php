<?php

namespace Plugins\Sirsoft\Sso\Tests\Support;

/**
 * 플러그인 자체 composer 의존성(firebase/php-jwt, onelogin/php-saml, robrichards/xmlseclibs)을 `_bundled` vendor 에서 읽는 시험 전용 로더.
 *
 * 코어 `tests/bootstrap.php` 는 확장 vendor 맵을 활성 디렉토리 먼저 모은다 — 개발 사이트에 플러그인이 설치돼 있으면 시험이 활성
 * 설치본(`plugins/sirsoft-sso/vendor`)의 패키지를 읽고, 설치·제거 도중에는 없는 파일을 include 해 500 이 난다. 이 로더를 맨 앞(prepend)에
 * 걸어 `_bundled` 가 이기게 한다. 루트 vendor 에 같은 접두가 있으면 건너뛴다(루트 패키지를 가리지 않는다).
 *
 * `vendor/autoload.php` 를 require 하지 않는다: Composer ClassLoader 가 하나 더 등록되면 Laravel 이 첫 로더 위치로 앱 루트를
 * 추정(`Application::inferBasePath`)해 플러그인 디렉터리를 앱 루트로 잘못 잡는다.
 *
 * @since 1.0.0
 */
final class BundledVendorLoader
{
    /** @var array<string, array<int, string>> 접두 => 디렉터리 */
    private static array $prefixes = [];

    /**
     * `_bundled` vendor 의 PSR-4 표를 읽어 로더를 맨 앞에 등록합니다 (한 번만).
     *
     * @param  string  $pluginRoot  `_bundled` 플러그인 루트
     * @return void 반환값 없음
     */
    public static function register(string $pluginRoot): void
    {
        $map = $pluginRoot.'/vendor/composer/autoload_psr4.php';
        if (self::$prefixes !== [] || ! is_file($map)) {
            return;
        }

        $rootMap = dirname($pluginRoot, 3).'/vendor/composer/autoload_psr4.php';
        $root = is_file($rootMap) ? require $rootMap : [];

        self::$prefixes = array_filter(
            require $map,
            static fn (string $prefix) => ! str_starts_with($prefix, 'Plugins\\') && ! isset($root[$prefix]),
            ARRAY_FILTER_USE_KEY,
        );

        spl_autoload_register([self::class, 'load'], true, true);
    }

    /**
     * 클래스를 `_bundled` vendor 에서 찾아 읽습니다.
     *
     * @param  string  $class  클래스 이름
     * @return void 반환값 없음
     */
    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $dirs) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }
            foreach ($dirs as $dir) {
                $file = $dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($file)) {
                    require_once $file;

                    return;
                }
            }
        }
    }

    /**
     * 등록한 접두 목록 (시험 단언용).
     *
     * @return array<int, string> 접두
     */
    public static function prefixes(): array
    {
        return array_keys(self::$prefixes);
    }
}
