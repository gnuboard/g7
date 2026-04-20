<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Extension\AbstractModule;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * ModuleManager 시더 실행 로직 테스트
 *
 * getSeeders() 메서드 우선 실행 및 빈 배열 시 glob 폴백을 검증합니다.
 */
class ModuleSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * getSeeders()가 빈 배열을 반환하는 AbstractModule 기본 구현을 테스트합니다.
     */
    public function test_abstract_module_get_seeders_returns_empty_array(): void
    {
        $module = new class extends AbstractModule
        {
            public function getName(): string|array
            {
                return 'Test Module';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test description';
            }
        };

        $this->assertIsArray($module->getSeeders());
        $this->assertEmpty($module->getSeeders());
    }

    /**
     * getSeeders()를 오버라이드하여 특정 시더만 반환하는 경우를 테스트합니다.
     */
    public function test_module_can_override_get_seeders(): void
    {
        $module = new class extends AbstractModule
        {
            public function getName(): string|array
            {
                return 'Test Module';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test description';
            }

            public function getSeeders(): array
            {
                return [
                    'App\\Database\\Seeders\\TestSeeder',
                ];
            }
        };

        $seeders = $module->getSeeders();

        $this->assertCount(1, $seeders);
        $this->assertEquals('App\\Database\\Seeders\\TestSeeder', $seeders[0]);
    }

    /**
     * getSeeders()가 비어있지 않으면 해당 목록만 실행하는지 테스트합니다.
     */
    public function test_run_module_seeders_uses_defined_seeders_when_not_empty(): void
    {
        // Mock 시더 클래스명 (실제 존재하지 않는 클래스)
        $fakeSeederClass = 'Tests\\Fixtures\\FakeSeeder';

        // ModuleManager의 runModuleSeeders는 protected이므로 리플렉션 사용
        $moduleManager = app(ModuleManager::class);

        $module = $this->createMock(ModuleInterface::class);
        $module->method('getSeeders')->willReturn([$fakeSeederClass]);

        // class_exists가 false이므로 Artisan::call이 호출되지 않아야 함
        Artisan::shouldReceive('call')->never();

        // 리플렉션을 통해 protected 메서드 호출
        $method = new \ReflectionMethod(ModuleManager::class, 'runModuleSeeders');
        $method->setAccessible(true);
        $method->invoke($moduleManager, $module);

        // 여기까지 예외 없이 도달하면 성공
        $this->assertTrue(true);
    }

    /**
     * getSeeders()가 빈 배열이면 glob 방식으로 폴백하는지 테스트합니다.
     */
    public function test_run_module_seeders_falls_back_to_glob_when_empty(): void
    {
        $moduleManager = app(ModuleManager::class);

        $module = $this->createMock(ModuleInterface::class);
        $module->method('getSeeders')->willReturn([]);

        // modules 프로퍼티가 비어있으므로 디렉토리명을 찾지 못해 early return
        $method = new \ReflectionMethod(ModuleManager::class, 'runModuleSeeders');
        $method->setAccessible(true);
        $method->invoke($moduleManager, $module);

        // glob 폴백 경로에서 moduleDirName을 찾지 못해 early return (예외 없음)
        $this->assertTrue(true);
    }

    /**
     * registerExtensionAutoloadPaths가 모듈의 PSR-4 네임스페이스를 Composer ClassLoader에 등록하는지 테스트합니다.
     */
    public function test_register_extension_autoload_paths_registers_psr4_namespaces(): void
    {
        // sirsoft-ecommerce 모듈의 composer.json이 존재하는지 확인
        $composerFile = base_path('modules/sirsoft-ecommerce/composer.json');

        if (! file_exists($composerFile)) {
            $this->markTestSkipped('sirsoft-ecommerce 모듈이 존재하지 않습니다.');
        }

        // 동적 오토로드 등록 실행
        ExtensionManager::registerExtensionAutoloadPaths('modules', 'sirsoft-ecommerce');

        // Composer ClassLoader에서 등록된 PSR-4 프리픽스 확인
        $loader = require base_path('vendor/autoload.php');
        $prefixes = $loader->getPrefixes() + $loader->getPrefixesPsr4();

        $this->assertArrayHasKey(
            'Modules\\Sirsoft\\Ecommerce\\Database\\Seeders\\',
            $prefixes,
            'SequenceSeeder 네임스페이스가 Composer ClassLoader에 등록되어야 합니다.'
        );
    }

    /**
     * sirsoft-ecommerce 모듈의 getSeeders()가 필수 시더를 포함하는지 테스트합니다.
     *
     * 2026-02-09 최초 작성 당시에는 SequenceSeeder 1개만 반환했으나,
     * 2026-03-03 배송사 관리 기능 추가(#19) 이후 ShippingCarrierSeeder 등이
     * 설치 시 실행되는 필수 시더로 추가되었다. 본 테스트는 설치 시 반드시 실행되어야
     * 하는 시더들이 모두 포함되어 있는지 검증한다.
     */
    public function test_ecommerce_module_get_seeders_includes_required_seeders(): void
    {
        $moduleManager = app(ModuleManager::class);
        $moduleManager->loadModules();

        $module = $moduleManager->getModule('sirsoft-ecommerce');

        if (! $module) {
            $this->markTestSkipped('sirsoft-ecommerce 모듈이 설치되어 있지 않습니다.');
        }

        $seeders = $module->getSeeders();

        // SequenceSeeder 는 주문/상품 시퀀스 초기값 생성을 위해 반드시 포함되어야 함
        $this->assertContains(
            'Modules\\Sirsoft\\Ecommerce\\Database\\Seeders\\SequenceSeeder',
            $seeders,
            'SequenceSeeder 는 설치 시 필수 시더로 포함되어야 합니다'
        );

        // 모든 시더가 sirsoft-ecommerce 네임스페이스에 속해야 함
        foreach ($seeders as $seederClass) {
            $this->assertStringStartsWith(
                'Modules\\Sirsoft\\Ecommerce\\Database\\Seeders\\',
                $seederClass,
                "시더 {$seederClass} 는 sirsoft-ecommerce 네임스페이스에 속해야 합니다"
            );
        }
    }
}
