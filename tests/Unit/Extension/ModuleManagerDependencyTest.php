<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleManager 의존성 체크 테스트
 *
 * getDependencies()가 연관 배열(identifier => version)을 반환할 때
 * checkDependencies, activateModule, enrichDependencies가
 * 모듈/플러그인 양쪽을 올바르게 검색하는지 테스트합니다.
 */
class ModuleManagerDependencyTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleManager = app(ModuleManager::class);
    }

    /**
     * checkDependencies가 활성 모듈 의존성일 때 예외를 던지지 않는지 테스트합니다.
     */
    public function test_check_dependencies_passes_with_active_module(): void
    {
        // 활성 모듈 생성
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '이커머스 모듈', 'en' => 'Ecommerce module'],
        ]);

        $module = \Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $module->shouldReceive('getDependencies')
            ->andReturn(['modules' => ['sirsoft-ecommerce' => '>=1.0.0']]);

        $reflection = new \ReflectionMethod($this->moduleManager, 'checkDependencies');

        $this->assertNull($reflection->invoke($this->moduleManager, $module));
    }

    /**
     * checkDependencies가 비활성 모듈 의존성일 때 예외를 던지는지 테스트합니다.
     */
    public function test_check_dependencies_throws_with_inactive_module(): void
    {
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '이커머스 모듈', 'en' => 'Ecommerce module'],
        ]);

        $module = \Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $module->shouldReceive('getDependencies')
            ->andReturn(['modules' => ['sirsoft-ecommerce' => '>=1.0.0']]);

        $reflection = new \ReflectionMethod($this->moduleManager, 'checkDependencies');

        $this->expectException(\Exception::class);
        $reflection->invoke($this->moduleManager, $module);
    }

    /**
     * checkDependencies가 활성 플러그인 의존성일 때 예외를 던지지 않는지 테스트합니다.
     */
    public function test_check_dependencies_passes_with_active_plugin(): void
    {
        Plugin::create([
            'identifier' => 'sirsoft-payment',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '결제', 'en' => 'Payment'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '결제 플러그인', 'en' => 'Payment plugin'],
        ]);

        $module = \Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $module->shouldReceive('getDependencies')
            ->andReturn(['plugins' => ['sirsoft-payment' => '>=1.0.0']]);

        $reflection = new \ReflectionMethod($this->moduleManager, 'checkDependencies');

        $this->assertNull($reflection->invoke($this->moduleManager, $module));
    }

    /**
     * checkDependencies가 미설치 의존성일 때 예외를 던지는지 테스트합니다.
     */
    public function test_check_dependencies_throws_with_missing_dependency(): void
    {
        $module = \Mockery::mock(\App\Contracts\Extension\ModuleInterface::class);
        $module->shouldReceive('getDependencies')
            ->andReturn(['modules' => ['nonexistent-extension' => '>=1.0.0']]);

        $reflection = new \ReflectionMethod($this->moduleManager, 'checkDependencies');

        $this->expectException(\Exception::class);
        $reflection->invoke($this->moduleManager, $module);
    }

    /**
     * enrichDependencies가 중첩 모듈 의존성의 identifier를 올바르게 반환하는지 테스트합니다.
     *
     * manifest(module.json/plugin.json)의 dependencies 필드는 중첩 구조
     * ['modules' => [...], 'plugins' => [...]] 로 저장되므로 enrichDependencies는
     * 해당 구조를 그대로 파싱합니다 (커밋 72156e6f0, 2026-02-23).
     */
    public function test_enrich_dependencies_returns_module_identifier_not_version(): void
    {
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '이커머스 모듈', 'en' => 'Ecommerce module'],
        ]);

        $reflection = new \ReflectionMethod($this->moduleManager, 'enrichDependencies');

        $dependencies = [
            'modules' => ['sirsoft-ecommerce' => '>=1.0.0'],
        ];
        $result = $reflection->invoke($this->moduleManager, $dependencies);

        $this->assertCount(1, $result);
        $this->assertEquals('sirsoft-ecommerce', $result[0]['identifier']);
        $this->assertEquals('module', $result[0]['type']);
        $this->assertNotEquals('>=1.0.0', $result[0]['identifier']);
    }

    /**
     * enrichDependencies가 중첩 플러그인 의존성도 올바르게 처리하는지 테스트합니다.
     */
    public function test_enrich_dependencies_returns_plugin_identifier(): void
    {
        Plugin::create([
            'identifier' => 'sirsoft-payment',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '결제', 'en' => 'Payment'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '결제 플러그인', 'en' => 'Payment plugin'],
        ]);

        $reflection = new \ReflectionMethod($this->moduleManager, 'enrichDependencies');

        $dependencies = [
            'plugins' => ['sirsoft-payment' => '>=1.0.0'],
        ];
        $result = $reflection->invoke($this->moduleManager, $dependencies);

        $this->assertCount(1, $result);
        $this->assertEquals('sirsoft-payment', $result[0]['identifier']);
        $this->assertEquals('plugin', $result[0]['type']);
    }

    /**
     * enrichDependencies가 미등록 의존성의 identifier를 그대로 반환하는지 테스트합니다.
     *
     * DB에 없는 의존성은 name 필드에 identifier를 사용하지만 type은 여전히
     * 선언된 구조(module/plugin)를 따릅니다.
     */
    public function test_enrich_dependencies_returns_identifier_for_missing(): void
    {
        $reflection = new \ReflectionMethod($this->moduleManager, 'enrichDependencies');

        $dependencies = [
            'modules' => ['nonexistent-extension' => '>=1.0.0'],
        ];
        $result = $reflection->invoke($this->moduleManager, $dependencies);

        $this->assertCount(1, $result);
        $this->assertEquals('nonexistent-extension', $result[0]['identifier']);
        $this->assertEquals('nonexistent-extension', $result[0]['name']);
        $this->assertEquals('module', $result[0]['type']);
    }

    /**
     * enrichDependencies가 중첩 구조가 아닌 경우 빈 배열을 반환하는지 테스트합니다.
     *
     * manifest 형식이 아닌 평면 배열 등은 파싱되지 않아야 합니다.
     */
    public function test_enrich_dependencies_returns_empty_for_non_nested_structure(): void
    {
        $reflection = new \ReflectionMethod($this->moduleManager, 'enrichDependencies');

        $dependencies = ['sirsoft-ecommerce' => '>=1.0.0'];
        $result = $reflection->invoke($this->moduleManager, $dependencies);

        $this->assertCount(0, $result);
    }
}
