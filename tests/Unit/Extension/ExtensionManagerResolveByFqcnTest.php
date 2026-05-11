<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use Tests\TestCase;

/**
 * ExtensionManager::resolveExtensionByFqcn() 단위 테스트
 *
 * FQCN → 모듈/플러그인 식별자 역변환 동작을 검증합니다.
 * directoryToNamespace() 의 역연산이며, 모듈 lang fallback 의 origin 식별 SSoT.
 */
class ExtensionManagerResolveByFqcnTest extends TestCase
{
    public function test_resolves_module_fqcn_to_kebab_identifier(): void
    {
        $this->assertSame(
            'sirsoft-ecommerce',
            ExtensionManager::resolveExtensionByFqcn('Modules\\Sirsoft\\Ecommerce\\Models\\Order'),
        );
    }

    public function test_resolves_plugin_fqcn_to_kebab_identifier(): void
    {
        $this->assertSame(
            'sirsoft-payment',
            ExtensionManager::resolveExtensionByFqcn('Plugins\\Sirsoft\\Payment\\Services\\PaymentService'),
        );
    }

    public function test_preserves_underscore_for_pascal_case_with_internal_word_boundary(): void
    {
        $this->assertSame(
            'sirsoft-daum_postcode',
            ExtensionManager::resolveExtensionByFqcn('Modules\\Sirsoft\\DaumPostcode\\Models\\Address'),
        );
    }

    public function test_returns_null_for_core_app_namespace(): void
    {
        $this->assertNull(ExtensionManager::resolveExtensionByFqcn('App\\Models\\User'));
    }

    public function test_returns_null_for_unknown_namespace(): void
    {
        $this->assertNull(ExtensionManager::resolveExtensionByFqcn('Vendor\\Anything\\Foo'));
    }

    public function test_returns_null_for_short_module_namespace(): void
    {
        // 'Modules\Foo' 만으로는 vendor-name 두 세그먼트 부족
        $this->assertNull(ExtensionManager::resolveExtensionByFqcn('Modules\\Foo'));
    }

    public function test_handles_leading_backslash(): void
    {
        $this->assertSame(
            'sirsoft-ecommerce',
            ExtensionManager::resolveExtensionByFqcn('\\Modules\\Sirsoft\\Ecommerce\\Models\\Order'),
        );
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull(ExtensionManager::resolveExtensionByFqcn(''));
    }

    public function test_round_trip_with_directory_to_namespace(): void
    {
        // directoryToNamespace 의 정확한 역연산 검증
        $identifier = 'sirsoft-ecommerce';
        $namespace = 'Modules\\'.ExtensionManager::directoryToNamespace($identifier).'\\Models\\Foo';
        $this->assertSame($identifier, ExtensionManager::resolveExtensionByFqcn($namespace));
    }
}
