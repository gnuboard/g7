<?php

namespace Tests\Feature\Timezone;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Extension\Traits\SynchronizesLayoutRows;
use App\Http\Requests\User\UserListRequest;
use App\Models\SitemapUrl;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Seo\PipeRegistry;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 코어가 만드는 날짜·시각 문자열의 시간대 테스트 (#160)
 *
 * - 화면 값(확장 업데이트 모달 「수정된 레이아웃」, 회원 목록 기본 기간, SEO 날짜 파이프, GeoIP 최근 갱신)은 보는 사람 시간대
 * - 사람이 읽는 탈퇴 접미사 날짜는 사이트 시간대
 * - 사이트맵 lastmod 는 오프셋을 반영해 UTC 로 저장
 */
class CoreDisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 고정 시각을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 확장 업데이트 모달의 「수정된 레이아웃」 시각은 보는 사람 시간대다 — UTC 16:00 = 서울 다음 날 01:00.
     *
     * @scenario surface=modified_layout_updated_at
     *
     * @effects core_screen_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_modified_layout_updated_at_uses_viewer_timezone(): void
    {
        app()->instance('user_timezone', 'Asia/Seoul');
        $row = new TemplateLayout;
        $row->content = [];
        $row->updated_at = Carbon::parse('2026-11-03 16:00:00', 'UTC');

        $subject = new class
        {
            use ComputesLayoutContentHash;
            use SynchronizesLayoutRows;
        };
        $method = new ReflectionMethod($subject, 'modifiedItem');
        $method->setAccessible(true);
        $item = $method->invoke($subject, 1, 'home', $row, 'layout');

        $this->assertSame('2026-11-04 01:00:00', $item['updated_at']);
    }

    /**
     * 회원 목록 「최근 1주」 기본 기간은 보는 사람의 오늘로 끝난다 — UTC 11-04 16:30 = 서울 11-05.
     *
     * @scenario surface=user_list_default_range
     *
     * @effects core_screen_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_user_list_week_filter_ends_at_viewer_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 16:30:00', 'UTC'));
        app()->instance('user_timezone', 'Asia/Seoul');

        $request = UserListRequest::create('/api/admin/users', 'GET', ['date_filter' => 'week']);
        $request->setContainer(app());
        $prepare = new ReflectionMethod($request, 'prepareForValidation');
        $prepare->setAccessible(true);
        $prepare->invoke($request);

        $this->assertSame('2026-11-05', $request->input('end_date'));
        $this->assertSame('2026-10-29', $request->input('start_date'));
    }

    /**
     * 탈퇴 접미사의 날짜(사람이 읽는 값)는 사이트 날짜다 — UTC 11-04 16:30 = 서울 11-05.
     *
     * @scenario surface=withdraw_suffix
     *
     * @effects withdraw_suffix_uses_site_date
     *
     * @return void 반환값 없음
     */
    public function test_withdraw_suffix_uses_site_date(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        Carbon::setTestNow(Carbon::parse('2026-11-04 16:30:00', 'UTC'));
        $user = User::factory()->create(['email' => 'tz-withdraw@example.com', 'name' => '시간대']);

        $user->withdraw();

        $fresh = $user->fresh();
        $this->assertStringContainsString('_deleted_20261105_', $fresh->email);
        $this->assertStringContainsString('_탈퇴_20261105', (string) $fresh->name);
    }

    /**
     * 사이트맵 lastmod 는 오프셋을 반영해 UTC 로 저장한다 — 오프셋을 버리면 9시간 어긋난다.
     *
     * @scenario surface=sitemap_lastmod
     *
     * @effects sitemap_lastmod_stored_in_utc
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_lastmod_with_offset_is_stored_in_utc(): void
    {
        app(SitemapUrlRepositoryInterface::class)->upsertForResource('page', '873', [[
            'loc' => 'https://example.com/page/tz',
            'contributor' => 'sirsoft-page',
            'lastmod' => '2026-11-04T10:00:00+09:00',
            'changefreq' => 'weekly',
            'priority' => 0.5,
        ]]);

        $raw = SitemapUrl::where('resource_type', 'page')->where('resource_id', '873')->value('lastmod');

        $this->assertStringStartsWith('2026-11-04 01:00:00', (string) $raw);
    }

    /**
     * SEO 날짜 파이프 — 오프셋(Z)이 있는 입력은 보는 사람 시간대로 바꾸고, 오프셋 없는 벽시계 문자열은 그대로 둔다.
     *
     * @scenario surface=seo_date_pipe
     *
     * @effects core_screen_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_seo_date_pipe_converts_offset_input_to_viewer_timezone(): void
    {
        app()->instance('user_timezone', 'Asia/Seoul');
        $registry = new PipeRegistry;

        // datetime 기본 형식은 분 단위(YYYY-MM-DD HH:mm)
        $this->assertSame('2026-11-04 01:00', $registry->execute('datetime', '2026-11-03T16:00:00Z'));
        $this->assertSame('2026-11-04', $registry->execute('date', '2026-11-03T16:00:00+00:00'));
        // 오프셋 없는 문자열은 이미 표시용 벽시계 값으로 보고 그대로 둔다
        $this->assertSame('2026-11-03 16:00', $registry->execute('datetime', '2026-11-03 16:00:00'));
    }

    /**
     * 고급 탭 GeoIP 최근 갱신 — 저장 ISO 는 그대로, 화면용 *_formatted 는 보는 사람 시간대.
     *
     * @scenario surface=geoip_last_updated
     *
     * @effects core_screen_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_geoip_last_updated_at_has_viewer_formatted_value(): void
    {
        app()->instance('user_timezone', 'Asia/Seoul');
        $config = app(ConfigRepositoryInterface::class);
        $geoip = $config->getCategory('geoip');
        $geoip['last_updated_at'] = '2026-11-03T16:00:00+00:00';
        $config->saveCategory('geoip', $geoip);

        $advanced = app(SettingsService::class)->getAllSettings()['advanced'] ?? [];

        $this->assertSame('2026-11-03T16:00:00+00:00', $advanced['geoip_last_updated_at'] ?? null);
        $this->assertSame('2026-11-04 01:00:00', $advanced['geoip_last_updated_at_formatted'] ?? null);
    }
}
