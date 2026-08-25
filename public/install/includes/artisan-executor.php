<?php

/**
 * 그누보드7 웹 인스톨러 - Artisan 커맨드 실행기 (인스톨러 러너)
 *
 * 설치 태스크가 Artisan 커맨드를 돌릴 때 거치는 단일 지점이다. 실행 방식은 호출부가
 * 고르지 않는다 — 유효 실행 모드(execution-mode.php)가 정한다.
 *
 *   subprocess : 현행 경로. `php -d memory_limit=512M artisan …` 을 exec() 로 스폰하고
 *                출력을 파일로 받는다 (pipe deadlock 회피). 본문은 종전 task-runner 의
 *                executeArtisanCommandSSE 등 3곳이 각자 갖고 있던 것을 옮긴 것이다 —
 *                로직을 바꾸지 않았다 (계획서 A-0 회귀 보장 1).
 *   inprocess  : CLI 스폰이 막힌 환경용 신규 경로. 같은 PHP 프로세스 안에서 Laravel 을
 *                부팅하고 코어 러너(App\Execution\InProcessCommandRunner)로 Artisan::call 한다.
 *                부팅은 요청당 1회이며, 한 요청이 한 태스크만 돌리는 stepwise 진행 모드와
 *                짝을 이룬다 (A-2 — 연속 호출 시 컨테이너 오염·실행 시간 상한 문제).
 *
 * 이 파일이 인스톨러의 "러너" 다. 코어 러너(app/Execution)를 직접 쓰지 못하는 이유는
 * 인스톨러가 Laravel 부팅 전 순수 PHP 로 돌기 때문이며, 서브프로세스 전략은 인스톨러
 * 고유 구성(출력 파일 리다이렉트·memory_limit 플래그·Step 3 php_binary)을 유지한다.
 *
 * 테스트 seam: `$GLOBALS['g7_installer_artisan_executor_override']` 에 callable 을 두면
 * 실제 실행 대신 그 콜백이 호출된다 — 단위 테스트가 태스크 흐름(순서·재개·롤백·env 주입)
 * 을 스폰 없이 검증하기 위한 것이고, 운영 코드는 이 전역을 설정하지 않는다.
 */

use App\Execution\CommandRunnerResolver;
use App\Execution\ExecutionMode;
use App\Support\ExecutionEnvironment;
use Illuminate\Contracts\Console\Kernel;

require_once __DIR__.'/execution-mode.php';

if (! defined('INSTALLER_INPROCESS_TIME_LIMIT')) {
    /**
     * in-process 부팅이 요청에 허용하는 시간(초).
     *
     * 워커 lock 의 stale 판정도 이 값으로 함께 늘린다 — 두 값이 갈리면 아직 살아 있는
     * 요청이 stale 로 판정되어 같은 태스크가 겹쳐 돈다.
     */
    define('INSTALLER_INPROCESS_TIME_LIMIT', 600);
}

if (! defined('INSTALLER_SUBPROCESS_TIME_LIMIT')) {
    /**
     * 서브프로세스 스폰이 블로킹되는 동안 이 요청이 lock 에 선언하는 stale 창(초).
     *
     * SSE 워커와 폴링 워커가 실제로 사는 시간(set_time_limit(600))과 같은 값이다 —
     * 그 구간에는 emit 이 없어 heartbeat 가 멈추므로, 선언값이 더 작으면 살아 있는
     * 요청이 stale 로 판정되어 같은 태스크가 겹쳐 돈다.
     */
    define('INSTALLER_SUBPROCESS_TIME_LIMIT', 600);
}

