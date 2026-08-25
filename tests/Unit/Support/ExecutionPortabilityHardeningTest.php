<?php

namespace Tests\Unit\Support;

use App\Execution\SubprocessCommandRunner;
use App\Support\ExecutionEnvironment;
use App\Support\PhpBinaryResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 코어 실행 계층의 호스팅 이식성 회귀 가드.
 *
 * 이 축의 결함은 전부 "개발 환경에서는 절대 재현되지 않는다" 는 공통 성질을 가진다 —
 * 패널 경로 부재·`open_basedir`·`disable_functions`·블로킹 read 는 로컬에서 늘 통과한다.
 * 그래서 판정을 **주입 가능한 자리**로 끌어내거나(disable_functions 목록·private 메서드),
 * 별도 프로세스를 실제로 띄워서 그 환경을 만들어 확인한다.
 *
 * @effects core_execution_layer_survives_restricted_hosting
 */
class ExecutionPortabilityHardeningTest extends TestCase
{
    // ========================================================================
    // 환경#5 — PHP 바이너리 후보 목록 (패널 경로 + Windows)
    // ========================================================================

    /**
     * 공유 호스팅 패널이 쓰는 경로가 후보에 들어 있다.
     *
     * 목록이 POSIX 배포판 관행뿐이면 cPanel/Plesk/CloudLinux/LiteSpeed 서버는
     * "PATH 에 php 가 없다" 만으로 in-process 로 떨어진다 — 실제로는 가능한 경로를 두고.
     */
    public function test_candidate_paths_cover_shared_hosting_panels(): void
    {
        $paths = PhpBinaryResolver::candidatePaths();

        foreach ([
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/opt/alt/php83/usr/bin/php',
            '/opt/plesk/php/8.3/bin/php',
            '/usr/local/lsws/lsphp83/bin/php',
        ] as $expected) {
            $this->assertContains($expected, $paths, "패널 후보 경로 누락: {$expected}");
        }
    }

    /**
     * Windows 후보는 `PHP_BINARY` 형제 디렉토리에서 파생된다.
     *
     * 고정 목록이 POSIX 뿐이면 Windows 에서는 후보가 0 이라 폴백이 아무 일도 하지 않는다.
     */
    public function test_candidate_paths_include_sibling_of_current_binary(): void
    {
        $dir = rtrim(str_replace('\\', '/', dirname(PHP_BINARY)), '/');

        $this->assertContains($dir.'/php.exe', PhpBinaryResolver::candidatePaths());
        $this->assertContains($dir.'/php', PhpBinaryResolver::candidatePaths());
    }

    /**
     * 후보 목록에 중복이 없다 — 같은 경로를 두 번 probe 하면 그만큼 스폰이 늘어난다.
     */
    public function test_candidate_paths_are_unique(): void
    {
        $paths = PhpBinaryResolver::candidatePaths();

        $this->assertSame(array_values(array_unique($paths)), $paths);
    }

    // ========================================================================
    // 환경#6 — stat 실패가 후보를 버리지 않는다
    // ========================================================================

