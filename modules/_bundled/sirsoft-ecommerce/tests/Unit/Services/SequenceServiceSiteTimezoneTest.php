<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Carbon\Carbon;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Enums\SequenceAlgorithm;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SequenceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * SequenceService 의 날짜 기준 테스트 (#160 D3)
 *
 * 주문·클레임 번호의 날짜·시각 부분과 일일 순번 리셋은 사이트 시간대의 날짜를 쓴다.
 */
class SequenceServiceSiteTimezoneTest extends ModuleTestCase
{
    private SequenceService $service;

    /** @var MockInterface&SequenceRepositoryInterface */
    private $repository;

    /**
     * 저장소를 모의 객체로 바꾸고 사이트 시간대·고정 시각을 맞춥니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['telescope.enabled' => false, 'app.default_user_timezone' => 'Asia/Seoul']);
        $this->repository = Mockery::mock(SequenceRepositoryInterface::class);
        $this->service = new SequenceService($this->repository);
        // UTC 11-04 16:30 = 서울 11-05 01:30
        Carbon::setTestNow(Carbon::parse('2026-11-04 16:30:00', 'UTC'));
    }

    /**
     * 고정 시각과 모의 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 타임스탬프 번호의 날짜·시각 부분은 사이트 시간대다.
     *
     * @scenario surface=order_number_date
     *
     * @effects order_number_date_uses_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_timestamp_code_uses_site_timezone_date(): void
    {
        $sequence = new Sequence([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::TIMESTAMP->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => PHP_INT_MAX,
            'cycle' => false,
            'pad_length' => 0,
            'max_history_count' => 0,
        ]);

        $this->repository->shouldReceive('findByType')->once()->with(SequenceType::ORDER)->andReturn($sequence);
        $this->repository->shouldReceive('findByTypeForUpdate')->once()->with(SequenceType::ORDER)->andReturn($sequence);
        $this->repository->shouldReceive('codeExists')->andReturn(false);
        $this->repository->shouldReceive('insertCode')->once();
        $this->repository->shouldReceive('updateCurrentValue')->once()->andReturn(true);

        $code = $this->service->generateCode(SequenceType::ORDER);

        $this->assertStringStartsWith('20261105-0130', $code);
    }

    /**
     * 일일 리셋은 사이트 날짜로 넘어간다 — UTC 로는 아직 11-04 여도 서울이 11-05 면 리셋하고 사이트 날짜를 남긴다.
     *
     * @scenario surface=daily_reset
     *
     * @effects daily_sequence_reset_uses_site_day
     *
     * @return void 반환값 없음
     */
    public function test_daily_reset_follows_site_day(): void
    {
        $sequence = new Sequence([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::DAILY->value,
            'prefix' => null,
            'current_value' => 41,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999,
            'cycle' => false,
            'pad_length' => 4,
            'last_reset_date' => '2026-11-04',
        ]);

        $resetDates = [];
        $this->repository->shouldReceive('findByTypeForUpdate')->once()->with(SequenceType::ORDER)->andReturn($sequence);
        $this->repository->shouldReceive('updateLastResetDate')->once()
            ->andReturnUsing(function ($seq, Carbon $date) use (&$resetDates) {
                $resetDates[] = $date->format('Y-m-d');

                return true;
            });
        $this->repository->shouldReceive('updateCurrentValue')->once()->with($sequence, 1)->andReturn(true);

        $next = $this->service->getNextSequence(SequenceType::ORDER);

        $this->assertSame(1, $next);
        $this->assertSame(['2026-11-05'], $resetDates);
    }

    /**
     * 실제 DB 왕복 — 사이트 날짜로 저장한 리셋 날짜(`date` 캐스트)를 다시 읽어도 같은 사이트 날짜 안에서는 리셋하지 않고,
     * 사이트 자정을 넘을 때만 리셋한다.
     *
     * @scenario surface=daily_reset
     *
     * @effects daily_sequence_reset_uses_site_day
     *
     * @return void 반환값 없음
     */
    public function test_daily_reset_round_trips_through_the_database(): void
    {
        Sequence::query()->updateOrCreate(['type' => SequenceType::SHIPPING->value], [
            'algorithm' => SequenceAlgorithm::DAILY->value,
            'prefix' => null,
            'current_value' => 41,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999,
            'cycle' => false,
            'pad_length' => 4,
            'last_reset_date' => '2026-11-04',
        ]);
        $service = new SequenceService(app(SequenceRepositoryInterface::class));
        $storedResetDate = fn (): string => (string) Sequence::query()->where('type', SequenceType::SHIPPING->value)->value('last_reset_date');

        // 서울 11-05 01:30 — 사이트 날짜가 바뀌어 리셋하고 사이트 날짜를 저장한다.
        $this->assertSame(1, $service->getNextSequence(SequenceType::SHIPPING));
        $this->assertStringStartsWith('2026-11-05', $storedResetDate());
        // 다시 읽은 저장값과 같은 사이트 날짜 — 리셋하지 않는다.
        $this->assertSame(2, $service->getNextSequence(SequenceType::SHIPPING));

        // 서울 11-05 23:59 — 아직 같은 날.
        Carbon::setTestNow(Carbon::parse('2026-11-05 14:59:00', 'UTC'));
        $this->assertSame(3, $service->getNextSequence(SequenceType::SHIPPING));

        // 서울 11-06 00:00 — 사이트 자정을 넘어 리셋한다(UTC 로는 아직 11-05 15:00).
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00:00', 'UTC'));
        $this->assertSame(1, $service->getNextSequence(SequenceType::SHIPPING));
        $this->assertStringStartsWith('2026-11-06', $storedResetDate());
    }
}
