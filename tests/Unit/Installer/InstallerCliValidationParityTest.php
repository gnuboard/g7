<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * 웹 Step 3(`handleStep3Post`)과 터미널 설치가 같은 입력에 같은 항목을 거부한다.
 *
 * 두 경로는 같은 공유 함수(`installer_validate_config`)를 부르지만, 각자 입력을 모으는 방식(폼 POST ↔ 옵션·
 * 환경변수)이 달라 한쪽만 값이 빠지거나 기본값이 달라지면 판정이 갈린다. 같은 값 묶음을 양쪽 채널로 넣어
 * 오류 항목 집합이 같은지 본다. 웹은 CSRF 를 일부러 틀리게 줘 저장·이동을 막고, 터미널은 DB 연결 단계를
 * 주입해 검증 다음에서 멈춘다.
 */
class InstallerCliValidationParityTest extends TestCase
{
    use RunsInstallerCli;

    /** 정상 값 묶음 */
    private const VALID = [
        'db_write_host' => '127.0.0.1', 'db_write_port' => '3306', 'db_write_database' => 'g7_parity', 'db_write_username' => 'g7_parity',
        'db_write_password' => 'Db-Passw0rd!', 'db_prefix' => 'g7_',
        'app_name' => 'G7 Parity', 'app_url' => 'https://parity.test',
        'admin_name' => '관리자', 'admin_email' => 'admin@parity.test', 'admin_password' => 'Admin-Passw0rd!x', 'admin_language' => 'ko',
        'site_language' => 'ko', 'php_binary' => 'php', 'composer_binary' => '', 'vendor_mode' => 'auto',
        'core_update_pending_path' => '', 'core_update_github_url' => 'https://github.com/gnuboard/g7',
    ];

    /** 값 → 터미널 옵션 (비밀값은 환경변수) */
    private const CLI_OPTION = [
        'db_write_host' => '--db-host', 'db_write_port' => '--db-port', 'db_write_database' => '--db-database', 'db_write_username' => '--db-username',
        'db_prefix' => '--db-prefix', 'app_name' => '--app-name', 'app_url' => '--app-url', 'admin_name' => '--admin-name',
        'admin_email' => '--admin-email', 'admin_language' => '--admin-language', 'site_language' => '--site-language',
        'php_binary' => '--php-binary', 'composer_binary' => '--composer-binary', 'vendor_mode' => '--vendor-mode',
        'core_update_pending_path' => '--core-pending-path', 'core_update_github_url' => '--github-url',
    ];

    /**
     * 임시 BASE_PATH 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeInstallerTempBase();
        $this->putBaseFile('.env', "APP_KEY=\n");
    }

    /**
     * 임시 BASE_PATH 를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();
        parent::tearDown();
    }

    /** 읽기 DB 를 켠 정상 값 (읽기 DB 사례가 덮어쓴다) */
    private const READ_DB = ['use_read_db' => '1', 'db_read_host' => '127.0.0.1', 'db_read_port' => '3306', 'db_read_database' => 'g7_parity', 'db_read_username' => 'g7_reader'];

    /** 읽기 DB 값 → 터미널 옵션 */
    private const CLI_READ_OPTION = [
        'db_read_host' => '--db-read-host', 'db_read_port' => '--db-read-port', 'db_read_database' => '--db-read-database', 'db_read_username' => '--db-read-username',
    ];

    /**
     * 터미널 비대화형 입력 경로가 없어 비교하지 않는 검증 항목 — 사유.
     *
     * 관리자 비밀번호 확인은 웹 폼의 두 번째 칸이다. 터미널은 대화형에서만 두 번 묻고(일치 확인은 prompt 단계),
     * 비대화형은 환경변수·파일 하나로 받으므로 같은 값 묶음을 만들 수 없다.
     */
    private const NOT_COMPARABLE = ['admin_password_confirm'];

