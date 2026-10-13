<?php

namespace Tests\Feature\Console;

use App\Models\NotificationDelivery;
use App\Services\NotificationDeliveryGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 알림 수신자별 발송 기록 정리 명령 테스트 (#139)
 */
class PruneNotificationDeliveriesCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * --days 보다 오래된 행만 지우고 지운 건수를 출력한다.
     *
     * @scenario recipients=within_chunk, rerun=none
     *
     * @effects stale_deliveries_pruned
     *
     * @return void 반환값 없음
     */
    public function test_prunes_rows_older_than_days_option(): void
    {
        $guard = app(NotificationDeliveryGuard::class);
        $this->travelTo('2026-10-01 00:00:00');
        $guard->claim('prune-old-1', 'type', 'mail', 'user:1');
        $guard->claim('prune-old-2', 'type', 'mail', 'user:2');
        $this->travelTo('2026-10-10 00:00:00');
        $guard->claim('prune-mid', 'type', 'mail', 'user:3');
        $this->travelTo('2026-10-12 00:00:00');

        $this->artisan('notification-delivery:prune', ['--days' => 7])
            ->expectsOutputToContain('2건')
            ->assertExitCode(0);

        $this->assertSame(['prune-mid'], NotificationDelivery::query()->where('event_id', 'like', 'prune-%')->pluck('event_id')->all());

        $this->artisan('notification-delivery:prune', ['--days' => 1])->assertExitCode(0);
        $this->assertSame(0, NotificationDelivery::query()->where('event_id', 'like', 'prune-%')->count());
    }
}
