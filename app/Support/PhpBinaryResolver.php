<?php

namespace App\Support;

/**
 * PHP/Composer 바이너리 경로 감지 및 검증 헬퍼.
 *
 * 카페24 등 비표준 경로에 PHP가 설치된 호스팅 환경을 위해
 * PHP 바이너리를 자동 탐색하고 버전을 검증합니다.
 */
class PhpBinaryResolver
{
    /**
     * 공통 PHP 바이너리 후보 경로 목록 (SSoT).
     *
     * 이 클래스가 후보 목록의 단일 출처다. 같은 목록을 다른 파일에 복사해 두면 한쪽만
     * 늘어난 채로 갈라져, 어느 화면에서는 찾고 어느 화면에서는 못 찾는 상태가 된다.
     *
     * 공유 호스팅의 다중 PHP 는 배포판 표준 경로에 없다 — 패널마다 자기 경로에 둔다.
     * 그 경로가 목록에 없으면 "PATH 에 php 가 없다" 만으로 in-process 로 떨어져,
     * 실제로는 서브프로세스가 가능한 서버가 계속 느린 경로를 탄다.
     *
     * @var array<int, string>
     */
    private const COMMON_PHP_PATHS = [
        // 배포판·소스 설치 표준
        '/usr/local/php84/bin/php',
        '/usr/local/php83/bin/php',
        '/usr/local/php82/bin/php',
        '/usr/bin/php8.4',
        '/usr/bin/php8.3',
        '/usr/bin/php8.2',
        '/usr/local/bin/php',
        '/usr/bin/php',
        // cPanel / EasyApache 4
        '/opt/cpanel/ea-php84/root/usr/bin/php',
        '/opt/cpanel/ea-php83/root/usr/bin/php',
        '/opt/cpanel/ea-php82/root/usr/bin/php',
        // CloudLinux alt-php (cPanel/DirectAdmin 공용)
        '/opt/alt/php84/usr/bin/php',
        '/opt/alt/php83/usr/bin/php',
        '/opt/alt/php82/usr/bin/php',
        // Plesk
        '/opt/plesk/php/8.4/bin/php',
        '/opt/plesk/php/8.3/bin/php',
        '/opt/plesk/php/8.2/bin/php',
        // LiteSpeed / OpenLiteSpeed lsphp
        '/usr/local/lsws/lsphp84/bin/php',
        '/usr/local/lsws/lsphp83/bin/php',
        '/usr/local/lsws/lsphp82/bin/php',
        // DirectAdmin CustomBuild
        '/usr/local/php84/bin/php-cli',
        '/usr/local/php83/bin/php-cli',
        '/usr/local/php82/bin/php-cli',
    ];

    /**
     * 최소 PHP 버전.
     *
     * @var string
     */
    private const MIN_PHP_VERSION = '8.2.0';

    /**
     * Laravel 설정에서 PHP 바이너리 경로를 반환합니다.
     *
     * @return string PHP 바이너리 경로
     */
    public static function resolve(): string
    {
        return config('process.php_binary', 'php') ?: 'php';
    }

    /**
     * Laravel 설정에서 Composer 바이너리 경로를 반환합니다.
     *
     * @return string Composer 바이너리 경로 (빈 문자열이면 시스템 PATH 사용)
     */
    public static function resolveComposer(): string
    {
        return config('process.composer_binary', '') ?: '';
    }

