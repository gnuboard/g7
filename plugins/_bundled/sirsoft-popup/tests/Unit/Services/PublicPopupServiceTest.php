<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Services\PublicPopupService;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 공개 노출 서비스 캐시 테스트 (매니페스트 public-popups.yaml — 캐시·봇 케이스)
 *
 * 캐시 키는 대상(guest·member)·언어, 만료 = min(설정 시간, 다음 상태 전환), 꺼낼 때 기간 재판정(#810 과 같은 두 겹).
 */
class PublicPopupServiceTest extends PluginTestCase
{
    /**
     * 기준 시각 (UTC)
     */
    private const NOW = '2026-12-01 12:00:00';

    /**
     * 테스트 준비 — 시각 고정, 캐시 10분.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $this->setPluginSettings(['public_cache_minutes' => 10]);
        $this->service()->invalidate();
    }

    /**
     * 테스트 정리 — 고정 시각을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 봇 요청은 캐시를 읽지도 쓰지도 않는다.
     *
     * @scenario surface=bot_skips_cache
     *
     * @return void 반환값 없음
     */
    public function test_bot_skips_cache(): void
    {
        Popup::factory()->html()->create();
        $cache = $this->cacheOf($this->service());

        $this->assertSame([], $this->service()->forRequest(null, 'ko', isBot: true)['popups']);
        $this->assertNull($cache->get($this->cacheKey($cache, 'guest', 'ko')), '봇은 캐시를 만들지 않는다');

        $this->service()->forRequest(null, 'ko');
        $this->assertNotNull($cache->get($this->cacheKey($cache, 'guest', 'ko')));
    }

    /**
     * 캐시 키는 대상·언어로 갈린다 — 회원 응답이 손님 캐시에 섞이지 않는다.
     *
     * @scenario surface=cache_key_per_audience_and_locale
     *
     * @return void 반환값 없음
     */
    public function test_cache_key_per_audience_and_locale(): void
    {
        $memberOnly = Popup::factory()->html(['ko' => '<p>회원</p>', 'en' => '<p>Member</p>'])->audience('member')->create();

        $this->assertSame([$memberOnly->id], array_column($this->service()->forRequest(User::factory()->make(), 'en')['popups'], 'id'));
        $this->assertSame([], $this->service()->forRequest(null, 'en')['popups'], '손님 캐시는 따로');
        $this->assertSame('<p>회원</p>', $this->service()->forRequest(User::factory()->make(), 'ko')['popups'][0]['body_html'], '언어 캐시도 따로');

        $cache = $this->cacheOf($this->service());
        foreach ([['member', 'en'], ['guest', 'en'], ['member', 'ko']] as [$audience, $locale]) {
            $this->assertNotNull($cache->get($this->cacheKey($cache, $audience, $locale)), $audience.':'.$locale);
        }
    }

    /**
     * 다음 상태 전환(예약 시작·노출 종료 다음 초)까지만 캐시한다 — 전환이 없으면 설정 시간.
     *
     * @scenario surface=cache_ttl_capped_at_next_transition
     *
     * @effects cache_never_outlives_period_boundary
     *
     * @return void 반환값 없음
     */
    public function test_ttl_capped_at_next_transition(): void
    {
        $this->assertSame(600, $this->service()->ttlSeconds(now(), 10), '전환이 없으면 설정 시간');

        $ending = Popup::factory()->period(now()->subDay(), now()->addSeconds(300))->create();
        $this->assertSame(301, $this->service()->ttlSeconds(now(), 10), '종료 다음 초');

        Popup::factory()->period(now()->addSeconds(120), now()->addDay())->create();
        $this->assertSame(120, $this->service()->ttlSeconds(now(), 10), '예약 시작');

        $ending->delete();
        Popup::factory()->inactive()->period(now()->addSeconds(30), now()->addDay())->create();
        $this->assertSame(120, $this->service()->ttlSeconds(now(), 10), '비활성 팝업의 전환은 보지 않는다');
    }

    /**
     * 캐시 수명 안이라도 꺼낼 때 기간을 다시 판정해, 이미 끝난 팝업은 내보내지 않는다.
     *
     * @scenario surface=cache_read_refilters_by_period
     *
     * @effects cache_never_outlives_period_boundary
     *
     * @return void 반환값 없음
     */
    public function test_read_refilters_by_period(): void
    {
        $popup = Popup::factory()->html(['ko' => '<p>곧 끝남</p>'])->period(now()->subDay(), now()->addMinute())->create();
        $this->assertSame([$popup->id], array_column($this->service()->forRequest(null, 'ko')['popups'], 'id'));

        // 캐시 수명이 경계를 넘도록 같은 키를 1시간짜리로 다시 담는다(만료 계산이 어긋난 상황을 흉내)
        $cache = $this->cacheOf($this->service());
        $key = $this->cacheKey($cache, 'guest', 'ko');
        $cache->put($key, $cache->get($key), 3600);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertNotNull($cache->get($key), '캐시는 아직 살아 있다');
        $this->assertSame([], $this->service()->forRequest(null, 'ko')['popups'], '꺼낼 때 다시 판정해 뺀다');
    }

    /**
     * 캐시 시간이 0 이면 매번 DB 에서 읽는다.
     *
     * @scenario surface=cache_disabled_when_zero_minutes
     *
     * @return void 반환값 없음
     */
    public function test_cache_disabled_when_zero_minutes(): void
    {
        $this->setPluginSettings(['public_cache_minutes' => 0]);
        $popup = Popup::factory()->html(['ko' => '<p>처음</p>'])->create();
        $this->assertSame('<p>처음</p>', $this->service()->forRequest(null, 'ko')['popups'][0]['body_html']);

        DB::table('popups')->where('id', $popup->id)->update(['body_html' => json_encode(['ko' => '<p>바로 반영</p>'])]);

        $this->assertSame('<p>바로 반영</p>', $this->service()->forRequest(null, 'ko')['popups'][0]['body_html']);
    }

    /**
     * 조회 결과 훅은 캐시에서 꺼낸 뒤 요청 정보(user·locale)와 함께 불리고, 가공한 값은 캐시에 들어가지 않는다.
     *
     * @scenario surface=result_hook_after_cache_with_context
     *
     * @return void 반환값 없음
     */
    public function test_result_hook_after_cache_with_context(): void
    {
        Popup::factory()->html()->create();
        $member = User::factory()->make();
        $seen = [];
        HookManager::addAction('sirsoft-popup.popup.before_public_list', function (array $context) use (&$seen) {
            $seen[] = 'before';
        });
        HookManager::addFilter('sirsoft-popup.popup.filter_public_list_result', function (array $popups, array $context) use (&$seen) {
            $seen[] = ['user' => $context['user'], 'locale' => $context['locale']];

            return $context['user'] === null ? $popups : array_map(fn (array $row) => $row + ['member_badge' => true], $popups);
        });
        HookManager::addAction('sirsoft-popup.popup.after_public_list', function (array $popups) use (&$seen) {
            $seen[] = 'after:'.count($popups);
        });

        $forMember = $this->service()->forRequest($member, 'ko')['popups'];

        $this->assertTrue($forMember[0]['member_badge']);
        $this->assertSame(['before', ['user' => $member, 'locale' => 'ko'], 'after:1'], $seen);
        $cache = $this->cacheOf($this->service());
        $this->assertArrayNotHasKey('member_badge', $cache->get($this->cacheKey($cache, 'member', 'ko'))['popups'][0], '가공 값은 캐시에 들어가지 않는다');
    }

    /**
     * 서비스를 만듭니다.
     *
     * @return PublicPopupService 공개 노출 서비스
     */
    private function service(): PublicPopupService
    {
        return app(PublicPopupService::class);
    }

    /**
     * 서비스가 쓰는 캐시 드라이버를 꺼냅니다.
     *
     * @param  PublicPopupService  $service  공개 노출 서비스
     * @return CacheInterface 캐시 드라이버
     */
    private function cacheOf(PublicPopupService $service): CacheInterface
    {
        return (new \ReflectionProperty(PublicPopupService::class, 'cache'))->getValue($service);
    }

    /**
     * 현재 캐시 키를 만듭니다(서비스와 같은 규칙).
     *
     * @param  CacheInterface  $cache  캐시 드라이버
     * @param  string  $audience  guest 또는 member
     * @param  string  $locale  언어
     * @return string 캐시 키
     */
    private function cacheKey(CacheInterface $cache, string $audience, string $locale): string
    {
        $version = (int) ($cache->get('popup:public:version') ?? 0);

        return 'popup:public:v'.$version.':'.$audience.':'.$locale;
    }
}
