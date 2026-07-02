<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Core\Concerns\BundledExtensionUpdatePrompt;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Exceptions\UpgradeHandoffException;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 코어 업그레이드 스텝을 별도 프로세스에서 실행합니다.
 *
 * CoreUpdateCommand 의 Step 10 에서 proc_open 으로 호출하여 최신 버전 클래스·
 * config 를 새 PHP 프로세스에서 로드하게 하는 진입점. 새 프로세스에서 실행되므로
 * upgrade step 이 신규 Service/Repository/Controller 등을 자유롭게 호출할 수 있다.
 *
 * 단, 이는 beta.3 이후 업그레이드 경로(경로 B)에만 적용된다. beta.1 → beta.2
 * 업그레이드는 beta.1 의 CoreUpdateCommand 가 본 커맨드를 알지 못하므로 spawn
 * 효과를 받지 못한다(경로 A) — 이 경우 upgrade step 파일 내부 로컬 로직으로
 * 후처리를 수행해야 한다. 상세는 docs/extension/upgrade-step-guide.md 참조.
 *
 * 단독 실행 안전성: 운영자가 HANDOFF 안내문 또는 수동 복구 목적으로 직접 호출하면
 * 기본값으로 부모 CoreUpdateCommand 가 처리하던 사전(Migration + Resync) 및
 * 사후(.env 버전 + 캐시 정리 + 번들 확장 일괄 업데이트) 단계를 자동 수행한다.
 * CoreUpdateCommand spawn 호출 시엔 `--skip-*` 옵션 5개로 중복 회피.
 *
 * `--steps-only`: 업그레이드 스텝만 실행하고 모든 보조 단계(권한 정상화 ·
 * 오토로드 재생성 · 마이그레이션 · resync · 버전 갱신 · 캐시 정리 · 번들 업데이트)를
 * 생략한다. 특정 스텝의 데이터 보정만 단발성으로 재실행할 때 사용한다. 보조 단계가
 * 이미 완료된 환경에서 무거운 권한 재귀 순회 등을 건너뛴다.
 */
class ExecuteUpgradeStepsCommand extends Command
{
    use BundledExtensionUpdatePrompt;
    use ClearsTemplateCaches;
    use HasUnifiedConfirm;

    protected $signature = 'core:execute-upgrade-steps
        {--from= : 시작 버전}
        {--to= : 대상 버전}
        {--force : 동일 버전 강제 실행 + 번들 확장 일괄 업데이트 prompt 스킵}
        {--steps-only : 업그레이드 스텝만 실행 — 권한 정상화·오토로드 재생성·마이그레이션·resync·버전 갱신·캐시 정리·번들 업데이트 등 모든 사전/사후 보조 단계 생략}
        {--skip-migrations : 마이그레이션 실행 생략 (CoreUpdateCommand spawn 시 부모 Step 9 가 이미 실행)}
        {--skip-resync : 코어 config 재로드 및 권한/메뉴/시더 동기화 생략 (동일 사유)}
        {--skip-version-env : .env APP_VERSION 갱신 생략 (부모 Step 11 가 처리)}
        {--skip-cache-clear : 캐시 정리 생략 (부모 Step 11 가 처리)}
        {--skip-bundled-updates : 번들 확장 일괄 업데이트 생략 (부모가 prompt 로 처리)}';

    protected $description = '코어 업그레이드 스텝을 별도 프로세스에서 실행합니다. 단독 실행 시 사전/사후 단계를 자동 수행하며, --steps-only 로 스텝만 실행할 수 있습니다.';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  CoreUpdateService  $service  코어 업데이트 서비스
     * @return int 종료 코드
     */
    public function handle(CoreUpdateService $service): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $force = (bool) $this->option('force');

        // --steps-only: 업그레이드 스텝만 실행하고 모든 보조 단계(권한 정상화 · 오토로드
        // 재생성 · 마이그레이션 · resync · 버전 갱신 · 캐시 정리 · 번들 업데이트)를 생략한다.
        // 운영자가 특정 스텝의 데이터 보정만 단발성으로 재실행할 때 사용한다.
        $stepsOnly = (bool) $this->option('steps-only');

