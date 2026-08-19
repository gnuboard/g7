<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
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
     * getAppConfigForFrontend()가 core.frontend.filter_app_config 훅으로 확장이 주입한 값을
     * appConfig 에 반영하는지 테스트합니다. (예: 이커머스 모듈이 요청 기기 유형 isIos 를 주입)
     *
     * 체크아웃 브랜드 마크 시나리오의 iOS 게이팅 체인 중 "모듈이 감지한 기기 정보가 코어
     * appConfig 를 거쳐 프론트로 전달되는" 구간을 이 테스트가 떠받친다.
     *
     * @scenario requires_ios=true, device=ios
     *
     * @effects is_ios_flows_to_global_appconfig
     */
    public function test_get_app_config_applies_frontend_filter_hook(): void
    {
        // 우선순위 100 — 이커머스 모듈이 등록한 InjectAppConfigDeviceListener(priority 20)보다
        // 뒤에 실행되어, 전체 스위트에서 그 리스너가 함께 등록돼 있어도 이 테스트의 주입값이
        // 최종적으로 반영됨을 검증한다(훅 적용 메커니즘 자체 검증, 스위트 순서 비의존).
        HookManager::addFilter(
            'core.frontend.filter_app_config',
            function (array $appConfig): array {
                $appConfig['isIos'] = true;
                $appConfig['__testHookMarker'] = 'applied';

                return $appConfig;
            },
            100
        );

        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $this->assertTrue($result['isIos']);
        // 훅이 실제로 적용됐음을 별도 마커로도 확인(다른 리스너 간섭과 무관).
        $this->assertSame('applied', $result['__testHookMarker'] ?? null);
        // 기존 정적 값은 그대로 유지된다.
        $this->assertArrayHasKey('supportedLocales', $result);
    }

    /**
     * config/frontend.php의 app_config 스키마가 비면 config 파생 키가 없는지 테스트합니다.
     *
     * 참고: core.frontend.filter_app_config 훅으로 확장(예: 이커머스 isIos)이 값을 주입할 수
     * 있으므로 결과가 완전히 비어 있음을 단정하지 않고, config 스키마 파생 키의 부재만 확인한다.
     */
    public function test_get_app_config_has_no_config_derived_keys_when_schema_empty(): void
    {
        // config 스키마를 비움
        config(['frontend.app_config' => []]);

        $service = app(SettingsService::class);

        $result = $service->getAppConfigForFrontend();

        $this->assertIsArray($result);
        // 스키마 파생 키(config_key 기반)는 없어야 한다.
        $this->assertArrayNotHasKey('supportedTimezones', $result);
        $this->assertArrayNotHasKey('supportedLocales', $result);
        $this->assertArrayNotHasKey('version', $result);
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
