<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Lifecycle;

use Composer\Autoload\ClassLoader;
use Firebase\JWT\JWT;
use OneLogin\Saml2\Constants;
use Plugins\Sirsoft\Sso\Identity\SsoReauthIdentityProvider;
use Plugins\Sirsoft\Sso\Plugin;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\BundledVendorLoader;
use RobRichards\XMLSecLibs\XMLSecurityDSig;

/**
 * 시험은 `_bundled` 코드를 시험한다 — 개발 사이트에 플러그인이 설치돼 있어도 플러그인 클래스와 플러그인 vendor 를 활성 설치본이 아니라
 * `_bundled` 에서 읽는지.
 *
 * @since 1.0.0
 */
class BundledAutoloadTest extends PluginTestCase
{
    /**
     * `_bundled` vendor 로더가 모든 Composer 로더보다 앞에 등록됐는지 확인한다 — 코어 시험 부트스트랩이 활성 디렉토리 vendor 를 먼저
     * 모으므로, 설치본이 있으면 이 순서가 아니면 활성 설치본 패키지를 읽는다.
     *
     * @scenario loader=bundled_vendor, order=before_composer_loaders
     *
     * @effects bundled_vendor_wins
     *
     * @return void 반환값 없음
     */
    public function test_bundled_vendor_loader_runs_before_composer_loaders(): void
    {
        $this->assertEqualsCanonicalizing(['OneLogin\\', 'Firebase\\JWT\\', 'RobRichards\\XMLSecLibs\\'], BundledVendorLoader::prefixes());

        $mine = null;
        $firstComposer = null;
        foreach (array_values(spl_autoload_functions()) as $index => $function) {
            if ($function === [BundledVendorLoader::class, 'load']) {
                $mine ??= $index;
            }
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                $firstComposer ??= $index;
            }
        }

        $this->assertNotNull($mine, '_bundled vendor 로더가 등록돼 있다');
        $this->assertNotNull($firstComposer);
        $this->assertLessThan($firstComposer, $mine, '_bundled vendor 로더가 Composer 로더보다 앞이다');
    }

    /**
     * 플러그인 클래스와 플러그인 vendor 클래스의 파일이 `_bundled` 아래인지 확인한다.
     *
     * @scenario class=plugin_entry|plugin_service|vendor_saml|vendor_jwt|vendor_xmlsec
     *
     * @effects classes_loaded_from_bundled
     *
     * @return void 반환값 없음
     */
    public function test_classes_load_from_bundled(): void
    {
        $bundled = str_replace('\\', '/', realpath(dirname(__DIR__, 3)));

        foreach ([Plugin::class, SsoReauthIdentityProvider::class, Constants::class, JWT::class, XMLSecurityDSig::class] as $class) {
            $file = str_replace('\\', '/', (string) (new \ReflectionClass($class))->getFileName());
            $this->assertStringStartsWith($bundled.'/', $file, $class.' 는 _bundled 에서 읽는다');
        }
    }
}