if (! function_exists('installerPutEnv')) {
    /**
     * 환경변수를 이 프로세스에 주입/해제한다 — `putenv` 없이도 성립하는 단일 지점.
     *
     * PHP 8 부터 `disable_functions` 는 함수를 **함수 테이블에서 제거**하므로 `@putenv()` 의
     * `@` 로도 `Error: Call to undefined function` 을 막지 못한다(실측). `exec` 를 막는
     * 호스팅은 `putenv` 도 대개 함께 막으므로, 무가드 호출은 in-process 경로를 통째로 죽인다.
     *
     * 그래서 `$_ENV`/`$_SERVER` 를 **항상** 채우고 `putenv` 는 가용할 때만 부른다. Laravel 은
     * `Env::disablePutenv()`(bootstrap/app.php) 라 in-process 부팅에는 앞의 둘로 충분하다.
     * 서브프로세스 자식에게 전달되는 것은 `putenv` 쪽뿐이므로, 그 경로에서 putenv 가 막혀
     * 있으면 값이 자식에 도달하지 않는다 — 그때는 스폰 자체가 이미 불가한 환경이다.
     *
     * @param  string  $key  환경변수 이름
     * @param  string|null  $value  값 (null 이면 해제)
     */
    function installerPutEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        if (! ExecutionEnvironment::hasFunction('putenv')) {
            return;
        }

        putenv($value === null ? $key : $key.'='.$value);
    }
}

if (! function_exists('installerReadEnv')) {
    /**
     * 환경변수를 읽는다 — `getenv` 가 막힌 호스팅에서도 성립하는 단일 지점.
     *
     * `installerPutEnv` 와 짝이다. 주입이 `$_ENV`/`$_SERVER` 를 채우므로 읽기도 그 둘을 먼저
     * 본다 — putenv 가 막힌 환경에서는 그 둘이 유일한 통로다.
     *
     * @param  string  $key  환경변수 이름
     * @return string|null 값 (없으면 null)
     */
    function installerReadEnv(string $key): ?string
    {
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (! ExecutionEnvironment::hasFunction('getenv')) {
            return null;
        }

        $value = getenv($key);

        return $value === false ? null : (string) $value;
    }
}

if (! function_exists('installerTempDir')) {
    /**
     * 시스템 임시 디렉토리 — `sys_get_temp_dir` 이 막힌 호스팅에서는 null.
     *
     * 호출부는 후보 목록에서 null 을 걸러 낸다 — 「임시 디렉토리를 못 쓴다」와 「빈 문자열
     * 경로」는 전혀 다른 결과다.
     *
     * @return string|null 임시 디렉토리 경로 (사용 불가면 null)
     */
    function installerTempDir(): ?string
    {
        if (! ExecutionEnvironment::hasFunction('sys_get_temp_dir')) {
            return null;
        }

        $dir = sys_get_temp_dir();

        return $dir === '' ? null : $dir;
    }
}

if (! function_exists('installerDescribeSignalExit')) {
    /**
     * 종료 코드가 시그널 종료를 뜻하면 그 사유 문구를 돌려준다 (13차 환경#25).
     *
     * 셸은 시그널로 죽은 자식을 `128 + N` 으로 보고한다 — 컨테이너 cgroup 의 OOM-kill 은
     * `137`(= 128 + SIGKILL). 종료 코드만 보면 "실패했다" 뿐이라 운영자가 메모리 한도를
     * 의심할 근거가 화면 어디에도 남지 않는다.
     *
     * @param  int  $returnCode  자식 종료 코드
     * @return string|null 시그널 종료면 사유 문구, 아니면 null
     */
    function installerDescribeSignalExit(int $returnCode): ?string
    {
        if ($returnCode <= 128 || $returnCode > 192) {
            return null;
        }

        return lang('error_subprocess_signal_terminated', ['signal' => (string) ($returnCode - 128)]);
    }
}

