<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\Tosspayments\Listeners\RegisterPgProviderListener;

/**
 * RegisterPgProviderListener 단위 테스트
 */
class RegisterPgProviderListenerTest extends TestCase
{
    private RegisterPgProviderListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new RegisterPgProviderListener();
    }

    /**
     * getSubscribedHooks가 올바른 훅 매핑을 반환하는지 확인
     */
    public function test_get_subscribed_hooks_returns_correct_hooks(): void
    {
        $hooks = RegisterPgProviderListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.payment.registered_pg_providers', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.payment.get_client_config', $hooks);

        // filter 타입 확인
        $this->assertEquals('filter', $hooks['sirsoft-ecommerce.payment.registered_pg_providers']['type']);
        $this->assertEquals('filter', $hooks['sirsoft-ecommerce.payment.get_client_config']['type']);

        // 메서드명 확인
        $this->assertEquals('registerProvider', $hooks['sirsoft-ecommerce.payment.registered_pg_providers']['method']);
        $this->assertEquals('getClientConfig', $hooks['sirsoft-ecommerce.payment.get_client_config']['method']);
    }

    /**
     * registerProvider가 토스페이먼츠를 제공자 목록에 추가하는지 확인
     */
    public function test_register_provider_adds_tosspayments(): void
    {
        $existingProviders = [
            ['id' => 'other_pg', 'name' => ['ko' => '기타 PG'], 'icon' => 'wallet'],
        ];

        $result = $this->listener->registerProvider($existingProviders);

        $this->assertCount(2, $result);

        $toss = $result[1];
        $this->assertEquals('tosspayments', $toss['id']);
        // 7.0.0-beta.4+ : registry payload name_key 계약
        $this->assertEquals('sirsoft-tosspayments::provider.name', $toss['name_key']);
        // name 은 활성 locale 로 해석된 문자열 (Container 미초기화 단위 환경에서는 빈 문자열)
        $this->assertIsString($toss['name']);
        $this->assertEquals('credit-card', $toss['icon']);
        $this->assertContains('card', $toss['supported_methods']);
    }

    /**
     * registerProvider 페이로드가 name_key (lang key) 를 보유해 활성 언어팩 보강 가능한지 확인.
     */
    public function test_register_provider_carries_name_key_for_lang_pack_resolution(): void
    {
        $result = $this->listener->registerProvider([]);
        $this->assertArrayHasKey('name_key', $result[0]);
        $this->assertSame('sirsoft-tosspayments::provider.name', $result[0]['name_key']);
    }

    /**
     * registerProvider가 기존 제공자를 유지하는지 확인
     */
    public function test_register_provider_preserves_existing_providers(): void
    {
        $existingProviders = [
            ['id' => 'pg_a', 'name' => ['ko' => 'PG A']],
            ['id' => 'pg_b', 'name' => ['ko' => 'PG B']],
        ];

        $result = $this->listener->registerProvider($existingProviders);

        $this->assertCount(3, $result);
        $this->assertEquals('pg_a', $result[0]['id']);
        $this->assertEquals('pg_b', $result[1]['id']);
        $this->assertEquals('tosspayments', $result[2]['id']);
    }

    /**
     * registerProvider가 빈 배열에도 동작하는지 확인
     */
    public function test_register_provider_works_with_empty_array(): void
    {
        $result = $this->listener->registerProvider([]);

        $this->assertCount(1, $result);
        $this->assertEquals('tosspayments', $result[0]['id']);
    }

    /**
     * getClientConfig가 다른 provider에 대해 원본 config를 반환하는지 확인
     */
    public function test_get_client_config_ignores_other_providers(): void
    {
        $config = ['some' => 'value'];
        $result = $this->listener->getClientConfig($config, 'inicis');

        $this->assertEquals($config, $result);
    }
}