    /**
     * 서버에서 사용 가능한 PHP 바이너리를 자동 탐색합니다.
     *
     * CLI SAPI 바이너리를 앞에 둔다 — 첫 후보인 `PHP_BINARY` 는 웹 서버가 실행 중인
     * 바이너리라 FPM/CGI 인 경우가 흔한데, 그것은 `--version` 이 정상 종료해 검증을
     * 통과하면서도 `artisan` 스크립트를 인자로 받지 못한다. 그대로 첫 후보로 쓰면
     * 판정은 subprocess 가능인데 실제 태스크는 전부 실패한다. 같은 서버에 진짜 CLI
     * 바이너리가 있으면 그쪽이 이긴다. CLI 가 하나도 없으면 종전대로 남은 후보를
     * 그대로 돌려준다 — `php-cgi` 로만 동작하던 환경을 새로 막지 않는다.
     *
     * @return array<array{path: string, version: string, sapi: string|null}> 발견된 PHP 바이너리 목록 (CLI 우선)
     */
    public static function autoDetect(): array
    {
        $found = [];
        $checkedPaths = [];

        // PHP_BINARY 상수 (현재 웹 서버가 사용하는 PHP)
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && ! in_array(PHP_BINARY, $checkedPaths, true)) {
            $checkedPaths[] = PHP_BINARY;
            $result = self::validate(PHP_BINARY);
            if ($result['valid']) {
                $found[] = ['path' => PHP_BINARY, 'version' => $result['version'], 'sapi' => $result['sapi']];
            }
        }

        // 공통 경로 스캔
        //
        // `file_exists()` 로 미리 거르지 않는다. `open_basedir` 가 시스템 binary 영역을
        // 벗어난 경로를 차단하는 호스팅(시놀로지 DSM 등)에서는 정상 바이너리도 false 가
        // 나오고 E_WARNING 까지 뿜는다 — 실행 가능 여부는 exec 결과가 최종 판정한다
        // (installer 의 binary-path-policy 가 같은 규칙을 이미 명문화했다).
        // 존재하지 않는 경로는 exec 이 즉시 비-0 으로 끝나므로 비용은 후보 수에 비례한
        // 짧은 스폰뿐이고, 이 경로 자체가 "지정 바이너리가 실패했을 때" 만 도는 폴백이다.
        foreach (self::candidatePaths() as $path) {
            if (in_array($path, $checkedPaths, true)) {
                continue;
            }
            $checkedPaths[] = $path;

            $result = self::validate($path);
            if ($result['valid']) {
                $found[] = ['path' => $path, 'version' => $result['version'], 'sapi' => $result['sapi']];
            }
        }

        // 시스템 PATH의 'php'
        if (! in_array('php', $checkedPaths, true)) {
            $checkedPaths[] = 'php';
            $result = self::validate('php');
            if ($result['valid']) {
                $found[] = ['path' => 'php', 'version' => $result['version'], 'sapi' => $result['sapi']];
            }
        }

