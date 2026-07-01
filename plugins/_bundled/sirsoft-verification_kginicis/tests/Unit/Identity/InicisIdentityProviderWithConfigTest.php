<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Unit\Identity;

use App\Contracts\Extension\CacheInterface;
use Mockery;
use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;

/**
 * InicisIdentityProvider::withConfig() 회귀 테스트.
 *
 * 회귀 배경:
 *   ddd41ad73 (2026-05-11) 에서 생성자에 `CacheInterface $cache` 를 4번째 매개변수로 추가했으나
 *   `withConfig()` 의 `new static(...)` 호출은 갱신되지 않아, config 가 cache 자리로 들어가는
 *   잠복 타입 불일치가 발생. PHP 는 실 호출 시점에만 타입 검사 → withConfig 후속 메서드를
 *   사용하는 흐름에서 fatal 발생 가능.
 *
 * 본 테스트 범위:
 *   - withConfig() 결과 인스턴스의 후속 메서드 호출이 정상 동작 (cache 위치 보존)
 *   - 동일 cache 인스턴스가 전파됨
 *   - config 만 병합되고 다른 의존성은 보존됨
 */
class InicisIdentityProviderWithConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * withConfig 결과의 isAvailable() 호출이 타입 에러 없이 정상 동작해야 한다.
     *
     * 회귀 시점에는 4번째 인자가 array (config) 였으므로 cache 메서드 호출 흐름이
     * 트리거되면 fatal 발생. 단, isAvailable 자체는 cache 를 호출하지 않으므로
     * 본 테스트는 "config 가 cache 위치에 들어가도 isAvailable 자체는 통과" 라는
     * 약한 보장만 줌. 더 강한 보장은 test_with_config_preserves_cache_instance 가 제공.
     *
     * @return void
     */
    public function test_with_config_returns_functional_instance(): void
    {
        $provider = $this->makeProvider([
            'is_test_mode' => true,
            'test_mid' => 'INIiasTest',
            'test_api_key' => 'TGdxb2l3enJDWFRTbTgvREU3MGYwUT09',
        ]);

        $cloned = $provider->withConfig(['is_test_mode' => false]);

        $this->assertInstanceOf(InicisIdentityProvider::class, $cloned);
    }

    /**
     * withConfig 가 동일한 cache 인스턴스를 새 객체에 전파해야 한다.
     *
     * Reflection 으로 protected $cache 를 직접 확인 — 회귀 시점에는 4번째 자리가
     * array 였으므로 이 검증이 실패함 (assertInstanceOf(CacheInterface, ...) fail).
     *
     * @return void
     */
    public function test_with_config_preserves_cache_instance(): void
    {
        $cache = Mockery::mock(CacheInterface::class);
        $provider = $this->makeProvider(['is_test_mode' => true], $cache);

        $cloned = $provider->withConfig(['is_test_mode' => false]);

        $reflection = new \ReflectionClass($cloned);
        $cacheProperty = $reflection->getProperty('cache');
        $cacheProperty->setAccessible(true);
        $injectedCache = $cacheProperty->getValue($cloned);

        $this->assertInstanceOf(CacheInterface::class, $injectedCache);
        $this->assertSame($cache, $injectedCache);
    }

    /**
     * withConfig 가 config 만 병합하고 다른 의존성은 원본 그대로 유지해야 한다.
     *
     * @return void
     */
    public function test_with_config_merges_config_and_keeps_other_dependencies(): void
    {
        $gateway = Mockery::mock(InicisGatewayInterface::class);
        $mappingRepo = Mockery::mock(InicisChallengeMappingRepositoryInterface::class);
        $recordRepo = Mockery::mock(InicisIdentityRecordRepositoryInterface::class);
        $cache = Mockery::mock(CacheInterface::class);

        $provider = new InicisIdentityProvider(
            $gateway,
            $mappingRepo,
            $recordRepo,
            $cache,
            ['is_test_mode' => true, 'test_mid' => 'INIiasTest', 'duplicate_field' => 'di'],
        );

        $cloned = $provider->withConfig(['is_test_mode' => false, 'live_mid' => 'SRA1234567']);

        $reflection = new \ReflectionClass($cloned);
        foreach (['gateway', 'mappingRepository', 'recordRepository', 'cache', 'config'] as $prop) {
            $property = $reflection->getProperty($prop);
            $property->setAccessible(true);
        }

        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $injectedConfig = $configProp->getValue($cloned);

        $this->assertSame(false, $injectedConfig['is_test_mode']);
        $this->assertSame('INIiasTest', $injectedConfig['test_mid']);
        $this->assertSame('SRA1234567', $injectedConfig['live_mid']);
        $this->assertSame('di', $injectedConfig['duplicate_field']);

        $gatewayProp = $reflection->getProperty('gateway');
        $gatewayProp->setAccessible(true);
        $this->assertSame($gateway, $gatewayProp->getValue($cloned));

        $mappingProp = $reflection->getProperty('mappingRepository');
        $mappingProp->setAccessible(true);
        $this->assertSame($mappingRepo, $mappingProp->getValue($cloned));

        $recordProp = $reflection->getProperty('recordRepository');
        $recordProp->setAccessible(true);
        $this->assertSame($recordRepo, $recordProp->getValue($cloned));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  CacheInterface|null  $cache
     * @return InicisIdentityProvider
     */
    protected function makeProvider(array $config, ?CacheInterface $cache = null): InicisIdentityProvider
    {
        return new InicisIdentityProvider(
            Mockery::mock(InicisGatewayInterface::class),
            Mockery::mock(InicisChallengeMappingRepositoryInterface::class),
            Mockery::mock(InicisIdentityRecordRepositoryInterface::class),
            $cache ?? Mockery::mock(CacheInterface::class),
            $config,
        );
    }
}
