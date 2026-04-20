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
     * getVendor()가 module.json 의 vendor 필드를 우선 사용하는지 테스트
     *
     * 이슈: https://github.com/gnuboard/g7/issues/9
     */
    public function test_get_vendor_prefers_manifest_vendor_field(): void
    {
        $module = $this->makeModuleWithManifest(
            manifest: ['vendor' => 'Glitter.kr'],
            moduleDir: '/tmp/glitter-reservation'
        );

        $this->assertSame('Glitter.kr', $module->getVendor());
    }

    /**
     * getVendor()가 manifest 에 vendor 필드가 없으면 디렉토리 prefix 로 폴백하는지 테스트
     */
    public function test_get_vendor_falls_back_to_identifier_prefix_when_manifest_missing(): void
    {
        $module = $this->makeModuleWithManifest(
            manifest: [],
            moduleDir: '/tmp/sirsoft-sample'
        );

        $this->assertSame('sirsoft', $module->getVendor());
    }

    /**
     * getVendor()가 manifest vendor 값이 빈 문자열이면 디렉토리 prefix 로 폴백하는지 테스트
     */
    public function test_get_vendor_falls_back_when_manifest_vendor_is_empty_string(): void
    {
        $module = $this->makeModuleWithManifest(
            manifest: ['vendor' => ''],
            moduleDir: '/tmp/acme-widget'
        );

        $this->assertSame('acme', $module->getVendor());
    }

    /**
     * manifest 와 디렉토리 경로를 주입 가능한 테스트용 AbstractModule 인스턴스를 생성합니다.
     *
     * AbstractModule 의 loadManifest() 와 getModulePath() 를 오버라이드하여
     * 파일 시스템에 접근하지 않고도 vendor 로직을 검증할 수 있습니다.
     *
     * @param  array<string, mixed>  $manifest  module.json 내용을 시뮬레이션할 배열
     * @param  string  $moduleDir  모듈 디렉토리 경로 (basename 이 식별자로 사용됨)
     */
    private function makeModuleWithManifest(array $manifest, string $moduleDir): AbstractModule
    {
        return new class($manifest, $moduleDir) extends AbstractModule
        {
            public function __construct(private array $fakeManifest, private string $fakeModulePath) {}

            protected function loadManifest(): array
            {
                return $this->fakeManifest;
            }

            protected function getModulePath(): string
            {
                return $this->fakeModulePath;
            }
        };
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