    /**
     * 정상·경계·위반 값 묶음 — [바꿀 값, 정상이어야 하는가].
     *
     * 위반 사례는 서버 검증(installer_validate_config)이 오류를 다는 항목 전부를 덮는다 —
     * test_cases_cover_every_validated_field 가 그 모집단을 소스에서 뽑아 확인한다.
     *
     * @return array<string, array{0: array<string, string>, 1: bool}> 사례
     */
    public static function cases(): array
    {
        $read = self::READ_DB;

        return [
            'all valid' => [[], true],
            'empty app name' => [['app_name' => ''], false],
            'bad app url' => [['app_url' => 'not-a-url'], false],
            'underscore host app url (boundary)' => [['app_url' => 'https://g7_cli.dev'], true],
            'bad admin email' => [['admin_email' => 'nope'], false],
            'empty admin name' => [['admin_name' => ''], false],
            'short admin password' => [['admin_password' => 'abc'], false],
            'admin password 7 bytes (boundary)' => [['admin_password' => 'abcdefg'], false],
            'admin password 8 bytes (boundary)' => [['admin_password' => 'abcdefgh'], true],
            'multibyte admin password 8 bytes (boundary)' => [['admin_password' => '가나a1'], true],
            'bad prefix' => [['db_prefix' => 'bad-prefix!'], false],
            'prefix 6 chars (boundary)' => [['db_prefix' => 'g7abc_'], true],
            'prefix 7 chars (boundary)' => [['db_prefix' => 'g7abcd_'], false],
            'too long prefix' => [['db_prefix' => str_repeat('p', 40).'_'], false],
            'empty db host' => [['db_write_host' => ''], false],
            'empty database' => [['db_write_database' => ''], false],
            'empty db username' => [['db_write_username' => ''], false],
            'privileged db account' => [['db_write_username' => 'root'], false],
            'privileged db account case and space (boundary)' => [['db_write_username' => ' ROOT '], false],
            'read db valid' => [$read, true],
            'read db empty host' => [['db_read_host' => ''] + $read, false],
            'read db empty database' => [['db_read_database' => ''] + $read, false],
            'read db privileged account' => [['db_read_username' => 'sa'] + $read, false],
            'php path not allowed' => [['php_binary' => 'evil;php'], false],
            'composer path not allowed' => [['composer_binary' => 'evil.sh'], false],
            'core pending path is a file' => [['core_update_pending_path' => '.env'], false],
            'bad github url' => [['core_update_github_url' => 'ftp://example.com/g7'], false],
            'bad vendor mode' => [['vendor_mode' => 'weird'], false],
            'bad admin language' => [['admin_language' => 'xx'], false],
            'several at once' => [['app_name' => '', 'admin_email' => 'x', 'db_prefix' => '!!'], false],
        ];
    }

    /**
     * 위반 사례가 서버 검증이 다루는 항목 전부를 덮는다 — 모집단은 config-validation.php 소스에서 뽑는다.
     *
     * 오류 키 직접 대입($errors['키']), DB 계정 검사의 항목 인자, 한 줄·URL 검사 목록을 합친다.
     * 새 검증 항목이 생겼는데 사례가 없으면 red 가 된다.
     *
     * @effects installer_cli_parity_cases_cover_validated_fields
     *
     * @return void 반환값 없음
     */
    public function test_cases_cover_every_validated_field(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/config-validation.php');
        preg_match_all('/\$errors\[\'([a-z_]+)\'\]\s*=/', $src, $direct);
        preg_match_all('/installer_validate_db_username\([^;]*?,\s*\'([a-z_]+)\',/', $src, $accounts);
        preg_match_all('/foreach \(\[([^\]]+)\] as \$(?:envField|urlField)\)/', $src, $lists);
        $listed = [];
        foreach ($lists[1] as $list) {
            preg_match_all('/\'([a-z_]+)\'/', $list, $m);
            $listed = array_merge($listed, $m[1]);
        }
        $population = array_values(array_unique(array_merge($direct[1], $accounts[1], $listed)));
        sort($population);
        $this->assertGreaterThanOrEqual(15, count($population), '검증 항목 추출이 비었다 — 정규식이 소스와 어긋남');

        // 위반 사례가 정상 값에서 실제로 바꾼 항목만 덮은 것으로 센다 (읽기 DB 사례의 나머지 정상 값은 제외).
        $baseline = self::VALID + self::READ_DB;
        $covered = [];
        foreach (self::cases() as [$override, $valid]) {
            if ($valid) {
                continue;
            }
            foreach ($override as $field => $value) {
                if ($value !== ($baseline[$field] ?? null)) {
                    $covered[] = $field;
                }
            }
        }

        $missing = array_values(array_diff($population, $covered, self::NOT_COMPARABLE));
        $this->assertSame([], $missing, '위반 사례가 없는 검증 항목');
    }

