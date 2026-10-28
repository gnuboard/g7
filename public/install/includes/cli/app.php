<?php

/**
 * 그누보드7 터미널 설치 - 본체
 *
 * `php g7-install [옵션]` (vendor 가 있으면 `php artisan core:install` 이 같은 본체를 부른다).
 * 웹 설치 마법사의 단계 0~5 를 같은 순서·같은 규칙으로 진행한다. 검증·요구사항·DB 검사·확장 해석·설치 계획·
 * .env 확정은 웹 엔드포인트와 **같은 공유 함수**를 부르고, 설치 작업은 웹 워커와 같은 `runInstallationTasks()`
 * 를 터미널 송출기로 실행한다. 규칙을 고칠 때는 공유 함수에서 고친다 — 이 파일에 사본 규칙을 두지 않는다.
 *
 * 입력 우선순위: 명령줄 옵션 > 환경변수 G7_INSTALL_* > --config=<json> > 웹 기본값(DEFAULT_INSTALL_CONFIG).
 * 비밀값(DB·관리자 비밀번호·GitHub 토큰)은 명령줄 인자로 받지 않는다 — 환경변수·파일·숨김 입력만.
 *
 * 순수 PHP 다(vendor 없이 동작). Laravel 을 부르지 않는다.
 */
if (! defined('BASE_PATH')) {
    define('BASE_PATH', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}

// 종료 코드 — 자동화 도구가 원인별로 분기한다.
if (! defined('G7_CLI_EXIT_OK')) {
    define('G7_CLI_EXIT_OK', 0);            // 설치 완료(경고 포함) · --skip-if-installed 로 이미 설치됨
    define('G7_CLI_EXIT_FAILED', 1);        // 설치 작업 실패 · 재설치 초기화 실패
    define('G7_CLI_EXIT_INVALID', 2);       // 입력·검증 오류
    define('G7_CLI_EXIT_INSTALLED', 3);     // 이미 설치됨
    define('G7_CLI_EXIT_REQUIREMENTS', 4);  // 요구사항 미충족
    define('G7_CLI_EXIT_CONFLICT', 5);      // 웹 소유·워커 활동 중·다른 터미널 설치 실행 중·state 충돌
    define('G7_CLI_EXIT_ABORTED', 6);       // 사용자 중단(Ctrl+C·SIGTERM)
    define('G7_CLI_EXIT_ROOT_REFUSED', 7);  // root 실행 거부 (--web-user 없음)
}

require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/functions.php';
require_once dirname(__DIR__).'/installer-state.php';
require_once dirname(__DIR__).'/installer-runtime.php';
require_once dirname(__DIR__).'/installer-ownership.php';
require_once dirname(__DIR__).'/config-validation.php';
require_once dirname(__DIR__).'/requirements.php';
require_once dirname(__DIR__).'/db-check.php';
require_once dirname(__DIR__).'/extension-selection.php';
require_once dirname(__DIR__).'/php-extension-requirements.php';
require_once dirname(__DIR__).'/install-plan.php';
require_once dirname(__DIR__).'/env-finalize.php';
require_once dirname(__DIR__).'/progress-emitter.php';
require_once dirname(__DIR__).'/task-runner.php';
require_once dirname(__DIR__, 2).'/api/_guard.php';
require_once __DIR__.'/prompt.php';
require_once __DIR__.'/signals.php';
require_once __DIR__.'/emitters.php';
require_once __DIR__.'/ownership.php';
require_once __DIR__.'/web-user.php';
require_once __DIR__.'/reinstall.php';
require_once __DIR__.'/install-flow.php';

if (! function_exists('g7_cli_option_spec')) {
    /**
     * 옵션 명세 — 이름 → [종류(value|flag|secret_file|list), 환경변수, 설정 파일 키·config 키].
     *
     * config 키는 웹 Step 3 입력 이름과 같다(1:1).
     *
     * @return array<string, array{type: string, env: string|null, key: string|null}> 옵션 명세
     */
    function g7_cli_option_spec(): array
    {
        $v = static fn (string $env, ?string $key) => ['type' => 'value', 'env' => $env, 'key' => $key];
        $f = static fn (string $env, ?string $key) => ['type' => 'flag', 'env' => $env, 'key' => $key];
        $s = static fn (string $env, string $key) => ['type' => 'secret_file', 'env' => $env, 'key' => $key];
        $l = static fn (string $env, string $key) => ['type' => 'list', 'env' => $env, 'key' => $key];

        return [
            'locale' => $v('G7_INSTALL_LOCALE', 'locale'),
            'accept-license' => $f('G7_INSTALL_ACCEPT_LICENSE', 'accept_license'),
            'db-host' => $v('G7_INSTALL_DB_HOST', 'db_write_host'),
            'db-port' => $v('G7_INSTALL_DB_PORT', 'db_write_port'),
            'db-database' => $v('G7_INSTALL_DB_DATABASE', 'db_write_database'),
            'db-username' => $v('G7_INSTALL_DB_USERNAME', 'db_write_username'),
            'db-password-file' => $s('G7_INSTALL_DB_PASSWORD', 'db_write_password'),
            'db-prefix' => $v('G7_INSTALL_DB_PREFIX', 'db_prefix'),
            'use-read-db' => $f('G7_INSTALL_USE_READ_DB', 'use_read_db'),
            'db-read-host' => $v('G7_INSTALL_DB_READ_HOST', 'db_read_host'),
            'db-read-port' => $v('G7_INSTALL_DB_READ_PORT', 'db_read_port'),
            'db-read-database' => $v('G7_INSTALL_DB_READ_DATABASE', 'db_read_database'),
            'db-read-username' => $v('G7_INSTALL_DB_READ_USERNAME', 'db_read_username'),
            'db-read-password-file' => $s('G7_INSTALL_DB_READ_PASSWORD', 'db_read_password'),
            'drop-existing-tables' => $f('G7_INSTALL_DROP_EXISTING_TABLES', 'drop_existing_tables'),
            'app-name' => $v('G7_INSTALL_APP_NAME', 'app_name'),
            'app-url' => $v('G7_INSTALL_APP_URL', 'app_url'),
            'admin-email' => $v('G7_INSTALL_ADMIN_EMAIL', 'admin_email'),
            'admin-name' => $v('G7_INSTALL_ADMIN_NAME', 'admin_name'),
            'admin-password-file' => $s('G7_INSTALL_ADMIN_PASSWORD', 'admin_password'),
            'admin-language' => $v('G7_INSTALL_ADMIN_LANGUAGE', 'admin_language'),
            'site-language' => $v('G7_INSTALL_SITE_LANGUAGE', 'site_language'),
            'core-pending-path' => $v('G7_INSTALL_CORE_PENDING_PATH', 'core_update_pending_path'),
            'github-url' => $v('G7_INSTALL_GITHUB_URL', 'core_update_github_url'),
            'github-token-file' => $s('G7_INSTALL_GITHUB_TOKEN', 'core_update_github_token'),
            'asset-url-mode' => $v('G7_INSTALL_ASSET_URL_MODE', 'asset_url_mode'),
            'php-binary' => $v('G7_INSTALL_PHP_BINARY', 'php_binary'),
            'composer-binary' => $v('G7_INSTALL_COMPOSER_BINARY', 'composer_binary'),
            'vendor-mode' => $v('G7_INSTALL_VENDOR_MODE', 'vendor_mode'),
            'admin-template' => $v('G7_INSTALL_ADMIN_TEMPLATE', 'ext.admin_templates'),
            'user-template' => $v('G7_INSTALL_USER_TEMPLATE', 'ext.user_templates'),
            'modules' => $l('G7_INSTALL_MODULES', 'ext.modules'),
            'plugins' => $l('G7_INSTALL_PLUGINS', 'ext.plugins'),
            'language-packs' => $l('G7_INSTALL_LANGUAGE_PACKS', 'ext.language_packs'),
            'all-extensions' => $f('G7_INSTALL_ALL_EXTENSIONS', 'all_extensions'),
            'config' => $v('G7_INSTALL_CONFIG', null),
            'output' => $v('G7_INSTALL_OUTPUT', null),
            'no-interaction' => $f('G7_INSTALL_NO_INTERACTION', null),
            'resume' => $f('G7_INSTALL_RESUME', null),
            'reset-state' => $f('G7_INSTALL_RESET_STATE', null),
            'takeover' => $f('G7_INSTALL_TAKEOVER', null),
            'skip-if-installed' => $f('G7_INSTALL_SKIP_IF_INSTALLED', null),
            'web-user' => $v('G7_INSTALL_WEB_USER', null),
            'web-group' => $v('G7_INSTALL_WEB_GROUP', null),
            'reinstall' => $f('G7_INSTALL_REINSTALL', null),
            'delete-vendor' => $f('G7_INSTALL_DELETE_VENDOR', null),
            'delete-extensions' => $f('G7_INSTALL_DELETE_EXTENSIONS', null),
            'delete-settings' => $f('G7_INSTALL_DELETE_SETTINGS', null),
            'delete-uploads' => $f('G7_INSTALL_DELETE_UPLOADS', null),
            'help' => ['type' => 'flag', 'env' => null, 'key' => null],
            'version' => ['type' => 'flag', 'env' => null, 'key' => null],
        ];
    }
}

if (! function_exists('g7_cli_forbidden_secret_options')) {
    /**
     * 명령줄로 받지 않는 비밀값 옵션 → 대신 쓸 방법.
     *
     * @return array<string, string> 옵션 → 환경변수 이름
     */
    function g7_cli_forbidden_secret_options(): array
    {
        return [
            'admin-password' => 'G7_INSTALL_ADMIN_PASSWORD',
            'admin-password-confirm' => 'G7_INSTALL_ADMIN_PASSWORD',
            'db-password' => 'G7_INSTALL_DB_PASSWORD',
            'db-write-password' => 'G7_INSTALL_DB_PASSWORD',
            'db-read-password' => 'G7_INSTALL_DB_READ_PASSWORD',
            'github-token' => 'G7_INSTALL_GITHUB_TOKEN',
        ];
    }
}

if (! function_exists('g7_cli_parse_args')) {
    /**
     * 명령줄 인자를 해석한다 — 상태를 바꾸기 전에 형식 오류를 모두 걸러낸다.
     *
     * @param  array<int, string>  $argv  인자 (스크립트 이름 제외)
     * @return array{options: array<string, string|bool>, errors: array<int, array{key: string, params: array<string, string>}>} 옵션과 오류
     */
    function g7_cli_parse_args(array $argv): array
    {
        $spec = g7_cli_option_spec();
        $forbidden = g7_cli_forbidden_secret_options();
        $options = [];
        $errors = [];

        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if ($arg === '-n') {
                $options['no-interaction'] = true;

                continue;
            }
            if ($arg === '-h') {
                $options['help'] = true;

                continue;
            }
            if (! str_starts_with($arg, '--')) {
                $errors[] = ['key' => 'cli_error_unknown_argument', 'params' => ['argument' => $arg]];

                continue;
            }

            $name = substr($arg, 2);
            $value = null;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (isset($forbidden[$name])) {
                $errors[] = ['key' => 'cli_error_secret_on_command_line', 'params' => ['option' => '--'.$name, 'env' => $forbidden[$name], 'file' => '--'.$name.'-file']];

                // 띄어 쓴 값(`--db-password 값`)도 함께 버린다 — 남기면 「알 수 없는 인자: 값」으로 비밀값이 출력된다.
                if ($value === null && isset($argv[$i + 1]) && ! str_starts_with($argv[$i + 1], '-')) {
                    $i++;
                }

                continue;
            }

            if (! isset($spec[$name])) {
                $errors[] = ['key' => 'cli_error_unknown_option', 'params' => ['option' => '--'.$name]];

                continue;
            }

            if ($spec[$name]['type'] === 'flag') {
                if ($value !== null && ! in_array(strtolower($value), ['1', '0', 'true', 'false', 'yes', 'no'], true)) {
                    $errors[] = ['key' => 'cli_error_flag_value', 'params' => ['option' => '--'.$name]];

                    continue;
                }
                $options[$name] = $value === null || in_array(strtolower($value), ['1', 'true', 'yes'], true);

                continue;
            }

            if ($value === null) {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || str_starts_with($next, '--')) {
                    $errors[] = ['key' => 'cli_error_option_needs_value', 'params' => ['option' => '--'.$name]];

                    continue;
                }
                $value = $next;
                $i++;
            }

            $options[$name] = $value;
        }

        return ['options' => $options, 'errors' => $errors];
    }
}

