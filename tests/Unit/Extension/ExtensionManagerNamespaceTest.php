<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Providers\ModuleRouteServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\PluginRouteServiceProvider;
use App\Providers\PluginServiceProvider;
use Tests\TestCase;

/**
 * 확장 디렉토리명·식별자를 PHP 네임스페이스로 바꾸는 규칙과, 그 규칙을 쓰는 매니저·프로바이더의 위임을 검증합니다.
 *
 * @scenario-manifest tests/scenarios/extension-namespace-conversion.yaml
 */
class ExtensionManagerNamespaceTest extends TestCase
{
    /**
     * 기본 하이픈 분리 네임스페이스 변환 테스트
     *
     * @scenario subject=directory_name
     *
     * @effects directory_name_converts_to_namespace
     *
     * @return void 반환값 없음
     */
    public function test_basic_hyphen_separated_conversion(): void
    {
        $this->assertSame('Sirsoft\Board', ExtensionManager::directoryToNamespace('sirsoft-board'));
        $this->assertSame('Sirsoft\Ecommerce', ExtensionManager::directoryToNamespace('sirsoft-ecommerce'));
        $this->assertSame('Sirsoft\Payment', ExtensionManager::directoryToNamespace('sirsoft-payment'));
    }

    /**
     * 언더스코어가 포함된 네임스페이스 변환 테스트
     *
     * @scenario subject=directory_name
     *
     * @effects directory_name_converts_to_namespace
     *
     * @return void 반환값 없음
     */
    public function test_underscore_conversion(): void
    {
        $this->assertSame('Sirsoft\DaumPostcode', ExtensionManager::directoryToNamespace('sirsoft-daum_postcode'));
        $this->assertSame('Sirsoft\AdminBasic', ExtensionManager::directoryToNamespace('sirsoft-admin_basic'));
        $this->assertSame('Sirsoft\UserTheme', ExtensionManager::directoryToNamespace('sirsoft-user_theme'));
    }

    /**
     * 다중 언더스코어가 포함된 네임스페이스 변환 테스트
     *
     * @scenario subject=directory_name
     *
     * @effects directory_name_converts_to_namespace
     *
     * @return void 반환값 없음
     */
    public function test_multiple_underscores_conversion(): void
    {
        $this->assertSame('Sirsoft\MyFancyModule', ExtensionManager::directoryToNamespace('sirsoft-my_fancy_module'));
        $this->assertSame('Vendor\OneTwo', ExtensionManager::directoryToNamespace('vendor-one_two'));
    }

    /**
     * identifierToNamespace가 모듈 접두어로 올바르게 변환하는지 테스트
     *
     * @scenario subject=identifier
     *
     * @effects identifier_converts_with_type_prefix
     *
     * @return void 반환값 없음
     */
    public function test_module_identifier_to_namespace(): void
    {
        $this->assertSame('Modules\Sirsoft\Board\\', ExtensionManager::moduleIdentifierToNamespace('sirsoft-board'));
        $this->assertSame('Modules\Sirsoft\DaumPostcode\\', ExtensionManager::moduleIdentifierToNamespace('sirsoft-daum_postcode'));
    }

    /**
     * identifierToNamespace가 플러그인 접두어로 올바르게 변환하는지 테스트
     *
     * @scenario subject=identifier
     *
     * @effects identifier_converts_with_type_prefix
     *
     * @return void 반환값 없음
     */
    public function test_plugin_identifier_to_namespace(): void
    {
        $this->assertSame('Plugins\Sirsoft\Payment\\', ExtensionManager::pluginIdentifierToNamespace('sirsoft-payment'));
        $this->assertSame('Plugins\Sirsoft\DaumPostcode\\', ExtensionManager::pluginIdentifierToNamespace('sirsoft-daum_postcode'));
    }

    /**
     * [회귀 #132] Manager 3개의 convertDirectoryToNamespace()가 언더스코어를 올바르게 처리하는지 검증
     *
     * @scenario subject=manager_delegation
     *
     * @effects managers_and_providers_share_conversion_rule
     *
     * @return void 반환값 없음
     */
    public function test_regression_manager_underscore_delegation(): void
    {
        $managers = [
            ModuleManager::class,
            PluginManager::class,
            TemplateManager::class,
        ];

        foreach ($managers as $managerClass) {
            $manager = $this->app->make($managerClass);
            $method = new \ReflectionMethod($manager, 'convertDirectoryToNamespace');

            $this->assertSame(
                'Sirsoft\DaumPostcode',
                $method->invoke($manager, 'sirsoft-daum_postcode'),
                "{$managerClass}::convertDirectoryToNamespace()가 언더스코어를 올바르게 처리해야 합니다"
            );

            $this->assertSame(
                'Sirsoft\AdminBasic',
                $method->invoke($manager, 'sirsoft-admin_basic'),
                "{$managerClass}::convertDirectoryToNamespace()가 언더스코어를 올바르게 처리해야 합니다"
            );
        }
    }

