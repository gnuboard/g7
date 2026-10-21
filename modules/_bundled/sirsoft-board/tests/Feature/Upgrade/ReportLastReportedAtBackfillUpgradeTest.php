<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_2_1;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.2.1 마지막 신고 시각 보정 업그레이드 테스트
 *
 * 1.2.0 까지 처리 중 케이스의 추가 신고가 `last_reported_at` 을 갱신하지 않아 처음 신고 시각에 머문 케이스를
 * 신고 기록의 가장 늦은 시각으로 앞당긴다. 이미 같거나 더 늦은 값·기록 없는 케이스는 건드리지 않고, 다시 돌려도 0건이다.
 */
#[Group('board')]
#[Group('upgrade')]
#[Group('report')]
class ReportLastReportedAtBackfillUpgradeTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'report-last-reported-backfill';
    }

    /**
     * 처리 중 추가 신고가 반영되지 않은 케이스·값이 빈 케이스는 신고 기록의 가장 늦은 시각으로 앞당겨진다.
     * 상태·생성 시각은 그대로다.
     *
     * @scenario upgrade_step=report_last_reported_at_backfill, existing_data=needs_change
     *
     * @effects upgrade_advances_stale_report_last_reported_at
     *
     * @return void 반환값 없음
     */
    public function test_upgrade_advances_stale_last_reported_at(): void
    {
        // 관찰 사례와 같은 꼴 — 검토 중 두 번째 신고가 들어왔는데 처음 신고 시각에 머묾
        $stale = $this->makeCase(10, 'review', '2026-10-17 12:06:51', ['2026-10-17 12:06:51', '2026-10-17 17:37:02']);
        // 값이 비었고 기록은 있다
        $empty = $this->makeCase(13, 'pending', null, ['2026-10-16 10:00:00', '2026-10-16 11:00:00']);

        $this->runUpgrade();

        $this->assertSame('2026-10-17 17:37:02', $this->lastReportedAt($stale));
        $this->assertSame('2026-10-16 11:00:00', $this->lastReportedAt($empty));
        $this->assertDatabaseHas('boards_reports', ['id' => $stale, 'status' => 'review', 'created_at' => '2026-10-17 12:06:51']);
    }

    /**
     * 이미 맞거나 기록보다 늦은 값(재접수 시각 등)은 되돌리지 않고, 다시 돌려도 바뀌는 행이 없다.
     *
     * @scenario upgrade_step=report_last_reported_at_backfill, existing_data=already_applied
     *
     * @effects report_last_reported_at_backfill_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_upgrade_keeps_current_or_later_values_and_is_idempotent(): void
    {
        $stale = $this->makeCase(10, 'review', '2026-10-17 12:06:51', ['2026-10-17 12:06:51', '2026-10-17 17:37:02']);
        $single = $this->makeCase(11, 'pending', '2026-10-17 13:08:57', ['2026-10-17 13:08:57']);
        $later = $this->makeCase(12, 'pending', '2026-10-19 09:00:00', ['2026-10-18 08:00:00']);

        $this->runUpgrade();

        $this->assertSame('2026-10-17 17:37:02', $this->lastReportedAt($stale));
        $this->assertSame('2026-10-17 13:08:57', $this->lastReportedAt($single));
        $this->assertSame('2026-10-19 09:00:00', $this->lastReportedAt($later), '더 늦은 값은 앞 시각으로 되돌리지 않는다');

        $before = DB::table('boards_reports')->orderBy('id')->get(['id', 'last_reported_at', 'updated_at'])->toArray();
        $this->runUpgrade();
        $after = DB::table('boards_reports')->orderBy('id')->get(['id', 'last_reported_at', 'updated_at'])->toArray();
        $this->assertEquals($before, $after);
    }

    /**
     * 신고 기록이 없는 케이스·신고가 없는 사이트는 아무것도 바뀌지 않는다.
     *
     * @scenario upgrade_step=report_last_reported_at_backfill, existing_data=nothing_to_change
     *
     * @effects report_last_reported_at_backfill_skips_cases_without_logs
     *
     * @return void 반환값 없음
     */
    public function test_upgrade_skips_cases_without_logs(): void
    {
        $this->runUpgrade(); // 신고 0건 사이트 — 예외 없이 끝난다

        $noLogs = $this->makeCase(14, 'rejected', null, []);

        $this->runUpgrade();

        $this->assertNull($this->lastReportedAt($noLogs));
    }

    /**
     * 신고 케이스와 신고 기록을 만듭니다.
     *
     * @param  int  $targetId  대상 게시글 ID
     * @param  string  $status  케이스 상태
     * @param  string|null  $lastReportedAt  저장해 둘 마지막 신고 시각
     * @param  array<int, string>  $logTimes  신고 기록 시각들(첫 값이 케이스 생성 시각)
     * @return int 케이스 ID
     */
    private function makeCase(int $targetId, string $status, ?string $lastReportedAt, array $logTimes): int
    {
        $createdAt = $logTimes[0] ?? '2026-10-15 00:00:00';

        $reportId = DB::table('boards_reports')->insertGetId([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $targetId,
            'status' => $status,
            'metadata' => json_encode([]),
            'last_reported_at' => $lastReportedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach ($logTimes as $at) {
            DB::table('boards_report_logs')->insert([
                'report_id' => $reportId,
                'reporter_id' => $this->createUser()->id,
                'reason_type' => 'spam',
                'reason_detail' => '스팸입니다.',
                'snapshot' => json_encode([]),
                'metadata' => json_encode([]),
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $reportId;
    }

    /**
     * 케이스의 마지막 신고 시각을 문자열로 읽습니다.
     *
     * @param  int  $reportId  케이스 ID
     * @return string|null 마지막 신고 시각
     */
    private function lastReportedAt(int $reportId): ?string
    {
        $value = DB::table('boards_reports')->where('id', $reportId)->value('last_reported_at');

        return $value === null ? null : (string) $value;
    }

    /**
     * 1.2.1 업그레이드 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_2_1)->run(new UpgradeContext('1.2.0', '1.2.1', '1.2.1', 'extension-upgrade'));
    }
}
