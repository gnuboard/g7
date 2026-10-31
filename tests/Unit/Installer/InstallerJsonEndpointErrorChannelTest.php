<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 API 진입점의 오류 출력 채널 가드 (20차 #8·#9·#13)
 *
 * 인스톨러 API 는 전부 구조화 응답(JSON/SSE)을 돌려주고 클라이언트가 파싱한다.
 * `display_errors=On` 호스트(XAMPP 기본, php.ini-development 기본)에서는 warning 한 줄이
 * 본문 앞에 붙어 파싱이 깨지고, 절반의 클라이언트 경로는 그 실패를 삼켜 **사유 없이
 * 기능이 죽는다** — 20차 실측: 진입점 11곳 중 4곳만 가드가 있었다. 18차 #A3 의
 * 「형제 5곳 전부 보유」 서술은 사실과 달랐다(§16.26.2 기록 정정).
 *
 * 모집단은 `public/install/api/*.php` 디렉토리 스캔으로 도출한다 — 수기 목록은 그 자체가
 * 사각이다(§16.25.10 규약 ②). 새 엔드포인트가 추가되면 이 게이트가 자동으로 요구한다.
 *
 * 같은 축의 open_basedir 절반(#8·#9): `detect-php` 는 문서루트 **밖** 후보 8경로를,
 * `check-core-pending-path` 는 운영자 임의 절대경로를 stat 한다. open_basedir 환경에서
 * 그 stat 은 warning 을 내므로(반환은 false — 판정은 그대로 옳다) 진단 억제가 필요하다.
 * `file_exists`/`is_dir` 는 차단 함수 축(hosting-blocked-function)이 아니라 **경고 채널**
 * 축이다 — `@` 가 정당한 도구인 지점이다 (PHP 8 에서도 이 함수들은 제거되지 않는다).
 */
class InstallerJsonEndpointErrorChannelTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 인스톨러 API 진입점 디렉토리의 절대 경로를 돌려준다.
     *
     * @return string API 디렉토리 절대 경로
     */
    private function apiDir(): string
    {
        return dirname(__DIR__, 3).'/public/install/api';
    }

    /**
     * 주석을 걷어낸 소스 — 주석 처리된 배선이 「살아 있는 배선」으로 오인되면 게이트가
     * 주석 한 줄로 꺼진다 (19차 #4 가 실측한 위장 형태). 토크나이저가 지우므로 코드 뒤
     * 꼬리 주석도 걷히고 문자열 안 `//`(URL)는 보존된다 — 종전의 정규식(줄 시작 `//` 만)은
     * 꼬리 주석 속 배선을 남겼다.
     *
     * @param  string  $file  API 디렉토리 안의 파일 이름
     * @return string 주석을 걷어낸 소스
     */
    private function strippedSource(string $file): string
    {
        return $this->sourceWithoutComments($this->apiDir().'/'.$file);
    }

    /**
     * 진입점 모집단 — 디렉토리 스캔 + 구조 판정으로 도출한다 (이름 목록 금지).
     *
     * 「진입점」의 기준은 **자기 손으로 HTTP 응답을 방출하는가**다 — `header()` 를 세우거나
     * 본문을 내보내는 파일만 출력 채널 오염의 피해자가 될 수 있다. 순수 함수 라이브러리
     * (`_guard.php`·`rollback-functions.php` — 최상위 실행·출력 0, 진입점이 require)는
     * 여기서 자연 제외된다. 이름으로 제외하면 다음 라이브러리 파일이 추가될 때 거짓 red 가
     * 나고, 그 red 를 이름 추가로 끄는 순간 수기 목록 사각(§16.25.10 규약 ②)이 재개방된다.
     *
     * @return array<int, string> 파일명 목록
     */
    private function endpointFiles(): array
    {
        $files = [];

        foreach ((array) scandir($this->apiDir()) as $entry) {
            if (! is_string($entry) || substr($entry, -4) !== '.php') {
                continue;
            }

            // `_` 접두는 이 저장소의 공용 include 관례다 (`_guard.php`) — 진입점이 아니라
            // 진입점 **안에서** 실행되므로, 채널 가드는 그것을 require 한 진입점이 소유한다.
            if ($entry[0] === '_') {
                continue;
            }

            $source = $this->sourceWithoutComments($this->apiDir().'/'.$entry);

            // 응답 방출 지점이 없는 파일은 라이브러리다. 문자열 리터럴 안의 "echo ..." 오탐을
            // 피하려고 행 시작 컨텍스트가 아닌 호출 형태(`header(` / `echo json_encode`)로 본다.
            $emitsResponse = str_contains($source, 'header(')
                || str_contains($source, 'echo json_encode');

            if (! $emitsResponse) {
                continue;
            }

            $files[] = $entry;
        }

        sort($files);
        $this->assertNotEmpty($files, '모집단 도출 실패 — api 디렉토리가 비어 있을 수 없다');
        // 도출 자기검증 (스캔·판정이 잘못되면 여기서 죽는다): 대표 진입점은 포함되고,
        // 함수 라이브러리는 제외되어야 한다.
        $this->assertContains('run-task.php', $files);
        $this->assertNotContains('rollback-functions.php', $files, 'rollback-functions.php 는 응답을 방출하지 않는 라이브러리다');
        $this->assertNotContains('_guard.php', $files);

        return $files;
    }

    /**
     * `apache_setenv` 의 두 번째 인자는 문자열이어야 한다 (G3 P0 실측 회귀).
     *
     * PHP 8 의 mod_php 에서 `apache_setenv('no-gzip', 1)` 은 TypeError 이고 `@` 는 Error 를
     * 삼키지 않는다 — `function_exists` 가드는 통과하는데(함수는 존재한다) 호출이 치명
     * 종료한다. 이 형태는 **mod_php 실환경에서만** 드러난다: CLI·PHPUnit 에는 apache_setenv
     * 자체가 없어 가드가 호출을 건너뛰므로 어떤 단위 게이트도 울리지 않았고, G3 의 P0
     * 리허설에서 finalize-env 가 500 으로 죽으며 실측됐다 (`.env` 머지 통째 소실).
     * 모집단은 installer 트리 전체 — 운영자 안내 문구(lang)의 예시 코드도 같은 계약이다.
     *
     * @scenario error_channel_surface=typed_call_argument, script_home=installer
     *
     * @effects installer_apache_setenv_second_argument_is_a_string
     *
     * @return void 반환값 없음
     */
    public function test_apache_setenv_second_argument_is_always_a_string(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 3).'/public/install';
        $pattern = '/apache_setenv\(\s*\\\\?[\'"][^\'"]+\\\\?[\'"]\s*,\s*(?!\s*\\\\?[\'"])/';

        // 판정식 자기검증 — 이 검사가 재는 것은 「위반이 없다」이므로, 판정식이 깨지면
        // 그 사실이 초록과 구분되지 않는다. 양성·음성 대조를 먼저 세운다
        // (7차 G3 라운드 E12).
        $this->assertSame(1, preg_match($pattern, "apache_setenv('no-gzip', 1);"));
        $this->assertSame(1, preg_match($pattern, 'apache_setenv(\'no-gzip\', $flag);'));
        $this->assertSame(0, preg_match($pattern, "apache_setenv('no-gzip', '1');"));

        $scanned = 0;
        $matchedTargets = 0;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (! $item->isFile() || ! in_array($item->getExtension(), ['php', 'js'], true)) {
                continue;
            }

            $scanned++;
            $source = $this->sourceWithoutComments($item->getPathname());
            if (str_contains($source, 'apache_setenv')) {
                $matchedTargets++;
            }

            // 문자열이 아닌 두 번째 인자(따옴표로 시작하지 않는 리터럴/변수) 검출.
            // lang 사전의 이스케이프 예시(\'no-gzip\')까지 같은 패턴으로 걸린다.
            if (preg_match($pattern, $source)) {
                $offenders[] = substr($item->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertSame([], $offenders, "apache_setenv 두 번째 인자가 문자열이 아닌 파일 (mod_php PHP 8 에서 TypeError → 500):\n".implode("\n", $offenders));

        // 모집단 하한 — `$root` 나 확장자 필터가 어긋나 스캔이 0건이 되면 위 비교는
        // 공허하다. 형제 도출(`endpointFiles()`)은 이미 하한을 갖는데 이 축만 없었다.
        $this->assertGreaterThan(30, $scanned, '모집단 도출 실패 — installer 트리를 훑지 못했다');
        $this->assertGreaterThan(0, $matchedTargets, '`apache_setenv` 를 담은 파일이 하나도 없다 — 이 계약의 대상이 사라졌다면 검사도 함께 옮겨야 한다');
    }

    /**
     * #13: 모든 API 진입점이 display_errors 를 가드된 형태로 끄고 error_reporting 을 유지한다.
     *
     * @scenario error_channel_surface=display_errors_guard
     *
     * @effects installer_api_error_output_never_corrupts_response_body
     *
     * @return void 반환값 없음
     */
    public function test_every_api_endpoint_disables_display_errors_with_guard(): void
    {
        $offenders = [];

        foreach ($this->endpointFiles() as $file) {
            $source = $this->strippedSource($file);

            $hasGuardedDisable = preg_match(
                '/installerHasFunction\(\s*\'ini_set\'\s*\)[^}]*@ini_set\(\s*\'display_errors\'\s*,\s*\'0\'\s*\)/s',
                $source
            ) === 1;
            $keepsReporting = str_contains($source, 'error_reporting(E_ALL)');

            if (! $hasGuardedDisable || ! $keepsReporting) {
                $offenders[] = $file.($hasGuardedDisable ? '' : ' [display_errors 가드 부재]').($keepsReporting ? '' : ' [error_reporting 부재]');
            }
        }

        $this->assertSame([], $offenders, "display_errors 출력 채널 가드가 빠진 진입점:\n".implode("\n", $offenders));
    }

    /**
     * 21차 N4·N5: 채널 가드는 **include 체인보다 앞**이어야 한다 — config/session/functions/
     * _guard 안의 warning(`session_start` 실패·`$_SERVER` 무가드 읽기·가드의 410 방출 경로)이
     * 배선보다 먼저 실행되면 그 텍스트가 본문 앞에 붙는 창이 그대로 남는다. 20차는 존재만
     * 파생 모집단으로 강제했고 순서는 수기 4건짜리 형제 게이트에 남아 있었다 — 여기로
     * 흡수한다(같은 파생 모집단).
     *
     * 선행 로드가 허용되는 것은 실행 모드 헬퍼 파일 하나뿐이다 — 최상위 실행문이 없고
     * 프로젝트 루트를 자체 역산하며, 가드 함수 `installerHasFunction` 의 출처다.
     *
     * @scenario error_channel_surface=include_order
     *
     * @effects installer_api_error_output_never_corrupts_response_body
     *
     * @return void 반환값 없음
     */
    public function test_error_channel_guard_precedes_include_chain(): void
    {
        $offenders = [];

        foreach ($this->endpointFiles() as $file) {
            $source = $this->strippedSource($file);

            $guardPos = strpos($source, "@ini_set('display_errors', '0')");
            if ($guardPos === false) {
                $offenders[] = $file.' [가드 부재]';

                continue;
            }

            // 비교 대상 include 는 **소스에서 도출**한다 (22차 N-4) — 수기 목록(20차의 5건)은
            // check-configuration 이 가드보다 앞에서 로드하는 binary-path-policy 를 놓쳤다.
            // 예외는 선언 전용 파일 둘뿐이며, 그 성질 자체를 아래 별도 테스트가 잠근다.
            preg_match_all('#require(?:_once)?[^;\n]*?/((?:includes/)?[\w-]+\.php)#', $source, $requireMatches, PREG_OFFSET_CAPTURE);

            foreach ($requireMatches[1] as $match) {
                $required = (string) $match[0];
                $position = (int) $match[1];

                if (in_array($required, ['includes/execution-mode.php', 'includes/binary-path-policy.php'], true)) {
                    continue;
                }

                if ($position < $guardPos) {
                    $offenders[] = $file.' [배선이 '.$required.' 뒤]';
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, "채널 가드가 include 체인 뒤에 있는 진입점 (21차 N4 · 22차 N-4):\n".implode("\n", $offenders));
    }

    /**
     * 위 순서 검사의 예외 2파일은 **선언 전용**이어야 한다 — 최상위 실행문이 생기는 순간
     * 그 warning 이 가드보다 먼저 나갈 수 있으므로, 예외의 근거를 구조로 잠근다.
     * 최상위 줄은 선언·가드 블록 여닫이·use 문만 허용된다.
     *
     * @scenario error_channel_surface=include_order
     *
     * @effects installer_api_error_output_never_corrupts_response_body
     *
     * @return void 반환값 없음
     */
    public function test_ordering_exempt_files_are_declaration_only(): void
    {
        $offenders = [];

        foreach (['execution-mode.php', 'binary-path-policy.php'] as $file) {
            $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/'.$file);

            foreach (explode("\n", $source) as $lineNo => $line) {
                // 들여쓰기된 줄은 블록 내부다 — 최상위 실행문만 본다.
                if ($line === '' || $line[0] === ' ' || $line[0] === "\t" || trim($line) === '') {
                    continue;
                }
                if (preg_match('/^(?:<\?php|declare|use |if \(! function_exists|\}|\{)/', $line) === 1) {
                    continue;
                }
                $offenders[] = $file.':'.($lineNo + 1).' → '.trim($line);
            }
        }

        $this->assertSame([], $offenders, "선언 전용이어야 할 예외 파일에 최상위 실행문이 생겼다 — 순서 검사 예외에서 제외할 것:\n".implode("\n", $offenders));
    }

    /**
     * #8: detect-php 의 문서루트 밖 후보 stat 은 진단을 억제한다 (open_basedir warning → 본문 오염 차단).
     *
     * @scenario error_channel_surface=stat_diagnostics
     *
     * @effects installer_api_error_output_never_corrupts_response_body
     *
     * @return void 반환값 없음
     */
    public function test_detect_php_candidate_stat_suppresses_diagnostics(): void
    {
        $source = $this->strippedSource('check-configuration.php');

        $this->assertMatchesRegularExpression(
            '/if \(! @file_exists\(\$path\)\)/',
            $source,
            'detect-php 공통 경로 스캔의 file_exists 는 @ 로 진단을 억제해야 한다 (#8)'
        );
    }

    /**
     * #9: check-core-pending-path 의 후속 stat(is_dir·is_writable)도 진단을 억제한다.
     *
     * @scenario error_channel_surface=stat_diagnostics
     *
     * @effects installer_api_error_output_never_corrupts_response_body
     *
     * @return void 반환값 없음
     */
    public function test_pending_path_probe_stat_suppresses_diagnostics(): void
    {
        $source = $this->strippedSource('check-configuration.php');

        $this->assertStringContainsString('! @is_dir($resolved)', $source, 'is_dir 진단 억제 부재 (#9)');
        $this->assertStringContainsString('@is_writable($resolved)', $source, 'is_writable 진단 억제 부재 (#9)');
    }
}
