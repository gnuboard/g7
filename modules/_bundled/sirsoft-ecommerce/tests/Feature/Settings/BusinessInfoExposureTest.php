<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Settings;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Listeners\StripBusinessInfoWhenHiddenListener;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사업자 정보 공개 + 켜고 끄기 테스트
 *
 * 사용자 화면 푸터(쇼핑몰 사업자 정보 조각, user_global_footer)가 그리는 사업자 항목만 공개 설정으로 내보내고,
 * 운영자가 표시(show_business_footer)를 끄면 응답(페이지 소스의 공개 설정 포함)에서 사업자 칸을 모두 뺀다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/business-info-footer-exposure.yaml
 */
class BusinessInfoExposureTest extends ModuleTestCase
{
    /** 푸터 조각이 그리는 사업자 항목 */
    private const LEGAL_KEYS = [
        'company_name', 'ceo_name', 'zipcode', 'base_address', 'detail_address',
        'phone', 'fax', 'email', 'business_number', 'mail_order_number', 'telecom_number',
        'privacy_officer', 'privacy_officer_email', 'hosting_provider',
    ];

    /** 공개하지 않는 항목 */
    private const PRIVATE_KEYS = ['business_type', 'business_category', 'public_asset_disk', 'shop_name'];

    /**
     * 사업자 정보를 채워 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(EcommerceSettingsService::class);
        foreach ([
            'company_name' => '시험 상사',
            'ceo_name' => '홍길동',
            'zipcode' => '06236',
            'base_address' => '서울시 강남구 테헤란로 1',
            'detail_address' => '10층',
            'phone' => '02-123-4567',
            'fax' => '02-123-4568',
            'email' => 'help@example.com',
            'business_number' => '123-45-67890',
            'mail_order_number' => '2026-서울강남-0001',
            'telecom_number' => 'T-0001',
            'privacy_officer' => '김책임',
            'privacy_officer_email' => 'privacy@example.com',
            'hosting_provider' => '시험 호스팅',
            'business_type' => '시험 업태',
            'business_category' => '시험 종목',
        ] as $key => $value) {
            $settings->setSetting('basic_info.'.$key, $value);
        }
    }

    /**
     * 기본(표시 켬)에서는 푸터 조각이 그리는 사업자 항목만 공개 설정에 실린다.
     *
     * @scenario path=module_frontend_settings, show_business_footer=on
     *
     * @effects legal_business_fields_exposed, private_business_fields_not_exposed, footer_switch_defaults_on
     *
     * @return void 반환값 없음
     */
    public function test_legal_fields_are_exposed_by_default(): void
    {
        $basicInfo = app(EcommerceSettingsService::class)->getFrontendSettings()['basic_info'];

        $this->assertTrue($basicInfo['show_business_footer']);
        foreach (self::LEGAL_KEYS as $key) {
            $this->assertArrayHasKey($key, $basicInfo, $key);
        }
        $this->assertSame('시험 상사', $basicInfo['company_name']);
        $this->assertSame('시험 호스팅', $basicInfo['hosting_provider']);
        foreach (self::PRIVATE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $basicInfo, $key);
        }
        $this->assertSame('02-123-4568', $basicInfo['fax']);
        $this->assertSame('privacy@example.com', $basicInfo['privacy_officer_email']);
        $this->assertStringNotContainsString('시험 업태', json_encode($basicInfo, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('footer_business_info_enabled', $basicInfo, '켜기 설정은 show_business_footer 하나');
    }

    /**
     * 표시를 끄면 사업자 칸이 하나도 나가지 않고 경로 설정은 남는다.
     *
     * @scenario path=module_frontend_settings, show_business_footer=off
     *
     * @effects business_fields_stripped_when_hidden, route_fields_kept_when_hidden
     *
     * @return void 반환값 없음
     */
    public function test_business_fields_are_stripped_when_footer_is_hidden(): void
    {
        app(EcommerceSettingsService::class)->setSetting('basic_info.show_business_footer', false);

        $basicInfo = app(EcommerceSettingsService::class)->getFrontendSettings()['basic_info'];

        $this->assertFalse($basicInfo['show_business_footer']);
        $this->assertSame('shop', $basicInfo['route_path']);
        $this->assertArrayHasKey('no_route', $basicInfo);
        $this->assertNoBusinessValues($basicInfo);
    }

    /**
     * 코어 공개 설정 필터 훅 리스너가 같은 규칙으로 이커머스 설정만 손본다.
     *
     * @scenario path=core_filter_hook, show_business_footer=off
     *
     * @effects core_filter_strips_business_fields, core_filter_ignores_other_extensions, core_filter_listener_registered
     *
     * @return void 반환값 없음
     */
    public function test_core_frontend_filter_listener_applies_the_same_rule(): void
    {
        $listener = app(StripBusinessInfoWhenHiddenListener::class);
        $exposed = [
            'basic_info' => [
                'route_path' => 'shop',
                'no_route' => false,
                'show_business_footer' => false,
                'company_name' => '시험 상사',
                'phone' => '02-123-4567',
                'business_number' => '123-45-67890',
            ],
            'mileage' => ['enabled' => true],
        ];

        $filtered = $listener->filterFrontendSettings($exposed, 'sirsoft-ecommerce');
        $this->assertNoBusinessValues($filtered['basic_info']);
        $this->assertSame('shop', $filtered['basic_info']['route_path']);
        $this->assertSame(['enabled' => true], $filtered['mileage']);

        // 다른 확장의 설정은 건드리지 않는다
        $this->assertSame($exposed, $listener->filterFrontendSettings($exposed, 'another-module'));

        // 표시가 켜져 있으면 그대로
        $exposed['basic_info']['show_business_footer'] = true;
        $this->assertSame($exposed, $listener->filterFrontendSettings($exposed, 'sirsoft-ecommerce'));

        $this->assertSame(
            ['core.module_settings.filter_frontend_settings'],
            array_keys(StripBusinessInfoWhenHiddenListener::getSubscribedHooks())
        );
        $this->assertContains(
            StripBusinessInfoWhenHiddenListener::class,
            app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getHookListeners()
        );
    }

    /**
     * 훅을 거친 결과도 같다 (리스너를 필터에 걸어 코어가 부르는 모양 그대로).
     *
     * @scenario path=core_filter_hook, show_business_footer=on
     *
     * @effects core_filter_keeps_business_fields_when_shown
     *
     * @return void 반환값 없음
     */
    public function test_filter_hook_round_trip_keeps_fields_when_shown(): void
    {
        $listener = app(StripBusinessInfoWhenHiddenListener::class);
        HookManager::addFilter('core.module_settings.filter_frontend_settings', [$listener, 'filterFrontendSettings']);

        $exposed = ['basic_info' => ['route_path' => 'shop', 'show_business_footer' => true, 'company_name' => '시험 상사']];

        $this->assertSame(
            $exposed,
            HookManager::applyFilters('core.module_settings.filter_frontend_settings', $exposed, 'sirsoft-ecommerce')
        );

        HookManager::clearFilter('core.module_settings.filter_frontend_settings');
    }

    // 이 시험은 켬·끔을 차례로 함께 잰다 (docblock 은 @scenario 를 1건만 인식하므로 끔 조합은 라인 주석으로 병기).
    // @scenario path=core_collect, show_business_footer=off

    /**
     * 코어가 사용자 화면 전역 설정(G7Config.modules)을 모으는 경로 그대로 — 켬이면 조각이 그리는 사업자 칸만, 끔이면 사업자 칸 0개.
     *
     * @scenario path=core_collect, show_business_footer=on
     *
     * @effects core_collect_exposes_legal_fields_only, core_collect_strips_business_fields_when_hidden
     *
     * @return void 반환값 없음
     */
    public function test_core_collected_frontend_settings_follow_the_footer_switch(): void
    {
        $listener = app(StripBusinessInfoWhenHiddenListener::class);
        HookManager::addFilter('core.module_settings.filter_frontend_settings', [$listener, 'filterFrontendSettings']);

        try {
            $collect = fn (): array => app(ModuleSettingsService::class)->getAllActiveSettings()['sirsoft-ecommerce']['basic_info'] ?? [];

            $shown = $collect();
            foreach (self::LEGAL_KEYS as $key) {
                $this->assertArrayHasKey($key, $shown, "켬: {$key} 가 코어 수집 결과에 없다");
            }
            foreach (self::PRIVATE_KEYS as $key) {
                $this->assertArrayNotHasKey($key, $shown, "켬: {$key} 는 공개하지 않는다");
            }

            app(EcommerceSettingsService::class)->setSetting('basic_info.show_business_footer', false);

            $hidden = $collect();
            $this->assertNoBusinessValues($hidden);
            $this->assertArrayHasKey('route_path', $hidden);
        } finally {
            HookManager::clearFilter('core.module_settings.filter_frontend_settings');
        }
    }

    // 이 시험은 켬·끔 두 값을 함께 잰다 (docblock 은 @scenario 를 1건만 인식하므로 끔 조합은 라인 주석으로 병기).
    // @scenario path=settings_save, show_business_footer=off

    /**
     * 저장 규칙이 호스팅 제공자·표시 여부 두 칸을 받는다(켬·끔 모두 통과).
     *
     * @scenario path=settings_save, show_business_footer=on
     *
     * @effects settings_save_accepts_new_fields
     *
     * @return void 반환값 없음
     */
    public function test_save_rules_accept_hosting_provider_and_footer_switch(): void
    {
        $data = ['basic_info' => ['hosting_provider' => str_repeat('가', 256), 'show_business_footer' => 'maybe']];
        $request = StoreEcommerceSettingsRequest::create('/', 'PUT', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $rules = array_intersect_key($request->rules(), array_flip(['basic_info.hosting_provider', 'basic_info.show_business_footer']));

        $this->assertCount(2, $rules);
        $errors = Validator::make($data, $rules)->errors();
        $this->assertTrue($errors->has('basic_info.hosting_provider'));
        $this->assertTrue($errors->has('basic_info.show_business_footer'));

        foreach ([true, false] as $shown) {
            $ok = ['basic_info' => ['hosting_provider' => '시험 호스팅', 'show_business_footer' => $shown]];
            $this->assertTrue(Validator::make($ok, $rules)->passes());
        }

        // 같은 뜻의 두 번째 토글은 규칙에 없다
        $this->assertArrayNotHasKey('basic_info.footer_business_info_enabled', $request->rules());
    }

    /**
     * 사업자 값이 하나도 남지 않았는지 확인합니다 (분리 칸 포함).
     *
     * @param  array<string, mixed>  $basicInfo  공개 기본 정보
     * @return void 반환값 없음
     */
    private function assertNoBusinessValues(array $basicInfo): void
    {
        foreach (self::LEGAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $basicInfo, $key);
        }

        $json = json_encode($basicInfo, JSON_UNESCAPED_UNICODE);
        foreach (['시험 상사', '홍길동', '02-123-4567', '02-123-4568', '123', 'help@example.com', 'T-0001', '김책임', 'privacy@example.com', '시험 호스팅', '서울강남'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
    }
}
