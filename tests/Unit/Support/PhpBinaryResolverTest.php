<?php

namespace Tests\Unit\Support;

use App\Support\PhpBinaryResolver;
use Tests\TestCase;

/**
 * PHP 바이너리 해석기 — 설정값 해석 · 형태 규칙 검증 · 자동 탐색 정렬.
 *
 * `ExecutionEnvironment::detect()` 의 `php_binary_probe` 축 값(valid / invalid / uncertain)을
 * 실제로 만드는 곳이다. 명시 지정 바이너리가 비-CLI(FPM/CGI)일 때 CLI 후보를 앞세우는 정렬은
 * 프로덕션에 비-CLI 후보가 없어 실측이 vacuous 하므로, 순수 함수(`sortCliFirst`)에 목록을
 * 직접 주입해 고정한다.
 *
 * 커버 축: php_binary_probe valid·invalid_with_autodetect·invalid_no_autodetect·uncertain
 */
class PhpBinaryResolverTest extends TestCase
{
    /**
     * resolve()는 config('process.php_binary') 값을 반환한다.
     */
    public function test_resolve_returns_config_value(): void
    {
        config(['process.php_binary' => '/usr/local/php84/bin/php']);

        $this->assertEquals('/usr/local/php84/bin/php', PhpBinaryResolver::resolve());
    }

    /**
     * resolve()는 config 값이 빈 문자열이면 'php'를 반환한다.
     */
    public function test_resolve_returns_php_when_config_is_empty(): void
    {
        config(['process.php_binary' => '']);

        $this->assertEquals('php', PhpBinaryResolver::resolve());
    }

    /**
     * resolve()는 config 값이 null이면 'php'를 반환한다.
     */
    public function test_resolve_returns_php_when_config_is_null(): void
    {
        config(['process.php_binary' => null]);

        $this->assertEquals('php', PhpBinaryResolver::resolve());
    }

    /**
     * resolveComposer()는 config('process.composer_binary') 값을 반환한다.
     */
    public function test_resolve_composer_returns_config_value(): void
    {
        config(['process.composer_binary' => '/usr/local/bin/composer']);

        $this->assertEquals('/usr/local/bin/composer', PhpBinaryResolver::resolveComposer());
    }

    /**
     * resolveComposer()는 config 값이 빈 문자열이면 빈 문자열을 반환한다.
     */
    public function test_resolve_composer_returns_empty_when_config_is_empty(): void
    {
        config(['process.composer_binary' => '']);

        $this->assertEquals('', PhpBinaryResolver::resolveComposer());
    }

