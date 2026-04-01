<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * getDynamicTables() 라이프사이클 메서드 및 Manager 동적 테이블 삭제 테스트
 *
 * ModuleManager/PluginManager의 동적 테이블 정리 로직을 검증합니다.
 */
class CleanupDynamicDataTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleManager = app(ModuleManager::class);
        $this->pluginManager = app(PluginManager::class);
    }

    /**
     * AbstractModule의 기본 getDynamicTables() 구현이 빈 배열을 반환하는지 테스트합니다.
     */
    public function test_abstract_module_default_get_dynamic_tables_returns_empty(): void
    {
        $module = new class extends AbstractModule
        {
            public function getName(): string
            {
                return 'Test Module';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string
            {
                return 'Test module for getDynamicTables';
            }
        };

        $this->assertSame([], $module->getDynamicTables());
    }

    /**
     * AbstractPlugin의 기본 getDynamicTables() 구현이 빈 배열을 반환하는지 테스트합니다.
     */
    public function test_abstract_plugin_default_get_dynamic_tables_returns_empty(): void
    {
        $plugin = new class extends AbstractPlugin
        {
            public function getName(): string
            {
                return 'Test Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string
            {
                return 'Test plugin for getDynamicTables';
            }
        };

        $this->assertSame([], $plugin->getDynamicTables());
    }

    /**
     * ModuleManager가 getDynamicTables() 반환값으로 테이블을 삭제하는지 테스트합니다.
     */
    public function test_module_manager_drops_dynamic_tables(): void
    {
        $module = \Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getDynamicTables')
            ->once()
            ->andReturn(['test_dynamic_table_a', 'test_dynamic_table_b']);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        Schema::shouldReceive('dropIfExists')
            ->once()
            ->with('test_dynamic_table_a');
        Schema::shouldReceive('dropIfExists')
            ->once()
            ->with('test_dynamic_table_b');

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $reflection = new \ReflectionMethod($this->moduleManager, 'cleanupDynamicModuleData');
        $reflection->invoke($this->moduleManager, $module);
    }

    /**
     * PluginManager가 getDynamicTables() 반환값으로 테이블을 삭제하는지 테스트합니다.
     */
    public function test_plugin_manager_drops_dynamic_tables(): void
    {
        $plugin = \Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getDynamicTables')
            ->once()
            ->andReturn(['test_dynamic_table_a', 'test_dynamic_table_b']);
        $plugin->shouldReceive('getIdentifier')->andReturn('test-plugin');

        Schema::shouldReceive('dropIfExists')
            ->once()
            ->with('test_dynamic_table_a');
        Schema::shouldReceive('dropIfExists')
            ->once()
            ->with('test_dynamic_table_b');

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $reflection = new \ReflectionMethod($this->pluginManager, 'cleanupDynamicPluginData');
        $reflection->invoke($this->pluginManager, $plugin);
    }

    /**
     * ModuleManager가 getDynamicTables() 예외 발생 시에도 계속 진행하는지 테스트합니다.
     */
    public function test_module_manager_continues_on_get_dynamic_tables_failure(): void
    {
        $module = \Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getDynamicTables')
            ->once()
            ->andThrow(new \Exception('동적 테이블 목록 조회 실패'));
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '동적 테이블 목록 조회 실패');
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $reflection = new \ReflectionMethod($this->moduleManager, 'cleanupDynamicModuleData');

        // 예외 없이 완료되면 성공 (래퍼에서 catch 후 계속 진행)
        $reflection->invoke($this->moduleManager, $module);
        $this->assertTrue(true);
    }

    /**
     * PluginManager가 getDynamicTables() 예외 발생 시에도 계속 진행하는지 테스트합니다.
     */
    public function test_plugin_manager_continues_on_get_dynamic_tables_failure(): void
    {
        $plugin = \Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getDynamicTables')
            ->once()
            ->andThrow(new \Exception('동적 테이블 목록 조회 실패'));
        $plugin->shouldReceive('getIdentifier')->andReturn('test-plugin');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '동적 테이블 목록 조회 실패');
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $reflection = new \ReflectionMethod($this->pluginManager, 'cleanupDynamicPluginData');

        // 예외 없이 완료되면 성공 (래퍼에서 catch 후 계속 진행)
        $reflection->invoke($this->pluginManager, $plugin);
        $this->assertTrue(true);
    }

    /**
     * ModuleManager가 빈 테이블 목록일 때 Schema::dropIfExists를 호출하지 않는지 테스트합니다.
     */
    public function test_module_manager_skips_when_no_dynamic_tables(): void
    {
        $module = \Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getDynamicTables')
            ->once()
            ->andReturn([]);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        // Schema::dropIfExists가 호출되지 않아야 함
        Schema::shouldReceive('dropIfExists')->never();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $reflection = new \ReflectionMethod($this->moduleManager, 'cleanupDynamicModuleData');
        $reflection->invoke($this->moduleManager, $module);
    }

    /**
     * ModuleManager가 개별 테이블 삭제 실패 시 나머지를 계속 삭제하는지 테스트합니다.
     */
    public function test_module_manager_continues_on_individual_table_drop_failure(): void
    {
        $module = \Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getDynamicTables')
            ->once()
            ->andReturn(['table_a', 'table_b', 'table_c']);
        $module->shouldReceive('getIdentifier')->andReturn('test-module');

        Schema::shouldReceive('dropIfExists')
            ->with('table_a')
            ->once()
            ->andThrow(new \Exception('삭제 실패'));
        Schema::shouldReceive('dropIfExists')
            ->with('table_b')
            ->once();
        Schema::shouldReceive('dropIfExists')
            ->with('table_c')
            ->once();

        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $context['dropped'] === 2 && $context['failed'] === 1;
            });

        $reflection = new \ReflectionMethod($this->moduleManager, 'cleanupDynamicModuleData');
        $reflection->invoke($this->moduleManager, $module);
    }
}