if (! function_exists('installerBuildArtisanCommandLine')) {
    /**
     * 커맨드명 + Artisan::call 규약 파라미터를 서브프로세스용 인자 문자열로 만든다.
     *
     * 종전 호출부가 손으로 조립하던 형태를 그대로 재현한다 — 커맨드명·bare 플래그는
     * 그대로, 값은 escapeshellarg, 값 옵션은 `--opt=` + escapeshellarg(value).
     *
     * 비대화형 강제(`--no-interaction`)는 여기가 아니라 **러너**가 붙인다 (13차 E3). 코어
     * `SubprocessCommandRunner` 도 `formatParameters()` 가 아니라 `run()` 에서 붙이며, 이
     * 함수는 파라미터를 argv 문자열로 옮기기만 하는 순수 변환으로 남는다.
     *
     * @param  string  $command  Artisan 커맨드명 (예: 'module:install')
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     * @return string `module:install 'sirsoft-board' --vendor-mode='bundled'` 형태
     */
    function installerBuildArtisanCommandLine(string $command, array $parameters = []): string
    {
        $tokens = [$command];

        foreach ($parameters as $key => $value) {
            if (is_string($key) && str_starts_with($key, '--')) {
                if (is_bool($value)) {
                    if ($value) {
                        $tokens[] = $key;
                    }

                    continue;
                }

                foreach ((array) $value as $item) {
                    $tokens[] = $key.'='.escapeshellarg((string) $item);
                }

                continue;
            }

            foreach ((array) $value as $item) {
                $tokens[] = escapeshellarg((string) $item);
            }
        }

        return implode(' ', $tokens);
    }
}

if (! function_exists('installerArtisanExecutionMode')) {
    /**
     * 이번 실행에 쓸 실행 모드를 돌려준다 (설치 상태의 확정값 우선).
     *
     * @return string subprocess|inprocess
     */
    function installerArtisanExecutionMode(): string
    {
        return installerEffectiveExecutionMode();
    }
}

if (! function_exists('installerRunArtisan')) {
    /**
     * Artisan 커맨드를 유효 실행 모드로 실행한다.
     *
     * 결과는 두 전략이 같은 형태로 돌려준다 — 종료 코드, 출력 라인 배열, 사용한 모드.
     * "실행됐지만 실패"(비-0) 와 "실행 자체가 불가"(스폰 실패·부팅 실패) 를 구분하지 않고
     * 둘 다 비-0 종료 + 사유 라인으로 표현한다. 호출부(태스크 함수)는 종전처럼 종료 코드와
     * 출력만 본다.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     * @return array{exit_code: int, output: array<int, string>, mode: string} 실행 결과
     */
    function installerRunArtisan(string $command, array $parameters = []): array
    {
        $override = $GLOBALS['g7_installer_artisan_executor_override'] ?? null;
        if (is_callable($override)) {
            $result = $override($command, $parameters);

            return [
                'exit_code' => (int) ($result['exit_code'] ?? 0),
                'output' => array_values(array_map('strval', (array) ($result['output'] ?? []))),
                'mode' => (string) ($result['mode'] ?? 'override'),
            ];
        }

        $mode = installerArtisanExecutionMode();

        if ($mode === ExecutionEnvironment::MODE_INPROCESS) {
            return installerRunArtisanInProcess($command, $parameters);
        }

        return installerRunArtisanSubprocess($command, $parameters);
    }
}