    /**
     * PHP·Composer 자동 감지 — 웹 Step 3 와 같다: 경로를 주지 않았고 기본 php 를 쓸 수 없으면 찾은 첫 PHP,
     * Composer 는 PATH 의 composer 가 아닌 찾은 경로(composer.phar)로 채운다. 기본 php 가 쓸 만하면 그대로 둔다.
     *
     * @effects installer_cli_detects_php_and_composer_like_web
     *
     * @return void 반환값 없음
     */
    public function test_cli_auto_selects_detected_php_and_composer_like_web(): void
    {
        $detect = static fn (bool $defaultOk): string => 'function installer_detect_php_binaries(): array { return '
            .var_export([
                'success' => true, 'default_php_available' => $defaultOk,
                'binaries' => [['path' => '/usr/local/php83/bin/php', 'version' => '8.3.9'], ['path' => '/usr/bin/php8.2', 'version' => '8.2.1']],
                'composer' => ['found' => true, 'path' => '/srv/g7/composer.phar', 'version' => '2.7.1'],
            ], true).'; }';

        $messages = function (bool $defaultOk, array $extraArgs = []) use ($detect): array {
            $args = ['--output=json', '--no-interaction', '--accept-license', '--locale=en'];
            foreach (array_diff_key(self::CLI_OPTION, ['php_binary' => 1, 'composer_binary' => 1]) as $field => $option) {
                $args[] = $option.'='.self::VALID[$field];
            }
            $run = $this->runCli(array_merge($args, $extraArgs), ['G7_INSTALL_DB_PASSWORD' => 'x', 'G7_INSTALL_ADMIN_PASSWORD' => self::VALID['admin_password']], <<<PHP
                function installer_collect_requirements(): array { return ['blocking' => false, 'all_required_passed' => true]; }
                function g7_cli_print_requirements(\$out, \$req, \$webUser): void {}
                function installer_test_database(array \$conn, string \$type, string \$lang): array { return ['success' => false, 'message' => 'DB_STUB_STOP']; }
                {$detect($defaultOk)}
                PHP);

            return array_column($this->eventsNamed($run['events'], 'info'), 'message');
        };

        $auto = implode("\n", $messages(false));
        $this->assertStringContainsString('using the detected PHP: /usr/local/php83/bin/php', $auto, '기본 php 불가인데 찾은 PHP 를 고르지 않았다');
        $this->assertStringContainsString('Using the detected Composer: /srv/g7/composer.phar', $auto);

        $defaultOk = implode("\n", $messages(true));
        $this->assertStringNotContainsString('using the detected PHP', $defaultOk, '기본 php 가 쓸 만하면 그대로 둔다(웹과 같음)');

        $given = implode("\n", $messages(false, ['--php-binary=/opt/php/bin/php', '--composer-binary=/opt/composer.phar']));
        $this->assertStringNotContainsString('using the detected PHP', $given, '직접 준 경로는 바꾸지 않는다');
        $this->assertStringNotContainsString('Using the detected Composer', $given);
    }

