<?php

namespace App\Console\Commands\Core;

use App\Extension\CoreVersionChecker;
use App\Extension\Helpers\CoreBackupHelper;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CoreUpdateCommand extends Command
{
    protected $signature = 'core:update
        {--force : 버전 비교 없이 강제 업데이트}
        {--no-backup : 백업 생성 건너뛰기}
        {--no-maintenance : 유지보수 모드 활성화 건너뛰기}
        {--local : 로컬 코드베이스를 업데이트 소스로 사용 (GitHub 스킵)}
        {--source= : 수동 업데이트용 소스 디렉토리 경로 (GitHub 다운로드 대신 지정 디렉토리 사용)}';

    protected $description = '그누보드7 코어를 최신 버전으로 업데이트합니다';

    private const TOTAL_STEPS = 11;

    /**
     * 커맨드를 실행합니다.
     *
     * @param  CoreUpdateService  $service  코어 업데이트 서비스
     * @return int 종료 코드
     */
    public function handle(CoreUpdateService $service): int
    {
        $backupPath = null;
        $maintenanceEnabled = false;
        $fromVersion = CoreVersionChecker::getCoreVersion();
        $toVersion = $fromVersion;
        $secret = null;
        $logEntries = [];

        $log = function (string $message) use (&$logEntries) {
            $logEntries[] = '['.date('H:i:s').'] '.$message;
        };

        $sourceDir = $this->option('source');

        try {
            // ── 시스템 요구사항 검증 (--source / --local 모드에서는 스킵) ──
            if (! $sourceDir && ! $this->option('local')) {
                $requirements = $service->checkSystemRequirements();
                if (! $requirements['valid']) {
                    $this->error(__('settings.core_update.system_requirements_failed'));
                    foreach ($requirements['errors'] as $error) {
                        $this->error("  - {$error}");
                    }
                    $this->newLine();
                    $this->info(__('settings.core_update.manual_update_guide'));

                    return Command::FAILURE;
                }

                $log('사용 가능한 추출 방법: '.implode(', ', $requirements['available_methods']));
            }

            // --source 옵션 검증
            if ($sourceDir) {
                $sourceDir = realpath($sourceDir);
                if (! $sourceDir || ! is_dir($sourceDir)) {
                    $this->error('지정된 소스 디렉토리가 존재하지 않습니다: '.($this->option('source')));

                    return Command::FAILURE;
                }
                $log("수동 업데이트 모드: 소스 디렉토리 = {$sourceDir}");
            }

            // ── Step 1: 업데이트 확인 (프로그레스바 없이) ──
            $this->info(__('settings.core_update.step_check').'...');
            $log('업데이트 확인 시작');

            if ($sourceDir || $this->option('local')) {
                // --source / --local 모드: GitHub 스킵, 소스의 버전 읽기 또는 시뮬레이션
                if ($sourceDir) {
                    $sourceConfigPath = $sourceDir.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                    if (file_exists($sourceConfigPath)) {
                        // env() 호출을 우회하여 소스의 default 버전값을 직접 파싱
                        $configContent = file_get_contents($sourceConfigPath);
                        if (preg_match("/['\"]version['\"]\s*=>\s*env\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $versionMatch)) {
                            $toVersion = $versionMatch[1];
                        } else {
                            $sourceConfig = include $sourceConfigPath;
                            $toVersion = $sourceConfig['version'] ?? $fromVersion;
                        }
                    }
                    $log("수동 업데이트 모드: {$fromVersion} → {$toVersion}");
                } else {
                    $parts = explode('.', $fromVersion);
                    $parts[count($parts) - 1] = (int) end($parts) + 1;
                    $toVersion = implode('.', $parts);
                    $log("로컬 모드: {$fromVersion} → {$toVersion} 시뮬레이션");
                }
            } else {
                $updateInfo = $service->checkForUpdates();
                $toVersion = $updateInfo['latest_version'];

                if (! empty($updateInfo['check_failed'])) {
                    $this->error('업데이트 확인 실패: '.($updateInfo['error'] ?? __('settings.core_update.unknown_error')));

                    return Command::FAILURE;
                }

                if (! $updateInfo['update_available'] && ! $this->option('force')) {
                    $this->info("현재 최신 버전입니다: {$fromVersion}");

                    return Command::SUCCESS;
                }
            }

            // 사용자 확인
            $this->newLine();
            $this->info("현재 버전: {$fromVersion}");
            $this->info("업데이트 버전: {$toVersion}");
            $this->newLine();

            if (! $this->confirm('코어를 업데이트하시겠습니까?')) {
                return Command::SUCCESS;
            }

            // Step 2~10 프로그레스바 (9단계)
            $remainingSteps = self::TOTAL_STEPS - 1;
            $bar = $this->output->createProgressBar($remainingSteps);
            $bar->setFormat(' %current%/%max% [%bar%] %message%');
            $bar->start();

            $onProgress = function (?string $step, ?string $detail) use ($bar) {
                if ($detail) {
                    $bar->setMessage($detail);
                }
                $bar->display();
            };

            // ── Step 2: _pending 경로 검증 ──
            $bar->setMessage(__('settings.core_update.step_validate_pending'));
            $bar->advance();
            $log('_pending 경로 검증');

            $validation = $service->validatePendingPath();
            if (! $validation['valid']) {
                $bar->finish();
                $this->newLine(2);
                $this->error('_pending 디렉토리 문제:');
                foreach ($validation['errors'] as $error) {
                    $this->error("  - {$error}");
                }
                $this->info("경로: {$validation['path']}");
                $this->info("소유자: {$validation['owner']}, 그룹: {$validation['group']}, 퍼미션: {$validation['permissions']}");

                return Command::FAILURE;
            }

            // ── Step 3: Maintenance 모드 ──
            $bar->setMessage(__('settings.core_update.step_maintenance'));
            $bar->advance();

            if (! $this->option('no-maintenance')) {
                $secret = $service->enableMaintenanceMode();
                $maintenanceEnabled = true;
                $log("유지보수 모드 활성화 (secret: {$secret})");
            }

            // ── Step 4: 다운로드 ──
            if ($sourceDir) {
                $bar->setMessage('소스 디렉토리 검증 중...');
            } elseif ($this->option('local')) {
                $bar->setMessage('로컬 소스 복제 중...');
            } else {
                $bar->setMessage(__('settings.core_update.step_download'));
            }
            $bar->advance();

            if ($sourceDir) {
                $log('수동 업데이트: 소스 디렉토리 검증');
                $service->validatePendingUpdate($sourceDir);

                // 원본 소스를 _pending으로 복제 (원본 보호)
                $pendingPath = $service->copySourceToPending($sourceDir, $onProgress);
                $log("소스 디렉토리를 _pending으로 복제 완료: {$pendingPath}");
            } elseif ($this->option('local')) {
                $log('로컬 소스 복제 시작');
                $pendingPath = $service->prepareLocalSource($onProgress);
                $log('로컬 소스 복제 완료');
            } else {
                $log("버전 {$toVersion} 다운로드 시작");
                $pendingPath = $service->downloadUpdate($toVersion, $onProgress);
                $log('다운로드 및 검증 완료');
            }

            // ── Step 5: 백업 ──
            $bar->setMessage(__('settings.core_update.step_backup'));
            $bar->advance();

            if (! $this->option('no-backup')) {
                $backupPath = $service->createBackup($onProgress);
                $log("백업 생성 완료: {$backupPath}");
            }

            // ── Step 6: _pending에서 Composer Install ──
            $composerSkipped = $service->isComposerUnchangedForCore($pendingPath);

            if ($composerSkipped) {
                $bar->setMessage('Composer 의존성 변경 없음 — 스킵');
                $bar->advance();
                $log('composer.json/lock 변경 없음, composer install 스킵');
            } else {
                $bar->setMessage(__('settings.core_update.step_composer'));
                $bar->advance();
                $log('_pending에서 composer install 시작');

                $service->runComposerInstallInPending($pendingPath, $onProgress);
                $log('_pending에서 composer install 완료');
            }

            // ── Step 7: 파일 적용 ──
            $bar->setMessage(__('settings.core_update.step_apply'));
            $bar->advance();
            $log('코어 파일 덮어쓰기 시작');

            $service->applyUpdate($pendingPath, $onProgress);
            $log('코어 파일 덮어쓰기 완료');

            // ── Step 8: vendor 디렉토리 복사 (_pending → 운영) ──
            if ($composerSkipped) {
                $bar->setMessage('vendor 복사 스킵');
                $bar->advance();
                $log('composer 스킵 → vendor 디렉토리 복사 불필요');
            } else {
                $bar->setMessage(__('settings.core_update.step_composer_prod'));
                $bar->advance();
                $log('vendor 디렉토리 복사 시작');

                $service->copyVendorFromPending($pendingPath, $onProgress);
                $log('vendor 디렉토리 복사 완료');
            }

            // ── Step 9: Migration + 역할/메뉴 동기화 ──
            $bar->setMessage(__('settings.core_update.step_migration'));
            $bar->advance();
            $log('마이그레이션, 역할/메뉴/메일템플릿 동기화 실행');

            $service->runMigrations();
            $service->syncCoreRolesAndPermissions();
            $service->syncCoreMenus();
            $service->syncCoreMailTemplates();
            $log('마이그레이션, 역할/메뉴/메일템플릿 동기화 완료');

            // ── Step 10: Upgrade Steps ──
            $bar->setMessage(__('settings.core_update.step_upgrade'));
            $bar->advance();
            $log('업그레이드 스텝 실행');

            $service->runUpgradeSteps($fromVersion, $toVersion, function (string $version) use ($bar, $log) {
                $bar->setMessage(__('settings.core_update.step_upgrade')." ({$version})");
                $bar->display();
                $log("업그레이드 스텝 실행: {$version}");
            });
            $log('업그레이드 스텝 완료');

            // ── Step 11: Cleanup ──
            $bar->setMessage(__('settings.core_update.step_cleanup'));
            $bar->advance();

            $service->updateVersionInEnv($toVersion);
            $service->clearAllCaches();

            $service->cleanupPending($pendingPath);

            if ($backupPath) {
                CoreBackupHelper::deleteBackup($backupPath);
            }

            if ($maintenanceEnabled) {
                $service->disableMaintenanceMode();
                $maintenanceEnabled = false;
            }

            $log('정리 완료');

            $bar->finish();
            $this->newLine(2);

            // 설치 로그 저장
            $this->saveUpdateLog($logEntries, $fromVersion, $toVersion, true);

            $this->info("그누보드7 코어가 {$toVersion} 버전으로 업데이트되었습니다!");
            $this->newLine();
            $this->warn('_bundled 확장이 업데이트되었습니다. 활성 확장에 반영하려면 다음 커맨드를 실행하세요:');
            $this->line('  php artisan module:update <identifier> --force');
            $this->line('  php artisan plugin:update <identifier> --force');
            $this->line('  php artisan template:update <identifier> --force');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $bar->finish();
            $this->newLine(2);

            $log("오류 발생: {$e->getMessage()}");

            // 롤백
            $restoreSuccess = false;
            $failedTargets = [];

            if ($backupPath) {
                $this->warn('백업에서 복원 중...');
                $log('백업 복원 시작');

                try {
                    $failedTargets = $service->restoreFromBackup($backupPath, $onProgress);

                    if (empty($failedTargets)) {
                        $log('백업 복원 완료');
                        $this->info('백업에서 복원되었습니다.');
                        $restoreSuccess = true;
                    } else {
                        $log('백업 부분 복원 완료 (실패: '.implode(', ', $failedTargets).')');
                        $this->warn('백업에서 부분 복원되었습니다.');
                        $this->error('복원 실패 항목: '.implode(', ', $failedTargets));
                    }
                } catch (\Throwable $restoreError) {
                    $log("백업 복원 실패: {$restoreError->getMessage()}");
                    $this->error("백업 복원 실패: {$restoreError->getMessage()}");
                }
            }

            // _pending 정리 (실패 시에도)
            if (! empty($pendingPath)) {
                $service->cleanupPending($pendingPath);
            }

            // 실패 리포트
            $reportPath = $service->generateFailureReport($e, $fromVersion, $toVersion);

            // 설치 로그 저장
            $this->saveUpdateLog($logEntries, $fromVersion, $toVersion, false);

            $this->error("코어 업데이트 실패: {$e->getMessage()}");
            $this->info("실패 리포트: {$reportPath}");

            if ($maintenanceEnabled) {
                $this->newLine();

                if ($restoreSuccess) {
                    // 완전 복원 성공 → 자동 유지보수 해제
                    try {
                        $service->disableMaintenanceMode();
                        $maintenanceEnabled = false;
                        $this->info('복원 완료: 유지보수 모드가 해제되었습니다.');
                    } catch (\Throwable) {
                        $this->warn('유지보수 모드 해제 실패. 수동으로 해제하세요: php artisan up');
                    }
                } else {
                    // 복원 실패 또는 부분 복원 → 수동 복구 안내
                    $this->warn('유지보수 모드가 유지됩니다.');

                    if (! empty($failedTargets) || ! $backupPath) {
                        $this->newLine();
                        $this->error('수동 복구가 필요합니다:');
                        if ($backupPath) {
                            $this->line("  1. 백업에서 수동 복원: cp -r {$backupPath}/* ".base_path().'/');
                        }
                        $this->line('  '.($backupPath ? '2' : '1').'. Composer 재설치: composer install --no-dev --optimize-autoloader');
                        $this->line('  '.($backupPath ? '3' : '2').'. 유지보수 해제: php artisan up');
                    } else {
                        $this->info('이전 버전으로 사이트를 운영하려면: php artisan up');
                    }

                    if ($secret) {
                        $this->info("관리자 접근: {$secret}");
                    }
                }
            }

            return Command::FAILURE;
        }
    }

    /**
     * 업데이트 로그를 파일로 저장합니다.
     *
     * @param  array  $entries  로그 엔트리 목록
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  종료 버전
     * @param  bool  $success  성공 여부
     */
    private function saveUpdateLog(array $entries, string $fromVersion, string $toVersion, bool $success): void
    {
        $timestamp = date('Ymd_His');
        $status = $success ? 'success' : 'failed';
        $logPath = storage_path("logs/core_update_{$status}_{$timestamp}.log");

        $header = implode("\n", [
            '=== 그누보드7 코어 업데이트 로그 ===',
            '상태: '.($success ? '성공' : '실패'),
            '날짜: '.date('Y-m-d H:i:s'),
            "시작 버전: {$fromVersion}",
            "대상 버전: {$toVersion}",
            '',
            '=== 실행 로그 ===',
        ]);

        $content = $header."\n".implode("\n", $entries)."\n";

        file_put_contents($logPath, $content);

        Log::info("코어 업데이트 로그 저장: {$logPath}");
    }
}
