<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature;

use App\Extension\Cache\PluginCacheDriver;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Listeners\CompleteInicisRecordAfterRegister;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * Inicis 본인인증 플러그인의 contextual binding 정확성 검증.
 *
 * InicisVerificationServiceProvider 의 $cacheServices 배열에 등록된
 * 클래스들이 본 플러그인 도메인 (`g7:plugin.sirsoft-verification_kginicis:*`) 의
 * PluginCacheDriver 를 contextual binding 으로 정확히 수신하는지 검증한다.
 *
 * 인스턴스 타입만 확인하면 다른 식별자의 PluginCacheDriver 가 주입되어도 통과되는
 * 결함이 남으므로, 실제 키 prefix 까지 단언한다.
 *
 * @see tests/scenarios/extension-cache-binding-isolation.yaml (축 2)
 */
class PluginCacheContextualBindingTest extends PluginTestCase
{
    /**
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=installed_before_provider,manager_state=plugin_resolved,consumer_type=single_class
     * @effects cache_service_receives_plugin_cache_driver_with_correct_identifier_prefix, inicis_identity_provider_receives_correctly_prefixed_plugin_cache
     */
    public function test_inicis_identity_provider_receives_plugin_scoped_cache(): void
    {
        $provider = $this->app->makeWith(InicisIdentityProvider::class, [
            'config' => [],
        ]);

        $cache = (new \ReflectionProperty($provider, 'cache'))->getValue($provider);

        $this->assertInstanceOf(PluginCacheDriver::class, $cache);

        $this->assertStringStartsWith(
            'g7:plugin.sirsoft-verification_kginicis:',
            $cache->resolveKey('test_key'),
            'InicisIdentityProvider 의 캐시 키 prefix 가 본 플러그인 식별자가 아니다.'
        );
    }

    /**
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=installed_before_provider,manager_state=plugin_resolved,consumer_type=multiple_classes
     * @effects cache_service_receives_plugin_cache_driver_with_correct_identifier_prefix
     */
    public function test_complete_record_listener_receives_plugin_scoped_cache(): void
    {
        $listener = $this->app->make(CompleteInicisRecordAfterRegister::class);

        $cache = (new \ReflectionProperty($listener, 'cache'))->getValue($listener);

        $this->assertInstanceOf(PluginCacheDriver::class, $cache);
        $this->assertStringStartsWith(
            'g7:plugin.sirsoft-verification_kginicis:',
            $cache->resolveKey('any_key'),
        );
    }
}