    /**
     * 같은 값 묶음 → 같은 오류 항목.
     *
     * @effects installer_cli_and_web_reject_same_fields
     *
     * @param  array<string, string>  $override  정상 값에서 바꿀 값
     * @param  bool  $valid  정상(양쪽 모두 오류 없음)이어야 하는 사례인지
     * @return void 반환값 없음
     */
    #[DataProvider('cases')]
    public function test_web_and_cli_reject_the_same_fields(array $override, bool $valid): void
    {
        $values = array_replace(self::VALID, $override);
        $web = $this->webErrorLabels($values);

        // 양쪽이 같이 비어 통과하는 헛 통과를 막는다 — 위반 사례는 웹이 반드시 무언가를 거부해야 한다.
        $valid ? $this->assertSame([], $web) : $this->assertNotEmpty($web, '위반 사례인데 웹이 거부하지 않았다');
        $this->assertSame($web, $this->cliErrorLabels($values));
    }

    /**
     * 웹 Step 3 의 오류 항목 (CSRF·DB 테스트 확인 제외) — 항목 표시 이름으로.
     *
     * @param  array<string, string>  $values  값 묶음
     * @return array<int, string> 정렬한 항목 이름
     */
    private function webErrorLabels(array $values): array
    {
        $post = $values + ['admin_password_confirm' => $values['admin_password']];

        $run = $this->runInstallerScript(
            'require_once $projectRoot."/public/install/includes/request-handler.php";'
            .'$formData = []; $errors = [];'
            .'handleStep3Post("ko", $formData, $errors);'
            .'unset($errors["csrf"], $errors["db_test"]);'
            .'$labels = array_map("installer_field_label", array_keys($errors)); sort($labels);'
            .'echo "__RESULT__=".json_encode($labels, JSON_UNESCAPED_UNICODE);',
            ['post' => $post, 'session' => ['csrf_token' => 'expected'], 'headers' => ['HTTP_X_CSRF_TOKEN' => 'wrong']]
        );

        $this->assertMatchesRegularExpression('/__RESULT__=/', $run['raw'], $run['raw']);
        preg_match('/__RESULT__=(.*)$/m', $run['raw'], $m);

        return json_decode($m[1], true);
    }

    /**
     * 터미널 설치의 오류 항목 — 검증 오류 줄(「항목: 사유」)의 항목 이름. DB 연결 단계는 주입한 실패로 멈춘다.
     *
     * @param  array<string, string>  $values  값 묶음
     * @return array<int, string> 정렬한 항목 이름
     */
    private function cliErrorLabels(array $values): array
    {
        $args = ['--output=json', '--no-interaction', '--accept-license'];
        foreach (self::CLI_OPTION as $field => $option) {
            $args[] = $option.'='.$values[$field];
        }
        if (($values['use_read_db'] ?? '') === '1') {
            $args[] = '--use-read-db';
            foreach (self::CLI_READ_OPTION as $field => $option) {
                $args[] = $option.'='.$values[$field];
            }
        }
        $env = ['G7_INSTALL_DB_PASSWORD' => $values['db_write_password'], 'G7_INSTALL_ADMIN_PASSWORD' => $values['admin_password']];

        $run = $this->runCli($args, $env, <<<'PHP'
            function installer_collect_requirements(): array { return ['blocking' => false, 'all_required_passed' => true]; }
            function g7_cli_print_requirements($out, $req, $webUser): void {}
            function installer_test_database(array $conn, string $type, string $lang): array { return ['success' => false, 'message' => 'DB_STUB_STOP']; }
            PHP);

        $this->assertSame(2, $run['exit'], $run['raw']);

        $labels = [];
        foreach ($this->eventsNamed($run['events'], 'error') as $error) {
            if ($error['message'] !== 'DB_STUB_STOP') {
                $labels[] = explode(': ', (string) $error['message'], 2)[0];
            }
        }
        sort($labels);

        return $labels;
    }
}