if (! function_exists('installerRunArtisanSubprocess')) {
    /**
     * 서브프로세스 전략 — 종전 executeArtisanCommandSSE 의 스폰 본문을 그대로 옮겼다.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     * @return array{exit_code: int, output: array<int, string>, mode: string} 실행 결과
     */
    function installerRunArtisanSubprocess(string $command, array $parameters = []): array
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            return [
                'exit_code' => 1,
                'output' => [lang('error_subprocess_unavailable')],
                'mode' => ExecutionEnvironment::MODE_SUBPROCESS,
            ];
        }

        // exec() 는 블로킹이고 그 동안에는 emit 이 없어 heartbeat 가 갱신되지 않는다 —
        // SSE·폴링 워커는 요청 수명 600초를 살면서 lock 에는 15초만 선언하므로, 오래 도는
        // 태스크(마이그레이션 등)에서 새로고침한 요청이 아직 살아 있는 이 요청을 stale 로
        // 보고 탈취해 같은 태스크를 겹쳐 돌린다. in-process 부팅이 같은 이유로 하는 것과
        // 대칭으로, 스폰 직전에 이 요청의 시간 예산을 lock 에도 알린다.
        // 선언과 요청 수명은 반드시 같이 움직인다. stepwise 요청은 자기 수명을 180초로 잡고
        // 들어오므로(run-task.php), 선언만 600 으로 올리면 비정상 종료 시 이미 죽은 요청의
        // 점유가 최대 10분 남아 다음 요청이 그동안 busy 를 받는다. in-process 부팅과 같은
        // 순서로 수명을 먼저 올린 뒤 그 값을 lock 에 알린다.
        // 선언값은 요청값이 아니라 **실제로 적용된 값**이다 (PO 결정 13차 환경#9) —
        // `set_time_limit` 이 막혔거나 호스팅이 상한을 잠근 환경에서 600 을 선언하면
        // 30초에 죽은 워커의 예산이 10분 남아 다음 요청이 그동안 거짓 busy 를 받는다.
        $timeBudget = installerSetTimeLimit(INSTALLER_SUBPROCESS_TIME_LIMIT);

        $workerId = function_exists('installerCurrentWorkerId') ? installerCurrentWorkerId() : null;
        if ($workerId !== null && function_exists('extendWorkerStaleWindow')) {
            extendWorkerStaleWindow($workerId, $timeBudget);
        }

        chdir(BASE_PATH);
        $output = [];
        $returnCode = 0;
        $phpBin = escapeshellarg(getPhpBinary());
        $artisanCommand = installerBuildArtisanCommandLine($command, $parameters);

        // 비대화형 강제 — 웹 요청 안에는 응답할 TTY 가 없다 (13차 E3). 코어의 두 러너
        // (SubprocessCommandRunner·InProcessCommandRunner)가 이미 같은 규약을 쓰므로, 여기만
        // 빠지면 같은 파라미터가 러너마다 다른 argv 로 실행된다 — 확인 프롬프트를 띄우는
        // 커맨드가 응답을 기다리며 멈추고, 화면에는 진행이 멈춘 것으로만 보인다.
        if (! array_key_exists('--no-interaction', $parameters)) {
            $artisanCommand .= ' --no-interaction';
        }

        // 출력 파일 예약이 실패하면(open_basedir 로 후보 디렉토리가 전부 막힌 환경) 리다이렉트
        // 자체가 성립하지 않는다. 쓸 수 없는 경로로 그냥 진행하면 파일이 생기지 않아 output 이
        // 빈 배열로 남고, 실패 사유가 통째로 사라진다 (13차 환경#12). 그 경우에는 파이프로
        // 받는다 — deadlock 회피 이점은 잃지만 사유는 남는다.
        $cmdLogFile = reserveCommandOutputFile();

        if ($cmdLogFile === null) {
            $fullCommand = "{$phpBin} -d memory_limit=512M artisan {$artisanCommand} 2>&1";
            exec($fullCommand, $output, $returnCode);
        } else {
            $fullCommand = "{$phpBin} -d memory_limit=512M artisan {$artisanCommand} > "
                .escapeshellarg($cmdLogFile).' 2>&1';
            exec($fullCommand, $_ignored, $returnCode);

            if (file_exists($cmdLogFile)) {
                $output = file($cmdLogFile, FILE_IGNORE_NEW_LINES) ?: [];
                @unlink($cmdLogFile);
            }
        }

        // 시그널로 죽은 자식은 출력이 비어 있는 것이 정상이다 — 사유를 여기서 붙인다.
        $signalReason = installerDescribeSignalExit((int) $returnCode);
        if ($signalReason !== null) {
            $output[] = $signalReason;
        }

        return [
            'exit_code' => (int) $returnCode,
            'output' => $output,
            'mode' => ExecutionEnvironment::MODE_SUBPROCESS,
        ];
    }
}