    /**
     * `file_exists()` 가 false 인 경로도 실행 가능하면 valid 로 판정한다.
     *
     * `open_basedir` 환경을 테스트에서 만들 수는 없지만, 성질은 같다 — "stat 은 실패하는데
     * exec 은 성공하는 경로". PATH 로 해석되는 bare 이름이 그 성질을 그대로 만족한다
     * (`file_exists('php.exe')` 는 CWD 기준이라 false, exec 은 PATH 에서 찾아 성공).
     * stat 조기 실패가 남아 있으면 이 단언이 red 가 된다.
     */
    public function test_validate_does_not_reject_path_that_stat_cannot_see(): void
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $this->markTestSkipped('exec 차단 환경 — probe 자체가 불가');
        }

        $bare = basename(str_replace('\\', '/', PHP_BINARY));

        if ($bare === '' || @file_exists($bare)) {
            $this->markTestSkipped('CWD 에 같은 이름의 파일이 있어 성질이 성립하지 않음');
        }

        $probe = [];
        $code = -1;
        exec(escapeshellarg($bare).' --version 2>&1', $probe, $code);

        if ($code !== 0) {
            $this->markTestSkipped("PATH 에서 {$bare} 를 해석할 수 없는 환경 — 성질이 성립하지 않음");
        }

        $result = PhpBinaryResolver::validate($bare);

        $this->assertTrue(
            $result['valid'],
            'stat 이 못 보는 경로를 exec 결과 확인 전에 버렸다 (open_basedir 회귀 #361 와 같은 형태)'
        );
    }

    /**
     * 실재하지 않는 경로의 **사유**는 그대로 "파일 없음" 이다 (기존 계약 보존).
     */
    public function test_validate_still_reports_missing_file_reason(): void
    {
        $result = PhpBinaryResolver::validate('/nonexistent/path/to/php');

        $this->assertFalse($result['valid']);
        $this->assertSame('php_probe_file_missing', $result['message_key']);
    }

    // ========================================================================
    // 환경#14 — 사유는 번역 가능한 키로 나간다
    // ========================================================================

    /**
     * 모든 invalid 분기가 `message_key` 를 싣는다.
     *
     * 코어가 한국어 문장을 확정해 API 응답에 실으면 그 항목만 번역되지 않은 채 나간다
     * (같은 응답의 다른 사유들은 lang 키를 쓴다).
     */
    public function test_validate_carries_translatable_message_key_in_every_branch(): void
    {
        foreach ([
            '' => 'php_probe_empty_path',
            '   ' => 'php_probe_empty_path',
            '/nonexistent/path/to/php' => 'php_probe_file_missing',
        ] as $path => $expectedKey) {
            $result = PhpBinaryResolver::validate((string) $path);

            $this->assertArrayHasKey('message_key', $result);
            $this->assertSame($expectedKey, $result['message_key'], "경로 [{$path}] 의 사유 키");
            $this->assertArrayHasKey('message_params', $result);
        }
    }

    /**
     * probe 결과도 그 키를 그대로 실어 나른다 — 중간에서 잃어버리면 소비처가 번역할 수 없다.
     */
    public function test_probe_propagates_message_key(): void
    {
        $probe = ExecutionEnvironment::probePhpBinary(
            '/nonexistent/php',
            validator: fn (): array => PhpBinaryResolver::validate('/nonexistent/php'),
            hasFunction: fn (): bool => true,
        );

        $this->assertSame('invalid', $probe['status']);
        $this->assertSame('php_probe_file_missing', $probe['message_key']);
    }

    /**
     * exec 차단·함수 차단 분기도 키를 싣는다.
     */
    public function test_probe_carries_message_key_when_exec_unavailable(): void
    {
        $probe = ExecutionEnvironment::probePhpBinary('php', hasFunction: fn (): bool => false);

        $this->assertSame('unavailable', $probe['status']);
        $this->assertSame('php_probe_exec_unavailable', $probe['message_key']);
    }

    // ========================================================================
    // 환경#24 — disable_functions 대조는 대소문자를 가리지 않는다
    // ========================================================================

    public function test_has_function_matches_disabled_list_case_insensitively(): void
    {
        $this->assertFalse(
            ExecutionEnvironment::hasFunction('proc_open', 'Proc_Open, shell_exec'),
            'PHP 함수명은 대소문자 무관 — 원문 비교면 이 대조는 어떤 입력에도 도달하지 않는다'
        );
        $this->assertFalse(ExecutionEnvironment::hasFunction('PROC_OPEN', 'proc_open'));
        $this->assertFalse(ExecutionEnvironment::hasFunction('exec', ' EXEC '));
        $this->assertTrue(ExecutionEnvironment::hasFunction('exec', 'proc_open,shell_exec'));
    }

    // ========================================================================
    // 보안#11 — 공백 포함 Composer 입력이 셸로 원문 전달되지 않는다
    // ========================================================================

    public function test_build_composer_command_escapes_space_separated_tokens(): void
    {
        $raw = 'sh -c "id > /tmp/g7_should_not_run"';

        $result = PhpBinaryResolver::buildComposerCommand('php', $raw);

        $this->assertNotSame($raw, $result, '원문이 그대로 명령줄이 됨');
        // 두 토큰이 각각 인용되어야 한다 — 인용 규칙은 OS 마다 다르므로 escapeshellarg 로 대조한다.
        $this->assertSame(
            escapeshellarg('sh').' '.escapeshellarg('-c "id > /tmp/g7_should_not_run"'),
            $result
        );
    }

    public function test_build_composer_command_keeps_interpreter_plus_script_form(): void
    {
        $result = PhpBinaryResolver::buildComposerCommand('php', '/usr/local/php84/bin/php /home/u/composer.phar');

        $this->assertStringContainsString('/usr/local/php84/bin/php', $result);
        $this->assertStringContainsString('composer.phar', $result);
        // 두 토큰이 각각 인용되어야 한다 — 인용 부호 없이 붙으면 원문 전달과 구분되지 않는다.
        $this->assertSame(
            escapeshellarg('/usr/local/php84/bin/php').' '.escapeshellarg('/home/u/composer.phar'),
            $result
        );
    }

    // ========================================================================
    // 환경#13 — getenv 차단 환경 + 블로킹 read
    // ========================================================================

    /**
     * `disable_functions=getenv` 환경에서도 자식 env 조립이 죽지 않는다.
     *
     * `disable_functions` 는 런타임에 바꿀 수 없으므로 그 환경을 **실제로 띄워서** 확인한다.
     * PHP 8 은 차단 함수를 함수 테이블에서 제거하므로 무가드 호출은 `Error` 로 죽는다 —
     * `@` 로는 막히지 않는다(진단 억제일 뿐).
     */
    public function test_inherited_environment_survives_disabled_getenv(): void
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $this->markTestSkipped('exec 차단 환경');
        }

        $autoload = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

        // 코드를 `-r` 로 넘기지 않는다 — Windows 의 escapeshellarg 는 큰따옴표를 공백으로
        // 치환하므로 인라인 코드가 그 자리에서 깨진다. 파일로 넘기면 OS 차이가 사라진다.
        $script = $this->writeChildScript(
            'require '.var_export($autoload, true).';'
            .'$m = new ReflectionMethod('.var_export(SubprocessCommandRunner::class, true).', "inheritedEnvironment");'
            .'$m->setAccessible(true);'
            .'$env = $m->invoke(null);'
            .'echo is_array($env) && $env !== [] ? "OK" : "EMPTY";'
        );

        $output = [];
        $exit = -1;
        exec(
            escapeshellarg(PHP_BINARY).' -d disable_functions=getenv '.escapeshellarg($script).' 2>&1',
            $output,
            $exit
        );

        @unlink($script);
        $joined = implode(PHP_EOL, $output);

        $this->assertSame(0, $exit, 'getenv 차단 환경에서 죽었다: '.$joined);
        $this->assertStringContainsString('OK', $joined, '대체 경로가 빈 환경을 돌려줬다: '.$joined);
    }

    /**
     * 침묵하는 자식을 무기한 기다리지 않는다.
     *
     * Unix 에서 `max_execution_time` 은 시스템 콜 대기를 세지 않으므로, 블로킹 read 루프는
     * PHP 실행 제한으로도 끝나지 않는다. 그 사이 워커 lock 은 heartbeat 없이 stale 로 넘어가
     * 다른 요청이 같은 작업을 겹쳐 실행한다.
     */
    public function test_output_read_gives_up_on_a_silent_child(): void
    {
        if (! ExecutionEnvironment::hasFunction('proc_open')) {
            $this->markTestSkipped('proc_open 차단 환경');
        }

        $script = $this->writeChildScript('sleep(30);');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
        $process = proc_open($command, $descriptors, $pipes);

        $this->assertIsResource($process);
        fclose($pipes[0]);

        if (@stream_set_blocking($pipes[1], false) === false) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process);
            proc_close($process);
            @unlink($script);
            $this->markTestSkipped(
                '이 플랫폼은 proc_open 파이프의 비블로킹 전환을 지원하지 않는다(Windows) — '
                .'예산 강제가 성립하지 않는 대신 max_execution_time 이 벽시계로 요청을 끊는다'
            );
        }
        stream_set_blocking($pipes[1], true);

        $method = new ReflectionMethod(SubprocessCommandRunner::class, 'readUntilEof');
        $method->setAccessible(true);

        $started = microtime(true);
        $output = $method->invoke(null, $pipes[1], $process, 1.0);
        $elapsed = microtime(true) - $started;

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($script);

        $this->assertLessThan(10.0, $elapsed, '예산을 넘겨도 read 가 끝나지 않았다');
        $this->assertStringContainsString('timed out', $output, '중단 사실이 출력에 남지 않음');
    }

    /**
     * 정상 자식의 출력은 한 바이트도 잃지 않는다 (비블로킹 전환 회귀 가드).
     */
    public function test_output_read_collects_full_child_output(): void
    {
        if (! ExecutionEnvironment::hasFunction('proc_open')) {
            $this->markTestSkipped('proc_open 차단 환경');
        }

        $expected = str_repeat('g7-output-line', 2000);
        $script = $this->writeChildScript('echo str_repeat("g7-output-line", 2000);');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
        $process = proc_open($command, $descriptors, $pipes);

        $this->assertIsResource($process);
        fclose($pipes[0]);

        $method = new ReflectionMethod(SubprocessCommandRunner::class, 'readUntilEof');
        $method->setAccessible(true);
        $output = $method->invoke(null, $pipes[1], $process, null);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($script);

        $this->assertSame($expected, $output);
    }

    /**
     * 자식 스크립트를 임시 파일로 만든다.
     *
     * `php -r` 인라인 코드는 Windows 의 escapeshellarg 가 큰따옴표를 공백으로 치환해
     * 그 자리에서 깨진다 — 파일로 넘기면 OS 차이가 사라진다.
     *
     * @param  string  $body  `<?php` 뒤에 올 본문
     * @return string 임시 스크립트 경로
     */
    private function writeChildScript(string $body): string
    {
        $path = sys_get_temp_dir().'/g7-child-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, '<?php
'.$body.'
');

        return $path;
    }
}
