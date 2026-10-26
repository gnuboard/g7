<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 공개 노출 서비스 캐시 테스트 (매니페스트 public-hero.yaml — 캐시 상한·재판정·끔)
 *
 * 캐시 수명은 설정 시간과 다음 시작·종료 시각 중 짧은 쪽이고, 꺼낼 때 지금 시각으로 다시 판정한다.
 */
class PublicHeroServiceTest extends PluginTestCase
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
     * 2분 뒤 시작하는 예약 배너가 있으면 캐시 수명은 120초다(설정 10분보다 짧게).
     *
     * @scenario surface=cache_ttl_capped_at_next_start
     *
     * @effects cache_never_outlives_period_boundary
     *
     * @return void 반환값 없음
     */
    public function test_ttl_capped_at_next_start(): void
    {
        Banner::factory()->create(['display_period' => 'range', 'starts_at' => now()->addSeconds(120), 'ends_at' => now()->addDay()]);

        $this->assertSame(120, $this->service()->ttlSeconds(HeroPosition::HomeHero, now(), 10));
    }

    /**
     * 5분 뒤 끝나는 노출 배너가 있으면 캐시 수명은 종료 다음 초까지(301초)다.
     *
     * @scenario surface=cache_ttl_capped_at_next_end
     *
     * @effects cache_never_outlives_period_boundary
     *
     * @return void 반환값 없음
     */
    public function test_ttl_capped_at_next_end(): void
    {
        Banner::factory()->create(['display_period' => 'range', 'starts_at' => now()->subDay(), 'ends_at' => now()->addSeconds(300)]);
        Banner::factory()->create(['display_period' => 'range', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3)]);

        $this->assertSame(301, $this->service()->ttlSeconds(HeroPosition::HomeHero, now(), 10));
        $this->assertSame(600, $this->service()->ttlSeconds(HeroPosition::ShopHero, now(), 10), '전환이 없으면 설정 시간');
    }

    /**
     * 캐시 수명 안이라도 꺼낼 때 기간을 다시 판정해, 이미 끝난 배너는 내보내지 않는다.
     *
     * @scenario surface=cache_read_refilters_by_period
     *
     * @effects cache_never_outlives_period_boundary
     *
     * @return void 반환값 없음
     */
    public function test_read_refilters_by_period(): void
    {
        $banner = Banner::factory()->withImages()->create([
            'display_period' => 'range', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMinute(), 'title' => ['ko' => '곧 끝남'],
        ]);
        $this->assertSame(['곧 끝남'], array_column($this->service()->forPosition(HeroPosition::HomeHero, 'ko')['banners'], 'title'));

        // 캐시 수명이 경계를 넘도록 같은 키를 1시간짜리로 다시 담는다(만료 계산이 어긋난 상황을 흉내)
        $cache = $this->cacheOf($this->service());
        $key = $this->cacheKey($cache);
        $cache->put($key, $cache->get($key), 3600);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertNotNull($cache->get($key), '캐시는 아직 살아 있다');
        $this->assertSame([], $this->service()->forPosition(HeroPosition::HomeHero, 'ko')['banners'], '꺼낼 때 다시 판정해 뺀다');
        $this->assertNotNull($banner->fresh());
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
        $banner = Banner::factory()->withImages()->create(['title' => ['ko' => '처음']]);
        $this->assertSame(['처음'], array_column($this->service()->forPosition(HeroPosition::HomeHero, 'ko')['banners'], 'title'));

        DB::table('hero_section_banners')->where('id', $banner->id)->update(['title' => json_encode(['ko' => '바로 반영'])]);

        $this->assertSame(['바로 반영'], array_column($this->service()->forPosition(HeroPosition::HomeHero, 'ko')['banners'], 'title'));
    }

    /**
     * 서비스를 만듭니다.
     *
     * @return PublicHeroService 공개 노출 서비스
     */
    private function service(): PublicHeroService
    {
        return app(PublicHeroService::class);
    }

    /**
     * 서비스가 쓰는 캐시 드라이버를 꺼냅니다.
     *
     * @param  PublicHeroService  $service  서비스
     * @return CacheInterface 캐시 드라이버
     */
    private function cacheOf(PublicHeroService $service): CacheInterface
    {
        return (new \ReflectionProperty(PublicHeroService::class, 'cache'))->getValue($service);
    }

    /**
     * 홈 위치·ko 의 현재 캐시 키를 만듭니다(서비스와 같은 규칙).
     *
     * @param  CacheInterface  $cache  캐시 드라이버
     * @return string 캐시 키
     */
    private function cacheKey(CacheInterface $cache): string
    {
        $version = (int) ($cache->get('hero:version:home_hero') ?? 0);

        return 'hero:home_hero:v'.$version.':ko';
    }
}
