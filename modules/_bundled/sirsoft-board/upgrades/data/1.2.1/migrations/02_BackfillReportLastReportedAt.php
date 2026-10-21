<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_2_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 신고 케이스의 마지막 신고 시각(`last_reported_at`)을 신고 기록의 가장 늦은 시각으로 맞춥니다.
 *
 * 1.2.0 까지는 접수·검토 중인 케이스에 다른 회원의 신고가 더해져도 `last_reported_at` 을 갱신하지 않아,
 * 여러 번 신고된 케이스가 처음 신고 시각에 머물렀다 — 관리자 신고 목록 정렬·신고일 칸·기간 거르개·대시보드
 * 미처리 목록이 실제 마지막 신고보다 이른 시각을 썼다. 1.2.1 부터 새 신고마다 갱신하므로 이미 쌓인 케이스를
 * 신고 기록(`boards_report_logs`) 최대 시각으로 한 번 맞춘다.
 *
 * 앞당기기만 한다 — 지금 값이 비었거나 기록 최대 시각보다 이른 행만 바꾸고, 더 늦은 값(재접수 시각 등)은 그대로 둔다.
 * idempotent: 맞출 행이 없으면 아무것도 하지 않는다(두 번째 실행 0건).
 * V-1 안전: Facades\DB/Schema 만 사용하고 Report·ReportLog 모델을 참조하지 않는다. 테이블명은 1.2.1 시점 리터럴.
 */
class BackfillReportLastReportedAt implements DataMigration
{
    /** 1.2.1 시점 신고 케이스 테이블 */
    private const REPORTS = 'boards_reports';

    /** 1.2.1 시점 신고 기록(신고자별) 테이블 */
    private const LOGS = 'boards_report_logs';

    /** 로그에 남길 대상 ID 표본 수 */
    private const SAMPLE = 20;

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillReportLastReportedAt';
    }

    /**
     * 신고 기록 최대 시각보다 이른 마지막 신고 시각을 앞당깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach ([self::REPORTS, self::LOGS] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[board:1.2.1] {$table} 테이블 미존재 — 마지막 신고 시각 보정 스킵");

                return;
            }
        }

        if (! Schema::hasColumn(self::REPORTS, 'last_reported_at')) {
            $context->logger->warning('[board:1.2.1] '.self::REPORTS.'.last_reported_at 컬럼 없음 — 마지막 신고 시각 보정 스킵');

            return;
        }

        $latest = DB::table(self::LOGS)
            ->select('report_id')
            ->selectRaw('MAX(created_at) as latest_reported_at')
            ->groupBy('report_id');

        $rows = DB::table(self::REPORTS.' as r')
            ->joinSub($latest, 'l', 'l.report_id', '=', 'r.id')
            ->whereNotNull('l.latest_reported_at')
            ->where(fn ($query) => $query
                ->whereNull('r.last_reported_at')
                ->orWhereColumn('r.last_reported_at', '<', 'l.latest_reported_at'))
            ->orderBy('r.id')
            ->get(['r.id', 'r.last_reported_at', 'l.latest_reported_at']);

        if ($rows->isEmpty()) {
            $context->logger->info('[board:1.2.1] 마지막 신고 시각이 신고 기록보다 이른 케이스 없음 — 보정 0건');

            return;
        }

        $updated = 0;
        foreach ($rows as $row) {
            // 조건을 다시 걸어 실행 중 들어온 새 신고가 기록한 더 늦은 시각을 되돌리지 않는다.
            $updated += DB::table(self::REPORTS)
                ->where('id', $row->id)
                ->where(fn ($query) => $query
                    ->whereNull('last_reported_at')
                    ->orWhere('last_reported_at', '<', $row->latest_reported_at))
                ->update(['last_reported_at' => $row->latest_reported_at]);
        }

        $context->logger->info("[board:1.2.1] 마지막 신고 시각 보정 {$updated}건(대상 {$rows->count()}건)", [
            'sample' => $rows->take(self::SAMPLE)->map(fn ($row) => [
                'report_id' => $row->id,
                'from' => $row->last_reported_at,
                'to' => $row->latest_reported_at,
            ])->all(),
        ]);
    }
}
