<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use Illuminate\Console\Command;

/**
 * 레이아웃 버전의 변경 요약(changes_summary)을 현재 알고리즘으로 재계산하는 커맨드
 *
 * 버전 변경량 측정이 키 경로 단위에서 라인 단위(버전 비교 diff 뷰와 동일 SSoT)로
 * 바뀌기 전에 저장된 버전들은 옛 기준의 changes_summary 를 담고 있어, 버전 목록의
 * 변경량이 상세 diff 와 불일치한다. 본 커맨드로 기존 버전을 일괄 재계산해 정합시킨다.
 */
class RecalculateLayoutVersionDiffsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'layout-versions:recalculate-diffs';

    /**
     * The console command description.
     */
    protected $description = '레이아웃 버전의 변경 요약을 현재 라인 단위 알고리즘으로 재계산합니다';

    /**
     * Execute the console command.
     *
     * @param  LayoutVersionRepositoryInterface  $repository  버전 저장소
     * @return int 명령 실행 결과 코드
     */
    public function handle(LayoutVersionRepositoryInterface $repository): int
    {
        $this->info('레이아웃 버전 변경 요약 재계산을 시작합니다...');

        $updated = $repository->recalculateAllChangeSummaries();

        $this->info("버전 {$updated}건의 변경 요약을 재계산했습니다.");

        return self::SUCCESS;
    }
}
