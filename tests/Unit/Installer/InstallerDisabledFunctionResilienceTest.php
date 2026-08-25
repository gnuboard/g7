<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * `disable_functions` 환경에서 인스톨러 화면이 서는지 실측한다 (13차 환경#1·환경#18).
 *
 * ## 왜 하위 프로세스인가
 *
 * PHP 8 부터 `disable_functions` 로 막힌 함수는 **함수 테이블에서 제거**된다 — 그래서
 * `@disk_free_space(...)` 처럼 `@` 를 붙여도 `Error: Call to undefined function` 이 그대로
 * 난다(`@` 는 진단 메시지를 억제할 뿐 Error 를 삼키지 않는다). 실측:
 *
 *   php -d disable_functions=putenv -r '@putenv("A=1");'
 *   → PHP Fatal error: Uncaught Error: Call to undefined function putenv()
 *
 * `disable_functions` 는 PHP_INI_SYSTEM 이라 돌고 있는 프로세스 안에서는 만들 수 없다.
 * 그래서 이 테스트는 `php -d disable_functions=…` 로 인스톨러 진입점 하네스를 띄우고,
 * 그 화면이 **치명 종료 없이** 정상 봉투를 내는지 본다.
 *
 * ## 무엇이 걸려 있는가
 *
 * `disk_free_space` 는 cPanel/CloudLinux/Imunify 의 표준 차단 목록에 `exec` 와 나란히 들어
 * 있다 — 이 안이 지원하려는 바로 그 호스팅이다. 그 환경에서 요구사항 검증은 첫 항목에서
 * 죽어 **목록이 통째로 안 뜨고**, 최상위 catch 가 개발자 원문(`Call to undefined function`)
 * 을 진단에 실어 보낸다. 세션 부팅의 `ini_set` 은 더 앞이라 화면이 백지가 된다.
 *
 * 18차가 연 축은 그보다 뒤다 — `ini_get` 과 `error_log` 는 이 목록에 없었고, 그 무가드 호출이
 * 하필 가드 판정 SSoT 자신(`ExecutionEnvironment::hasFunction`)과 최후 폴백 진단 채널에 있었다.
 * 그리고 하네스가 **첫 화면 경로만** 띄웠기 때문에, 요구사항이 전부 초록인 환경에서 태스크가
 * 한 개도 돌지 않는 상태(`installerSetTimeLimit` 의 무가드 `ini_get`)는 어떤 검사에도 걸리지
 * 않았다 — 그래서 stepwise 런타임 하네스를 함께 둔다.
 *
 * 커버 축: blocked_function disk_free_space·ini_set·ini_get·error_log·realpath·set_time_limit·ignore_user_abort·glob·php_uname·sys_get_temp_dir·putenv·opcache_get_status·scandir / entry requirements_screen·session_boot·stepwise_runtime / outcome renders·fatal
 *
 * @effects installer_requirements_screen_survives_blocked_functions, installer_session_boot_survives_blocked_ini_set, installer_disk_space_check_reports_unknown_instead_of_blocking, installer_stepwise_runtime_survives_blocked_functions
 */
class InstallerDisabledFunctionResilienceTest extends TestCase
{
    /**
     * 요구사항 화면이 막혀도 서야 하는 함수들.
     *
     * 목록을 손으로 줄이면 그만큼 사각이 늘어난다 — 호스팅이 실제로 막는 함수 중 이 안의
     * 코드가 부르는 것을 전부 담는다.
     *
     * @var array<int, string>
     */
    private const BLOCKED_FUNCTIONS = [
        'disk_free_space',
        'ini_set',
        'realpath',
        'set_time_limit',
        'ignore_user_abort',
        'glob',
        'php_uname',
        'sys_get_temp_dir',
        'putenv',
        'opcache_get_status',
        // 하위 디렉토리 점검 (checkDirectoryPermissions) — 가드가 붙어 대기 목록에서 승격됐다
        'scandir',
        // 18차. `ini_set` 의 짝인데 이 목록에 없었다 — 그래서 `ExecutionEnvironment::hasFunction()`
        // 이 `ini_get('disable_functions')` 를 무가드로 부르는 것을 어떤 검사도 보지 못했다.
        // **가드 판정 SSoT 자신**이라 그 한 줄이 죽으면 「가드가 있다」고 선언한 지점이 전부
        // 동시에 무너진다: 세션 부팅·요구사항 화면 둘 다 백지였다 (실측 재현).
        'ini_get',
        // 18차. 인스톨러의 **최후 폴백 진단 채널**이자 세션 부팅 최상위 코드(session.php).
        // 3인자 형태가 임의 파일 쓰기라 하드닝 프로필의 표준 차단 대상인데, 무가드 호출이
        // 28곳이었다 — 막히면 첫 요청이 백지가 되고, 그 사유를 남길 채널이 바로 그것이다.
        'error_log',
    ];

    /**
     * 아직 가드가 없는 지점의 대기 목록 (사유 = 소유 파일).
     *
     * 조용히 목록 밖으로 빼지 않는다 — 여기 남아 있는 한 「알고 있으나 아직 안 고쳤다」이고,
     * 고치면 이 검사가 red 가 되어 목록에서 지우라고 알려 준다. 13차 공정의 파일 소유권
     * 밖이라 이번에 손대지 못한 지점이다.
     *
     * @var array<string, string>
     */
    private const PENDING_UNGUARDED = [
        // 비어 있음 = 알려진 무가드 지점이 남아 있지 않다. `scandir` 은 가드가 붙어
        // BLOCKED_FUNCTIONS 전수 검사로 승격됐다.
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('exec')) {
            $this->markTestSkipped('exec 가 없어 하위 프로세스 실측을 할 수 없다');
        }

        if (! defined('PHP_BINARY') || PHP_BINARY === '') {
            $this->markTestSkipped('PHP_BINARY 를 알 수 없어 하위 프로세스를 띄울 수 없다');
        }
    }

    /**
     * 하네스를 `disable_functions` 와 함께 실행한다.
     *
     * @param  string  $fixture  stubs/ 하위 하네스 파일명
     * @param  string  $disabled  막을 함수 목록 (쉼표 구분)
     * @return array{code: int, output: string} 종료 코드와 표준출력(표준오류 포함)
     */
    private function runProbe(string $fixture, string $disabled): array
    {
        $command = escapeshellarg(PHP_BINARY)
            .' -d '.escapeshellarg('disable_functions='.$disabled)
            .' '.escapeshellarg(__DIR__.'/stubs/'.$fixture)
            .' 2>&1';

        $output = [];
        $code = -1;
        exec($command, $output, $code);

        return ['code' => $code, 'output' => implode("\n", $output)];
    }

    public function test_requirements_screen_renders_when_common_functions_are_blocked(): void
    {
        foreach (self::BLOCKED_FUNCTIONS as $function) {
            $result = $this->runProbe('requirements_probe.php', $function);

            $this->assertStringNotContainsString(
                'Fatal error',
                $result['output'],
                $function.' 이(가) 막힌 환경에서 요구사항 화면이 치명 종료했다 — 그 화면은 목록이 통째로 뜨지 않는다'
            );

            $decoded = json_decode($result['output'], true);

            $this->assertIsArray(
                $decoded,
                $function.' 이(가) 막힌 환경에서 요구사항 응답이 JSON 이 아니다: '.substr($result['output'], 0, 200)
            );
            $this->assertArrayHasKey('php_version', $decoded, $function.' 차단 시 요구사항 항목이 비었다');
            $this->assertArrayHasKey('disk_space', $decoded);
        }
    }

    /**
     * 단계별 실행(stepwise)의 런타임 판정이 차단 환경에서도 성립해야 한다 (18차).
     *
     * ## 왜 형제 검사로 부족한가
     *
     * 위 두 검사는 **첫 화면 경로**(요구사항 화면 · 세션 부팅)만 본다. G2 가 신설한
     * 태스크 실행 경로는 그 화면들을 지난 **뒤에** 처음 돌기 때문에, 요구사항이 전부 초록인
     * 환경에서도 태스크가 한 개도 안 도는 상태가 성립한다 — 그리고 그 구간을
     * `disable_functions` 아래에서 실행해 본 검사는 1~17차 어디에도 없었다.
     *
     * 실측 사례: `installerSetTimeLimit()` 이 바로 위 `set_time_limit` 은 가드하면서
     * 다음 줄의 `ini_get('max_execution_time')` 은 무가드였다. `run-task.php` 가 lock 을
     * 잡기 전에 부르는 자리라, 막힌 호스팅에서는 JSON 봉투도 서지 못한 500 이 된다.
     */
    public function test_stepwise_runtime_judgment_survives_blocked_functions(): void
    {
        foreach (self::BLOCKED_FUNCTIONS as $function) {
            $result = $this->runProbe('stepwise_runtime_probe.php', $function);

            $this->assertStringNotContainsString(
                'Fatal error',
                $result['output'],
                $function.' 이(가) 막힌 환경에서 stepwise 런타임 판정이 치명 종료했다 — 태스크가 한 개도 돌지 않는다'
            );

            $decoded = json_decode($result['output'], true);

            $this->assertIsArray(
                $decoded,
                $function.' 차단 시 stepwise 런타임 판정 응답이 JSON 이 아니다: '.substr($result['output'], 0, 200)
            );

            // 시간 예산은 lock 이 선언하는 값이다 — 0 이면 살아 있는 워커가 즉시 탈취된다.
            $this->assertGreaterThan(
                0,
                $decoded['budget'] ?? 0,
                $function.' 차단 시 시간 예산이 0 이 됐다 — lock 선언이 사라진다'
            );
        }
    }

    public function test_disk_space_check_reports_unknown_instead_of_blocking(): void
    {
        $result = $this->runProbe('requirements_probe.php', 'disk_free_space');
        $decoded = json_decode($result['output'], true);

        $this->assertIsArray($decoded, '요구사항 응답이 JSON 이 아니다: '.substr($result['output'], 0, 200));

        // 확인 불가는 차단하지 않는다 — checkOpcache 의 3상태와 같은 규칙.
        $this->assertTrue($decoded['disk_space']['unknown'] ?? false, '확인 불가 상태가 표시되지 않는다');
        $this->assertTrue($decoded['disk_space']['passed'] ?? false, '확인 불가가 설치를 차단하고 있다');
        $this->assertNull($decoded['disk_space']['current_mb'], '측정하지 못한 값을 숫자로 단정하고 있다');
    }

    /**
     * 21차 #21-6: 「확인 불가」의 두 갈래는 같은 판정이어야 한다.
     *
     * `disk_free_space` 가 **차단**된 갈래는 unknown+passed=true(위 테스트)인데, 함수는
     * 있으나 호출이 **false 를 돌려준** 갈래(NFS/CIFS 마운트·일부 컨테이너 볼륨)는
     * passed=false 로 떨어져 있었다 — 같은 「확인 불가」가 8줄 간격으로 반대 판정을 내고,
     * 화면은 그것을 빨강+차단으로 증폭한다. 디스크는 넉넉한데 설치가 원리상 진행 불가가
     * 되는 거짓 차단이다(20차 #6 과 같은 형태).
     *
     * 행위 재현이 불가한 갈래(실 디스크에서 false 를 강제할 수 없음)라 소스 계약으로
     * 고정한다 — false 갈래의 반환 배열이 차단 갈래와 같은 3상태 값을 갖는지.
     */
    public function test_disk_probe_failure_branch_matches_blocked_branch(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/public/install/api/check-configuration.php'
        );

        $branchStart = strpos($source, 'if ($freeSpaceBytes === false)');
        $this->assertNotFalse($branchStart, 'disk_free_space 실패 갈래가 사라졌다');

        $branch = substr($source, $branchStart, 420);

        $this->assertStringContainsString("'unknown' => true", $branch);
        $this->assertStringContainsString("'passed' => true", $branch, '호출 실패 갈래가 차단으로 판정한다 (21차 #21-6) — 차단 갈래와 같은 「확인 불가 = 경고」여야 한다');
        $this->assertStringContainsString("'current_mb' => null", $branch, '측정하지 못한 값은 0 이 아니라 null 이다 (차단 갈래와 동일 계약)');
    }

    public function test_session_boot_survives_blocked_ini_set(): void
    {
        $result = $this->runProbe('session_boot_probe.php', 'ini_set');

        $this->assertStringNotContainsString(
            'Fatal error',
            $result['output'],
            'ini_set 이 막힌 환경에서 세션 부팅이 치명 종료했다 — 인스톨러 첫 화면이 백지가 된다'
        );

        $decoded = json_decode($result['output'], true);

        $this->assertIsArray($decoded, '세션 부팅 응답이 JSON 이 아니다: '.substr($result['output'], 0, 200));
        $this->assertTrue($decoded['session'] ?? false, 'ini_set 이 막혀도 세션 자체는 떠야 한다');
    }

    public function test_pending_unguarded_call_sites_still_fatal_and_stay_on_the_ledger(): void
    {
        if (self::PENDING_UNGUARDED === []) {
            // 목록이 비면 검사할 대상도 없다 — 그 사실 자체를 단언해 둔다. 새 무가드 지점이
            // 목록에 오르면 아래 전수 검사가 그대로 되살아난다.
            $this->assertSame([], self::PENDING_UNGUARDED, '대기 목록이 비어 있다 (알려진 무가드 지점 없음)');

            return;
        }

        foreach (self::PENDING_UNGUARDED as $function => $owner) {
            $result = $this->runProbe('requirements_probe.php', $function);

            $this->assertStringContainsString(
                'Fatal error',
                $result['output'],
                $function.' 은(는) 이제 가드된 것으로 보인다 — PENDING_UNGUARDED 목록에서 지우고 위 전수 검사로 옮길 것 ('.$owner.')'
            );
        }
    }

    /**
     * 인스톨러 첫 요청이 타는 파일들 — 요구사항 화면이 뜨기 **전**에 도는 코드.
     *
     * `index.php` 가 최상단에서 `require_once` 하는 것들이며, `session.php` 는 함수 선언이
     * 아니라 **최상위 코드**라 로드되는 순간 실행된다.
     *
     * @var array<int, string>
     */
    private const BOOT_PATH_FILES = [
        'public/install/includes/config.php',
        'public/install/includes/functions.php',
        'public/install/includes/session.php',
        'public/install/includes/installer-state.php',
        'public/install/includes/request-handler.php',
        'public/install/includes/execution-mode.php',
        'public/install/includes/validation.php',
        'public/install/api/_guard.php',
    ];

    /**
     * 부팅 경로가 부르는 확장 의존 함수를 **소스에서 도출**한다.
     *
     * 손으로 적은 목록은 그 자체가 사각이다 — 이 검사가 잡으려는 것이 바로 「새로 추가된
     * 무가드 호출」이므로, 목록을 사람이 갱신해야 하면 갱신을 잊는 순간 검사가 무력해진다.
     * 그래서 파일을 읽어 호출문을 뽑는다. 부팅 경로에 새 `mb_*` 호출이 생기면 그 함수가
     * 자동으로 이 검사의 모집단에 들어온다.
     *
     * @return array<int, string> 함수명 (중복 제거·정렬)
     */
    private function extensionFunctionsCalledOnBootPath(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach (self::BOOT_PATH_FILES as $relative) {
            $path = $root.'/'.$relative;
            $source = is_file($path) ? (string) file_get_contents($path) : '';

            if ($source === '') {
                $this->fail('부팅 경로 파일을 읽지 못했다: '.$relative);
            }

            if (preg_match_all('/\b(mb_[a-z0-9_]+)\s*\(/i', $source, $matches)) {
                foreach ($matches[1] as $function) {
                    $found[strtolower($function)] = true;
                }
            }
        }

        $functions = array_keys($found);
        sort($functions);

        return $functions;
    }

    /**
     * 필수 확장(mbstring)이 없는 서버에서도 첫 화면과 요구사항 화면이 서야 한다 (16차 B-1·B-2).
     *
     * ## 왜 이 축이 형제 축과 별개인가
     *
     * 위 `BLOCKED_FUNCTIONS` 는 「호스팅이 **함수**를 막았을 때」를 본다. 이 검사는
     * 「**확장 자체가 없을 때**」를 본다 — 호출부에서는 둘의 증상이 같지만(함수 테이블 부재),
     * 그 사실을 운영자에게 알려 줄 책임자가 다르다. mbstring 은 `REQUIRED_EXTENSIONS` 항목이라
     * **요구사항 화면이 「없습니다」라고 말해 주어야 하는 대상**이다. 그 화면에 닿기 전에
     * 죽으면 운영자에게 남는 것은 백지뿐이고, 원인을 알 방법이 없다.
     *
     * 13차가 같은 형태를 함수 축에서 치명으로 판정했으나(요구사항 화면이 통째로 안 뜬다),
     * 확장 축에는 검사가 없어 16차까지 살아남았다.
     *
     * ## 재현 방식
     *
     * 확장을 언로드할 수는 없으므로 그 확장의 **함수를 막는다**. 호출 지점에서 일어나는 일은
     * 확장 부재와 동일하다(PHP 8 은 막힌 함수를 함수 테이블에서 제거한다).
     *
     * @effects installer_boot_survives_missing_required_extension
     */
    public function test_boot_and_requirements_survive_missing_mbstring(): void
    {
        $functions = $this->extensionFunctionsCalledOnBootPath();

        $this->assertNotSame(
            [],
            $functions,
            '부팅 경로에서 mb_* 호출을 하나도 찾지 못했다 — 도출 정규식이 죽었을 가능성이 있다'
        );

        // ① 하나씩 막기 — 어느 호출이 가드되지 않았는지 지목할 수 있다.
        foreach ($functions as $function) {
            foreach (['session_boot_probe.php', 'requirements_probe.php'] as $probe) {
                $result = $this->runProbe($probe, $function);

                $this->assertStringNotContainsString(
                    'Fatal error',
                    $result['output'],
                    $function.' 이(가) 없는 환경에서 '.$probe.' 가 치명 종료했다 — '
                        .'mbstring 은 요구사항 화면이 「없습니다」라고 알려 줘야 하는 필수 확장인데, '
                        .'그 화면에 닿기 전에 죽으면 운영자에게는 백지만 남는다'
                );

                $this->assertIsArray(
                    json_decode($result['output'], true),
                    $function.' 차단 시 '.$probe.' 응답이 JSON 이 아니다: '.substr($result['output'], 0, 200)
                );
            }
        }

        // ② 전량 막기 — 확장이 통째로 없는 상태에 가장 가깝다. 하나씩 통과해도 조합에서
        //    깨지는 분기(가드가 일부 함수에만 붙은 경우)를 여기서 잡는다.
        foreach (['session_boot_probe.php', 'requirements_probe.php'] as $probe) {
            $result = $this->runProbe($probe, implode(',', $functions));

            $this->assertStringNotContainsString(
                'Fatal error',
                $result['output'],
                'mbstring 함수 전량이 없는 환경에서 '.$probe.' 가 치명 종료했다'
            );

            $this->assertIsArray(
                json_decode($result['output'], true),
                'mbstring 전량 차단 시 '.$probe.' 응답이 JSON 이 아니다: '.substr($result['output'], 0, 200)
            );
        }
    }
}
