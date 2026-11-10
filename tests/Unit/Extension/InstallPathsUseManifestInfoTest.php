<?php

namespace Tests\Unit\Extension;

use App\Http\Controllers\Concerns\OrchestratesCascadeInstall;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use App\Services\ModuleService;
use App\Services\PluginService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 설치·의존 판정 경로는 진입 파일을 실행하는 상세 조회(getPluginInfo·getModuleInfo)를 쓰지 않는다 (#159).
 *
 * 미설치 확장의 상세 조회는 `_bundled` 진입 파일을 PHP 로 실행한다. 같은 프로세스(명령줄 설치·웹 ZIP/GitHub 설치)에서
 * 이어서 설치하면, 진입 클래스가 상속하는 자기 소스 클래스의 조회 실패가 Composer 음성 캐시로 남아 설치가
 * 「Class not found」 로 죽고, 진입 파일이 자체 vendor 를 부르면 「Cannot declare class ComposerAutoloaderInit…」 로 죽었다.
 * 설치 판정은 manifest 만 읽는 `getPluginManifestInfo()`·`getModuleManifestInfo()` 를 쓴다.
 */
class InstallPathsUseManifestInfoTest extends TestCase
{
    /**
     * 클래스 전체를 검사할 설치 판정 경로.
     *
     * @return array<string, array{class-string}> 클래스
     */
    public static function installDecisionClasses(): array
    {
        return [
            'orchestrator' => [CascadeInstallOrchestrator::class],
            'preview builder' => [ExtensionInstallPreviewBuilder::class],
            'web cascade concern' => [OrchestratesCascadeInstall::class],
        ];
    }

    /**
     * 설치 판정 클래스에는 진입 파일을 실행하는 상세 조회 호출이 없다.
     *
     * @scenario probe_mode=manifest, loader=core
     *
     * @effects install_probe_reads_manifest_only
     *
     * @param  class-string  $class  검사 대상 클래스
     * @return void 반환값 없음
     */
    #[DataProvider('installDecisionClasses')]
    public function test_install_decision_class_does_not_call_entry_executing_info(string $class): void
    {
        $source = (string) file_get_contents((new \ReflectionClass($class))->getFileName());

        $this->assertDoesNotMatchRegularExpression('/->getPluginInfo\(/', $source, "{$class} 가 설치 판정에 getPluginInfo 를 씁니다");
        $this->assertDoesNotMatchRegularExpression('/->getModuleInfo\(/', $source, "{$class} 가 설치 판정에 getModuleInfo 를 씁니다");
        $this->assertMatchesRegularExpression('/->get(Plugin|Module)ManifestInfo\(/', $source);
    }

    /**
     * ZIP·GitHub 설치의 「이미 설치됐는가」 확인 메서드.
     *
     * @return array<string, array{class-string, string, string}> 서비스, 메서드, 써야 할 호출
     */
    public static function alreadyInstalledChecks(): array
    {
        return [
            'plugin' => [PluginService::class, 'ensurePluginNotInstalled', 'getPluginManifestInfo('],
            'module' => [ModuleService::class, 'ensureModuleNotInstalled', 'getModuleManifestInfo('],
        ];
    }

    /**
     * ZIP·GitHub 설치의 중복 확인은 같은 요청의 설치를 위해 manifest 만 읽는다.
     *
     * @scenario probe_mode=manifest, loader=core
     *
     * @effects install_probe_reads_manifest_only
     *
     * @param  class-string  $class  서비스 클래스
     * @param  string  $method  확인 메서드
     * @param  string  $expectedCall  써야 할 호출
     * @return void 반환값 없음
     */
    #[DataProvider('alreadyInstalledChecks')]
    public function test_already_installed_check_reads_manifest_only(string $class, string $method, string $expectedCall): void
    {
        $ref = new \ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        $this->assertStringContainsString($expectedCall, $body);
        $this->assertDoesNotMatchRegularExpression('/->get(Plugin|Module)Info\(/', $body, "{$class}::{$method} 가 진입 파일을 실행하는 상세 조회를 씁니다");
    }
}
