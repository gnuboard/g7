<?php

namespace Tests\Feature\Analytics;

use App\Analytics\NotificationCooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 같은 경고는 하루 한 번 — 알림 쿨다운.
 *
 * 집계는 매시, 액션 상한은 수집 요청마다 판정한다. 쿨다운이 없으면 원인이 고쳐지기 전까지 같은
 * 알림이 관리자 알림함을 채운다. 세 자리(집계 실패·원장 한계·방문당 상한)가 이 한 판정을 쓴다.
 */
class NotificationCooldownTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 날 두 번째는 막고, 종류가 다르거나 다음 날이면 다시 허용한다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects same_alert_is_sent_once_a_day
     */
    public function test_same_type_notifies_once_per_site_day(): void
    {
        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'Asia/Seoul']);
        $this->travelTo(Carbon::parse('2026-09-01 10:00:00', 'UTC'));

        $cooldown = app(NotificationCooldown::class);

        $this->assertTrue($cooldown->shouldNotify('archive_failed'));
        $this->assertFalse($cooldown->shouldNotify('archive_failed'), '같은 날 두 번째');
        $this->assertTrue($cooldown->shouldNotify('ledger_limit_exceeded'), '종류가 다르면 따로 센다');

        // 사이트 자정(서울 00:00 = UTC 15:00)을 넘기면 새날이다.
        $this->travelTo(Carbon::parse('2026-09-01 15:00:01', 'UTC'));
        $this->assertTrue($cooldown->shouldNotify('archive_failed'));
    }
}
