<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use Tests\PureTestCase;

/**
 * ExtensionManager::resolveExtensionByFqcn() 단위 테스트
 *
 * FQCN → 모듈/플러그인 식별자 역변환 동작을 검증합니다.
 * directoryToNamespace() 의 역연산이며, 모듈 lang fallback 의 origin 식별 SSoT.
 */
class ExtensionManagerResolveByFqcnTest extends PureTestCase
{
    /**
     * Modules\Vendor\Name\... 형태의 FQCN 을 vendor-name 케밥 식별자로 되돌린다.
     *
     * @effects extension_manager_resolves_modules_namespace_to_kebab_identifier
     *
     * @return void 반환값 없음
     */
    public function test_resolves_module_fqcn_to_kebab_identifier(): void
    {
        $this->assertSame(
            'sirsoft-ecommerce',
            ExtensionManager::resolveExtensionByFqcn('Modules\\Sirsoft\\Ecommerce\\Models\\Order'),
        );
    }

    /**
     * Plugins\Vendor\Name\... 형태의 FQCN 을 vendor-name 케밥 식별자로 되돌린다.
     *
     * @effects extension_manager_resolves_plugins_namespace_to_kebab_identifier
     *
     * @return void 반환값 없음
     */
    public function test_resolves_plugin_fqcn_to_kebab_identifier(): void
    {
        $this->assertSame(
            'sirsoft-payment',
            ExtensionManager::resolveExtensionByFqcn('Plugins\\Sirsoft\\Payment\\Services\\PaymentService'),
        );
    }

    /**
     * 이름 세그먼트의 PascalCase 내부 단어 경계(DaumPostcode)는 밑줄(daum_postcode)로 복원한다.
     *
     * @effects extension_manager_handles_internal_underscore_via_pascal_to_snake
     *
     * @return void 반환값 없음
     */
    public function test_preserves_underscore_for_pascal_case_with_internal_word_boundary(): void
    {
        $this->assertSame(
            'sirsoft-daum_postcode',
            ExtensionManager::resolveExtensionByFqcn('Modules\\Sirsoft\\DaumPostcode\\Models\\Address'),
        );
    }

    /**
     * 코어 App\ 네임스페이스 클래스는 확장이 아니므로 null 을 돌려준다.
     *
     * @effects extension_manager_returns_null_for_app_namespace
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_for_core_app_namespace(): void
    {
        $this->assertNull(ExtensionManager::resolveExtensionByFqcn('App\\Models\\User'));
    }

    /**
     * Modules·Plugins 가 아닌 임의 최상위 네임스페이스는 null 을 돌려준다.
     *
     * @effects extension_manager_returns_null_for_unknown_namespace
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_for_unknown_namespace(): void
    {
        $this->assertNull(ExtensionManager::resolveExtensionByFqcn('Vendor\\Anything\\Foo'));
    }

    /**
     * vendor·name 두 세그먼트가 모자란 Modules\Foo 는 식별자를 만들 수 없어 null 을 돌려준다.
     *
     * @effects extension_manager_returns_null_for_too_short_namespace
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_for_short_module_namespace(): void
    {
        // 'Modules\Foo' 만으로는 vendor-name 두 세그먼트 부족
        $this->assertNull(ExtensionManager::resolveExtensionByFqcn('Modules\\Foo'));
    }

    /**
     * 앞에 역슬래시가 붙은 전역 FQCN 도 같은 식별자로 되돌린다.
     *
     * @effects extension_manager_ignores_leading_backslash
     *
     * @return void 반환값 없음
     */
    public function test_handles_leading_backslash(): void
    {
        $this->assertSame(
            'sirsoft-ecommerce',
            ExtensionManager::resolveExtensionByFqcn('\\Modules\\Sirsoft\\Ecommerce\\Models\\Order'),
        );
    }

    /**
     * 빈 문자열 FQCN 은 null 을 돌려준다.
     *
     * @effects extension_manager_returns_null_for_empty_fqcn
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull(ExtensionManager::resolveExtensionByFqcn(''));
    }

    /**
     * directoryToNamespace() 로 만든 네임스페이스를 되돌리면 원래 식별자가 나온다(정확한 역연산).
     *
     * @effects extension_manager_round_trips_directory_to_namespace
     *
     * @return void 반환값 없음
     */
    public function test_round_trip_with_directory_to_namespace(): void
    {
        // directoryToNamespace 의 정확한 역연산 검증
        $identifier = 'sirsoft-ecommerce';
        $namespace = 'Modules\\'.ExtensionManager::directoryToNamespace($identifier).'\\Models\\Foo';
        $this->assertSame($identifier, ExtensionManager::resolveExtensionByFqcn($namespace));
    }
}