if (! function_exists('installerBootLaravelForInProcess')) {
    /**
     * in-process 실행을 위해 같은 프로세스에 Laravel 을 부팅한다 (요청당 1회).
     *
     * 콘솔 커널로 부트스트랩한다 — Artisan::call 이 요구하는 것이 그것이고, 웹 커널의
     * 미들웨어·라우팅은 필요 없다. 설치 중 DB 자격증명·APP_KEY 는 runtime.php 를 읽는
     * InstallerRuntimeServiceProvider 가 주입하므로 서브프로세스 경로와 같은 설정으로 뜬다.
     *
     * @return array{ok: bool, error?: string} 부팅 결과
     */
    function installerBootLaravelForInProcess(): array
    {
        static $booted = null;

        if ($booted !== null) {
            return $booted;
        }

        $autoload = BASE_PATH.'/vendor/autoload.php';
        $bootstrap = BASE_PATH.'/bootstrap/app.php';

        if (! is_file($autoload) || ! is_file($bootstrap)) {
            return $booted = ['ok' => false, 'error' => lang('error_inprocess_vendor_missing')];
        }

        // 서브프로세스 경로의 `-d memory_limit=512M` 와 같은 여유를 준다 (상향만, 하향 없음).
        // `ini_get`/`ini_set` 은 둘 다 호스팅 표준 차단 목록에 있다 — 무가드로 부르면
        // in-process 부팅이 그 자리에서 치명 종료하고, in-process 는 무CLI 환경의 **유일한**
        // 실행 경로라 대안이 없다. 못 읽으면 상향을 건너뛴다 (현재 한도로 진행).
        //
        // 상향은 재조회로 실적용을 확인하고 미달이면 진행 기록에 남긴다 (20차 #1) —
        // installerIniSet 은 void 라 종전에는 ini_set 차단 호스트에서 상향 실패가 어디에도
        // 남지 않았고, 이후 OOM 은 사유 없는 중단으로만 보였다. 시간 예산(실적용치 반환·
        // 선언)과 같은 규칙이다. 해석 불가 값(null)은 상향을 시도하지 않는다 — 판단 근거가
        // 없는 상태에서의 변경은 하향일 수도 있다.
        $current = installerHasFunction('ini_get') ? ini_get('memory_limit') : false;
        $currentBytes = ($current !== false && $current !== '-1') ? installerIniBytes((string) $current) : null;
        if ($current !== '-1' && $currentBytes !== null && $currentBytes < 512 * 1024 * 1024) {
            installerIniSet('memory_limit', '512M');

            $applied = installerHasFunction('ini_get') ? ini_get('memory_limit') : false;
            $appliedBytes = ($applied !== false && $applied !== '-1') ? installerIniBytes((string) $applied) : null;
            if ($applied !== '-1' && $appliedBytes !== null && $appliedBytes < 512 * 1024 * 1024 && function_exists('addLog')) {
                addLog(lang('log_memory_limit_raise_shortfall', [
                    'current' => (string) $applied,
                    'target' => '512M',
                ]));
            }
        }
        $timeBudget = installerSetTimeLimit(INSTALLER_INPROCESS_TIME_LIMIT);

        // 이 요청의 시간 예산이 늘었다는 것을 워커 lock 에도 알린다 — 태스크 실행 중에는
        // heartbeat 를 갱신할 수 없으므로, 알리지 않으면 아직 살아 있는 이 요청을 다른
        // 요청이 stale 로 보고 탈취해 같은 태스크를 겹쳐 돌린다.
        //
        // 선언값은 요청값이 아니라 **실제로 적용된 값**이다 (PO 결정 13차 환경#9) — 요청값을
        // 그대로 선언하면 그 시간을 얻지 못한 환경에서 죽은 워커의 예산이 그만큼 남는다.
        $workerId = function_exists('installerCurrentWorkerId') ? installerCurrentWorkerId() : null;
        if ($workerId !== null && function_exists('extendWorkerStaleWindow')) {
            extendWorkerStaleWindow($workerId, $timeBudget);
        }

        // 이 부팅은 Artisan 커맨드를 돌리기 위한 것이다 — 서브프로세스 `php artisan …` 과 같은
        // 조건으로 떠야 두 전략이 등가다. 웹 SAPI 에서는 runningInConsole() 이 false 라
        // CoreServiceProvider 가 템플릿 로딩(DB 조회)을 건너뛰지 않고, 테이블이 아직 없는
        // 설치 초기(migrate 직전)에는 그 조회가 부팅 자체를 죽인다. Laravel 은
        // APP_RUNNING_IN_CONSOLE 로 판정을 덮어쓸 수 있다 (Env::disablePutenv 환경이라
        // $_ENV/$_SERVER 에 둔다).
        foreach (['APP_RUNNING_IN_CONSOLE'] as $key) {
            installerPutEnv($key, 'true');
        }

        try {
            require_once $autoload;

            $app = require $bootstrap;
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();

            // 부팅 중 HandleExceptions 가 display_errors 를 다시 켤 수 있다 — JSON 응답에
            // 경고 HTML 이 섞이지 않도록 인스톨러 정책(화면 출력 없음)으로 되돌린다.
            // 이 줄은 try 안이지만 그 catch 는 「부팅 실패」로 보고한다 — 실제로는 부팅에
            // 성공한 뒤 죽은 것이라 사유가 틀린 채 남는다. 형제 지점과 같이 가드한다.
            installerIniSet('display_errors', '0');
        } catch (Throwable $e) {
            return $booted = ['ok' => false, 'error' => lang('error_inprocess_boot_failed', ['error' => $e->getMessage()])];
        }

        return $booted = ['ok' => true];
    }
}

