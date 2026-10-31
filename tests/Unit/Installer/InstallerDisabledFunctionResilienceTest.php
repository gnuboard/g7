<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

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
 * 커버 축: blocked_function disk_free_space·ini_set·ini_get·error_log·realpath·set_time_limit·ignore_user_abort·glob·php_uname·sys_get_temp_dir·putenv·opcache_get_status·scandir / entry requirements_screen·session_boot·stepwise_runtime·corrupt_state_copies / outcome renders·fatal
 */
class InstallerDisabledFunctionResilienceTest extends TestCase
{
    use ScansPhpSourceFunctions;

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
        // 3차 G3 라운드. 노출 점검(exposure-check.php)이 링크 실측 임시 파일명을 가르는 데
        // 이 함수를 무가드로 썼다 — 막힌 호스팅에서 요구사항 화면이 통째로 뜨지 않았다.
        // 호출 자체를 난수원으로 통일해 없앴고, 되살아나면 이 전수 검사가 잡는다.
        'getmypid',
        // 4차 G3 라운드. 3차가 정적 룰에는 등재했는데 **이 전수 검사에는 넣지 않아**, 계획서
        // 문언(「룰과 전수 검사에 등재」)이 절반만 사실이었다. 두 게이트의 목록이 어긋나면
        // 어느 쪽을 봐도 「검사했다」와 「보지 않았다」가 구분되지 않는다.
        'posix_getpid',
        // 4차 G3 라운드. 둘 다 하드닝 프로필이 막을 수 있는데 인스톨러가 **비밀 채널
        // (runtime.php 0600)·설치 상태 저장·vendor 설치·완료 플래그**에서 무가드로 불렀다.
        // 막히면 PHP 8 은 함수 테이블에서 지우므로 Error(치명)이고, 그 지점들은 전부 설치가
        // 한 걸음도 나아가지 못하는 자리다. 형제 지점(exposure-check.php)은 이미 가드가
        // 있었으므로 규약이 파일마다 갈려 있던 상태였다.
        'chmod',
        // 5차 G3 라운드. 4차가 「catch 안의 uniqid 폴백」을 교체했다고 기록했으나 잔존 2곳이
        // 남아 있었고, 정적 룰·이 목록·대기 목록 **셋 다 이 이름을 몰라** 아무도 잡지
        // 못했다. 남은 자리 하나는 요구사항 화면의 디렉토리 권한 점검이라 첫 화면이 백지다.
        'uniqid',
        // 7차 G3 라운드. 인스톨러는 이미 `installerHasFunction('symlink')` 로 이 이름을
        // 차단 가능으로 다뤘는데, **이 목록과 정적 룰 둘 다 그 이름을 몰랐다** — 그래서
        // 코어 형제 두 지점(`StorageLinkHelper::createLink` · `FilePermissionHelper`)의
        // `@symlink(...)` 무가드가 세 게이트 전부를 통과했고, 그 상태로 아래 대기 목록은
        // 「알려진 무가드 지점 없음」을 선언하고 있었다.
        'symlink',
        // 7차 G3 라운드 D-13. 대기 목록이 「비어 있다」를 **도출로** 바꾸자마자 이 여섯이
        // 드러났다 — 정적 룰이 가드를 요구하고 부팅 경로가 실제로 부르는데 이 전수 검사의
        // 모집단에는 없었다. 전부 가드는 갖고 있었으므로 결함이 아니라 **측정 공백**이며,
        // 손으로 비운 선언이 그만큼 사실이 아니었다는 실례다.
        'exec',
        'proc_open',
        'getenv',
        'posix_geteuid',
        'posix_getpwuid',
        'posix_getgrgid',
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

    /**
     * 하위 프로세스를 띄울 수단(exec 함수와 PHP 실행 파일 경로)이 없으면 이 클래스의 실측을 건너뛴다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 파손 상태 사본 나열은 `glob` 이 막혀도 **목록을 낸다** (14차 G3 W2-2).
     *
     * 종전에는 `glob` 차단 시 빈 목록이었다 — 그러면 「같은 내용 1회」와 「상한 5」 두
     * 가드가 동시에 증발해 폴링마다 마스킹 전 상태 사본이 무제한 증식한다. 형제
     * (`installerListExposureProbeFiles`)와 같은 `scandir` 폴백을 요구한다.
     *
     * @scenario blocked_function_entry=corrupt_state_copies, state_file=corrupt
     *
     * @effects installer_listing_guards_survive_blocked_glob
     *
     * @return void 반환값 없음
     */
    public function test_corrupt_state_copies_are_listed_when_glob_is_blocked(): void
    {
        putenv('G7_PROBE_SUFFIX=globonly-'.bin2hex(random_bytes(3)));
        $result = $this->runProbe('corrupt_state_copies_probe.php', 'glob');
        $this->assertSame(0, $result['code'], '차단 환경에서도 하네스는 정상 종료해야 한다: '.$result['output']);

        $decoded = json_decode(trim($result['output']), true);
        $this->assertIsArray($decoded, '하네스 출력이 JSON 이 아니다: '.$result['output']);
        $this->assertFalse($decoded['glob_available'], '하네스가 glob 을 실제로 막지 못했다 — 이 검사는 아무것도 재지 않는다');
        $this->assertSame(2, $decoded['count'], 'glob 이 막히면 사본 목록이 비어 두 가드가 함께 증발한다: '.$result['output']);
        $this->assertNotContains('installer-state.json.corrupt.notes', $decoded['names'], '형식이 다른 이물이 목록에 끼면 상한이 조기 소진된다');
    }

