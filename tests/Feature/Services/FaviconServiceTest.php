<?php

namespace Tests\Feature\Services;

use App\Models\Attachment;
use App\Services\FaviconService;
use App\Services\TemplateSettingsService;
use App\Support\Favicon\BundledFavicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 파비콘 우선순위 해석 테스트 (#434)
 *
 * ① 활성 템플릿 설정 > ② 일반 탭 site_favicon > ③ 번들(또는 운영자 public/favicon.ico). 무효 첨부는 다음 계층으로.
 */
class FaviconServiceTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 코어 파비콘 설정을 비우고 시작합니다(개발 환경 설정이 새지 않게).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('g7_settings.core.general.site_favicon', []);
    }

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
     * 새 해석기(요청 내 캐시 없음)를 돌려줍니다.
     *
     * @return FaviconService 해석기
     */
    private function resolver(): FaviconService
    {
        $this->resetFaviconResolution();

        return app()->make(FaviconService::class);
    }

    /**
     * 계층이 위에서부터 이긴다 — 템플릿 > 코어 > 번들, 그리고 비우면 한 단계씩 내려간다.
     *
     * @scenario surface=spa
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_tiers_resolve_top_down(): void
    {
        $this->makeSettingsTemplate('qa434-fav', 'user', 'active');
        $core = $this->makeFaviconAttachment('site_favicon', 'image/x-icon', "\x00\x00\x01\x00qa");
        $tpl = $this->makeFaviconAttachment('template_settings.qa434-fav.branding.favicon', 'image/png');

        $this->assertSame('bundled', $this->resolver()->resolve('user')['source']);
        $this->assertCount(3, $this->resolver()->resolve('user')['links']);

        Config::set('g7_settings.core.general.site_favicon', [$core->id]);
        $core_result = $this->resolver()->resolve('user');
        $this->assertSame('core', $core_result['source']);
        $this->assertSame('image/x-icon', $core_result['links'][0]['type']);
        $this->assertStringEndsWith('/api/favicon/'.$core->hash, $core_result['links'][0]['href']);

        app(TemplateSettingsService::class)->save('qa434-fav', ['branding' => ['favicon' => [$tpl->id]]]);
        $tpl_result = $this->resolver()->resolve('user');
        $this->assertSame('template', $tpl_result['source']);
        $this->assertSame('apple-touch-icon', $tpl_result['links'][1]['rel'], 'png 는 apple-touch-icon 도 같은 파일로');

        $this->assertSame('core', $this->resolver()->resolve('admin')['source'], '관리자 화면은 관리자 템플릿 설정만 본다');

        $describe = $this->resolver()->describe();
        $this->assertSame('template', $describe['user']['source']);
        $this->assertSame('/admin/templates/qa434-fav/settings', $describe['user']['settings_route']);
    }

    /**
     * 첨부 행이 지워졌거나 다른 컬렉션이면 다음 계층으로 내려간다.
     *
     * @scenario surface=spa
     *
     * @effects falls_through_on_missing_row
     *
     * @return void 반환값 없음
     */
    public function test_invalid_attachment_falls_through(): void
    {
        $logo = $this->makeFaviconAttachment('site_logo', 'image/png');
        Config::set('g7_settings.core.general.site_favicon', [$logo->id]);
        $this->assertSame('bundled', $this->resolver()->resolve('user')['source'], '다른 컬렉션 첨부는 무효');

        $core = $this->makeFaviconAttachment('site_favicon', 'image/png');
        Config::set('g7_settings.core.general.site_favicon', [$core->id]);
        $this->assertSame('core', $this->resolver()->resolve('user')['source']);

        Attachment::whereKey($core->id)->forceDelete();
        $this->assertSame('bundled', $this->resolver()->resolve('user')['source'], '행이 지워지면 번들로');
    }

    /**
     * 운영자가 바꾼 public/favicon.ico(비어 있지 않고 번들과 다름)면 그 파일 하나만 링크한다.
     *
     * @scenario surface=spa
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_operator_favicon_ico_wins_bundled_tier(): void
    {
        $path = public_path('favicon.ico');
        $original = File::get($path);

        try {
            File::put($path, "\x00\x00\x01\x00operator-icon");
            $result = $this->resolver()->resolve('user');
            $this->assertSame('custom_ico', $result['source']);
            $this->assertCount(1, $result['links']);
            $this->assertStringContainsString('/favicon.ico?v=', $result['links'][0]['href']);

            File::put($path, File::get(public_path(BundledFavicon::DIRECTORY.'/favicon.ico')));
            $this->assertSame('bundled', $this->resolver()->resolve('user')['source'], '번들과 같은 파일은 운영자 파일이 아니다');
        } finally {
            File::put($path, $original);
            BundledFavicon::flush();
        }
    }

    /**
     * 관리자 화면도 같은 3계층이다 — 켜진 관리자 템플릿 설정 > 일반 탭 > 번들. 사용자 템플릿 설정은 관리자 화면에 쓰이지 않는다.
     *
     * @scenario surface=spa
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_admin_target_tiers_resolve_top_down(): void
    {
        $this->makeSettingsTemplate('qa434-adm', 'admin', 'active');
        $this->makeSettingsTemplate('qa434-usr', 'user', 'active');
        $core = $this->makeFaviconAttachment('site_favicon', 'image/x-icon', "\x00\x00\x01\x00qa");
        $admin = $this->makeFaviconAttachment('template_settings.qa434-adm.branding.favicon', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $user = $this->makeFaviconAttachment('template_settings.qa434-usr.branding.favicon', 'image/png');
        app(TemplateSettingsService::class)->save('qa434-usr', ['branding' => ['favicon' => [$user->id]]]);

        $this->assertSame('bundled', $this->resolver()->resolve('admin')['source'], '사용자 템플릿 파비콘은 관리자 화면 계층이 아니다');

        Config::set('g7_settings.core.general.site_favicon', [$core->id]);
        $result = $this->resolver()->resolve('admin');
        $this->assertSame('core', $result['source']);
        $this->assertSame($core->id, $result['attachment_id']);

        app(TemplateSettingsService::class)->save('qa434-adm', ['branding' => ['favicon' => [$admin->id]]]);
        $result = $this->resolver()->resolve('admin');
        $this->assertSame('template', $result['source']);
        $this->assertSame('qa434-adm', $result['template_identifier']);
        $this->assertSame('image/svg+xml', $result['links'][0]['type']);
        $this->assertCount(1, $result['links'], 'svg 는 apple-touch-icon 을 내지 않는다');
    }

    /**
     * 첨부 행은 있어도 MIME 이 파비콘 목록 밖이면 무효 — 템플릿 계층은 코어로, 코어 계층은 번들로 내려간다.
     *
     * @scenario surface=spa
     *
     * @effects falls_through_on_missing_row
     *
     * @return void 반환값 없음
     */
    public function test_attachment_with_mime_outside_whitelist_falls_through(): void
    {
        $this->makeSettingsTemplate('qa434-fav', 'user', 'active');
        $tpl = $this->makeFaviconAttachment('template_settings.qa434-fav.branding.favicon', 'image/bmp', 'BMqa434');
        $core = $this->makeFaviconAttachment('site_favicon', 'application/pdf', '%PDF-qa434');
        $goodCore = $this->makeFaviconAttachment('site_favicon', 'image/png');
        app(TemplateSettingsService::class)->save('qa434-fav', ['branding' => ['favicon' => [$tpl->id]]]);
        $this->assertSame([$tpl->id], app(TemplateSettingsService::class)->get('qa434-fav', 'branding.favicon'), '행은 저장돼 있다(전제)');

        Config::set('g7_settings.core.general.site_favicon', [$goodCore->id]);
        $this->assertSame('core', $this->resolver()->resolve('user')['source'], '템플릿 첨부 MIME 이 목록 밖이면 코어로');

        Config::set('g7_settings.core.general.site_favicon', [$core->id]);
        $this->assertSame('bundled', $this->resolver()->resolve('user')['source'], '코어 첨부 MIME 이 목록 밖이면 번들로');
    }

    /**
     * 빈 `favicon.ico` 는 운영자 파일이 아니다 — 번들 계층(여러 형식 링크)을 낸다. 실제 public 폴더는 건드리지 않고
     * 임시 public 경로(번들 파비콘 복사본 포함)를 쓴다.
     *
     * @scenario surface=spa
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_empty_favicon_ico_is_not_operator_file(): void
    {
        $original = public_path();
        $temp = storage_path('framework/testing/qa434-public-'.uniqid());
        File::copyDirectory(public_path(BundledFavicon::DIRECTORY), $temp.'/'.BundledFavicon::DIRECTORY);

        try {
            app()->usePublicPath($temp);

            File::put($temp.'/favicon.ico', '');
            $result = $this->resolver()->resolve('user');
            $this->assertSame('bundled', $result['source'], '빈 favicon.ico 는 운영자 파일이 아니다');
            $this->assertGreaterThan(1, count($result['links']));

            // 대조 — 같은 임시 경로의 내용 있는 다른 파일은 운영자 파일로 판정된다(경로가 실제로 쓰였다)
            File::put($temp.'/favicon.ico', "\x00\x00\x01\x00operator-icon");
            $this->assertSame('custom_ico', $this->resolver()->resolve('user')['source']);
        } finally {
            app()->usePublicPath($original);
            File::deleteDirectory($temp);
            BundledFavicon::flush();
        }
    }
}