        if ($from === '' || $to === '') {
            $this->error('--from 과 --to 는 필수 옵션입니다.');

            return self::INVALID;
        }

        // spawn 자식 진입 시 활성 모듈/플러그인의 PSR-4 매핑을 fresh 등록.
        // 부모 프로세스의 autoload-extensions.php 가 stale 한 경우 upgrade step 안에서
        // ModuleManager / PluginManager 호출 → declaration 메서드가 Models/Services 등
        // 다른 클래스 lazy load 시 "Class not found" 발생. 진입 직후 1회 호출로 모든 후속
        // upgrade step (현재 + 미래) 이 fresh autoload 환경에서 실행됨을 보장.
        //
        // 본 커맨드 자체가 spawn 자식 (proc_open 으로 fork 된 별개 PHP 프로세스) 의 진입점이라
        // 디스크의 fresh ExtensionManager(beta.X+1) 클래스를 메모리에 로드한 상태. 따라서
        // `app(ExtensionManager::class)->updateComposerAutoload()` 직접 호출은 stale 가능성
        // 없음. (Artisan::call 대신 직접 호출 — nested Artisan::call 이 outer 명령의
        // output buffer 를 덮어쓰는 Laravel 동작 회피)
        if (! $stepsOnly) {
            try {
                app(ExtensionManager::class)->updateComposerAutoload();
            } catch (\Throwable $e) {
                Log::warning('upgrade step spawn 자식: updateComposerAutoload 호출 실패', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // spawn 자식 진입 시 활성 디렉토리의 쓰기 권한 디렉토리를 멱등적으로 보장.
        // fresh 디스크 config (`app.update.restore_ownership_group_writable`) 를 읽어 처리하므로
        // 미래 release 가 새 쓰기 권한 디렉토리를 도입할 때 본 호출은 자동으로 신규 항목을 처리한다.
        // upgrade step 에 mkdir/chown 코드를 매번 하드코딩할 필요 없음.
        //
        // 한계: 부모(이전 버전) 만 알고 있던 신규 디렉토리는 처리 불가 — 그 일회성 케이스는
        // 해당 release 의 upgrade step 단발 처리. (예: beta.3→beta.4 의 lang-packs/* 보정)
        if (! $stepsOnly) {
            try {
                $writablePaths = (array) config('app.update.restore_ownership_group_writable', []);
                if (! empty($writablePaths)) {
                    $service->ensureWritableDirectories(
                        $writablePaths,
                        function (string $level, string $msg): void {
                            // 콘솔 + upgrade 로그 채널 동시 출력 — 운영자가 단일 파일(upgrade.log)에서
                            // spawn 자식의 권한 정상화 진행을 추적할 수 있도록 양쪽 모두 누적.
                            $this->{$level === 'warning' ? 'warn' : 'info'}($msg);
                            Log::channel('upgrade')->$level('[spawn] '.$msg);
                        },
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('upgrade step spawn 자식: ensureWritableDirectories 호출 실패', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 코어 업데이트 spawn 자식 감지.
        //
        // CoreUpdateCommand::spawnUpgradeStepsProcess 가 자식에 `G7_UPDATE_IN_PROGRESS=1` env 를
        // 전달한다 (beta.3 부터 존재). 이 시그널은 자식이 부모의 `core:update` 컨텍스트에서
        // 실행 중임을 의미한다. 부모는 본인이 사전(Step 9 migration + resync) + 사후
        // (Step 11 version-env + cache-clear + bundled prompt) 단계를 모두 책임지므로 자식은
        // 5단계 모두 스킵해야 한다.
        //
        // 구버전 부모(예: beta.5)는 신버전 자식이 도입한 `--skip-*` 옵션을 모른다. 옵션 부재
        // 시 자식이 사후 단계를 실행하면 (특히 bundled prompt) non-interactive 환경에서
        // `Aborted.` exit=1 회귀 발생 (882deb9b0 사각지대). env 시그널이 옵션 부재를 보완.
        //
        // 단독 호출 (운영자 수동) 시엔 env 미설정 → 모든 단계 정상 진행.
        $isSpawnChild = getenv('G7_UPDATE_IN_PROGRESS') === '1';

        // 사전 단계 (단독 실행 안전성 보장).
        //
        // CoreUpdateCommand 가 spawn 호출 시엔 부모 Step 9 (CoreUpdateCommand.php:408-409) 에서
        // 이미 동일 단계를 실행했으므로 --skip-migrations / --skip-resync 로 중복 회피.
        // 운영자 단독 호출 시 옵션 미전달 → 기본값으로 두 단계 자동 수행 →
        // migration / permission / menu / seeder 누락 차단.
        if (! $this->option('skip-migrations') && ! $isSpawnChild && ! $stepsOnly) {
            $this->info('마이그레이션 실행');
            $service->runMigrations();
        } else {
            $this->info($stepsOnly ? '[steps-only] 마이그레이션 스킵' : '[spawn] 마이그레이션 스킵 — 부모가 이미 실행');
        }

        if (! $this->option('skip-resync') && ! $isSpawnChild && ! $stepsOnly) {
            $this->info('코어 config 재로드 및 권한/메뉴/시더 동기화');
            $service->reloadCoreConfigAndResync();
        } else {
            $this->info($stepsOnly ? '[steps-only] resync 스킵' : '[spawn] resync 스킵 — 부모가 이미 실행');
        }

        $stepsExecuted = 0;
        $stepsDiscovered = 0;

        try {
            $service->runUpgradeSteps(
                $from,
                $to,
                function (string $version) use (&$stepsExecuted): void {
                    $this->info("upgrade step 실행: {$version}");
                    $stepsExecuted++;
                },
                $force,
                function (int $discovered) use (&$stepsDiscovered): void {
                    $stepsDiscovered = $discovered;
                },
            );
        } catch (UpgradeHandoffException $e) {
            // 업그레이드 스텝이 새 PHP 프로세스 재진입을 요청했다.
            // 부모 프로세스(spawnUpgradeStepsProcess)가 [HANDOFF] 라인을 stdout 에서
            // 읽어 페이로드를 복원하고 UpgradeHandoffException 재구성 후 상위로 던지도록,
            // JSON 페이로드를 표식과 함께 출력한다. 표식 문자열은 구분자 역할이므로 일반
            // step 출력과 충돌하지 않게 고정된 접두사를 사용한다.
            //
            // resumeCommand 는 step 작성자가 null 로 두는 것을 권장(CoreUpdateCommand 가
            // from/to 버전을 사용해 자동 생성). null 도 JSON 으로 그대로 전달.
            $payload = json_encode([
                'afterVersion' => $e->afterVersion,
                'reason' => $e->reason,
                'resumeCommand' => $e->resumeCommand,
            ], JSON_UNESCAPED_UNICODE);

            $this->line('[HANDOFF] '.$payload);

            Log::info('core:execute-upgrade-steps 핸드오프', [
                'from' => $from,
                'to' => $to,
                'after' => $e->afterVersion,
                'reason' => $e->reason,
            ]);

            $this->restoreUpgradeLogOwnership();

            return UpgradeHandoffException::EXIT_CODE;
        } catch (\Throwable $e) {
            Log::error('core:execute-upgrade-steps 실패', [
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            $this->restoreUpgradeLogOwnership();

            return self::FAILURE;
        }

        // 부모 (CoreUpdateCommand::spawnUpgradeStepsProcess) 가 silent skip 가드용으로 파싱하는
        // 명시 종료 신호. [HANDOFF] 와 동일 구조의 라인 프로토콜. 정상 종료 (handoff 미발생) 경로
        // 에서만 발행되며, 부모는 exit=0 인데 본 라인이 부재하면 mode=abort 시 throw,
        // mode=fallback 시 in-process 진행 (`spawn_failure_mode`).
        $this->line('[STEPS_EXECUTED] '.json_encode([
            'count' => $stepsExecuted,
            'discovered' => $stepsDiscovered,
            'fromVersion' => $from,
            'toVersion' => $to,
        ], JSON_UNESCAPED_UNICODE));

        // 사후 단계 (단독 실행 안전성 보장).
        //
        // CoreUpdateCommand spawn 시엔 부모 Step 11 (CoreUpdateCommand.php:457-458) 및
        // 번들 확장 일괄 업데이트 prompt (라인 497-515) 가 자식 종료 후 실행하므로
        // --skip-version-env / --skip-cache-clear / --skip-bundled-updates 로 중복 회피.
        // 단독 실행 시엔 옵션 미전달 → 기본값으로 3단계 자동 수행 → gnuboard/g7#34 의 운영자 수동 절차
        // (sed APP_VERSION + cache:clear + module/plugin/template:update --force --source=bundled) 통합.
        if (! $this->option('skip-version-env') && ! $isSpawnChild && ! $stepsOnly) {
            $this->info(".env APP_VERSION={$to} 갱신");
            $service->updateVersionInEnv($to);
        } else {
            $this->info($stepsOnly ? '[steps-only] .env APP_VERSION 갱신 스킵' : '[spawn] .env APP_VERSION 갱신 스킵 — 부모가 처리');
        }

        if (! $this->option('skip-cache-clear') && ! $isSpawnChild && ! $stepsOnly) {
            $this->info('캐시 정리 (config/route/view/services/packages)');
            $service->clearAllCaches();
            // 업그레이드 스텝 단독 실행 시에도 코어 lang/routes/layout 변경이
            // 프론트엔드 캐시 stale 로 가려지지 않도록 `ext.cache_version` bump.
            // spawn 자식·steps-only 모드에서는 부모가 처리하므로 스킵.
            $this->incrementExtensionCacheVersion();
        } else {
            $this->info($stepsOnly ? '[steps-only] 캐시 정리 스킵' : '[spawn] 캐시 정리 스킵 — 부모가 처리');
        }

        if (! $this->option('skip-bundled-updates') && ! $isSpawnChild && ! $stepsOnly) {
            $this->info('번들 확장 일괄 업데이트 (모듈/플러그인/템플릿/언어팩)');
            $this->runBundledExtensionUpdatePrompt(
                app(ModuleManager::class),
                app(PluginManager::class),
                app(TemplateManager::class),
                $force,
            );
        } else {
            $this->info($stepsOnly ? '[steps-only] 번들 확장 일괄 업데이트 스킵' : '[spawn] 번들 확장 일괄 업데이트 스킵 — 부모가 처리');
        }

        $this->restoreUpgradeLogOwnership();

        return self::SUCCESS;
    }

    /**
     * spawn 자식이 root 로 만든 upgrade 로그 파일의 소유권을 부모(storage/logs) 로 정합합니다.
     *
     * upgrade step 은 sudo 코어 업데이트에서 별도 PHP 프로세스로 spawn 되며(이 커맨드),
     * root 로 실행되어 `upgrade-YYYY-MM-DD.log`(Log::channel('upgrade')) 를 root 소유로
     * 만든다. 부모(CoreUpdateCommand) 프로세스는 업데이트 시작 시점의 *이전 버전* 클래스를
     * 메모리에 들고 있어, 부모 측 로그 정상화 로직이 추가돼 있어도 그 버전엔 없을 수 있다
     * (클래스 캐싱). 반면 본 spawn 자식은 항상 *신버전* 코드로 실행되므로, upgrade 로그를
     * 만든 주체인 자식이 자기 종료 직전에 직접 소유권을 정합하면 부모 버전과 무관하게 동작한다.
     *
     * 그 결과 이후 www-data(php-fpm) 의 module:update upgrade step 이 같은 날짜 upgrade
     * 로그에 append 할 수 있다. 본 메서드는 어떤 로그도 쓰지 않는다(쓰면 다시 root 가 됨).
     * sudo 아닌 환경에서는 silent no-op(멱등).
     */
    private function restoreUpgradeLogOwnership(): void
    {
        $logsDir = storage_path('logs');
        if (! is_dir($logsDir)) {
            return;
        }

        // 코어('upgrade-*.log') + 확장('extension-upgrade-*.log') 두 채널의 daily 로그를 모두
        // 정합한다. glob 'upgrade-*.log' 는 'extension-' 접두사 파일을 매칭하지 못하므로
        // 두 패턴을 각각 순회한다.
        foreach (['upgrade-*.log', 'extension-upgrade-*.log'] as $pattern) {
            foreach (glob($logsDir.DIRECTORY_SEPARATOR.$pattern) ?: [] as $logFile) {
                FilePermissionHelper::inheritOwnershipFromParent($logFile);
            }
        }
    }
}
