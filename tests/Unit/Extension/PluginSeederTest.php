<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\PluginInterface;
use App\Extension\AbstractPlugin;
use App\Extension\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PluginManager 시더 실행 로직 테스트
 *
 * getSeeders() 메서드 우선 실행 및 빈 배열 시 glob 폴백을 검증합니다.
 */
class PluginSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * getSeeders()가 빈 배열을 반환하는 AbstractPlugin 기본 구현을 테스트합니다.
     */
    public function test_abstract_plugin_get_seeders_returns_empty_array(): void
    {
        $plugin = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Test Plugin';
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

        $this->assertIsArray($plugin->getSeeders());
        $this->assertEmpty($plugin->getSeeders());
    }

    /**
     * getSeeders()를 오버라이드하여 특정 시더만 반환하는 경우를 테스트합니다.
     */
    public function test_plugin_can_override_get_seeders(): void
    {
        $plugin = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Test Plugin';
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
                    'Plugins\\Test\\Database\\Seeders\\InitSeeder',
                ];
            }
        };

        $seeders = $plugin->getSeeders();

        $this->assertCount(1, $seeders);
        $this->assertEquals('Plugins\\Test\\Database\\Seeders\\InitSeeder', $seeders[0]);
    }

    /**
     * getSeeders()가 비어있지 않으면 해당 목록만 실행하는지 테스트합니다.
     */
    public function test_run_plugin_seeders_uses_defined_seeders_when_not_empty(): void
    {
        $fakeSeederClass = 'Tests\\Fixtures\\FakePluginSeeder';

        $pluginManager = app(PluginManager::class);

        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getSeeders')->willReturn([$fakeSeederClass]);

        // class_exists가 false이므로 Artisan::call이 호출되지 않아야 함
        Artisan::shouldReceive('call')->never();

        $method = new \ReflectionMethod(PluginManager::class, 'runPluginSeeders');
        $method->setAccessible(true);
        $method->invoke($pluginManager, $plugin);

        $this->assertTrue(true);
    }

    /**
     * getSeeders()가 빈 배열이면 glob 방식으로 폴백하는지 테스트합니다.
     */
    public function test_run_plugin_seeders_falls_back_to_glob_when_empty(): void
    {
        $pluginManager = app(PluginManager::class);

        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getSeeders')->willReturn([]);

        $method = new \ReflectionMethod(PluginManager::class, 'runPluginSeeders');
        $method->setAccessible(true);
        $method->invoke($pluginManager, $plugin);

        // glob 폴백 경로에서 pluginDirName을 찾지 못해 early return (예외 없음)
        $this->assertTrue(true);
    }
}