    /**
     * 정리 스윕의 **차단 분기**가 「쓸지 못했다」로 보고하는가 — 그리고 `glob` 하나로는
     * 더 이상 멈추지 않는가 (15차 G3 라운드에 계약을 **강화**했다).
     *
     * 종전에는 `glob` 이 막히면 정리가 통째로 무동작이었다. 그런데 **sentinel 생성은 그
     * 조건과 무관하게 계속되므로** 무인증 요청 반복이 `storage/installer` 에 상한 없이
     * 쌓였고, 총량 방어를 맡긴다고 적어 둔 진입 스윕이 바로 그 환경에서 사문이었다.
     * 나열을 `scandir` 폴백으로 넓혀, 「쓸지 못했다」는 **두 함수가 모두 막힌** 경우로
     * 좁혔다.
     *
     * `installerCleanupExposureProbe()` 는 「지울 것이 없었다(removed:0)」와 「쓸지 못했다」를
     * 나눠 보고한다 — 뭉뚱그리면 정리가 통째로 무동작인 호스팅에서도 「정리 완료 (0개)」가
     * 남아 운영자가 잔여물이 없다고 읽는다. 그런데 그 두 갈래 중 **차단 쪽을 재는 검사가
     * 없었다**: `glob` 은 `disable_functions` 로만 막히고, 그 설정은 돌고 있는 프로세스
     * 안에서 만들 수 없다. 그래서 형제 하네스와 같은 방식으로 별도 프로세스에서 잰다.
     *
     * @scenario blocked_function_entry=exposure_cleanup
     *
     * @effects installer_listing_guards_survive_blocked_glob
     *
     * @return void 반환값 없음
     */
    public function test_cleanup_reports_it_could_not_sweep_when_glob_is_blocked(): void
    {
        // `glob` 만 막힌 환경은 이제 **정상적으로 쓴다** — 나열이 `scandir` 로 넘어간다.
        putenv('G7_PROBE_SUFFIX=globonly');
        $globOnly = $this->runProbe('exposure_cleanup_probe.php', 'glob');
        $this->assertSame(0, $globOnly['code'], '차단 환경에서도 하네스는 정상 종료해야 한다: '.$globOnly['output']);

        $decodedGlobOnly = json_decode(trim($globOnly['output']), true);
        $this->assertIsArray($decodedGlobOnly, '하네스 출력이 JSON 이 아니다: '.$globOnly['output']);
        $this->assertTrue(
            $decodedGlobOnly['swept'],
            'glob 만 막힌 환경은 scandir 로 쓸 수 있어야 한다 — 못 쓸면 잔여물이 무한히 쌓인다'
        );

        // 나열 수단이 **둘 다** 막혔을 때만 「쓸지 못했다」다.
        putenv('G7_PROBE_SUFFIX=blocked');
        $blocked = $this->runProbe('exposure_cleanup_probe.php', 'glob,scandir');
        $this->assertSame(0, $blocked['code'], '차단 환경에서도 하네스는 정상 종료해야 한다: '.$blocked['output']);

        $decoded = json_decode(trim($blocked['output']), true);
        $this->assertIsArray($decoded, '하네스 출력이 JSON 이 아니다: '.$blocked['output']);
        $this->assertFalse($decoded['swept'], '나열 수단이 전부 막힌 환경은 「쓸지 못했다」로 보고해야 한다');
        $this->assertSame('listing_unavailable', $decoded['reason']);

        // 대조군 — 같은 하네스가 차단 없이 돌면 「쓸었다」다. 이 대조가 없으면 하네스가
        // 늘 false 를 내도 위 단언만으로는 드러나지 않는다.
        putenv('G7_PROBE_SUFFIX=allowed');
        $allowed = $this->runProbe('exposure_cleanup_probe.php', '');
        $decodedAllowed = json_decode(trim($allowed['output']), true);
        $this->assertIsArray($decodedAllowed, '대조군 출력이 JSON 이 아니다: '.$allowed['output']);
        $this->assertTrue($decodedAllowed['swept'], '차단이 없으면 쓸었다고 보고해야 한다');
        $this->assertNull($decodedAllowed['reason']);
    }

