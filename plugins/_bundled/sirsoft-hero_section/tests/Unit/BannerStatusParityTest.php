<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit;

use Illuminate\Support\Carbon;
use Plugins\Sirsoft\HeroSection\Enums\BannerStatus;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Repositories\BannerRepository;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 노출 상태 판정 일치 테스트 (매니페스트 banner-status.yaml)
 *
 * 모델 접근자(PHP 판정, BannerStatus::resolve)와 Repository 상태 필터(SQL 판정)가 경계 시각마다 같은 상태를 내는지,
 * 공개 노출 조회가 「노출중」일 때만 그 배너를 내는지 잠근다. 경계: 시작 정각부터 노출, 종료 정각까지 노출.
 */
class BannerStatusParityTest extends PluginTestCase
{
    /**
     * 기준 시작 시각 (UTC)
     */
    private const START = '2026-11-01 00:00:00';

    /**
     * 기준 종료 시각 (UTC)
     */
    private const END = '2026-11-10 00:00:00';

    /**
     * 테스트가 끝나면 고정 시각을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 상시·사용·기간 안 → Active.
     *
     * @scenario display_period=always, is_active=true, now_position=within
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_always_active_within_is_active(): void
    {
        $this->assertStatusParity('always', true, 'within', BannerStatus::Active);
    }

    /**
     * 상시·비활성·기간 안 → Inactive.
     *
     * @scenario display_period=always, is_active=false, now_position=within
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_always_inactive_within_is_inactive(): void
    {
        $this->assertStatusParity('always', false, 'within', BannerStatus::Inactive);
    }

    /**
     * 기간 지정·사용·시작 1초 전 → Scheduled.
     *
     * @scenario display_period=range, is_active=true, now_position=before_start
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_active_before_start_is_scheduled(): void
    {
        $this->assertStatusParity('range', true, 'before_start', BannerStatus::Scheduled);
    }

    /**
     * 기간 지정·사용·시작 정각 → Active.
     *
     * @scenario display_period=range, is_active=true, now_position=at_start
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_active_at_start_is_active(): void
    {
        $this->assertStatusParity('range', true, 'at_start', BannerStatus::Active);
    }

    /**
     * 기간 지정·사용·기간 안 → Active.
     *
     * @scenario display_period=range, is_active=true, now_position=within
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_active_within_is_active(): void
    {
        $this->assertStatusParity('range', true, 'within', BannerStatus::Active);
    }

    /**
     * 기간 지정·사용·종료 정각 → Active.
     *
     * @scenario display_period=range, is_active=true, now_position=at_end
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_active_at_end_is_active(): void
    {
        $this->assertStatusParity('range', true, 'at_end', BannerStatus::Active);
    }

    /**
     * 기간 지정·사용·종료 1초 뒤 → Ended.
     *
     * @scenario display_period=range, is_active=true, now_position=after_end
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_active_after_end_is_ended(): void
    {
        $this->assertStatusParity('range', true, 'after_end', BannerStatus::Ended);
    }

    /**
     * 기간 지정·비활성·시작 1초 전 → Inactive.
     *
     * @scenario display_period=range, is_active=false, now_position=before_start
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_inactive_before_start_is_inactive(): void
    {
        $this->assertStatusParity('range', false, 'before_start', BannerStatus::Inactive);
    }

    /**
     * 기간 지정·비활성·시작 정각 → Inactive.
     *
     * @scenario display_period=range, is_active=false, now_position=at_start
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_inactive_at_start_is_inactive(): void
    {
        $this->assertStatusParity('range', false, 'at_start', BannerStatus::Inactive);
    }

    /**
     * 기간 지정·비활성·기간 안 → Inactive.
     *
     * @scenario display_period=range, is_active=false, now_position=within
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_inactive_within_is_inactive(): void
    {
        $this->assertStatusParity('range', false, 'within', BannerStatus::Inactive);
    }

    /**
     * 기간 지정·비활성·종료 정각 → Inactive.
     *
     * @scenario display_period=range, is_active=false, now_position=at_end
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_inactive_at_end_is_inactive(): void
    {
        $this->assertStatusParity('range', false, 'at_end', BannerStatus::Inactive);
    }

    /**
     * 기간 지정·비활성·종료 1초 뒤 → Inactive.
     *
     * @scenario display_period=range, is_active=false, now_position=after_end
     *
     * @effects accessor_status_matches_query_filter, public_exposure_matches_active_status
     *
     * @return void 반환값 없음
     */
    public function test_range_inactive_after_end_is_inactive(): void
    {
        $this->assertStatusParity('range', false, 'after_end', BannerStatus::Inactive);
    }

    /**
     * 한 조합의 PHP 판정·SQL 판정·공개 노출이 기대 상태와 같은지 확인합니다.
     *
     * @param  string  $period  노출 기간 방식
     * @param  bool  $active  사용 여부
     * @param  string  $nowPosition  기준 시각 위치
     * @param  BannerStatus  $expected  기대 상태
     * @return void 반환값 없음
     */
    private function assertStatusParity(string $period, bool $active, string $nowPosition, BannerStatus $expected): void
    {
        $start = Carbon::parse(self::START, 'UTC');
        $end = Carbon::parse(self::END, 'UTC');
        $now = match ($nowPosition) {
            'before_start' => $start->copy()->subSecond(),
            'at_start' => $start->copy(),
            'within' => $start->copy()->addDays(4),
            'at_end' => $end->copy(),
            'after_end' => $end->copy()->addSecond(),
        };

        $banner = Banner::factory()->forPosition('home_hero')->create([
            'is_active' => $active,
            'display_period' => $period,
            'starts_at' => $period === 'range' ? $start : null,
            'ends_at' => $period === 'range' ? $end : null,
        ]);
        Carbon::setTestNow($now);

        $this->assertSame($expected, $banner->fresh()->statusAt($now), 'PHP 판정');

        $repository = app(BannerRepository::class);
        foreach (BannerStatus::cases() as $status) {
            $found = $repository->applyStatusFilter(Banner::query()->whereKey($banner->id), $status, $now)->exists();
            $this->assertSame($status === $expected, $found, 'SQL 판정 '.$status->value);
        }

        $exposed = $repository->exposedForPlacement(Placement::of('home_hero'), $now)->pluck('id')->all();
        $this->assertSame($expected === BannerStatus::Active, in_array($banner->id, $exposed, true), '공개 노출');
    }
}