if (! function_exists('g7_cli_read_secret_file')) {
    /**
     * 값을 파일에서 읽는다 — 파일 끝 개행 하나만 제거한다.
     *
     * @param  string  $path  파일 경로
     * @return string|null 값 (읽기 실패면 null)
     */
    function g7_cli_read_secret_file(string $path): ?string
    {
        $content = @file_get_contents($path);
        if (! is_string($content)) {
            return null;
        }

        return (string) preg_replace('/\r?\n\z/', '', $content);
    }
}

if (! function_exists('g7_cli_truthy')) {
    /**
     * 환경변수·설정 파일 값의 참/거짓 판정.
     *
     * @param  mixed  $value  값
     * @return bool 참이면 true
     */
    function g7_cli_truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (! function_exists('g7_cli_config_file_keys')) {
    /**
     * `--config` JSON 이 받을 수 있는 키 (비밀 키는 거부 — 비밀은 파일 옵션으로).
     *
     * @return array{allowed: array<int, string>, secret: array<int, string>} 허용·비밀 키
     */
    function g7_cli_config_file_keys(): array
    {
        $allowed = ['extensions'];
        foreach (g7_cli_option_spec() as $row) {
            if ($row['key'] !== null && $row['type'] !== 'secret_file' && ! str_starts_with($row['key'], 'ext.')) {
                $allowed[] = $row['key'];
            }
        }

        return [
            'allowed' => $allowed,
            'secret' => ['db_write_password', 'db_read_password', 'admin_password', 'admin_password_confirm', 'core_update_github_token'],
        ];
    }
}

if (! function_exists('g7_cli_collect_input')) {
    /**
     * 입력을 병합한다 — 명령줄 > 환경변수 > --config > 웹 기본값.
     *
     * @param  array<string, string|bool>  $options  해석된 명령줄 옵션
     * @param  array<string, string>  $env  환경변수
     * @return array{config: array<string, mixed>, secrets: array<string, string>, extensions: array<string, array<int, string>>, explicit: array<string, bool>, errors: array<int, array{key: string, params: array<string, string>}>} 병합 결과
     */
    function g7_cli_collect_input(array $options, array $env): array
    {
        $errors = [];
        $config = DEFAULT_INSTALL_CONFIG;
        unset($config['db_write_password'], $config['db_read_password'], $config['admin_password'], $config['core_update_github_token']);
        $config['site_language'] = '';
        $config['asset_url_mode'] = '';
        $config['drop_existing_tables'] = false;
        $config['accept_license'] = false;
        $config['all_extensions'] = false;
        $config['locale'] = '';
        $extensions = ['admin_templates' => null, 'user_templates' => null, 'modules' => null, 'plugins' => null, 'language_packs' => null];
        $secrets = [];
        $explicit = [];

        // 1) 설정 파일
        $configPath = $options['config'] ?? ($env['G7_INSTALL_CONFIG'] ?? null);
        if (is_string($configPath) && $configPath !== '') {
            $decoded = json_decode((string) @file_get_contents($configPath), true);
            if (! is_array($decoded)) {
                $errors[] = ['key' => 'cli_error_config_file', 'params' => ['path' => $configPath]];
            } else {
                $keys = g7_cli_config_file_keys();
                foreach ($decoded as $key => $value) {
                    if (in_array($key, $keys['secret'], true)) {
                        $errors[] = ['key' => 'cli_error_config_secret_key', 'params' => ['key' => (string) $key]];
                    } elseif (! in_array($key, $keys['allowed'], true)) {
                        $errors[] = ['key' => 'cli_error_config_unknown_key', 'params' => ['key' => (string) $key]];
                    } elseif ($key === 'extensions') {
                        foreach ((array) $value as $cat => $ids) {
                            if (! array_key_exists($cat, $extensions)) {
                                $errors[] = ['key' => 'cli_error_config_unknown_key', 'params' => ['key' => 'extensions.'.$cat]];

                                continue;
                            }
                            $extensions[$cat] = array_values(array_map('strval', (array) $ids));
                        }
                    } else {
                        $config[$key] = is_bool($value) ? $value : (string) $value;
                        $explicit[$key] = true;
                    }
                }
            }
        }

        // 2) 환경변수 → 3) 명령줄 (뒤가 앞을 덮는다)
        foreach ([['source' => 'env'], ['source' => 'cli']] as $layer) {
            foreach (g7_cli_option_spec() as $name => $row) {
                if ($row['key'] === null) {
                    continue;
                }

                if ($layer['source'] === 'env') {
                    if ($row['env'] === null || ! array_key_exists($row['env'], $env) || $env[$row['env']] === '') {
                        continue;
                    }
                    $raw = $env[$row['env']];
                } else {
                    if (! array_key_exists($name, $options)) {
                        continue;
                    }
                    $raw = $options[$name];
                }

                switch ($row['type']) {
                    case 'secret_file':
                        if ($layer['source'] === 'env') {
                            $secrets[$row['key']] = (string) $raw;
                        } else {
                            $value = g7_cli_read_secret_file((string) $raw);
                            if ($value === null) {
                                $errors[] = ['key' => 'cli_error_secret_file', 'params' => ['option' => '--'.$name, 'path' => (string) $raw]];
                            } else {
                                $secrets[$row['key']] = $value;
                            }
                        }
                        break;
                    case 'flag':
                        $config[$row['key']] = g7_cli_truthy($raw);
                        $explicit[$row['key']] = true;
                        break;
                    case 'list':
                        $extensions[substr($row['key'], 4)] = array_values(array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen'));
                        break;
                    default:
                        if (str_starts_with($row['key'], 'ext.')) {
                            $value = trim((string) $raw);
                            $extensions[substr($row['key'], 4)] = ($value === '' || $value === 'none') ? [] : [$value];
                        } else {
                            $config[$row['key']] = (string) $raw;
                            $explicit[$row['key']] = true;
                        }
                }
            }
        }

        return ['config' => $config, 'secrets' => $secrets, 'extensions' => $extensions, 'explicit' => $explicit, 'errors' => $errors];
    }
}

if (! function_exists('g7_cli_detect_locale')) {
    /**
     * 설치 UI 언어 — 명시값 → LANG/LC_ALL 이 en* 면 en → ko.
     *
     * @param  string  $explicit  --locale·G7_INSTALL_LOCALE 값
     * @param  array<string, string>  $env  환경변수
     * @return string ko|en
     */
    function g7_cli_detect_locale(string $explicit, array $env): string
    {
        if (array_key_exists($explicit, SUPPORTED_LANGUAGES)) {
            return $explicit;
        }

        $sys = strtolower((string) ($env['LC_ALL'] ?? $env['LANG'] ?? ''));

        return str_starts_with($sys, 'en') ? 'en' : 'ko';
    }
}

if (! function_exists('g7_cli_probe_asset_url_mode')) {
    /**
     * 자산 URL 방식 판정 — 웹 브라우저 프로브와 같은 두 주소·같은 판정식(매직 토큰 + JS 콘텐츠 형식).
     *
     * 사이트가 아직 응답하지 않으면(도커 빌드 등) 빈 값 — 웹의 「판정 불가」와 같아 defaults.json 기본값을 따른다.
     * 요청은 읽기 전용 프로브라 비밀을 싣지 않으며, 로컬 자체 서명 인증서에서도 판정되도록 인증서 검증을 끈다.
     *
     * @param  string  $appUrl  사이트 URL
     * @return string extension | extensionless | ''
     */
    function g7_cli_probe_asset_url_mode(string $appUrl): string
    {
        $root = rtrim($appUrl, '/');
        $probe = static function (string $path) use ($root): bool {
            $context = stream_context_create([
                'http' => ['timeout' => 5, 'ignore_errors' => true, 'header' => "Cache-Control: no-cache\r\n"],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $body = @file_get_contents($root.$path, false, $context);
            if (! is_string($body)) {
                return false;
            }
            $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
            $status = isset($headers[0]) && preg_match('/\s(\d{3})\s?/', $headers[0], $m) ? (int) $m[1] : 0;
            $type = '';
            foreach ($headers as $header) {
                if (stripos($header, 'content-type:') === 0) {
                    $type = $header;
                }
            }

            return $status >= 200 && $status < 300 && preg_match('/javascript|ecmascript/i', $type) && str_contains($body, 'G7_ASSET_PROBE_OK');
        };

        $withExt = $probe('/api/system/asset-probe.js');
        $withoutExt = $probe('/api/system/asset-probe');

        if ($withExt && $withoutExt) {
            return 'extension';
        }

        return (! $withExt && $withoutExt) ? 'extensionless' : '';
    }
}

if (! class_exists('G7CliOutput')) {
    /**
     * 사람용·JSON 출력 공통 창구 — 단계 진행·정보·오류를 같은 이벤트 이름으로 낸다.
     */
    final class G7CliOutput
    {
        /** 송출기 */
        public ProgressEmitter $emitter;

        /** JSON 출력 여부 */
        public bool $json;

        /**
         * @param  bool  $json  JSON 출력이면 true
         */
        public function __construct(bool $json)
        {
            $this->json = $json;
            $this->emitter = $json ? new JsonLinesEmitter : new CliEmitter;
        }

        /**
         * 단계 제목.
         *
         * @param  int|string  $step  단계 번호
         * @param  string  $title  제목
         * @return void 반환값 없음
         */
        public function step(int|string $step, string $title): void
        {
            $this->raw('step', ['step' => $step, 'title' => $title]);
        }

        /**
         * 정보 한 줄.
         *
         * @param  string  $message  문구
         * @return void 반환값 없음
         */
        public function info(string $message): void
        {
            $this->raw('info', ['message' => $message]);
        }

        /**
         * 경고 한 줄.
         *
         * @param  string  $message  문구
         * @return void 반환값 없음
         */
        public function warn(string $message): void
        {
            $this->raw('warning', ['message' => $message]);
        }

        /**
         * 오류.
         *
         * @param  string  $message  문구
         * @param  string|null  $key  번역 키
         * @param  string|null  $detail  상세
         * @return void 반환값 없음
         */
        public function error(string $message, ?string $key = null, ?string $detail = null): void
        {
            $this->raw('error', array_filter(['message' => $message, 'message_key' => $key, 'error' => $detail], static fn ($v) => $v !== null));
        }

        /**
         * 이벤트 그대로 (installation.log 에 남기지 않는다 — 설치 작업 로그와 섞지 않기 위해).
         *
         * @param  string  $event  이벤트 이름
         * @param  array<string, mixed>  $data  데이터
         * @return void 반환값 없음
         */
        public function raw(string $event, array $data): void
        {
            if ($this->json) {
                fwrite(STDOUT, installer_json_encode(['event' => $event] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

                return;
            }

            (new CliEmitter)->emitRaw($event, $data);
        }
    }
}

if (! function_exists('g7_cli_fail')) {
    /**
     * 오류 목록을 출력하고 종료 코드를 돌려준다.
     *
     * @param  G7CliOutput  $out  출력
     * @param  array<int, array{key: string, params: array<string, string>}|string>  $errors  오류 (키+치환값 또는 문구)
     * @param  int  $code  종료 코드
     * @return int 종료 코드
     */
    function g7_cli_fail(G7CliOutput $out, array $errors, int $code): int
    {
        foreach ($errors as $error) {
            if (is_array($error)) {
                $out->error(lang($error['key'], $error['params']), $error['key']);
            } else {
                $out->error($error);
            }
        }
        $out->raw('summary', ['exit_code' => $code]);

        return $code;
    }
}

if (! function_exists('g7_install_cli_main')) {
    /**
     * 터미널 설치 진입점.
     *
     * @param  array<int, string>  $argv  전체 인자 (0번은 스크립트)
     * @param  array<string, string>|null  $env  환경변수 (null 이면 getenv())
     * @return int 종료 코드 (G7_CLI_EXIT_*)
     */
    function g7_install_cli_main(array $argv, ?array $env = null): int
    {
        @set_time_limit(0);
        $env ??= getenv();
        $parsed = g7_cli_parse_args(array_slice($argv, 1));
        $options = $parsed['options'];

        $input = g7_cli_collect_input($options, $env);
        $uiLang = g7_cli_detect_locale((string) $input['config']['locale'], $env);
        $GLOBALS['translations'] = loadTranslations($uiLang);
        $_SESSION = ['g7_locale' => $uiLang];

        $out = new G7CliOutput(($options['output'] ?? ($env['G7_INSTALL_OUTPUT'] ?? 'text')) === 'json');

        if (! empty($options['help'])) {
            fwrite(STDOUT, lang('cli_help')."\n");

            return G7_CLI_EXIT_OK;
        }
        if (! empty($options['version'])) {
            fwrite(STDOUT, 'g7-install '.getCoreAppVersion()."\n");

            return G7_CLI_EXIT_OK;
        }

        $output = $options['output'] ?? ($env['G7_INSTALL_OUTPUT'] ?? 'text');
        if (! in_array($output, ['text', 'json'], true)) {
            $parsed['errors'][] = ['key' => 'cli_error_option_value', 'params' => ['option' => '--output', 'value' => (string) $output]];
        }
        // 설치 언어는 ko·en 만(3.4) — 지원하지 않는 값을 조용히 자동 판정으로 바꾸지 않는다.
        $requestedLocale = (string) $input['config']['locale'];
        if ($requestedLocale !== '' && ! array_key_exists($requestedLocale, SUPPORTED_LANGUAGES)) {
            $parsed['errors'][] = ['key' => 'cli_error_option_value', 'params' => ['option' => '--locale', 'value' => $requestedLocale]];
        }
        // 사이트 기본 언어·자산 URL 방식도 같다 — 공유 검증은 웹 숨김 값용으로 기본값에 맞추므로 터미널 입력은 여기서 거른다.
        $enumOptions = [
            '--site-language' => ['site_language', array_keys(SUPPORTED_LANGUAGES)],
            '--asset-url-mode' => ['asset_url_mode', ['extension', 'extensionless']],
        ];
        foreach ($enumOptions as $option => [$key, $allowed]) {
            $value = trim((string) ($input['config'][$key] ?? ''));
            if ($value !== '' && ! in_array($value, $allowed, true)) {
                $parsed['errors'][] = ['key' => 'cli_error_option_value', 'params' => ['option' => $option, 'value' => $value]];
            }
        }

        // 형식 오류는 어떤 상태도 바꾸기 전에 거른다.
        $formatErrors = [...$parsed['errors'], ...$input['errors']];
        if ($formatErrors !== []) {
            return g7_cli_fail($out, $formatErrors, G7_CLI_EXIT_INVALID);
        }

        $interactive = ! $out->json && empty($options['no-interaction']) && ! g7_cli_truthy($env['G7_INSTALL_NO_INTERACTION'] ?? '') && g7_cli_stdin_is_tty();

        // root 실행 판정 — 파일을 하나라도 만들기 전에.
        $webUserName = (string) ($options['web-user'] ?? ($env['G7_INSTALL_WEB_USER'] ?? ''));
        $webGroupName = (string) ($options['web-group'] ?? ($env['G7_INSTALL_WEB_GROUP'] ?? ''));
        $webUser = null;
        if (DIRECTORY_SEPARATOR === '\\') {
            if ($webUserName !== '') {
                $out->warn(lang('cli_web_user_ignored_windows'));
            }
        } elseif (g7_cli_is_root()) {
            if ($webUserName === '') {
                return g7_cli_fail($out, [['key' => 'cli_error_root_refused', 'params' => ['base' => BASE_PATH]]], G7_CLI_EXIT_ROOT_REFUSED);
            }
            $webUser = g7_cli_resolve_web_user($webUserName, $webGroupName !== '' ? $webGroupName : null);
            if ($webUser === null) {
                return g7_cli_fail($out, [['key' => 'cli_error_web_user_not_found', 'params' => ['user' => $webUserName.($webGroupName !== '' ? ':'.$webGroupName : '')]]], G7_CLI_EXIT_INVALID);
            }
        } elseif ($webUserName !== '') {
            return g7_cli_fail($out, [['key' => 'cli_error_web_user_requires_root', 'params' => []]], G7_CLI_EXIT_INVALID);
        }

        $flag = static fn (string $name): bool => ! empty($options[$name]) || g7_cli_truthy($env['G7_INSTALL_'.strtoupper(str_replace('-', '_', $name))] ?? '');

        // 설치 완료 판정 (D7) / 재설치 (「5.4」)
        $installed = installer_is_completed();
        $reinstallKey = null;
        if ($installed && $flag('resume') && is_file(INSTALLER_RUNTIME_PATH)) {
            // 설치 작업은 끝났는데 .env 확정 전에 멈춘 터미널 설치 — 확정만 이어서 한다.
            $lock = g7_cli_acquire_process_lock();
            if ($lock === null) {
                return g7_cli_fail($out, [['key' => 'cli_error_another_cli_running', 'params' => []]], G7_CLI_EXIT_CONFLICT);
            }
            $finalize = installer_finalize_env();

            return $finalize['success'] ? G7_CLI_EXIT_OK : g7_cli_fail($out, [['key' => 'cli_error_finalize_failed', 'params' => []]], G7_CLI_EXIT_FAILED);
        }

        if ($flag('reinstall')) {
            if (! $installed) {
                return g7_cli_fail($out, [['key' => 'cli_error_reinstall_not_installed', 'params' => []]], G7_CLI_EXIT_INVALID);
            }
            if (empty($input['config']['drop_existing_tables'])) {
                return g7_cli_fail($out, [['key' => 'cli_error_reinstall_requires_drop', 'params' => []]], G7_CLI_EXIT_INVALID);
            }
        } elseif ($installed) {
            if ($flag('skip-if-installed')) {
                $out->info(lang('cli_already_installed_skip'));
                $out->raw('summary', ['exit_code' => G7_CLI_EXIT_OK, 'already_installed' => true]);

                return G7_CLI_EXIT_OK;
            }

            return g7_cli_fail($out, [['key' => 'cli_error_already_installed', 'params' => []]], G7_CLI_EXIT_INSTALLED);
        }

        // 동시 실행 방지 (A7)
        $lock = g7_cli_acquire_process_lock();
        if ($lock === null) {
            return g7_cli_fail($out, [['key' => 'cli_error_another_cli_running', 'params' => []]], G7_CLI_EXIT_CONFLICT);
        }

        g7_cli_install_signal_handlers();

        // 재설치 초기화는 설치 흐름이 약관·요구사항·환경설정·DB 검사를 마친 뒤에 한다 — 먼저 지우면 입력 오류 하나로
        // 표식만 지워지고 테이블은 남은 「반쯤 초기화된」 사이트가 된다.
        $exitCode = g7_cli_run_install($out, $input, $options, $env, $uiLang, $interactive, $flag, $reinstallKey, $webUser);

        return $exitCode;
    }
}

if (! function_exists('g7_cli_run_reinstall')) {
    /**
     * 재설치 초기화 — 지울 목록을 보이고(대화형은 현재 APP_URL 입력으로 확인) 지운다.
     *
     * @param  G7CliOutput  $out  출력
     * @param  callable(string): bool  $flag  플래그 판정
     * @param  bool  $interactive  대화형 여부
     * @param  string|null  &$appKey  이어 쓸 APP_KEY (참조)
     * @return int|null 종료 코드 (계속 진행이면 null)
     */
    function g7_cli_run_reinstall(G7CliOutput $out, callable $flag, bool $interactive, ?string &$appKey): ?int
    {
        $opts = [
            'delete_vendor' => $flag('delete-vendor'),
            'delete_extensions' => $flag('delete-extensions'),
            'delete_settings' => $flag('delete-settings'),
            'delete_uploads' => $flag('delete-uploads'),
        ];

        $out->step('R', lang('cli_reinstall_title'));
        foreach (installer_cli_reinstall_targets($opts) as $group => $target) {
            $out->info(lang('cli_reinstall_group_'.$group).' — '.count($target['paths']));
        }
        if (! $opts['delete_extensions']) {
            $out->warn(lang('cli_reinstall_warn_keep_extensions'));
        }
        if (! $opts['delete_uploads']) {
            $out->warn(lang('cli_reinstall_warn_keep_uploads'));
        }
        $out->info(lang($opts['delete_settings'] ? 'cli_reinstall_new_app_key' : 'cli_reinstall_keep_app_key'));

        if ($interactive) {
            $env = (string) @file_get_contents(BASE_PATH.'/.env');
            $currentUrl = preg_match('/^APP_URL=["\']?([^"\'\r\n]*)/m', $env, $m) ? $m[1] : '';
            if (g7_cli_ask(lang('cli_reinstall_confirm_url', ['url' => $currentUrl])) !== $currentUrl) {
                return g7_cli_fail($out, [['key' => 'cli_reinstall_cancelled', 'params' => []]], G7_CLI_EXIT_INVALID);
            }
        }

        $result = installer_cli_reinstall($opts);
        foreach ($result['rows'] as $row) {
            $line = lang('cli_reinstall_group_'.$row['group']).': '.lang('cli_reinstall_removed', ['count' => (string) $row['removed']]);
            $row['failed'] === [] ? $out->info($line) : $out->error($line, null, implode("\n", $row['failed']));
        }
        $out->raw('reinstall', ['rows' => $result['rows'], 'keep_app_key' => $result['app_key'] !== null]);

        if (! $result['ok']) {
            return g7_cli_fail($out, [['key' => 'cli_error_reinstall_failed', 'params' => []]], G7_CLI_EXIT_FAILED);
        }

        $appKey = $result['app_key'];

        return null;
    }
}