if (! function_exists('installerIniBytes')) {
    /**
     * php.ini 크기 표기(128M, 1G …)를 바이트로 바꾼다.
     *
     * 해석은 `installerParseByteSize()`(execution-mode.php) 단일 지점에 위임한다 (20차 #3) —
     * 종전에는 두 파서가 공존했고 규칙이 달랐다: 이쪽은 알 수 없는 단위를 `(int)` 로 조용히
     * 접었고(`'1x'` → 1바이트), 소수 표기(`1.5G`)도 잘랐다. 갈라진 파서는 같은 ini 값에
     * 다른 판정을 낳는다. 해석 불가는 null 로 구분한다 — 호출자는 근거 없는 값으로 상향
     * 여부를 판단하지 않는다.
     *
     * @param  string  $value  ini 값
     * @return int|null 바이트 수 (-1 은 무제한 그대로, 해석 불가는 null)
     */
    function installerIniBytes(string $value): ?int
    {
        return installerParseByteSize($value);
    }
}

if (! function_exists('installerRunArtisanInProcess')) {
    /**
     * in-process 전략 — Laravel 을 부팅한 뒤 코어 러너로 Artisan::call 한다.
     *
     * 커맨드 안에서 새어 나오는 표준 출력(경고 등)은 출력 버퍼로 거둬 결과 라인에 합친다 —
     * 응답 본문(JSON)에 섞이면 클라이언트 파싱이 깨진다.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     * @return array{exit_code: int, output: array<int, string>, mode: string} 실행 결과
     */
    function installerRunArtisanInProcess(string $command, array $parameters = []): array
    {
        $mode = ExecutionEnvironment::MODE_INPROCESS;

        $boot = installerBootLaravelForInProcess();
        if (! $boot['ok']) {
            return ['exit_code' => 1, 'output' => [$boot['error'] ?? 'boot failed'], 'mode' => $mode];
        }

        ob_start();
        try {
            $runner = app(CommandRunnerResolver::class)->resolve(ExecutionMode::Inprocess);
            $result = $runner->run($command, $parameters);
            $exitCode = $result->exitCode;
            $outputText = $result->output;
        } catch (Throwable $e) {
            $exitCode = 1;
            $outputText = get_class($e).': '.$e->getMessage();
        }
        $stray = (string) ob_get_clean();

        $lines = preg_split('/\r\n|\r|\n/', trim($outputText)) ?: [];
        if (trim($stray) !== '') {
            $lines = array_merge($lines, preg_split('/\r\n|\r|\n/', trim($stray)) ?: []);
        }

        return [
            'exit_code' => (int) $exitCode,
            'output' => array_values(array_filter($lines, static fn ($l) => $l !== '')),
            'mode' => $mode,
        ];
    }
}
