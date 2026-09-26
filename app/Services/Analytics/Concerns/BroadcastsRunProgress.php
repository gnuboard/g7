<?php

namespace App\Services\Analytics\Concerns;

use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Services\Analytics\ArchiveService;

/**
 * 실행 이력 한 행의 지금 상태를 관리자 채널로 방송합니다.
 *
 * 진단 화면은 `analytics_archive_runs` 의 한 행을 진행률의 출처로 삼고, 소켓이 켜져 있으면 그 행의
 * 방송을 받아 바를 움직이며 종료 상태에서 목록을 다시 받는다. 그런데 같은 표에 쓰는 실행이 집계만은
 * 아니다 — 소급 익명화와 확장 데이터 정리도 `running` 행을 만들고 진행률을 갱신한다. 그 둘이 방송하지
 * 않으면 소켓을 켠 사이트에서 배지는 떴는데 바는 멈춰 있고, 작업이 끝나도 새로고침 전까지 「실행 중」이
 * 그대로 남는다(#682 S3 라운드 2 — 설계 결정: 익명화·정리도 방송한다).
 *
 * 채널·이벤트 이름은 집계와 같은 것을 쓴다. 화면은 한 자리에서만 듣고, 행 안의 `run_type` 으로 무엇이
 * 도는지 가린다.
 */
trait BroadcastsRunProgress
{
    /**
     * 실행 이력의 지금 상태를 방송합니다.
     *
     * 방송 드라이버가 꺼져 있으면 `HookManager` 가 건너뛰므로 여기서 분기하지 않는다.
     *
     * @param  AnalyticsArchiveRun  $run  실행 이력
     * @return void 없음
     */
    protected function broadcastRunProgress(AnalyticsArchiveRun $run): void
    {
        HookManager::broadcast(ArchiveService::PROGRESS_CHANNEL, ArchiveService::PROGRESS_EVENT, [
            'type' => 'archive_progress',
            // 목록 API 와 같은 화면용 모양(시각은 사이트 표준시) — 두 길이 같은 값을 보게 한다.
            'data' => $run->toScreenArray(),
        ]);
    }
}
