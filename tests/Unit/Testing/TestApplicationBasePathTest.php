<?php

namespace Tests\Unit\Testing;

use Composer\Autoload\ClassLoader;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * 테스트 앱의 기준 경로는 등록된 Composer 로더 순서와 무관하게 저장소 루트다.
 *
 * Composer 가 만든 `vendor/autoload.php` 는 자기 로더를 `ClassLoader::getRegisteredLoaders()` 맨 앞에 넣는다.
 * 업그레이드 단계처럼 확장 vendor 오토로더를 실제로 싣는 코드를 테스트가 돌리면, 프레임워크 기본
 * `Application::inferBasePath()` 는 그 확장 폴더를 기준 경로로 잡는다. 그러면 같은 프로세스의 뒤 테스트가
 * 모두 `<확장>/bootstrap/app.php` 를 찾다 부팅에 실패했다(2026-11-03 실측 — `RunUpgradeStepsAbstractGuardTest`
 * 뒤 820여 건 연쇄 오류).
 */
class TestApplicationBasePathTest extends TestCase
{
    /**
     * 확장 vendor 로더가 맨 앞에 등록돼도 새 테스트 앱은 저장소 루트에서 부팅한다.
     *
     * @scenario case=foreign_loader_first
     *
     * @effects test_app_boots_from_repository_root
     *
     * @return void 반환값 없음
     */
    public function test_app_boots_from_repository_root_when_extension_loader_is_registered_first(): void
    {
        $root = realpath(dirname(__DIR__, 3));
        $foreign = new ClassLoader($root.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'zz-foreign'.DIRECTORY_SEPARATOR.'vendor');
        $foreign->register(true);

        try {
            $this->assertStringContainsString('zz-foreign', (string) array_key_first(ClassLoader::getRegisteredLoaders()), '표본 로더가 맨 앞에 서야 시험이 의미가 있다');

            $app = $this->createApplication();

            $this->assertSame($root, realpath($app->basePath()));
        } finally {
            $foreign->unregister();
            // 새로 만든 앱이 전역 컨테이너·파사드를 차지하므로 이 테스트의 앱으로 돌려놓는다(tearDown 이 이 앱을 정리)
            Container::setInstance($this->app);
            Facade::setFacadeApplication($this->app);
        }
    }
}