    /**
     * [회귀 #132] ServiceProvider 4개가 디렉토리명의 언더스코어를 올바르게 처리하는지 검증
     * (확장 프로바이더 등록기 2종은 resolveProviderClass(), 라우트 프로바이더 2종은 convertDirectoryToNamespace())
     *
     * @scenario subject=provider_delegation
     *
     * @effects managers_and_providers_share_conversion_rule
     *
     * @return void 반환값 없음
     */
    public function test_regression_service_provider_underscore_delegation(): void
    {
        // 확장 프로바이더 등록기 2종은 7.1.3 에서 변환을 공개 정적 resolveProviderClass() 로 옮겼다(설치 때 선등록이 같은 규칙을 쓰게)
        $this->assertSame(
            'Modules\Sirsoft\DaumPostcode\Providers\DaumPostcodeServiceProvider',
            ModuleServiceProvider::resolveProviderClass('sirsoft-daum_postcode', '/x/src/Providers/DaumPostcodeServiceProvider.php')
        );
        $this->assertSame(
            'Plugins\Sirsoft\MyFancyModule\Providers\MyFancyModuleServiceProvider',
            PluginServiceProvider::resolveProviderClass('sirsoft-my_fancy_module', '/x/src/Providers/MyFancyModuleServiceProvider.php')
        );

        $providers = [
            ModuleRouteServiceProvider::class,
            PluginRouteServiceProvider::class,
        ];

        foreach ($providers as $providerClass) {
            $provider = $this->app->make($providerClass, ['app' => $this->app]);
            $method = new \ReflectionMethod($provider, 'convertDirectoryToNamespace');

            $this->assertSame(
                'Sirsoft\DaumPostcode',
                $method->invoke($provider, 'sirsoft-daum_postcode'),
                "{$providerClass}::convertDirectoryToNamespace()가 언더스코어를 올바르게 처리해야 합니다"
            );

            $this->assertSame(
                'Sirsoft\MyFancyModule',
                $method->invoke($provider, 'sirsoft-my_fancy_module'),
                "{$providerClass}::convertDirectoryToNamespace()가 다중 언더스코어를 올바르게 처리해야 합니다"
            );
        }
    }

    /**
     * validateIdentifierFormat이 유효한 식별자를 통과시키는지 테스트
     *
     * @scenario subject=identifier_validation
     *
     * @effects valid_identifier_accepted
     *
     * @return void 반환값 없음
     */
    public function test_validate_identifier_format_passes_valid(): void
    {
        // 예외가 발생하지 않으면 통과
        ExtensionManager::validateIdentifierFormat('sirsoft-board');
        ExtensionManager::validateIdentifierFormat('sirsoft-daum_postcode');
        ExtensionManager::validateIdentifierFormat('sirsoft-board2');
        ExtensionManager::validateIdentifierFormat('my_vendor-my_module');

        $this->assertTrue(true); // 예외 없이 도달하면 성공
    }

    /**
     * validateIdentifierFormat이 잘못된 식별자에 예외를 발생시키는지 테스트
     *
     * @scenario subject=identifier_validation
     *
     * @effects invalid_identifier_rejected
     *
     * @return void 반환값 없음
     */
    public function test_validate_identifier_format_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExtensionManager::validateIdentifierFormat('sirsoftboard'); // 하이픈 없음
    }

    /**
     * validateIdentifierFormat이 숫자 시작 단어를 거부하는지 테스트
     *
     * @scenario subject=identifier_validation
     *
     * @effects invalid_identifier_rejected
     *
     * @return void 반환값 없음
     */
    public function test_validate_identifier_format_rejects_digit_start(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExtensionManager::validateIdentifierFormat('sirsoft-2shop');
    }

    /**
     * validateIdentifierFormat이 대문자를 거부하는지 테스트
     *
     * @scenario subject=identifier_validation
     *
     * @effects invalid_identifier_rejected
     *
     * @return void 반환값 없음
     */
    public function test_validate_identifier_format_rejects_uppercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExtensionManager::validateIdentifierFormat('Sirsoft-Board');
    }

    /**
     * [회귀 #132] 실제 설치된 sirsoft-daum_postcode 플러그인의 네임스페이스 해석 검증
     *
     * PluginManager가 loadPlugins()로 플러그인을 로드할 때 생성하는 클래스 경로가
     * 실제 plugin.php의 네임스페이스 선언과 일치하는지 end-to-end 검증
     *
     * @scenario subject=installed_plugin
     *
     * @effects installed_plugin_namespace_matches_class
     *
     * @return void 반환값 없음
     */
    public function test_regression_installed_plugin_namespace_matches_actual_class(): void
    {
        $pluginDir = base_path('plugins/sirsoft-daum_postcode');

        if (! is_dir($pluginDir)) {
            $this->markTestSkipped('sirsoft-daum_postcode 플러그인이 설치되어 있지 않습니다');
        }

        // PluginManager가 생성하는 네임스페이스 경로
        $manager = $this->app->make(PluginManager::class);
        $method = new \ReflectionMethod($manager, 'convertDirectoryToNamespace');
        $namespace = $method->invoke($manager, 'sirsoft-daum_postcode');
        $expectedClass = "Plugins\\{$namespace}\\Plugin";

        // Laravel 부팅 시 PluginServiceProvider가 이미 플러그인을 로드하므로
        // class_exists로 클래스가 올바르게 해석되었는지 검증
        $this->assertTrue(
            class_exists($expectedClass),
            "PluginManager가 생성한 클래스 경로 '{$expectedClass}'가 실제 플러그인 클래스와 일치해야 합니다"
        );

        // 실제 클래스의 네임스페이스가 기대값과 일치하는지 추가 검증
        $reflection = new \ReflectionClass($expectedClass);
        $this->assertSame(
            'Plugins\Sirsoft\DaumPostcode',
            $reflection->getNamespaceName(),
            '실제 플러그인 네임스페이스가 Plugins\Sirsoft\DaumPostcode여야 합니다'
        );
    }
}
