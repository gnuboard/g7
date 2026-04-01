<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\PluginManager;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PluginManager 의존성 체크 테스트
 *
 * getDependencies()가 연관 배열(identifier => version)을 반환할 때
 * checkDependencies, activatePlugin, enrichDependencies가
 * 모듈/플러그인 양쪽을 올바르게 검색하는지 테스트합니다.
 */
class PluginManagerDependencyTest extends TestCase
{
    use RefreshDatabase;

    private PluginManager $pluginManager;

    /**
     * 테스트용 stub 플러그인 디렉토리 경로
     */
    private string $stubPluginDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginManager = app(PluginManager::class);

        // _bundled 이동 후 active 디렉토리에 plugin.php가 필요하므로 stub 생성
        $this->stubPluginDir = base_path('plugins/test-depcheck');
        File::ensureDirectoryExists($this->stubPluginDir);
        File::put($this->stubPluginDir.'/plugin.php', <<<'PHP'
<?php
namespace Plugins\Test\Depcheck;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function getDependencies(): array
    {
        return ['sirsoft-ecommerce' => '>=1.0.0'];
    }
}
PHP);
        File::put($this->stubPluginDir.'/plugin.json', json_encode([
            'identifier' => 'test-depcheck',
            'version' => '1.0.0',
            'name' => ['ko' => '의존성 테스트', 'en' => 'Dep Test'],
        ]));
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->stubPluginDir)) {
            File::deleteDirectory($this->stubPluginDir);
        }

        parent::tearDown();
    }

    /**
     * 플러그인이 활성 모듈에 의존할 때 활성화가 성공하는지 테스트합니다.
     */
    public function test_activate_plugin_with_active_module_dependency_succeeds(): void
    {
        // 의존 대상: 활성 모듈 생성
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '이커머스 모듈', 'en' => 'Ecommerce module'],
        ]);

        // 플러그인 DB 레코드 생성 (설치됨, 비활성)
        Plugin::create([
            'identifier' => 'test-depcheck',
            'vendor' => 'test',
            'name' => ['ko' => '의존성 테스트', 'en' => 'Dep Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '의존성 테스트', 'en' => 'Dep Test'],
        ]);

        // stub 플러그인 로드 후 활성화 시도
        $this->pluginManager->loadPlugins();
        $result = $this->pluginManager->activatePlugin('test-depcheck');

        // 의존성 경고가 없어야 함 (모듈이 활성 상태이므로)
        $this->assertArrayNotHasKey('warning', $result, '활성 모듈 의존성이 있는데 경고가 발생함');
    }

    /**
     * 플러그인이 비활성 모듈에 의존할 때 경고를 반환하는지 테스트합니다.
     */
    public function test_activate_plugin_with_inactive_module_dependency_returns_warning(): void
    {
        // 의존 대상: 비활성 모듈 생성
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '이커머스 모듈', 'en' => 'Ecommerce module'],
        ]);

        // 플러그인 DB 레코드 생성
        Plugin::create([
            'identifier' => 'test-depcheck',
            'vendor' => 'test',
            'name' => ['ko' => '의존성 테스트', 'en' => 'Dep Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '의존성 테스트', 'en' => 'Dep Test'],
        ]);

        // stub 플러그인 로드 후 활성화 시도
        $this->pluginManager->loadPlugins();
        $result = $this->pluginManager->activatePlugin('test-depcheck');

        // 의존성 미충족 경고가 있어야 함
        $this->assertTrue($result['warning'] ?? false, '비활성 모듈 의존성에 대한 경고가 없음');
        $this->assertNotEmpty($result['missing_modules'] ?? [], 'missing_modules가 비어있음');
    }

    /**
     * 플러그인이 미설치 모듈에 의존할 때 경고를 반환하는지 테스트합니다.
     */
    public function test_activate_plugin_with_missing_module_dependency_returns_warning(): void
    {
        // 의존 대상 모듈이 DB에 없음
        Plugin::create([
            'identifier' => 'test-depcheck',
            'vendor' => 'test',
            'name' => ['ko' => '의존성 테스트', 'en' => 'Dep Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '의존성 테스트', 'en' => 'Dep Test'],
        ]);

        // stub 플러그인 로드 후 활성화 시도
        $this->pluginManager->loadPlugins();
        $result = $this->pluginManager->activatePlugin('test-depcheck');

        // 의존성 미충족 경고
        $this->assertTrue($result['warning'] ?? false, '미설치 모듈 의존성에 대한 경고가 없음');
    }

    /**
     * enrichDependencies가 모듈 의존성의 identifier를 올바르게 반환하는지 테스트합니다.
     * (버전 문자열이 아닌 실제 identifier)
     */
    public function test_enrich_dependencies_returns_module_identifier_not_version(): void
    {
        // 모듈 생성
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '이커머스 모듈', 'en' => 'Ecommerce module'],
        ]);

        // protected 메서드 테스트를 위해 리플렉션 사용
        $reflection = new \ReflectionMethod($this->pluginManager, 'enrichDependencies');

        $dependencies = ['sirsoft-ecommerce' => '>=1.0.0'];
        $result = $reflection->invoke($this->pluginManager, $dependencies);

        $this->assertCount(1, $result);
        $this->assertEquals('sirsoft-ecommerce', $result[0]['identifier']);
        $this->assertEquals('module', $result[0]['type']);
        // 버전 문자열이 identifier로 사용되지 않아야 함
        $this->assertNotEquals('>=1.0.0', $result[0]['identifier']);
    }

    /**
     * enrichDependencies가 플러그인 의존성도 올바르게 처리하는지 테스트합니다.
     */
    public function test_enrich_dependencies_returns_plugin_identifier(): void
    {
        // 플러그인 생성
        Plugin::create([
            'identifier' => 'sirsoft-payment',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '결제', 'en' => 'Payment'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '결제 플러그인', 'en' => 'Payment plugin'],
        ]);

        $reflection = new \ReflectionMethod($this->pluginManager, 'enrichDependencies');

        $dependencies = ['sirsoft-payment' => '>=1.0.0'];
        $result = $reflection->invoke($this->pluginManager, $dependencies);

        $this->assertCount(1, $result);
        $this->assertEquals('sirsoft-payment', $result[0]['identifier']);
        $this->assertEquals('plugin', $result[0]['type']);
    }

    /**
     * enrichDependencies가 미등록 의존성을 unknown 타입으로 반환하는지 테스트합니다.
     */
    public function test_enrich_dependencies_returns_unknown_for_missing(): void
    {
        $reflection = new \ReflectionMethod($this->pluginManager, 'enrichDependencies');

        $dependencies = ['nonexistent-extension' => '>=1.0.0'];
        $result = $reflection->invoke($this->pluginManager, $dependencies);

        $this->assertCount(1, $result);
        $this->assertEquals('nonexistent-extension', $result[0]['identifier']);
        $this->assertEquals('unknown', $result[0]['type']);
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

        // 의존성이 있는 플러그인 Mock
        $plugin = \Mockery::mock(\App\Contracts\Extension\PluginInterface::class);
        $plugin->shouldReceive('getDependencies')
            ->andReturn(['sirsoft-ecommerce' => '>=1.0.0']);

        // protected 메서드 리플렉션
        $reflection = new \ReflectionMethod($this->pluginManager, 'checkDependencies');

        // 예외가 발생하지 않아야 함
        $this->assertNull($reflection->invoke($this->pluginManager, $plugin));
    }

    /**
     * checkDependencies가 비활성 모듈 의존성일 때 예외를 던지는지 테스트합니다.
     */
    public function test_check_dependencies_throws_with_inactive_module(): void
    {
        // 비활성 모듈 생성
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '이커머스 모듈', 'en' => 'Ecommerce module'],
        ]);

        $plugin = \Mockery::mock(\App\Contracts\Extension\PluginInterface::class);
        $plugin->shouldReceive('getDependencies')
            ->andReturn(['sirsoft-ecommerce' => '>=1.0.0']);

        $reflection = new \ReflectionMethod($this->pluginManager, 'checkDependencies');

        $this->expectException(\Exception::class);
        $reflection->invoke($this->pluginManager, $plugin);
    }

    /**
     * checkDependencies가 미설치 의존성일 때 예외를 던지는지 테스트합니다.
     */
    public function test_check_dependencies_throws_with_missing_dependency(): void
    {
        $plugin = \Mockery::mock(\App\Contracts\Extension\PluginInterface::class);
        $plugin->shouldReceive('getDependencies')
            ->andReturn(['nonexistent-module' => '>=1.0.0']);

        $reflection = new \ReflectionMethod($this->pluginManager, 'checkDependencies');

        $this->expectException(\Exception::class);
        $reflection->invoke($this->pluginManager, $plugin);
    }

    /**
     * checkDependencies가 활성 플러그인 의존성일 때 예외를 던지지 않는지 테스트합니다.
     */
    public function test_check_dependencies_passes_with_active_plugin(): void
    {
        // 활성 플러그인 생성
        Plugin::create([
            'identifier' => 'sirsoft-payment',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '결제', 'en' => 'Payment'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '결제 플러그인', 'en' => 'Payment plugin'],
        ]);

        $plugin = \Mockery::mock(\App\Contracts\Extension\PluginInterface::class);
        $plugin->shouldReceive('getDependencies')
            ->andReturn(['sirsoft-payment' => '>=1.0.0']);

        $reflection = new \ReflectionMethod($this->pluginManager, 'checkDependencies');

        $this->assertNull($reflection->invoke($this->pluginManager, $plugin));
    }
}
