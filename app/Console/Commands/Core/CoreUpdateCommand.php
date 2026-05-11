<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Core\Concerns\BundledExtensionUpdatePrompt;
use App\Exceptions\UpgradeHandoffException;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Extension\CoreVersionChecker;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorMode;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CoreUpdateCommand extends Command
{
    use BundledExtensionUpdatePrompt;
    use HasUnifiedConfirm;

    protected $signature = 'core:update
        {--force : 버전 비교 없이 강제 업데이트}
        {--no-backup : 백업 생성 건너뛰기}
        {--no-maintenance : 유지보수 모드 활성화 건너뛰기}
        {--local : 로컬 코드베이스를 업데이트 소스로 사용 (GitHub 스킵)}
        {--source= : 수동 업데이트용 소스 디렉토리 경로 (GitHub 다운로드 대신 지정 디렉토리 사용)}
        {--zip= : 수동 업데이트용 ZIP 파일 경로 (GitHub 다운로드 대신 지정 ZIP 추출 사용)}
        {--vendor-mode=auto : vendor 설치 모드 (auto|composer|bundled)}';

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
        // 코어 업데이트 진행 플래그: 업데이트 중에는 일시적 버전 불일치로 인한 자동 비활성화를
        // 차단해야 한다 (CoreServiceProvider::validateAndDeactivateIncompatibleExtensions 및
        // spawn 자식이 모두 이 플래그를 감지). 커맨드 종료 시 프로세스 env 도 함께 소멸하므로
        // 정리 로직 불필요.
        $_ENV['G7_UPDATE_IN_PROGRESS'] = '1';
        $_SERVER['G7_UPDATE_IN_PROGRESS'] = '1';
        putenv('G7_UPDATE_IN_PROGRESS=1');

        $backupPath = null;
        $maintenanceEnabled = false;
        $fromVersion = CoreVersionChecker::getCoreVersion();
        $toVersion = $fromVersion;
        $secret = null;
        $logEntries = [];
        $ownershipSnapshot = [];

        $log = function (string $message) use (&$logEntries) {
            $logEntries[] = '['.date('H:i:s').'] '.$message;
        };

        $this->warnIfFileOwnerMismatch($log);

        $sourceDir = $this->option('source');
        $zipPath = $this->option('zip');

        try {
            // --source 와 --zip 동시 지정 금지
            if ($sourceDir && $zipPath) {
                $this->error('--source 와 --zip 은 동시에 지정할 수 없습니다. 하나만 선택하세요.');

                return Command::FAILURE;
            }
            if (($sourceDir || $zipPath) && $this->option('local')) {
                $this->error('--local 은 --source / --zip 과 동시에 사용할 수 없습니다.');

                return Command::FAILURE;
            }

            // --zip 옵션 검증 (zip 은 ZipArchive/unzip 추출이 필요하므로 시스템 요구사항 검증 대상)
            if ($zipPath) {
                $resolvedZip = realpath($zipPath);
                if (! $resolvedZip || ! is_file($resolvedZip)) {
                    $this->error('지정된 ZIP 파일이 존재하지 않습니다: '.$zipPath);

                    return Command::FAILURE;
                }
                $zipPath = $resolvedZip;
            }

            // ── 시스템 요구사항 검증 (--source / --local 모드에서는 스킵, --zip 은 필요) ──
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

            if ($zipPath) {
                $log("수동 업데이트 모드: ZIP 파일 = {$zipPath}");
            }

            // ── Step 1: 업데이트 확인 (프로그레스바 없이) ──
            $this->info(__('settings.core_update.step_check').'...');
            $log('업데이트 확인 시작');

            if ($sourceDir || $this->option('local') || $zipPath) {
                // --source / --local / --zip 모드: GitHub 스킵, 소스의 버전 읽기
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
                } elseif ($zipPath) {
                    // --zip 모드: 버전은 추출 후에만 확인 가능. 일단 fromVersion 유지하고 Step 4 다운로드 후 재판별.
                    $toVersion = $fromVersion;
                    $log("ZIP 업데이트 모드: 버전은 추출 후 판별 ({$zipPath})");
                } else {
                    // --local 모드: 현재 코드베이스의 config/app.php에서 버전을 직접 파싱
                    $localConfigPath = base_path('config'.DIRECTORY_SEPARATOR.'app.php');
                    $configContent = file_get_contents($localConfigPath);
                    if (preg_match("/['\"]version['\"]\s*=>\s*env\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $versionMatch)) {
                        $toVersion = $versionMatch[1];
                    }
                    $log("로컬 모드: {$fromVersion} → {$toVersion}");
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
            if ($zipPath) {
                $this->info('업데이트 버전: (ZIP 추출 후 판별)');
            } else {
                $this->info("업데이트 버전: {$toVersion}");
            }
            $this->newLine();

            if (! $this->unifiedConfirm('코어를 업데이트하시겠습니까?', false)) {
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
            } elseif ($zipPath) {
                $bar->setMessage('ZIP 파일 추출 중...');
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
            } elseif ($zipPath) {
                $log("ZIP 추출 시작: {$zipPath}");
                $pendingPath = $service->extractZipToPending($zipPath, $onProgress);
                $log("ZIP 추출 완료: {$pendingPath}");

                // 추출된 소스의 config/app.php 에서 toVersion 갱신
                $zipConfigPath = $pendingPath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
                if (file_exists($zipConfigPath)) {
                    $zipConfigContent = file_get_contents($zipConfigPath);
                    if (preg_match("/['\"]version['\"]\s*=>\s*env\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $zipConfigContent, $versionMatch)) {
                        $toVersion = $versionMatch[1];
                        $log("ZIP 소스 버전 확인: {$toVersion}");
                    }
                }
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

            // 원본 소유권 스냅샷 — 이후 Step 11 에서 composer 등이 오염시킨 소유권을
            // 각 경로의 업데이트 전 원본으로 복원하기 위해 백업 직후 시점에 수집한다.
            $ownershipSnapshot = $service->snapshotOwnership();
            if (! empty($ownershipSnapshot)) {
                $log('원본 소유권 스냅샷 수집: '.implode(',', array_keys($ownershipSnapshot)));
            }

            // PHP-FPM 쓰기 영역의 항목별 정확 스냅샷 (owner/group/perms). Stage 4.
            // sudo update 가 root 로 만들 수 있는 좁은 영역(storage/logs, storage/framework,
            // storage/app/core_pending, bootstrap/cache) 의 모든 하위 항목을 재귀 stat 하여
            // Step 11/12 의 restoreOwnership 이 항목별 정확 복원하도록 전달한다.
            // 사용자 데이터 영역(storage/app/{modules,plugins,attachments,public,settings})
            // 은 본 스냅샷 대상이 아니며 chown 자체가 빠지므로 시드/업로드 owner 가 보존된다.
            $detailedOwnershipSnapshot = $service->snapshotOwnershipDetailed([
                'storage/logs',
                'storage/framework',
                'storage/app/core_pending',
                'bootstrap/cache',
            ]);
            if (! empty($detailedOwnershipSnapshot)) {
                $log('항목별 정확 스냅샷 수집: '.count($detailedOwnershipSnapshot).'개 항목 (PHP-FPM 쓰기 영역)');
            }

            // ── Step 6: _pending에서 Vendor 설치 (composer 또는 bundled) ──
            $vendorMode = VendorMode::fromStringOrAuto((string) $this->option('vendor-mode'));
            $composerSkipped = $vendorMode !== VendorMode::Bundled
                && $service->isComposerUnchangedForCore($pendingPath);

            if ($composerSkipped) {
                $bar->setMessage('Composer 의존성 변경 없음 — 스킵');
                $bar->advance();
                $log('composer.json/lock 변경 없음, vendor 재설치 스킵');
                $vendorResult = null;
            } else {
                $bar->setMessage(__('settings.core_update.step_composer'));
                $bar->advance();
                $log("_pending vendor 설치 시작 (mode: {$vendorMode->value})");

                try {
                    $vendorResult = $service->runVendorInstallInPending($pendingPath, $vendorMode, $onProgress);
                    $log(sprintf(
                        '_pending vendor 설치 완료 (strategy: %s, packages: %d)',
                        $vendorResult->strategy,
                        $vendorResult->packageCount,
                    ));
                } catch (VendorInstallException $e) {
                    $this->error('Vendor 설치 실패: '.$e->getMessage());
                    throw $e;
                }
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

                // composer/bundled 모드 모두 _pending/vendor/ 가 구성되어 있으므로 공통 복사
                $service->copyVendorFromPending($pendingPath, $onProgress);
                $log('vendor 디렉토리 복사 완료');
            }

            // ── Step 9: Migration + 역할/메뉴 동기화 ──
            //
            // 동기화는 반드시 reloadCoreConfigAndResync() 로 호출한다. 본 메서드는 부모
            // 프로세스가 부팅 시점에 캐시한 stale config 를 우회해 디스크의 fresh
            // config/core.php 를 require → Config Repository 에 재주입한 뒤 syncCore* 를
            // 호출한다. 디스크는 Step 7(applyUpdate) 에서 이미 신버전으로 교체되어 있다.
            //
            // syncCoreRolesAndPermissions / syncCoreMenus 직접 호출 금지 — 부모 메모리의
            // 구버전 config 로 sync 가 돌면 신규 권한/메뉴가 누락된다 (#326 회귀).
            $bar->setMessage(__('settings.core_update.step_migration'));
            $bar->advance();
            $log('마이그레이션, 역할/메뉴 동기화 실행');

            $service->runMigrations();
            $service->reloadCoreConfigAndResync();
            $log('마이그레이션, 역할/메뉴 동기화 완료');

            // ── Step 10: Upgrade Steps ──
            //
            // 경로 B (beta.3+): 별도 프로세스 spawn — 새 PHP 프로세스가 최신 클래스·config 를
            //   로드하므로 upgrade step 이 신규 Service/Model/Repository 등을 자유롭게 사용 가능.
            //
            // 경로 A (beta.1 → beta.2): beta.1 CoreUpdateCommand 가 in-process 로 실행되는
            //   상황에서는 본 재작성된 로직 자체가 활성화되지 않는다. 해당 경로의 후처리는
            //   upgrade step 파일(Upgrade_7_0_0_beta_2) 내부 로컬 로직으로 수행된다.
            //
            // proc_open 미지원 / 실행 실패 시 in-process fallback 으로 안전하게 전환.
            $bar->setMessage(__('settings.core_update.step_upgrade'));
            $bar->advance();
            $log('업그레이드 스텝 별도 프로세스 실행 시도');

            // progress bar 라인과 spawn stdout / step 로그가 같은 줄에 섞이지 않도록
            // bar 를 잠시 지우고, 상세 로그를 별도 줄로 출력한 뒤 다시 표시한다.
            $bar->clear();
            $this->newLine();
            $this->info('── 업그레이드 스텝 실행 ──');

            $spawned = $this->spawnUpgradeStepsProcess(
                $fromVersion,
                $toVersion,
                (bool) $this->option('force'),
                $log,
            );

            if (! $spawned) {
                $log('별도 프로세스 실행 실패 — in-process fallback');
                $service->runUpgradeSteps($fromVersion, $toVersion, function (string $version) use ($log) {
                    $this->line("  • upgrade step 실행: {$version}");
                    $log("업그레이드 스텝 실행: {$version}");
                }, (bool) $this->option('force'));
                $service->reloadCoreConfigAndResync();
                $log('fallback 완료 (config 재로드 + 권한/메뉴 재동기화)');
            }
            $log('업그레이드 스텝 완료');

            $this->newLine();
            $bar->display();

            // ── Step 11: Cleanup ──
            $bar->setMessage(__('settings.core_update.step_cleanup'));
            $bar->advance();

            $service->updateVersionInEnv($toVersion);
            $service->clearAllCaches();

            // sudo 실행 시 composer 등 외부 프로세스가 root 로 생성한 파일의 소유권을
            // 백업 직후 수집한 원본 스냅샷 기준으로 복원 (각 경로 고유 소유자 유지).
            // detailedSnapshot 동시 전달 — PHP-FPM 쓰기 영역의 owner/group/perms 를 항목별
            // 정확 복원하여 #282 (sudo update 후 traversal 비트 손실) 회귀 차단.
            $service->restoreOwnership($ownershipSnapshot, $onProgress, $detailedOwnershipSnapshot);
            $this->surfacePermissionWarnings($service, $log);
            $log('업데이트 경로 소유권 복원 완료');

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

            // _bundled 확장 일괄 업데이트 프롬프트 (번들에 신버전이 있을 때만 표시).
            //
            // 주의: Step 11 restoreOwnership 이후에 실행되므로, 프롬프트 내 실제
            // 업데이트 실행 시 composer / updateComposerAutoload 등으로 생성되는
            // bootstrap/cache/autoload-extensions.php, {modules|plugins|templates}/{id}/vendor,
            // storage/app/* 등이 sudo 컨텍스트에서 root 로 오염될 수 있다.
            // 실제 업데이트가 수행된 경우 restoreOwnership 을 한 번 더 호출해 재복원한다.
            $promptResult = $this->runBundledExtensionUpdatePrompt(
                app(ModuleManager::class),
                app(PluginManager::class),
                app(TemplateManager::class),
                (bool) $this->option('force'),
            );

            if (($promptResult['success'] ?? 0) > 0) {
                $this->newLine();
                $this->info('일괄 업데이트로 생성된 파일의 소유권을 복원하는 중...');
                // 일괄 확장 update 가 sudo 컨텍스트에서 root 로 만들 수 있는 PHP-FPM 쓰기
                // 영역의 owner/group/perms 를 항목별 정확 복원 (Stage 4).
                $service->restoreOwnership($ownershipSnapshot, $onProgress, $detailedOwnershipSnapshot);
                // restoreOwnership 의 진행 표시($onProgress → $bar->display())가
                // 개행 없이 끝나므로 다음 셸 프롬프트가 같은 줄에 붙는 것을 방지.
                $this->newLine(2);
                $this->surfacePermissionWarnings($service, $log);
                $log('일괄 확장 업데이트 후 소유권 재복원 완료');
            }

            return Command::SUCCESS;

        } catch (UpgradeHandoffException $e) {
            // 업그레이드 스텝이 새 PHP 프로세스 재진입을 요청한 경우.
            //
            // 롤백하지 않음 — 파일 교체 / migration / composer 결과까지는 이미 `toVersion` 상태로 유효.
            // Step 11 cleanup 을 축소 실행하여 중간 상태를 확정하고, 사용자에게 스텝 전용 재실행 안내.
            //
            //  - updateVersionInEnv(toVersion): 디스크가 이미 toVersion 이므로 .env 도 toVersion 으로
            //    일치시킨다. afterVersion 이 아님 — afterVersion 으로 두면 사용자가 다시 core:update
            //    를 돌릴 때 GitHub 재다운로드부터 전체가 반복된다. toVersion 으로 고정 + 사용자에게는
            //    execute-upgrade-steps 만 실행하도록 안내하여 재다운로드를 회피한다.
            //  - clearAllCaches: 신규 코드·config 반영
            //  - restoreOwnership: sudo 생성 파일 소유권 복원
            //  - cleanupPending: _pending 정리
            //  - 백업은 유지 (재실행 중 실패해도 복구 가능)
            //  - maintenance 해제: 서비스 재개
            //
            // resumeCommand 는 예외에 명시되지 않았으면 `execute-upgrade-steps --from=<afterVersion>
            // --to=<toVersion> --force` 로 자동 생성. step 만 단독 실행되어 재다운로드 없음.
            $bar->finish();
            $this->newLine(2);

            $log("업그레이드 핸드오프 수신: {$e->afterVersion} 까지 완료 — {$e->reason}");

            $resumeCommand = $e->resumeCommand ?? sprintf(
                'php artisan core:execute-upgrade-steps --from=%s --to=%s --force',
                $e->afterVersion,
                $toVersion,
            );

            try {
                $service->updateVersionInEnv($toVersion);
                $service->clearAllCaches();
                // Stage 4 — handoff cleanup 도 detailed snapshot 으로 정확 복원
                $service->restoreOwnership($ownershipSnapshot, $onProgress, $detailedOwnershipSnapshot);
                $this->surfacePermissionWarnings($service, $log);
                $log('핸드오프 cleanup 완료 (버전 toVersion 고정 + 캐시 clear + 소유권 복원)');

                if (! empty($pendingPath)) {
                    $service->cleanupPending($pendingPath);
                }

                if ($maintenanceEnabled) {
                    $service->disableMaintenanceMode();
                    $maintenanceEnabled = false;
                    $log('유지보수 모드 해제');
                }
            } catch (\Throwable $cleanupError) {
                $log("핸드오프 cleanup 중 오류: {$cleanupError->getMessage()}");
                $this->warn("핸드오프 cleanup 중 오류 발생 — 수동 점검 필요: {$cleanupError->getMessage()}");
            }

            $this->saveUpdateLog($logEntries, $fromVersion, $toVersion, true);

            $this->newLine();
            $this->warn('업그레이드 파일 반영은 완료되었으나, 일부 업그레이드 스텝이 현재 프로세스에서 실행될 수 없어 중단되었습니다.');
            $this->newLine();
            $this->line("  완료 스텝 지점: {$e->afterVersion}");
            $this->line("  파일·설정 버전: {$toVersion} (이미 반영됨)");
            $this->line("  사유: {$e->reason}");
            $this->newLine();
            $this->info('나머지 스텝을 적용하려면 아래 명령을 실행하세요 (재다운로드 없이 스텝만 실행):');
            $this->line("  {$resumeCommand}");
            $this->newLine();
            $this->warn('⚠ 위 명령을 실행하지 않으면 버전 표시는 최신이지만 일부 스텝이 미실행 상태로 남습니다.');
            $this->newLine();

            if ($backupPath) {
                $this->line("백업이 유지되었습니다: {$backupPath}");
                $this->newLine();
            }

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
     * 업그레이드 스텝을 별도 PHP 프로세스에서 실행합니다 (경로 B).
     *
     * proc_open 으로 `core:execute-upgrade-steps` 커맨드를 spawn 하여 새 PHP 프로세스가
     * 최신 파일 기준으로 모든 클래스·config 를 로드하게 한다. 이로써 upgrade step 이
     * 신규 Service/Repository/Controller 등을 자유롭게 참조할 수 있다.
     *
     * stdout 을 실시간으로 상위 콘솔에 전달하여 진행 상황을 보여주고, exit code === 0
     * 일 때만 성공으로 판정한다. proc_open 미지원 환경·커맨드 미존재·비정상 종료는
     * false 반환하여 호출자가 in-process fallback 으로 전환할 수 있게 한다.
     *
     * 핸드오프 신호: 자식이 exit=UpgradeHandoffException::EXIT_CODE 로 종료하면서
     * stdout 에 `[HANDOFF] <json>` 라인을 남기면, 본 메서드는 UpgradeHandoffException 을
     * 재구성하여 상위로 던진다 (CoreUpdateCommand::handle 의 catch 블록이 처리).
     *
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  대상 버전
     * @param  bool  $force  동일 버전 강제 실행 여부
     * @param  \Closure  $log  로그 엔트리 수집 콜백
     * @return bool  spawn 성공 여부 (false 면 fallback 실행 필요)
     *
     * @throws UpgradeHandoffException  자식이 핸드오프 신호를 보낸 경우
     */
    private function spawnUpgradeStepsProcess(string $fromVersion, string $toVersion, bool $force, \Closure $log): bool
    {
        if (! function_exists('proc_open')) {
            $log('proc_open 비활성 — spawn 스킵, in-process fallback 진행');

            return false;
        }

        $phpBinary = config('process.php_binary', PHP_BINARY);
        $artisan = base_path('artisan');

        $command = [
            $phpBinary,
            $artisan,
            'core:execute-upgrade-steps',
            '--from='.$fromVersion,
            '--to='.$toVersion,
        ];
        if ($force) {
            $command[] = '--force';
        }

        $commandLine = implode(' ', array_map('escapeshellarg', $command)).' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // spawn 자식에 전달할 env 구성.
        //
        // 주의: `$_ENV` 는 `variables_order` php.ini 설정(기본 CLI 가 "EGPCS" 지만 호스팅에 따라
        // "GPCS" 만 포함해 E 없을 수 있음) 에 따라 비어있을 수 있다. 이 경우 `$_ENV` 에 값을
        // 할당해도 실제 프로세스 환경변수 테이블에는 반영 안 되며, `array_merge($_ENV, ...)` 결과도
        // 불완전해져 spawn 자식이 G7_UPDATE_IN_PROGRESS 등 핵심 플래그를 받지 못한다.
        //
        // getenv() (인자 없이 호출, PHP 7.1+) 은 프로세스 환경변수 테이블을 직접 반환해
        // putenv 로 설정한 값까지 확실히 포함한다. getenv() 를 기반으로 삼고 $_ENV 로 보완하여
        // 양쪽 채널의 합집합을 자식에 전달한다.
        //
        // APP_VERSION: Step 11 updateVersionInEnv 는 아직 실행 전이라 .env 는 fromVersion.
        // G7_UPDATE_IN_PROGRESS: CoreServiceProvider::validateAndDeactivate 가드가 참조.
        $env = array_merge(getenv(), $_ENV, [
            'APP_VERSION' => $toVersion,
            'G7_UPDATE_IN_PROGRESS' => '1',
        ]);

        $process = proc_open($commandLine, $descriptors, $pipes, base_path(), $env);
        if (! is_resource($process)) {
            $log('spawn 실패 — proc_open 자원 생성 실패');

            return false;
        }

        fclose($pipes[0]);

        // stdout 실시간 전달 — 상위 콘솔에서 진행 상황 확인 가능
        // 단, [HANDOFF] 접두사 라인은 상위 콘솔로 노출하지 않고 페이로드만 보관한다
        // (exit=UpgradeHandoffException::EXIT_CODE 감지 시 UpgradeHandoffException 재구성용).
        $handoffPayload = null;
        while (! feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $trimmed = rtrim($line);
                if ($trimmed === '') {
                    continue;
                }

                if (str_starts_with($trimmed, '[HANDOFF] ')) {
                    $json = substr($trimmed, strlen('[HANDOFF] '));
                    $decoded = json_decode($json, true);
                    // resumeCommand 는 null 허용 (자식이 null 로 전달한 경우 부모가
                    // CoreUpdateCommand catch 분기에서 from/to 기반으로 자동 생성)
                    if (is_array($decoded)
                        && array_key_exists('afterVersion', $decoded)
                        && array_key_exists('reason', $decoded)
                        && array_key_exists('resumeCommand', $decoded)
                    ) {
                        $handoffPayload = $decoded;
                        $log('[spawn] 핸드오프 신호 수신: after='.$decoded['afterVersion']);

                        continue;
                    }
                    // 구조가 깨진 핸드오프 라인 — 정상 출력으로 간주해 그대로 전달
                }

                $this->line($trimmed);
                $log('[spawn] '.$trimmed);
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === UpgradeHandoffException::EXIT_CODE && $handoffPayload !== null) {
            $log("spawn 핸드오프 종료 (exit={$exitCode})");

            // resumeCommand 가 null 이면 null 그대로 전달 — 상위 catch 에서 자동 생성.
            $resume = $handoffPayload['resumeCommand'];

            throw new UpgradeHandoffException(
                afterVersion: (string) $handoffPayload['afterVersion'],
                reason: (string) $handoffPayload['reason'],
                resumeCommand: is_string($resume) ? $resume : null,
            );
        }

        if ($exitCode === 0) {
            $log('spawn 완료 (exit=0)');

            return true;
        }

        $log("spawn 비정상 종료 (exit={$exitCode}) — fallback 진행");

        return false;
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

    /**
     * 현재 실행 사용자와 코어 파일 소유자가 다른 경우 경고를 표시합니다.
     *
     * 공유 호스팅에서 FTP 사용자(파일 소유자)와 SSH 사용자, 또는 SSH 사용자와
     * www-data 가 다른 경우 권한 거부가 발생할 수 있습니다. 차단하지는 않고
     * 사용자가 직접 판단하도록 경고만 출력합니다.
     */
    private function warnIfFileOwnerMismatch(\Closure $log): void
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            return; // Windows 또는 posix 확장 미설치
        }

        $coreFile = base_path('composer.json');
        if (! file_exists($coreFile)) {
            return;
        }

        $currentUid = posix_geteuid();
        $ownerUid = fileowner($coreFile);

        if ($currentUid === $ownerUid) {
            return;
        }

        $currentUser = posix_getpwuid($currentUid)['name'] ?? (string) $currentUid;
        $ownerUser = posix_getpwuid($ownerUid)['name'] ?? (string) $ownerUid;

        $this->newLine();
        $this->warn('⚠ 실행 사용자와 코어 파일 소유자가 다릅니다.');
        $this->warn("  실행 사용자: {$currentUser} (uid={$currentUid})");
        $this->warn("  코어 소유자: {$ownerUser} (uid={$ownerUid})");
        $this->warn('  → 권한 거부가 발생할 수 있습니다. 일반적으로 코어 파일 소유자로');
        $this->warn('    SSH 로그인 후 실행해야 합니다. vendor/ 가 다른 사용자 소유인 경우');
        $this->warn("    'chown -R \$(whoami) vendor/' 후 재시도하세요.");
        $this->newLine();

        $log("권한 경고: 실행자={$currentUser}({$currentUid}) vs 소유자={$ownerUser}({$ownerUid})");

        Log::warning('core:update 실행 사용자가 코어 파일 소유자와 다름', [
            'current_uid' => $currentUid,
            'current_user' => $currentUser,
            'owner_uid' => $ownerUid,
            'owner_user' => $ownerUser,
        ]);
    }

    /**
     * `restoreOwnership()` 직후 누적된 권한 정상화 실패 경고를 콘솔/로그에 즉시 노출합니다.
     *
     * 운영자가 sudo 환경 결함(파일시스템 ACL, immutable 비트, NFS 권한 거부 등) 으로
     * 일부 경로 chown / chmod 실패 시 그 경로와 운영자 수동 복구 명령을 즉시 보여준다.
     * 본 메서드 호출 후 service 의 `lastPermissionWarnings` 가 다음 호출 시 초기화되므로
     * 매 `restoreOwnership` 직후 1회 호출 패턴이 정합.
     *
     * @param  CoreUpdateService  $service
     * @param  callable  $log  내부 로그 누적 콜백 (`saveUpdateLog` 입력용)
     * @return void
     */
    private function surfacePermissionWarnings(CoreUpdateService $service, callable $log): void
    {
        $warnings = $service->getLastPermissionWarnings();
        if (empty($warnings)) {
            return;
        }

        $this->newLine();
        $this->warn('⚠ 권한 정상화 실패 — 일부 경로의 소유권/그룹 쓰기 권한을 복원하지 못했습니다.');
        foreach ($warnings as $w) {
            $kind = $w['kind'] === 'chown' ? '소유권' : '그룹 쓰기';
            $this->warn(sprintf('  - %s [%s]: %d 건 실패', $w['target'], $kind, $w['failed']));
            foreach (array_slice($w['failed_paths'], 0, 5) as $p) {
                $this->line("      · {$p}");
            }
            if (count($w['failed_paths']) > 5) {
                $this->line(sprintf('      · … (총 %d건, 상위 5건만 표시)', $w['failed']));
            }
        }
        $this->newLine();
        $this->line('  복구 예시:');
        $this->line('    sudo chown -R <owner>:<group> <path>');
        $this->line('    sudo chmod -R g+w <path>');
        $this->newLine();

        $log(sprintf('권한 정상화 실패 %d 건 — 운영자 수동 복구 필요', count($warnings)));
    }
}
