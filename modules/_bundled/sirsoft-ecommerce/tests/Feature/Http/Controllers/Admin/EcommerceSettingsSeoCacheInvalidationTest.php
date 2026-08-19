<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Listeners\CoreActivityLogListener;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSettingRequest;
use Modules\Sirsoft\Ecommerce\Listeners\SeoSettingsCacheListener;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 모듈 설정 저장 시 `core.module_settings.after_save` 발화 (B-5)
 *
 * 이 훅을 구독하는 리스너가 셋(이커머스 SEO 캐시 / 게시판 SEO 캐시 / 코어 활동로그) 있는데,
 * 정작 발화 지점인 `ModuleSettingsService::save()` 는 프로덕션에서 호출되지 않는다.
 * 모듈 설정은 각 모듈의 관리자 컨트롤러가 자기 SettingsService 로 직접 저장하기 때문이다.
 * 그 결과 모듈 환경설정에서 SEO 메타 템플릿을 바꿔도 SEO 캐시가 무효화되지 않았다.
 *
 * 발화 지점을 관리자 컨트롤러로 둔다 — 서비스에 두면 테스트 fixture 의 모든 저장이
 * 활동로그·SEO 무효화를 유발하고, 훅 의미도 "관리자가 설정을 저장했다" 이다.
 */
class EcommerceSettingsSeoCacheInvalidationTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    /**
     * 훅 수신 기록 [[identifier, settings, result], ...]
     *
     * @var array<int, array>
     */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);

        $this->received = [];
        HookManager::addAction('core.module_settings.after_save', function (...$args) {
            $this->received[] = $args;
        }, 5);
    }

    /**
     * 설정 저장 API 가 모듈 설정 저장 훅을 발화한다. (실패-먼저)
     *
     * @scenario actor=admin, save_path=bulk
     *
     * @effects module_settings_after_save_hook_fired
     */
    public function test_settings_save_fires_module_settings_after_save_hook(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'seo',
            'seo' => ['meta_product_title' => '{product_name} | shop'],
        ])->assertOk();

        $this->assertCount(1, $this->received, '모듈 설정 저장 훅이 발화되지 않았습니다.');
        [$identifier, $settings, $result] = $this->received[0];

        $this->assertSame('sirsoft-ecommerce', $identifier);
        $this->assertArrayHasKey('seo', $settings, '훅 payload 가 저장된 카테고리를 담지 않았습니다.');
        $this->assertTrue($result);
    }

    /**
     * 은행 목록 저장도 같은 훅을 발화한다. (실패-먼저)
     *
     * @scenario actor=admin, save_path=save_banks
     *
     * @effects module_settings_after_save_hook_fired
     */
    public function test_bank_save_fires_module_settings_after_save_hook(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase.'/banks', [
            'banks' => [['code' => '004', 'name' => ['ko' => '국민은행', 'en' => 'Kookmin Bank']]],
        ])->assertOk();

        $this->assertCount(1, $this->received, '은행 저장이 모듈 설정 저장 훅을 발화하지 않았습니다.');
        [$identifier, $settings] = $this->received[0];

        $this->assertSame('sirsoft-ecommerce', $identifier);
        $this->assertArrayHasKey('order_settings', $settings);
    }

    /**
     * 저장 실패(검증 탈락) 시에는 훅이 발화되지 않는다.
     *
     * @scenario actor=admin, save_path=bulk, outcome=validation_failed
     *
     * @effects module_settings_after_save_hook_not_fired_on_failure
     */
    public function test_hook_is_not_fired_when_validation_fails(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['shipping_fee_tax_policy' => 'no_such_policy'],
        ])->assertStatus(422);

        $this->assertSame([], $this->received, '검증 실패인데 저장 훅이 발화되었습니다.');
    }

    /**
     * 이커머스 SEO 캐시 리스너가 이 훅으로 실제 무효화를 수행한다.
     *
     * @scenario actor=admin, save_path=bulk, listener=seo_cache
     *
     * @effects seo_cache_invalidated_on_module_settings_save
     */
    public function test_seo_cache_listener_receives_the_hook(): void
    {
        $listener = new SeoSettingsCacheListener;
        $hooks = $listener::getSubscribedHooks();

        $this->assertArrayHasKey('core.module_settings.after_save', $hooks);

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'seo',
            'seo' => ['meta_product_title' => '{product_name}'],
        ])->assertOk();

        $this->assertNotEmpty($this->received);
        // 리스너가 SEO 키 존재로 무효화를 판정하므로 payload 에 seo 카테고리가 실려야 한다
        $this->assertArrayHasKey('meta_product_title', $this->received[0][1]['seo'] ?? []);
    }

    // ─── 실제 무효화 수행 ──────────────────────────────────────

    /**
     * SEO 탭 저장이 상품 상세 레이아웃 캐시를 실제로 지운다. (훅 발화가 아닌 결과 검증)
     *
     * 훅 발화만 고정하면 리스너가 조용히 아무것도 하지 않게 되어도 green 이다.
     * 캐시 매니저를 mock 해 호출 자체를 단언한다.
     *
     * @scenario actor=admin, save_path=bulk, listener=seo_cache
     *
     * @effects seo_cache_invalidated_on_module_settings_save
     */
    public function test_seo_tab_save_actually_invalidates_layout_cache(): void
    {
        $cache = Mockery::mock(SeoCacheManagerInterface::class);
        $cache->shouldReceive('invalidateByLayout')->with('shop/show')->atLeast()->once();
        $cache->shouldReceive('invalidateByLayout')->andReturnNull();
        $this->app->instance(SeoCacheManagerInterface::class, $cache);

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'seo',
            'seo' => ['meta_product_title' => '{product_name} | shop'],
        ])->assertOk();

        $this->addToAssertionCount(1);
    }

    /**
     * SEO 와 무관한 탭 저장은 SEO 캐시를 건드리지 않는다.
     *
     * @scenario actor=admin, save_path=bulk, listener=seo_cache, tab=non_seo
     *
     * @effects seo_cache_untouched_for_non_seo_tab
     */
    public function test_non_seo_tab_save_does_not_invalidate_layout_cache(): void
    {
        $cache = Mockery::mock(SeoCacheManagerInterface::class);
        $cache->shouldNotReceive('invalidateByLayout');
        $this->app->instance(SeoCacheManagerInterface::class, $cache);

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['auto_cancel_days' => 5],
        ])->assertOk();

        $this->addToAssertionCount(1);
    }

    /**
     * 관리자 단건 저장(`updateSetting`) 경로도 같은 훅을 발화한다.
     *
     * 훅 발화는 서비스가 아니라 관리자 컨트롤러가 한다 — 서비스에 두면 테스트 fixture 나
     * 내부 호출까지 활동로그·SEO 무효화를 유발하기 때문이다. 그래서 이 경로도 컨트롤러가
     * 직접 발화해야 하며, 그것을 여기서 고정한다.
     *
     * 이 메서드에는 아직 라우트가 없어(설정 화면은 벌크 저장만 사용) HTTP 로 도달할 수
     * 없다. 그래서 컨트롤러 메서드를 직접 호출한다 — 라우트가 붙는 시점에 발화 계약이
     * 이미 고정되어 있게 한다.
     *
     * @scenario actor=admin, save_path=single_key
     *
     * @effects module_settings_after_save_hook_fired
     */
    public function test_admin_single_key_save_fires_module_settings_after_save_hook(): void
    {
        $this->actingAs($this->adminUser);

        $request = UpdateSettingRequest::create('/', 'PUT', [
            'key' => 'seo.meta_product_title',
            'value' => '{product_name}',
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        app(EcommerceSettingsController::class)->updateSetting($request)->getData();

        $this->assertCount(1, $this->received, '관리자 단건 저장이 모듈 설정 저장 훅을 발화하지 않았습니다.');
        [$identifier, $settings] = $this->received[0];

        $this->assertSame('sirsoft-ecommerce', $identifier);
        $this->assertArrayHasKey('seo', $settings, '단건 저장 payload 가 벌크와 같은 카테고리 형태가 아닙니다.');
        $this->assertArrayHasKey('meta_product_title', $settings['seo']);
    }

    /**
     * 서비스 직접 호출은 훅을 발화하지 않는다. (설계 고정)
     *
     * 발화 지점을 서비스로 내리면 내부 호출·시더·테스트 fixture 의 모든 저장이 활동로그와
     * SEO 무효화를 유발한다. 그래서 서비스는 조용해야 한다 — 이 비발화를 명시 고정한다.
     *
     * @scenario actor=system, save_path=single_key
     *
     * @effects service_level_save_stays_silent
     */
    public function test_service_level_save_does_not_fire_the_hook(): void
    {
        app(EcommerceSettingsService::class)->setSetting('seo.meta_product_title', '{product_name}');

        $this->assertSame([], $this->received, '서비스 직접 저장이 관리자 저장 훅을 발화했습니다.');
    }

    /**
     * 서비스의 벌크 저장도 훅을 발화하지 않는다. (설계 고정)
     *
     * 단건만 조용하고 벌크는 발화하면, 시더·업그레이드 스텝의 대량 저장이 운영자 감사
     * 기록을 오염시킨다. 침묵은 저장 경로 전부에 걸린 계약이다.
     *
     * @scenario actor=system, save_path=bulk
     *
     * @effects service_level_save_stays_silent
     */
    public function test_service_level_bulk_save_does_not_fire_the_hook(): void
    {
        app(EcommerceSettingsService::class)->saveSettings([
            'seo' => ['meta_product_title' => '{product_name} | shop'],
        ]);

        $this->assertSame([], $this->received, '서비스 벌크 저장이 관리자 저장 훅을 발화했습니다.');
    }

    /**
     * 서비스의 은행 목록 저장도 훅을 발화하지 않는다. (설계 고정)
     *
     * @scenario actor=system, save_path=save_banks
     *
     * @effects service_level_save_stays_silent
     */
    public function test_service_level_bank_save_does_not_fire_the_hook(): void
    {
        app(EcommerceSettingsService::class)->saveBanks([
            ['code' => '004', 'name' => ['ko' => '국민은행', 'en' => 'Kookmin Bank']],
        ]);

        $this->assertSame([], $this->received, '서비스 은행 저장이 관리자 저장 훅을 발화했습니다.');
    }

    /**
     * 저장이 코어 활동로그에 기록된다.
     *
     * 코어 활동로그 리스너가 같은 훅을 구독하므로, 훅이 죽으면 SEO 캐시와 함께
     * 설정 변경 감사 기록도 조용히 사라진다.
     *
     * @scenario actor=admin, save_path=bulk, listener=activity_log
     *
     * @effects module_settings_save_recorded_in_activity_log
     */
    public function test_settings_save_is_recorded_in_activity_log(): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.module_settings.after_save', $hooks);
        $this->assertSame('handleModuleSettingsAfterSave', $hooks['core.module_settings.after_save']['method']);

        $before = DB::table('activity_logs')->where('action', 'module_settings.save')->count();

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'seo',
            'seo' => ['meta_product_title' => '{product_name}'],
        ])->assertOk();

        $this->assertGreaterThan(
            $before,
            DB::table('activity_logs')->where('action', 'module_settings.save')->count(),
            '설정 저장이 활동로그에 기록되지 않았습니다.'
        );
    }
}
