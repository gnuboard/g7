<?php

namespace Tests\Unit\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * 확장 설치 흐름은 진입 파일을 로드하기 전에 그 확장의 PSR-4 매핑을 등록한다.
 *
 * 설치 시점에는 autoload-extensions.php 가 아직 갱신되지 않았다. 진입 파일(plugin.php / module.php)의
 * getConfigValues()/getSettingsSchema() 가 자기 src/ 클래스를 호출하면 그 클래스가 해석되지 않아
 * "Class not found" 로 설치가 중단된다. 종전에는 시더 실행 직전에만 등록해, 그보다 앞선 검증·설정
 * 초기화 단계가 사각이었다 (7.0.10 신규 설치에서 gdpr 플러그인으로 실제 발생. 업그레이드 경로는
 * 기설치본의 매핑이 이미 있어 재현되지 않는다).
 */
class ExtensionInstallAutoloadOrderTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string, string, string, string}>
     */
    public static function installFlows(): array
    {
        return [
            'plugin' => [PluginManager::class, 'installPlugin', 'copyFromPendingOrBundled($pluginName', "registerExtensionAutoloadPaths('plugins', \$pluginName)", 'reloadPlugin($pluginName)'],
            'module' => [ModuleManager::class, 'installModule', 'copyFromPendingOrBundled($moduleName', "registerExtensionAutoloadPaths('modules', \$moduleName)", 'reloadModule($moduleName)'],
        ];
    }

    /**
     * @dataProvider installFlows
     */
    public function test_autoload_paths_are_registered_between_copy_and_entry_file_load(
        string $class,
        string $method,
        string $copyCall,
        string $registerCall,
        string $reloadCall,
    ): void {
        $ref = new \ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        $copyPos = strpos($body, $copyCall);
        $registerPos = strpos($body, $registerCall);
        $reloadPos = strpos($body, $reloadCall);

        $this->assertNotFalse($copyPos, "{$class}::{$method} 에 복사 호출이 없습니다");
        $this->assertNotFalse($registerPos, "{$class}::{$method} 가 진입 파일 로드 전에 PSR-4 매핑을 등록하지 않습니다");
        $this->assertNotFalse($reloadPos, "{$class}::{$method} 에 재로드 호출이 없습니다");

        $this->assertGreaterThan($copyPos, $registerPos, '오토로드 등록은 활성 디렉토리 복사 뒤에 와야 합니다 (그 전에는 composer.json 이 없다)');
        $this->assertLessThan($reloadPos, $registerPos, '오토로드 등록은 진입 파일 로드(reload) 앞에 와야 합니다');
    }
}
