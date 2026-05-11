<?php

namespace App\Console\Commands\Core;

use App\Exceptions\UpgradeHandoffException;
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
 */
class ExecuteUpgradeStepsCommand extends Command
{
    protected $signature = 'core:execute-upgrade-steps
        {--from= : 시작 버전}
        {--to= : 대상 버전}
        {--force : 동일 버전 강제 실행}';

    protected $description = '코어 업그레이드 스텝을 별도 프로세스에서 실행합니다 (CoreUpdateCommand 내부용)';

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
        try {
            app(\App\Extension\ExtensionManager::class)->updateComposerAutoload();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('upgrade step spawn 자식: updateComposerAutoload 호출 실패', [
                'error' => $e->getMessage(),
            ]);
        }

        // spawn 자식 진입 시 활성 디렉토리의 쓰기 권한 디렉토리를 멱등적으로 보장.
        // fresh 디스크 config (`app.update.restore_ownership_group_writable`) 를 읽어 처리하므로
        // 미래 release 가 새 쓰기 권한 디렉토리를 도입할 때 본 호출은 자동으로 신규 항목을 처리한다.
        // upgrade step 에 mkdir/chown 코드를 매번 하드코딩할 필요 없음.
        //
        // 한계: 부모(이전 버전) 만 알고 있던 신규 디렉토리는 처리 불가 — 그 일회성 케이스는
        // 해당 release 의 upgrade step 단발 처리. (예: beta.3→beta.4 의 lang-packs/* 보정)
        try {
            $writablePaths = (array) config('app.update.restore_ownership_group_writable', []);
            if (! empty($writablePaths)) {
                $service->ensureWritableDirectories(
                    $writablePaths,
                    function (string $level, string $msg): void {
                        // 콘솔 + upgrade 로그 채널 동시 출력 — PO 가 단일 파일(upgrade.log)에서
                        // spawn 자식의 권한 정상화 진행을 추적할 수 있도록 양쪽 모두 누적.
                        $this->{$level === 'warning' ? 'warn' : 'info'}($msg);
                        \Illuminate\Support\Facades\Log::channel('upgrade')->$level('[spawn] '.$msg);
                    },
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('upgrade step spawn 자식: ensureWritableDirectories 호출 실패', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $service->runUpgradeSteps(
                $from,
                $to,
                fn (string $version) => $this->info("upgrade step 실행: {$version}"),
                $force,
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

            return UpgradeHandoffException::EXIT_CODE;
        } catch (\Throwable $e) {
            Log::error('core:execute-upgrade-steps 실패', [
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
