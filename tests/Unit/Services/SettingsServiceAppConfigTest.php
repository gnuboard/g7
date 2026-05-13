<?php

namespace Tests\Unit\Services;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SettingsService의 getAppConfigForFrontend() 메서드 테스트
 *
 * config/frontend.php 스키마를 기반으로 config() 값을 프론트엔드에 노출하는
 * 기능을 검증합니다.
 */
class SettingsServiceAppConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    /**
     * getAppConfigForFrontend()가 supportedTimezones를 올바른 Select 옵션 형식으로 반환하는지 테스트합니다.
     *
     * 전 IANA 타임존 지원 + 오프셋 라벨 형식
     */
    public function test_get_app_config_returns_supported_timezones(): void
    {
        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $this->assertArrayHasKey('supportedTimezones', $result);
        $this->assertIsArray($result['supportedTimezones']);
        $this->assertNotEmpty($result['supportedTimezones']);

        // 각 항목이 {value, label} 형식인지 확인
        foreach ($result['supportedTimezones'] as $item) {
            $this->assertIsArray($item);
            $this->assertArrayHasKey('value', $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertMatchesRegularExpression(
                '/^\(UTC[+-]\d{2}:\d{2}\) .+/',
                $item['label']
            );
        }

        // Asia/Seoul, UTC가 포함되는지 확인 (value 기준)
        $values = array_column($result['supportedTimezones'], 'value');
        $this->assertContains('Asia/Seoul', $values);
        $this->assertContains('UTC', $values);
    }

    /**
     * supportedTimezones가 IANA 전체 목록 크기와 일치하는지 테스트합니다.
     */
    public function test_supported_timezones_contains_full_iana_list(): void
    {
        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $this->assertCount(
            count(\DateTimeZone::listIdentifiers()),
            $result['supportedTimezones']
        );
    }

    /**
     * supportedTimezones가 오프셋 오름차순으로 정렬되는지 테스트합니다.
     */
    public function test_supported_timezones_sorted_by_offset_ascending(): void
    {
        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $prevOffset = null;
        foreach ($result['supportedTimezones'] as $item) {
            $offset = (new \DateTimeZone($item['value']))->getOffset($now);
            if ($prevOffset !== null) {
                $this->assertGreaterThanOrEqual($prevOffset, $offset);
            }
            $prevOffset = $offset;
        }
    }

    /**
     * getAppConfigForFrontend()가 supportedLocales를 올바르게 반환하는지 테스트합니다.
     */
    public function test_get_app_config_returns_supported_locales(): void
    {
        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $this->assertArrayHasKey('supportedLocales', $result);
        $this->assertIsArray($result['supportedLocales']);
        $this->assertContains('ko', $result['supportedLocales']);
        $this->assertContains('en', $result['supportedLocales']);
    }

    /**
     * config/frontend.php가 없는 경우 빈 배열을 반환하는지 테스트합니다.
     */
    public function test_get_app_config_returns_empty_when_no_config(): void
    {
        // config를 비움
        config(['frontend.app_config' => []]);

        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * config_key가 없는 필드 스키마는 무시되는지 테스트합니다.
     */
    public function test_get_app_config_skips_fields_without_config_key(): void
    {
        config(['frontend.app_config' => [
            'validKey' => [
                'config_key' => 'app.supported_timezones',
                'type' => 'array',
            ],
            'invalidKey' => [
                'type' => 'string',
                // config_key 누락
            ],
        ]]);

        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $this->assertArrayHasKey('validKey', $result);
        $this->assertArrayNotHasKey('invalidKey', $result);
    }

    /**
     * castValue가 올바른 타입으로 캐스팅하는지 테스트합니다.
     */
    public function test_get_app_config_casts_values_by_type(): void
    {
        config(['frontend.app_config' => [
            'arrayValue' => [
                'config_key' => 'app.supported_timezones',
                'type' => 'array',
            ],
        ]]);

        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $this->assertIsArray($result['arrayValue']);
    }
}