    /**
     * 차단 목록의 함수를 하나씩 막은 하위 프로세스에서 요구사항 화면 하네스가 치명 종료 없이 항목이 담긴 JSON 을 내는지 확인한다.
     *
     * @scenario blocked_function_entry=requirements_screen
     *
     * @effects installer_requirements_screen_survives_blocked_functions
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario blocked_function_entry=stepwise_runtime, installation_mode=stepwise
     *
     * @effects installer_stepwise_runtime_survives_blocked_functions
     *
     * @return void 반환값 없음
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

    /**
     * 디스크 여유 공간 함수가 막히면 요구사항 응답이 확인 불가로 표시하되 통과로 두고, 측정하지 못한 값을 숫자로 단정하지 않는지 확인한다.
     *
     * @scenario blocked_function_entry=requirements_screen
     *
     * @effects installer_disk_space_check_reports_unknown_instead_of_blocking
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario blocked_function_entry=requirements_screen
     *
     * @effects installer_disk_space_check_reports_unknown_instead_of_blocking
     *
     * @return void 반환값 없음
     */
    public function test_disk_probe_failure_branch_matches_blocked_branch(): void
    {
        $source = $this->sourceWithoutComments(
            dirname(__DIR__, 3).'/public/install/api/check-configuration.php'
        );

        $branchStart = strpos($source, 'if ($freeSpaceBytes === false)');
        $this->assertNotFalse($branchStart, 'disk_free_space 실패 갈래가 사라졌다');

        $branch = substr($source, $branchStart, 420);

        $this->assertStringContainsString("'unknown' => true", $branch);
        $this->assertStringContainsString("'passed' => true", $branch, '호출 실패 갈래가 차단으로 판정한다 (21차 #21-6) — 차단 갈래와 같은 「확인 불가 = 경고」여야 한다');
        $this->assertStringContainsString("'current_mb' => null", $branch, '측정하지 못한 값은 0 이 아니라 null 이다 (차단 갈래와 동일 계약)');
    }

    /**
     * ini_set 이 막힌 하위 프로세스에서 세션 부팅 하네스가 치명 종료 없이 세션을 띄웠다는 JSON 을 내는지 확인한다.
     *
     * @scenario blocked_function_entry=session_boot
     *
     * @effects installer_session_boot_survives_blocked_ini_set
     *
     * @return void 반환값 없음
     */
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

