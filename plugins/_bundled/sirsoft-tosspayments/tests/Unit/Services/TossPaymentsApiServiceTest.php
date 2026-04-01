<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * TossPaymentsApiService 단위 테스트
 */
class TossPaymentsApiServiceTest extends TestCase
{
    /**
     * PluginSettingsService mock을 생성합니다.
     *
     * @param array $settings 반환할 설정값
     * @return PluginSettingsService
     */
    private function mockSettingsService(array $settings): PluginSettingsService
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')
            ->with('sirsoft-tosspayments')
            ->willReturn($settings);

        return $mock;
    }

    /**
     * 테스트 모드에서 테스트 시크릿 키를 사용하는지 확인
     */
    public function test_constructor_uses_test_secret_key_in_test_mode(): void
    {
        $service = new TossPaymentsApiService($this->mockSettingsService([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_abc123',
            'live_secret_key' => 'live_sk_xyz789',
        ]));

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('secretKey');
        $prop->setAccessible(true);

        $this->assertEquals('test_sk_abc123', $prop->getValue($service));
    }

    /**
     * 라이브 모드에서 라이브 시크릿 키를 사용하는지 확인
     */
    public function test_constructor_uses_live_secret_key_in_live_mode(): void
    {
        $service = new TossPaymentsApiService($this->mockSettingsService([
            'is_test_mode' => false,
            'test_secret_key' => 'test_sk_abc123',
            'live_secret_key' => 'live_sk_xyz789',
        ]));

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('secretKey');
        $prop->setAccessible(true);

        $this->assertEquals('live_sk_xyz789', $prop->getValue($service));
    }

    /**
     * 설정이 비어있을 때 기본값(테스트 모드, 빈 키)으로 초기화되는지 확인
     */
    public function test_constructor_defaults_to_test_mode_with_empty_key(): void
    {
        $service = new TossPaymentsApiService($this->mockSettingsService([]));

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('secretKey');
        $prop->setAccessible(true);

        $this->assertEquals('', $prop->getValue($service));
    }

    /**
     * 플러그인 설정이 null일 때 기본값으로 초기화되는지 확인
     */
    public function test_constructor_handles_null_settings_gracefully(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')
            ->with('sirsoft-tosspayments')
            ->willReturn(null);

        $service = new TossPaymentsApiService($mock);

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('secretKey');
        $prop->setAccessible(true);

        $this->assertEquals('', $prop->getValue($service));
    }
}