        return self::sortCliFirst($found);
    }

    /**
     * 자동 탐색 후보 경로 전체 (고정 목록 + 현재 플랫폼에서 파생한 후보).
     *
     * Windows 는 패널 관행이 없는 대신 `PHP_BINARY` 의 형제 파일이 사실상 유일한 단서다 —
     * 웹 요청은 `php-cgi.exe` 로 도는 경우가 흔하고, 같은 디렉토리의 `php.exe` 가 CLI 다.
     * 고정 목록이 POSIX 관행뿐이면 Windows 에서는 후보가 0 이라 폴백이 아무 일도 하지 않는다.
     *
     * @return array<int, string> 후보 경로 목록 (중복 제거, 탐색 순서 유지)
     */
    public static function candidatePaths(): array
    {
        $paths = self::COMMON_PHP_PATHS;

        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $dir = rtrim(str_replace('\\', '/', dirname(PHP_BINARY)), '/');

            if ($dir !== '') {
                foreach (['php.exe', 'php', 'php-cli', 'php-cli.exe'] as $name) {
                    $paths[] = $dir.'/'.$name;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * 후보 목록을 CLI 우선으로 정렬합니다 (각 그룹 안의 탐색 순서는 보존).
     *
     * autoDetect() 에서 분리해 둔다 — 그 함수는 실제로 프로세스를 스폰하므로 이 저장소의
     * 개발 환경에서는 CLI 후보만 나오고, 정렬을 지우거나 뒤집어도 테스트가 통과한다.
     * 순수 함수로 떼어 놓으면 비-CLI 가 섞인 목록을 주입해 정렬 자체를 고정할 수 있다.
     *
     * @param  array<array{path: string, version: string, sapi: string|null}>  $found  탐색 결과
     * @return array<array{path: string, version: string, sapi: string|null}> CLI 우선 정렬 결과
     */
    public static function sortCliFirst(array $found): array
    {
        $cli = [];
        $others = [];
        foreach ($found as $candidate) {
            if (($candidate['sapi'] ?? null) === 'cli') {
                $cli[] = $candidate;
            } else {
                $others[] = $candidate;
            }
        }

        return array_merge($cli, $others);
    }

    /**
     * 지정된 PHP 바이너리 경로의 유효성을 검증합니다.
     *
     * 파일 존재 여부로 **조기 실패하지 않는다**. `open_basedir` 가 걸린 호스팅에서는
     * 정상 바이너리에도 `file_exists()` 가 false 를 돌려주고 E_WARNING 을 뿜는다
     * (`display_errors` 가 켜진 서버에서는 그 warning 이 JSON 응답에 섞인다).
     * 그래서 stat 결과는 **사유를 고르는 데만** 쓰고, 판정은 언제나 exec 결과가 한다.
     *
     * @param  string  $path  PHP 바이너리 경로
     * @return array{valid: bool, version: string|null, sapi: string|null, message: string, message_key: string, message_params: array<string, string>} 검증 결과
     */
    public static function validate(string $path): array
    {
        // trim 으로 판정한다 — empty() 는 '0' 을 빈 값으로 보고, 공백만 있는 경로는
        // 이 가드를 통과해 "파일이 존재하지 않습니다: " 라는 엉뚱한 사유가 기록된다.
        if (trim($path) === '') {
            return self::invalid('php_probe_empty_path', [], 'PHP 바이너리 경로가 비어있습니다.');
        }

        // stat 은 "실패했을 때 무엇이라 부를지" 를 정하는 힌트일 뿐이다. `@` 로 감싸
        // open_basedir warning 이 응답에 섞이지 않게 한다.
        $looksMissing = $path !== 'php' && ! @file_exists($path);

        // 명령어 실행하여 버전 확인
        $command = escapeshellarg($path).' --version 2>&1';
        $output = [];
        $returnCode = -1;

        // audit:allow hosting-blocked-function-requires-guard 가드는 호출 진입점 두 곳이 갖는다 —
        // ExecutionEnvironment::probePhpBinary() 와 인스톨러 validatePhpPath() 가 각각
        // hasFunction('exec') 으로 먼저 판정하고 「판정 불확실」을 돌려준다. 이 함수는 그 뒤에만
        // 도달하며, 파일 단위로 보는 룰에는 그 가드가 보이지 않는다.
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return $looksMissing
                ? self::invalid('php_probe_file_missing', ['path' => $path], "파일이 존재하지 않습니다: {$path}")
                : self::invalid('php_probe_exec_failed', ['path' => $path], "PHP 실행 실패: {$path}");
        }

        // 버전 파싱 (예: "PHP 8.2.15 (cli) ...")
        $outputStr = implode("\n", $output);
        if (preg_match('/PHP\s+(\d+\.\d+\.\d+)/', $outputStr, $matches)) {
            $version = $matches[1];
            $sapi = self::parseSapi($outputStr);

            if (version_compare($version, self::MIN_PHP_VERSION, '>=')) {
                return [
                    'valid' => true,
                    'version' => $version,
                    'sapi' => $sapi,
                    'message' => "PHP {$version} (최소 요구 버전 ".self::MIN_PHP_VERSION.' 충족)',
                    'message_key' => 'php_probe_ok',
                    'message_params' => ['version' => $version, 'min_version' => self::MIN_PHP_VERSION],
                ];
            }

            return [
                'valid' => false,
                'version' => $version,
                'sapi' => $sapi,
                'message' => "PHP {$version}은 최소 요구 버전 ".self::MIN_PHP_VERSION.'을 충족하지 않습니다.',
                'message_key' => 'php_probe_version_low',
                'message_params' => ['version' => $version, 'min_version' => self::MIN_PHP_VERSION],
            ];
        }

        return self::invalid('php_probe_unparsable', [], 'PHP 버전을 파싱할 수 없습니다.');
    }

    /**
     * invalid 판정 결과를 만듭니다.
     *
     * `message` 는 로그·개발 편의를 위한 하위호환 필드이고, 화면에 노출되는 사유는
     * `message_key` 로 실어 보낸다 — 코어가 한국어 문장을 확정해 API 응답에 실으면
     * 같은 응답의 다른 사유들이 번역되는 것과 달리 그 항목만 번역되지 않은 채 나간다.
     *
     * @param  string  $key  다국어 키 (접두사 없음 — 소비처가 자기 네임스페이스를 붙인다)
     * @param  array<string, string>  $params  치환 파라미터
     * @param  string  $fallback  하위호환 메시지
     * @return array{valid: bool, version: null, sapi: null, message: string, message_key: string, message_params: array<string, string>}
     */
    private static function invalid(string $key, array $params, string $fallback): array
    {
        return [
            'valid' => false,
            'version' => null,
            'sapi' => null,
            'message' => $fallback,
            'message_key' => $key,
            'message_params' => $params,
        ];
    }

    /**
     * `--version` 출력에서 SAPI 이름을 뽑습니다 (예: "PHP 8.3.26 (cli)" → `cli`).
     *
     * FPM/CGI 바이너리도 `--version` 은 정상 종료하며 같은 형태로 버전을 찍는다 —
     * 구분은 이 괄호 안 이름뿐이다.
     *
     * @param  string  $versionOutput  `{binary} --version` 의 표준출력
     * @return string|null 소문자 SAPI 이름 (파싱 불가 시 null)
     */
    private static function parseSapi(string $versionOutput): ?string
    {
        if (preg_match('/PHP\s+\d+\.\d+\.\d+[^(]*\(([^)]+)\)/', $versionOutput, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return null;
    }

    /**
     * Composer 실행 명령어를 생성합니다.
     *
     * 공백이 포함된 입력은 "인터프리터 + 스크립트" 두 토큰으로 해석하고 **각 토큰을 따로
     * 이스케이프**한다. 원문을 그대로 셸에 돌려주면 그 문자열이 곧 명령줄이 되어
     * `sh -c "..."` 같은 입력이 실행된다 — 인스톨러 쪽은 이미 이 형태를 금지했는데
     * (`installer_resolve_php_composer_pair`) 코어에만 옛 형태가 남아 있었다.
     *
     * @param  string  $phpBinary  PHP 바이너리 경로
     * @param  string  $composerBinary  Composer 바이너리 경로 (빈 문자열이면 시스템 PATH 사용)
     * @return string 실행 가능한 Composer 명령어
     */
    public static function buildComposerCommand(string $phpBinary = 'php', string $composerBinary = ''): string
    {
        if ($composerBinary) {
            // 공백 포함 = "인터프리터 + 스크립트" 형태 (예: "/usr/local/php84/bin/php /home/user/g7/composer.phar")
            if (str_contains($composerBinary, ' ')) {
                $tokens = preg_split('/\s+/', trim($composerBinary), 2);

                if (! is_array($tokens) || count($tokens) !== 2 || $tokens[0] === '' || $tokens[1] === '') {
                    return 'composer';
                }

                return escapeshellarg($tokens[0]).' '.escapeshellarg($tokens[1]);
            }

            return str_ends_with($composerBinary, '.phar')
                ? escapeshellarg($phpBinary).' '.escapeshellarg($composerBinary)
                : escapeshellarg($composerBinary);
        }

        return 'composer';
    }
}
