<?php

namespace App\Console\Commands;

use App\Services\StorageLeftoverPruneService;
use Illuminate\Console\Command;

/**
 * 스토리지 잔존물 정리 커맨드
 *
 * 확장/코어 업데이트·설치가 중단되며 남긴 임시 산출물(_pending 스테이징 ·
 * storage/app/temp · vendor 번들 스테이징)과 오래된 백업본, 레거시 브라우저 로그를
 * 회수한다. 로직은 `StorageLeftoverPruneService` 가 소유한다
 * (`CleanupExtensionBundlesCommand` 위임 패턴 미러).
 */
class PruneStorageLeftoversCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'storage:prune-leftovers
                            {--days=3 : 임시 산출물 보존일 (mtime 기준, 경과분 삭제)}
                            {--backup-days=30 : 백업 보존일 (identifier 별 최신 1개는 나이와 무관하게 보존)}
                            {--dry-run : 실제 삭제 없이 대상 목록만 확인}';

    /**
     * The console command description.
     */
    protected $description = '중단된 업데이트·설치가 남긴 임시 산출물과 오래된 백업본을 정리합니다';

    /**
     * 대상군 키 → 출력 라벨.
     *
     * @var array<string, string>
     */
    private const GROUP_LABELS = [
        'staging' => '업데이트 스테이징 잔존',
        'temp' => '코어 임시 산출물',
        'vendor_bundle_staging' => 'vendor 번들 스테이징',
        'extension_backups' => '확장 백업',
        'core_backups' => '코어 백업',
        'legacy_browser_log' => '레거시 브라우저 로그',
    ];

    /**
     * Execute the console command.
     *
     * @param  StorageLeftoverPruneService  $service  잔존물 정리 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(StorageLeftoverPruneService $service): int
    {
        $days = max(0, (int) $this->option('days'));
        $backupDays = max(0, (int) $this->option('backup-days'));
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->prune($days, $backupDays, $dryRun);

        $total = 0;

        foreach (self::GROUP_LABELS as $key => $label) {
            $paths = $result[$key] ?? [];
            $count = count($paths);
            $total += $count;

            $this->line("{$label}: {$count}건");

            if ($dryRun) {
                foreach ($paths as $path) {
                    $this->line("  - {$path}");
                }
            }
        }

        $this->info($dryRun
            ? "삭제 대상 합계 {$total}건 (dry-run — 실제 삭제 없음)"
            : "합계 {$total}건이 삭제되었습니다. (임시 보존일: {$days}일, 백업 보존일: {$backupDays}일)");

        return self::SUCCESS;
    }
}
