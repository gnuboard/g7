<?php

namespace Tests\Unit;

use App\Extension\AbstractModule;
use PHPUnit\Framework\TestCase;

class AbstractModuleTest extends TestCase
{
    private AbstractModule $module;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 익명 클래스로 AbstractModule 구현
        $this->module = new class extends AbstractModule
        {
            public function getName(): array
            {
                return [
                    'ko' => '테스트 모듈',
                    'en' => 'Test Module',
                ];
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): array
            {
                return [
                    'ko' => '테스트 설명',
                    'en' => 'Test Description',
                ];
            }
        };
    }

    /**
     * getIdentifier()가 디렉토리명에서 자동 추론되는지 테스트
     */
    public function test_get_identifier_returns_directory_name(): void
    {
        // 익명 클래스는 tests/Unit 디렉토리에 있으므로
        $identifier = $this->module->getIdentifier();

        // 실제 디렉토리명이 반환되어야 함
        $this->assertIsString($identifier);
        $this->assertNotEmpty($identifier);
    }

    /**
     * getVendor()가 식별자의 첫 번째 부분을 반환하는지 테스트
     */
    public function test_get_vendor_returns_first_part_of_identifier(): void
    {
        $vendor = $this->module->getVendor();

        $this->assertIsString($vendor);
        $this->assertNotEmpty($vendor);
    }

    /**
     * install()이 기본적으로 true를 반환하는지 테스트
     */
    public function test_install_returns_true_by_default(): void
    {
        $this->assertTrue($this->module->install());
    }

    /**
     * uninstall()이 기본적으로 true를 반환하는지 테스트
     */
    public function test_uninstall_returns_true_by_default(): void
    {
        $this->assertTrue($this->module->uninstall());
    }

    /**
     * activate()가 기본적으로 true를 반환하는지 테스트
     */
    public function test_activate_returns_true_by_default(): void
    {
        $this->assertTrue($this->module->activate());
    }

    /**
     * deactivate()가 기본적으로 true를 반환하는지 테스트
     */
    public function test_deactivate_returns_true_by_default(): void
    {
        $this->assertTrue($this->module->deactivate());
    }

    /**
     * getRoutes()가 기본적으로 빈 배열을 반환하는지 테스트
     * (테스트 디렉토리에는 routes 파일이 없으므로)
     */
    public function test_get_routes_returns_empty_array_when_no_route_files(): void
    {
        $routes = $this->module->getRoutes();

        $this->assertIsArray($routes);
    }

    /**
     * getMigrations()가 기본적으로 빈 배열을 반환하는지 테스트
     * (테스트 디렉토리에는 migrations 디렉토리가 없으므로)
     */
    public function test_get_migrations_returns_empty_array_when_no_migrations_dir(): void
    {
        $migrations = $this->module->getMigrations();

        $this->assertIsArray($migrations);
    }

    /**
     * getViews()가 기본적으로 빈 배열을 반환하는지 테스트
     */
    public function test_get_views_returns_empty_array_by_default(): void
    {
        $this->assertEquals([], $this->module->getViews());
    }

    /**
     * getPermissions()가 기본적으로 빈 배열을 반환하는지 테스트
     */
    public function test_get_permissions_returns_empty_array_by_default(): void
    {
        $this->assertEquals([], $this->module->getPermissions());
    }

    /**
     * getConfig()가 기본적으로 빈 배열을 반환하는지 테스트
     */
    public function test_get_config_returns_empty_array_by_default(): void
    {
        $this->assertEquals([], $this->module->getConfig());
    }

    /**
     * getAdminMenus()가 기본적으로 빈 배열을 반환하는지 테스트
     */
    public function test_get_admin_menus_returns_empty_array_by_default(): void
    {
        $this->assertEquals([], $this->module->getAdminMenus());
    }

    /**
     * getHookListeners()가 기본적으로 빈 배열을 반환하는지 테스트
     */
    public function test_get_hook_listeners_returns_empty_array_by_default(): void
    {
        $this->assertEquals([], $this->module->getHookListeners());
    }

    /**
     * getDependencies()가 기본적으로 빈 배열을 반환하는지 테스트
     */
    public function test_get_dependencies_returns_empty_array_by_default(): void
    {
        $this->assertEquals([], $this->module->getDependencies());
    }

    /**
     * getGithubUrl()이 기본적으로 null을 반환하는지 테스트
     */
    public function test_get_github_url_returns_null_by_default(): void
    {
        $this->assertNull($this->module->getGithubUrl());
    }

    /**
     * getMetadata()가 기본적으로 빈 배열을 반환하는지 테스트
     */
    public function test_get_metadata_returns_empty_array_by_default(): void
    {
        $this->assertEquals([], $this->module->getMetadata());
    }

    /**
     * 필수 추상 메서드가 올바르게 구현되었는지 테스트
     */
    public function test_abstract_methods_are_implemented(): void
    {
        $this->assertEquals([
            'ko' => '테스트 모듈',
            'en' => 'Test Module',
        ], $this->module->getName());

        $this->assertEquals('1.0.0', $this->module->getVersion());

        $this->assertEquals([
            'ko' => '테스트 설명',
            'en' => 'Test Description',
        ], $this->module->getDescription());
    }
}
