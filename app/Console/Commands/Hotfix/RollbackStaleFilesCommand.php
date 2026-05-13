<?php

namespace App\Console\Commands\Hotfix;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Extension\Helpers\CoreBackupHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 자동 롤백 후 활성 디렉토리에 잔존할 수 있는 신 버전 신규 파일을 진단/정리하는 단발성
 * 결함 보정 도구.
 *
 * 결함 컨텍스트: 7.0.0-beta.5 이전의 코어 자동 롤백은 backup → 활성 overlay 복사 방식
 * 으로만 동작하여, 신 버전이 활성 디렉토리에 새로 추가한 파일은 백업에 없으므로 삭제되지
 * 않고 잔존했다. 결과적으로 `bootstrap/providers.php` 는 백업본(구버전) 으로 되돌아가지만
 * 디스크에는 신 ServiceProvider 파일이 잔존하여 `BindingResolutionException` 등 부팅
 * 부정합 발생.
 *
 * 도입 버전: 7.0.0-beta.6 신설.
 *
 * 동작:
 *  - `--backup` 미지정 시 `storage/app/core_backups/` 의 가장 최근 백업 자동 선택
 *  - 백업 디렉토리에 `_new_files_manifest.json` 이 있으면 그 기반으로 잔존 파일 후보 식별 (정확)
 *  - manifest 부재 시 보수적 진단 모드 (실제 정리는 운영자 수동)
 *  - 기본은 진단 모드 (실제 삭제 없이 후보 목록만 출력)
 *  - `--prune` 옵션 시 확인 프롬프트 후 정리. symlink/protected_paths 가드 적용
 *  - 정리 결과는 `storage/logs/hotfix_rollback_stale_files_<timestamp>.log` 기록
 *
 * @permanent — 7.0.0-beta.5 이전 사용자의 잔존 결함 회복을 위해 영구 보존. 향후 운영자가
 *              과거 롤백 흔적을 점검할 수 있도록 유지하며, deprecation 예정 없음.
 */
class RollbackStaleFilesCommand extends Command
{
    use HasUnifiedConfirm;

    protected $signature = 'hotfix:rollback-stale-files
        {--backup= : 특정 백업 디렉토리 경로 지정 (미지정 시 가장 최근 백업 자동 선택)}
        {--prune : 진단만이 아닌 실제 정리 수행 (확인 프롬프트 동반)}';

    protected $description = '[hotfix/beta.6 신설] 코어 자동 롤백 후 활성 디렉토리에 잔존할 수 있는 신 버전 신규 파일을 진단/정리합니다.';

    public function handle(): int
    {
        $backupPath = $this->resolveBackupPath();
        if ($backupPath === null) {
            $this->info('사용 가능한 백업이 없습니다 (`storage/app/core_backups/` 비어 있음).');

            return Command::SUCCESS;
        }

        $this->info("백업 디렉토리: {$backupPath}");

        $manifestPath = $backupPath.DIRECTORY_SEPARATOR.CoreBackupHelper::NEW_FILES_MANIFEST;
        $manifestAvailable = File::exists($manifestPath);

        if (! $manifestAvailable) {
            $this->warn('⚠ _new_files_manifest.json 부재 — 보수적 진단 모드로 진행합니다.');
            $this->line('  manifest 가 없으면 신 파일 vs 사용자 추가 파일을 정확히 구분할 수 없습니다.');
            $this->line('  beta.5 이전 사용자: beta.6 업그레이드 스텝이 manifest 를 사후 작성하므로');
            $this->line('  먼저 `php artisan core:execute-upgrade-steps --from=<버전> --to=7.0.0-beta.6 --force`');
            $this->line('  를 실행한 뒤 본 커맨드를 재실행하는 것을 권장합니다.');
            $this->newLine();
        }

        $candidates = $this->collectCandidates($backupPath, $manifestAvailable);

        if ($candidates['files'] === [] && $candidates['dirs'] === []) {
            $this->info('잔존 후보 없음 — 활성 디렉토리가 정상 상태입니다.');

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            '잔존 후보: 파일 %d개, 디렉토리 %d개',
            count($candidates['files']),
            count($candidates['dirs']),
        ));
        foreach (array_slice($candidates['files'], 0, 20) as $f) {
            $this->line("  · 파일: {$f}");
        }
        if (count($candidates['files']) > 20) {
            $this->line(sprintf('  ... (총 %d건, 상위 20건만 표시)', count($candidates['files'])));
        }
        foreach (array_slice($candidates['dirs'], 0, 10) as $d) {
            $this->line("  · 디렉토리: {$d}");
        }
        if (count($candidates['dirs']) > 10) {
            $this->line(sprintf('  ... (총 %d건, 상위 10건만 표시)', count($candidates['dirs'])));
        }

        if (! $this->option('prune')) {
            $this->newLine();
            $this->info('진단 모드: 실제 삭제하지 않았습니다. 정리하려면 `--prune` 옵션을 추가하세요.');

            return Command::SUCCESS;
        }

