<?php

namespace Tests\Feature\Services;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\Storage\CoreStorageDriver;
use App\Models\Attachment;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 환경설정 일반 탭 파비콘(site_favicon) 저장·응답 테스트 (#434)
 *
 * 사이트 로고와 같은 규율 — 제출한 목록이 저장 대상, 그 컬렉션에 실재하는 첨부만, 뺀 첨부는 저장 성공 뒤 파기,
 * 제출하지 않은 저장은 기존 값 유지. 저장 응답은 계산 필드가 실린 공개 설정(frontend_settings)을 함께 준다.
 */
class SettingsServiceSiteFaviconTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 테스트가 만든 픽스처를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanUpTemplateSettingsFixtures();

        parent::tearDown();
    }

    /**
     * 파비콘을 정하고 교체하면 이전 첨부는 파기되고, 다른 칸만 저장하면 그대로 남는다. 옛 설정 파일에 키가 없어도 빈 배열.
     *
     * @scenario surface=spa
     *
     * @effects site_favicon_saved_and_replaced
     *
     * @return void 반환값 없음
     */
    public function test_site_favicon_is_saved_replaced_and_kept_when_omitted(): void
    {
        $service = app(SettingsService::class);
        $config = app(ConfigRepositoryInterface::class);

        $this->assertSame([], $service->getAllSettings()['general']['site_favicon'], '저장값이 없는 옛 설치도 빈 배열');

        $first = $this->makeFaviconAttachment('site_favicon');
        $logo = $this->makeFaviconAttachment('site_logo');
        $service->saveSettings(['_tab' => 'general', 'general' => ['site_favicon' => [$logo->id, $first->id]]]);
        $this->assertSame([$first->id], $config->getCategory('general')['site_favicon'], '다른 컬렉션 첨부는 걸러진다');

        $service->saveSettings(['_tab' => 'general', 'general' => ['site_name' => '사이트']]);
        $this->assertSame([$first->id], $config->getCategory('general')['site_favicon'], '제출하지 않으면 유지');

        $second = $this->makeFaviconAttachment('site_favicon');
        $service->saveSettings(['_tab' => 'general', 'general' => ['site_favicon' => [$second->id]]]);
        $this->assertSame([$second->id], $config->getCategory('general')['site_favicon']);
        $this->assertDatabaseMissing('attachments', ['id' => $first->id]);
    }

    /**
     * 저장 응답에 계산 필드가 실린 공개 설정(frontend_settings)이 함께 오고, 조회 응답 _meta.favicon 이 우선순위를 알린다.
     * 2개 이상·다른 컬렉션 첨부는 422.
     *
     * @scenario surface=admin_settings
     *
     * @effects save_response_carries_frontend_settings, priority_meta_described
     *
     * @return void 반환값 없음
     */
    public function test_save_response_and_priority_meta(): void
    {
        $admin = $this->makeAdmin(['core.settings.read', 'core.settings.update']);
        $favicon = $this->makeFaviconAttachment('site_favicon');
        $logo = $this->makeFaviconAttachment('site_logo');

        $general = ['site_name' => 'qa434', 'site_url' => 'https://example.com', 'admin_email' => 'admin@example.com', 'timezone' => 'Asia/Seoul', 'language' => 'ko', 'currency' => 'KRW'];

        $response = $this->actingAs($admin)->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => $general + ['site_favicon' => [['id' => $favicon->id]]],
        ]);
        $response->assertOk();

        $this->assertStringEndsWith('/api/favicon/'.$favicon->hash, (string) $response->json('data.frontend_settings.general.site_favicon_url'));
        $this->assertArrayHasKey('site_logo_url', $response->json('data.frontend_settings.general'));

        $this->actingAs($admin)->postJson('/api/admin/settings', ['_tab' => 'general', 'general' => $general + ['site_favicon' => [$logo->id]]])->assertStatus(422);
        $this->actingAs($admin)->postJson('/api/admin/settings', ['_tab' => 'general', 'general' => $general + ['site_favicon' => [$favicon->id, $favicon->id]]])->assertStatus(422);

        Config::set('g7_settings.core.general.site_favicon', [$favicon->id]);
        $template = $this->makeSettingsTemplate('qa434-meta', 'admin', 'active');
        $tplFavicon = $this->makeFaviconAttachment('template_settings.qa434-meta.branding.favicon');
        app(\App\Services\TemplateSettingsService::class)->save('qa434-meta', ['branding' => ['favicon' => [$tplFavicon->id]]]);
        $this->resetFaviconResolution();

        $meta = $this->actingAs($admin)->getJson('/api/admin/settings')->assertOk()->json('data._meta.favicon');
        $this->assertSame('template', $meta['admin']['source']);
        $this->assertSame($template->identifier, $meta['admin']['template_identifier']);
        $this->assertSame('/admin/templates/qa434-meta/settings', $meta['admin']['settings_route']);
        $this->assertSame('core', $meta['user']['source']);
    }

    /**
     * 저장에 실패했거나 이탈로 남은 미참조 파비콘 첨부는 설정에 되살아나지 않는다 — 저장 목록은 이번 제출이 정한다.
     *
     * @scenario surface=admin_settings
     *
     * @effects site_favicon_saved_and_replaced
     *
     * @return void 반환값 없음
     */
    public function test_unreferenced_favicon_is_not_re_absorbed(): void
    {
        $service = app(SettingsService::class);
        $config = app(ConfigRepositoryInterface::class);

        $orphan = $this->makeFaviconAttachment('site_favicon');
        $fresh = $this->makeFaviconAttachment('site_favicon');
        $service->saveSettings(['_tab' => 'general', 'general' => ['site_favicon' => [$fresh->id]]]);

        $this->assertSame([$fresh->id], $config->getCategory('general')['site_favicon'], '제출하지 않은 미참조 첨부가 편입되면 안 된다');
        $this->assertDatabaseHas('attachments', ['id' => $orphan->id]);
    }

    /**
     * 제출값에 이미 지워진 첨부 ID 가 섞여 있으면 그 ID 는 저장되지 않는다.
     *
     * @scenario surface=admin_settings
     *
     * @effects site_favicon_saved_and_replaced
     *
     * @return void 반환값 없음
     */
    public function test_deleted_favicon_id_is_filtered_out(): void
    {
        $service = app(SettingsService::class);
        $config = app(ConfigRepositoryInterface::class);

        $favicon = $this->makeFaviconAttachment('site_favicon');
        $gone = $this->makeFaviconAttachment('site_favicon');
        Attachment::whereKey($gone->id)->forceDelete();

        $service->saveSettings(['_tab' => 'general', 'general' => ['site_favicon' => [$gone->id, $favicon->id, 999999]]]);

        $this->assertSame([$favicon->id], $config->getCategory('general')['site_favicon']);
    }

    /**
     * 저장이 실패하면 뺀 파비콘의 행·파일·설정이 모두 남는다 — 파기는 저장 확정 뒤에만.
     *
     * @scenario surface=admin_settings
     *
     * @effects site_favicon_saved_and_replaced
     *
     * @return void 반환값 없음
     */
    public function test_failed_save_keeps_removed_favicon(): void
    {
        $config = app(ConfigRepositoryInterface::class);
        $favicon = $this->makeFaviconAttachment('site_favicon');
        app(SettingsService::class)->saveSettings(['_tab' => 'general', 'general' => ['site_favicon' => [$favicon->id]]]);
        $this->assertSame([$favicon->id], $config->getCategory('general')['site_favicon']);

        // 저장 단계만 실패하게 한다 (조회는 실제 동작)
        $failing = \Mockery::mock($config);
        $failing->shouldReceive('saveCategory')->andReturnFalse();
        $this->app->instance(ConfigRepositoryInterface::class, $failing);

        app(SettingsService::class)->saveSettings(['_tab' => 'general', 'general' => ['site_favicon' => []]]);

        $this->app->instance(ConfigRepositoryInterface::class, $config);

        $this->assertDatabaseHas('attachments', ['id' => $favicon->id]);
        $this->assertTrue((new CoreStorageDriver($favicon->disk))->exists('', $favicon->path), '저장이 실패했으면 파일도 남아야 한다');
        $this->assertSame([$favicon->id], $config->getCategory('general')['site_favicon'], '설정도 그대로');
    }

    /**
     * 파비콘 기능 전에 저장된 일반 탭 파일(키 `site_favicon` 없음)도 조회 결과는 빈 배열이다.
     *
     * @scenario surface=admin_settings
     *
     * @effects site_favicon_saved_and_replaced
     *
     * @return void 반환값 없음
     */
    public function test_general_file_without_site_favicon_key_reads_empty_array(): void
    {
        Storage::disk('settings')->put('general.json', json_encode(['site_name' => 'legacy', 'site_logo' => []], JSON_UNESCAPED_UNICODE));
        $this->app->forgetInstance(ConfigRepositoryInterface::class);
        $this->app->forgetInstance(SettingsService::class);

        $this->assertArrayNotHasKey('site_favicon', json_decode((string) Storage::disk('settings')->get('general.json'), true), '전제 — 파일에 키가 없다');
        $general = app(SettingsService::class)->getAllSettings()['general'];

        $this->assertSame('legacy', $general['site_name'], '그 파일을 실제로 읽었다');
        $this->assertSame([], $general['site_favicon']);
    }
}