    /**
     * 「알려진 무가드 지점 없음」이 **손으로 비운 선언**이 아님을 증명한다 (7차 G3 D-13).
     *
     * @scenario blocked_function_entry=boot_path_ledger, guard_population=installer_sources
     *
     * @effects installer_unguarded_call_ledger_is_derived_not_hand_emptied
     *
     * @return void 반환값 없음
     */
    public function test_pending_unguarded_call_sites_still_fatal_and_stay_on_the_ledger(): void
    {
        if (self::PENDING_UNGUARDED === []) {
            // 목록이 비면 검사할 대상이 없다. 종전에는 그 사실을 `assertSame([], PENDING_UNGUARDED)`
            // 로 「단언」했는데 상수를 자기 자신과 비교하는 것이라 **언제나 참**이었다 — 즉
            // 「알려진 무가드 지점 없음」의 근거가 그 목록을 손으로 비운 것 자체였고, 실제로
            // 7차 G3 라운드에서 `symlink` 두 지점이 그 선언 밖에 있었다 (D-13·D-3).
            //
            // 대신 **도출**을 단언한다: 이 파일의 전수 검사 목록(BLOCKED_FUNCTIONS)이 정적 룰이
            // 가드를 요구하는 이름 전부를 담고 있는가. 룰에 이름이 하나 늘면 이 검사가 red 가
            // 되어 사람이 옮긴다 — 손으로 비운 목록에는 없던 강제다.
            $this->assertPendingLedgerIsDerivedNotHandEmptied();

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
     * 대기 목록이 **비어 있다는 사실**이 도출된 것인지 확인합니다.
     *
     * 「알려진 무가드 지점 없음」은 손으로 비울 수 있는 선언이라, 그 자체로는 근거가 되지
     * 않는다. 정적 룰(`hosting-blocked-function-requires-guard`)이 가드를 요구하는 이름 중
     * **부팅 경로가 실제로 부르는 것**을 도출해, 이 파일의 전수 검사 목록이 그것을 전부
     * 담고 있는지 본다. 룰에 이름이 늘거나 부팅 경로에 새 호출이 생기면 red 가 된다
     * (7차 G3 라운드 D-13).
     *
     * @return void 반환값 없음
     */
    private function assertPendingLedgerIsDerivedNotHandEmptied(): void
    {
        $required = $this->guardedFunctionNamesFromRule();

        $this->assertGreaterThan(
            20,
            count($required),
            '정적 룰에서 가드 대상 함수명을 도출하지 못했다 — 도출이 비면 아래 비교가 공허하다'
        );

        $bootSource = '';
        $root = dirname(__DIR__, 3);
        foreach (self::BOOT_PATH_FILES as $relative) {
            $bootSource .= $this->sourceWithoutComments($root.'/'.$relative);
        }

        $missing = [];
        foreach ($required as $function) {
            // 부팅 경로가 실제로 부르는 이름만 본다 — 룰 전체를 요구하면 인스톨러가 쓰지도
            // 않는 함수의 프로브를 강요하게 되어 검사가 목적을 잃는다.
            if (preg_match('/(?<![\w$>])'.preg_quote($function, '/').'\s*\(/', $bootSource) !== 1) {
                continue;
            }
            if (in_array($function, self::BLOCKED_FUNCTIONS, true)) {
                continue;
            }

            $missing[] = $function;
        }

        $this->assertSame(
            [],
            $missing,
            '정적 룰이 가드를 요구하고 부팅 경로가 실제로 부르는데 이 전수 검사 목록에 없는 함수: '
            .implode(', ', $missing).' — 「알려진 무가드 지점 없음」 선언이 그만큼 사실이 아니다'
        );
    }

    /**
     * 정적 룰 파일에서 가드 대상 함수명을 도출합니다.
     *
     * 룰은 JS 라 PHP 에서 실행할 수 없다. 배열 리터럴의 따옴표 문자열을 뽑는다 — 이름이
     * 늘면 그대로 따라오고, 룰 파일이 사라지면 도출이 비어 위 하한 단언이 red 가 된다.
     *
     * @return array<int, string> 함수명 목록
     */
    private function guardedFunctionNamesFromRule(): array
    {
        $path = dirname(__DIR__, 3).'/.claude/scripts/audit/rules/hosting-blocked-function-requires-guard.cjs';

        if (! is_file($path)) {
            return [];
        }

        // 이 자리의 룰 파일은 **공통 원본으로 위임하는 shim** 일 수 있다. 그 판본에는
        // `GUARDED_FUNCTIONS` 상수가 없으므로 도출이 언제나 0 이 되고, 아래 비교가 공허해져
        // 이 검사가 red 로 굳는다 — 하네스 진입점이 생성물로 바뀐 뒤 실제로 그랬다.
        //
        // 두 번째 경로를 적어 두는 대신 **위임을 따라간다.** 진입점이 가리키는 곳을 읽으므로
        // 원본이 어디로 옮겨져도 이 검사가 따라가고, 테스트가 하네스 배치를 알 필요도 없다.
        for ($hop = 0; $hop < 3; $hop++) {
            // 위임 판정도 주석을 지운 뒤에 한다 — 주석에 적힌 위임 경로를 따라가지 않게 한다.
            $raw = $this->sourceWithoutComments($path, false);

            // shim 은 작다(1 KB 미만). 룰 원본은 수십 KB 라 크기로 가른다 — 원본 안의
            // `require('node:fs')` 같은 호출을 위임으로 오인하지 않기 위한 구분이다.
            if (strlen($raw) > 4096) {
                break;
            }

            if (! preg_match('/require(?:\.resolve)?\(\s*"([^"]+)"\s*\)/', $raw, $m)) {
                break;
            }

            $target = realpath(dirname($path).'/'.$m[1]);

            if ($target === false || ! is_file($target)) {
                break;
            }

            $path = $target;
        }

        // 룰은 JS 다 — 주석 속 `'name'` 이 목록에 섞이지 않도록 JS 규칙으로 주석을 지운다.
        $source = $this->sourceWithoutComments($path, false);
        $names = [];

        foreach (['GUARDED_FUNCTIONS', 'INSTALLER_SCOPED_FUNCTIONS'] as $constant) {
            $start = strpos($source, 'const '.$constant.' = [');
            if ($start === false) {
                continue;
            }

            $end = strpos($source, '];', $start);
            if ($end === false) {
                continue;
            }

            $body = substr($source, $start, $end - $start);
            if (preg_match_all("/'([a-z0-9_]+)'/i", $body, $m)) {
                foreach ($m[1] as $name) {
                    $names[strtolower($name)] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
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
            $source = is_file($path) ? $this->sourceWithoutComments($path) : '';

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
     * @scenario blocked_function_entry=session_boot
     *
     * @effects installer_boot_survives_missing_required_extension
     *
     * @return void 반환값 없음
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