        if (! $manifestAvailable) {
            $this->newLine();
            $this->error('manifest 부재 상태에서는 자동 prune 을 수행하지 않습니다 (사용자 추가 파일 손실 위험).');
            $this->line('beta.6 업그레이드 스텝을 먼저 실행하여 manifest 를 사후 작성한 뒤 재시도하세요.');

            return Command::FAILURE;
        }

        $this->newLine();
        if (! $this->unifiedConfirm('위 후보를 활성 디렉토리에서 정리하시겠습니까?', false)) {
            $this->info('취소되었습니다.');

            return Command::SUCCESS;
        }

        $protectedPaths = (array) config('app.update.protected_paths', []);
        $result = CoreBackupHelper::pruneNewFiles($backupPath, base_path(), $protectedPaths);

        $this->newLine();
        $this->info(sprintf(
            '정리 완료: 파일 %d개, 디렉토리 %d개 제거. 보호 %d건, symlink skip %d건, 실패 %d건.',
            $result['removed_files'],
            $result['removed_dirs'],
            $result['protected_count'],
            $result['symlink_skipped'],
            $result['failed_count'],
        ));

        $logPath = storage_path(sprintf(
            'logs/hotfix_rollback_stale_files_%s.log',
            date('Ymd_His'),
        ));
        @file_put_contents($logPath, $this->buildLogEntry($backupPath, $result, $candidates));
        $this->line("로그: {$logPath}");

        Log::info('hotfix:rollback-stale-files 정리 완료', [
            'backup_path' => $backupPath,
            'result' => $result,
        ]);

        return Command::SUCCESS;
    }

    /**
     * `--backup` 옵션 또는 가장 최근 백업 디렉토리 경로를 반환.
     */
    private function resolveBackupPath(): ?string
    {
        $explicit = $this->option('backup');
        if ($explicit !== null && $explicit !== '') {
            $real = realpath($explicit);
            if ($real === false || ! is_dir($real)) {
                $this->error("지정된 백업 디렉토리가 존재하지 않습니다: {$explicit}");

                return null;
            }

            return $real;
        }

        $backups = CoreBackupHelper::listBackups();
        if ($backups === []) {
            return null;
        }
        // listBackups 는 정렬 보장이 약하므로 mtime 기준으로 다시 정렬
        usort($backups, fn ($a, $b) => filemtime($b['path']) <=> filemtime($a['path']));

        return $backups[0]['path'] ?? null;
    }

    /**
     * 잔존 후보 식별 — manifest 가 있으면 그 기반, 부재 시 빈 배열 반환 (운영자 수동 검토 안내).
     *
     * @return array{files:array<int,string>, dirs:array<int,string>}
     */
    private function collectCandidates(string $backupPath, bool $manifestAvailable): array
    {
        if (! $manifestAvailable) {
            return ['files' => [], 'dirs' => []];
        }

        $manifestPath = $backupPath.DIRECTORY_SEPARATOR.CoreBackupHelper::NEW_FILES_MANIFEST;
        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            return ['files' => [], 'dirs' => []];
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return ['files' => [], 'dirs' => []];
        }

        $files = [];
        foreach ((array) ($manifest['new_files'] ?? []) as $rel) {
            if (! is_string($rel) || $rel === '') {
                continue;
            }
            $absolute = base_path($rel);
            if (file_exists($absolute) || is_link($absolute)) {
                $files[] = $rel;
            }
        }

        $dirs = [];
        foreach ((array) ($manifest['new_dirs'] ?? []) as $rel) {
            if (! is_string($rel) || $rel === '') {
                continue;
            }
            $absolute = base_path($rel);
            if (is_dir($absolute) && ! is_link($absolute)) {
                $dirs[] = $rel;
            }
        }

        return ['files' => $files, 'dirs' => $dirs];
    }

    /**
     * 진단/정리 결과를 로그 텍스트로 빌드.
     */
    private function buildLogEntry(string $backupPath, array $result, array $candidates): string
    {
        $lines = [
            '=== hotfix:rollback-stale-files 실행 로그 ===',
            '날짜: '.date('Y-m-d H:i:s'),
            "백업 디렉토리: {$backupPath}",
            '',
            '결과:',
            sprintf('  파일 제거: %d', $result['removed_files']),
            sprintf('  디렉토리 제거: %d', $result['removed_dirs']),
            sprintf('  보호 경로 skip: %d', $result['protected_count']),
            sprintf('  symlink skip: %d', $result['symlink_skipped']),
            sprintf('  실패: %d', $result['failed_count']),
            '',
            '후보 목록 (실행 직전 스냅샷):',
        ];
        foreach ($candidates['files'] as $f) {
            $lines[] = "  · 파일: {$f}";
        }
        foreach ($candidates['dirs'] as $d) {
            $lines[] = "  · 디렉토리: {$d}";
        }

        return implode("\n", $lines)."\n";
    }
}
