<?php

namespace Tests\Unit\Providers;

use App\Providers\CoreServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * 진입점(artisan·index.php)을 거치지 않은 부팅의 확장 오토로드 폴백은 ExtensionManager 등록 함수에 위임한다 (#159).
 *
 * 폴백 분기가 PSR-4·classmap·files 를 직접 등록하던 사본은 `vendor_autoloads` 를 빠뜨려, 그 부팅에서는 확장이 쓰는
 * 외부 패키지를 찾지 못했다. 한 함수로 위임하면 vendor 로드와 중복 선언 가드까지 같은 경로를 탄다.
 */
class CoreServiceProviderFallbackDelegatesTest extends TestCase
{
    /**
     * 폴백 분기는 진입점 등록 확인 뒤 ExtensionManager::registerExtensionAutoload 로 위임하고 직접 등록하지 않는다.
     *
     * @scenario probe_mode=php, loader=core
     *
     * @effects fallback_boot_loads_extension_vendor
     *
     * @return void 반환값 없음
     */
    public function test_fallback_branch_delegates_to_extension_manager(): void
    {
        $ref = new \ReflectionMethod(CoreServiceProvider::class, 'registerExtensionAutoload');
        $lines = file($ref->getFileName());
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        $guardPos = strpos($body, "defined('G7_EXTENSION_AUTOLOAD_REGISTERED')");
        $delegatePos = strpos($body, 'ExtensionManager::registerExtensionAutoload(');

        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($delegatePos, '폴백 분기가 ExtensionManager::registerExtensionAutoload 로 위임하지 않습니다 — vendor_autoloads 가 빠집니다.');
        $this->assertGreaterThan($guardPos, $delegatePos);
        $this->assertStringNotContainsString('->addPsr4(', $body, '폴백 분기에 직접 등록 사본이 남았습니다');
        $this->assertStringNotContainsString('->addClassMap(', $body, '폴백 분기에 직접 등록 사본이 남았습니다');
    }
}