    /**
     * validate()는 빈 경로에 대해 invalid를 반환한다.
     *
     * @effects validate_rejects_empty_or_nonexistent_binary_path
     */
    public function test_validate_returns_invalid_for_empty_path(): void
    {
        $result = PhpBinaryResolver::validate('');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['version']);
    }

    /**
     * 공백만 있는 경로는 "비어있다" 로 판정한다 — 빈 경로와 같은 사유여야 한다.
     *
     * `empty()` 로 판정하면 이 값이 가드를 통과해 `file_exists('   ')` 로 내려가고,
     * 운영자에게는 "파일이 존재하지 않습니다:    " 라는 엉뚱한 사유가 기록된다.
     * 빈 문자열 하나만 검사하면 두 판정 방식이 구분되지 않아 회귀가 즉시 재발한다.
     */
    public function test_validate_treats_whitespace_only_path_as_empty(): void
    {
        $result = PhpBinaryResolver::validate('   ');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['version']);
        $this->assertStringNotContainsString(
            '파일이 존재하지 않습니다',
            $result['message'],
            '공백 경로는 존재 확인까지 내려가지 않는다 — 사유가 "비어있다" 여야 한다'
        );
    }

    /**
     * 경로 `'0'` 은 빈 값이 아니다 — `empty('0')` 이 참이라 그 판정으로는 조용히 거부된다.
     *
     * 실제로 존재하지 않는 파일이므로 결과는 invalid 지만, 그 **사유**가 달라야 한다.
     * 사유가 "비어있다" 로 나오면 운영자는 자기가 입력한 값이 무시됐다고 읽는다.
     */
    public function test_validate_does_not_treat_zero_path_as_empty(): void
    {
        $result = PhpBinaryResolver::validate('0');

        $this->assertFalse($result['valid'], "'0' 은 실재하지 않는 경로라 invalid 다");
        $this->assertStringNotContainsString(
            '비어있습니다',
            $result['message'],
            "'0' 은 입력된 값이다 — 빈 경로로 오분류하면 사유가 사실과 달라진다"
        );
    }

    /**
     * validate()는 존재하지 않는 파일 경로에 대해 invalid를 반환한다.
     *
     * @effects validate_rejects_empty_or_nonexistent_binary_path
     */
    public function test_validate_returns_invalid_for_nonexistent_path(): void
    {
        $result = PhpBinaryResolver::validate('/nonexistent/path/to/php');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['version']);
        $this->assertStringContainsString('존재하지 않습니다', $result['message']);
    }

    /**
     * validate()는 현재 시스템 PHP를 유효하게 판정한다.
     */
    public function test_validate_accepts_current_php_binary(): void
    {
        $result = PhpBinaryResolver::validate(PHP_BINARY);

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $result['version']);
    }

    /**
     * validate()는 'php' (시스템 PATH)를 유효하게 판정한다.
     */
    public function test_validate_accepts_system_php(): void
    {
        $result = PhpBinaryResolver::validate('php');

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['version']);
    }

    /**
     * buildComposerCommand()는 composerBinary가 빈 문자열이면 'composer'를 반환한다.
     */
    public function test_build_composer_command_returns_composer_when_no_binary(): void
    {
        $result = PhpBinaryResolver::buildComposerCommand('php', '');

        $this->assertEquals('composer', $result);
    }

    /**
     * buildComposerCommand()는 .phar 파일이면 PHP 바이너리로 실행한다.
     */
    public function test_build_composer_command_uses_php_for_phar(): void
    {
        $result = PhpBinaryResolver::buildComposerCommand('/usr/local/php84/bin/php', '/usr/local/bin/composer.phar');

        $this->assertStringContainsString('/usr/local/php84/bin/php', $result);
        $this->assertStringContainsString('composer.phar', $result);
    }

    /**
     * buildComposerCommand()는 일반 바이너리는 직접 실행한다.
     */
    public function test_build_composer_command_uses_binary_directly(): void
    {
        $result = PhpBinaryResolver::buildComposerCommand('php', '/usr/local/bin/composer');

        $this->assertStringContainsString('composer', $result);
        // PHP 바이너리는 포함되지 않아야 함 (.phar가 아니므로)
        $this->assertStringNotContainsString('php', $result);
    }

    /**
     * autoDetect()는 최소 하나의 PHP 바이너리를 감지한다.
     */
    public function test_auto_detect_finds_at_least_one_php(): void
    {
        $found = PhpBinaryResolver::autoDetect();

        $this->assertNotEmpty($found);
        $this->assertArrayHasKey('path', $found[0]);
        $this->assertArrayHasKey('version', $found[0]);
    }

    /**
     * autoDetect() 결과의 모든 항목은 유효한 버전 형식을 가진다.
     */
    public function test_auto_detect_results_have_valid_version_format(): void
    {
        $found = PhpBinaryResolver::autoDetect();

        foreach ($found as $entry) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $entry['version']);
        }
    }

    /**
     * validate() 는 SAPI 를 함께 돌려준다.
     *
     * FPM/CGI 바이너리도 --version 은 정상 종료하며 같은 형태로 버전을 찍으므로,
     * 괄호 안 SAPI 이름이 CLI 여부를 가리는 유일한 단서다.
     *
     * @effects validate_reports_sapi_for_configured_binary
     */
    public function test_validate_reports_sapi(): void
    {
        $result = PhpBinaryResolver::validate(PHP_BINARY);

        $this->assertArrayHasKey('sapi', $result);
        $this->assertSame('cli', $result['sapi'], '테스트 러너는 CLI SAPI 로 돈다');
    }

    /**
     * autoDetect() 는 CLI SAPI 바이너리를 앞에 둔다.
     *
     * 첫 후보인 PHP_BINARY 는 웹 서버가 실행 중인 바이너리라 FPM/CGI 인 경우가 흔하다.
     * 그 바이너리는 --version 검증을 통과하면서도 artisan 스크립트를 인자로 받지 못해,
     * 그대로 실행 바이너리로 승격되면 판정(subprocess 가능)과 실행(전 태스크 실패)이 어긋난다.
     *
     * @effects auto_detect_orders_cli_binaries_first
     */
    public function test_auto_detect_orders_cli_binaries_first(): void
    {
        $found = PhpBinaryResolver::autoDetect();

        $this->assertNotEmpty($found);
        $this->assertArrayHasKey('sapi', $found[0]);

        $seenNonCli = false;
        foreach ($found as $entry) {
            if (($entry['sapi'] ?? null) === 'cli') {
                $this->assertFalse(
                    $seenNonCli,
                    'CLI 바이너리가 비-CLI 뒤에 오면 안 된다: '.$entry['path']
                );

                continue;
            }
            $seenNonCli = true;
        }
    }

    /**
     * 정렬 규칙 자체를 고정한다 — 비-CLI 가 섞인 목록으로.
     *
     * 위 테스트는 실제 탐색 결과를 쓰는데 이 저장소의 개발 환경에서는 CLI 후보만 나온다.
     * 그러면 `$seenNonCli` 가 참이 되는 원소가 없어 단언이 한 번도 평가되지 않고,
     * 정렬을 지우거나 뒤집어도 통과한다(4차 라운드가 vacuous 로 판정한 지점).
     * 순수 함수로 분리한 정렬에 목록을 직접 주입해 그 사각을 닫는다.
     */
    public function test_sort_cli_first_moves_cli_ahead_and_preserves_order_within_groups(): void
    {
        $sorted = PhpBinaryResolver::sortCliFirst([
            ['path' => '/usr/sbin/php-fpm', 'version' => '8.3.0', 'sapi' => 'fpm'],
            ['path' => '/usr/bin/php', 'version' => '8.3.0', 'sapi' => 'cli'],
            ['path' => '/usr/bin/php-cgi', 'version' => '8.3.0', 'sapi' => 'cgi'],
            ['path' => '/opt/php83/bin/php', 'version' => '8.3.1', 'sapi' => 'cli'],
        ]);

        $this->assertSame(
            ['/usr/bin/php', '/opt/php83/bin/php', '/usr/sbin/php-fpm', '/usr/bin/php-cgi'],
            array_column($sorted, 'path'),
            'CLI 가 앞, 각 그룹 안에서는 탐색 순서가 보존되어야 한다'
        );
    }

    /**
     * SAPI 를 모르는 후보(구형 결과)는 비-CLI 로 취급하되 목록에서 사라지지 않는다.
     */
    public function test_sort_cli_first_keeps_unknown_sapi_candidates(): void
    {
        $sorted = PhpBinaryResolver::sortCliFirst([
            ['path' => '/unknown/php', 'version' => '8.2.0', 'sapi' => null],
            ['path' => '/usr/bin/php', 'version' => '8.3.0', 'sapi' => 'cli'],
        ]);

        $this->assertSame(['/usr/bin/php', '/unknown/php'], array_column($sorted, 'path'));
        $this->assertCount(2, $sorted, '후보를 제거하지 않는다');
    }
}
