<?php

namespace App\Support;

/**
 * 설치 잔여 파일(`storage/installer/runtime.php`) 판정 도구.
 *
 * 웹 설치는 진행 중 DB 계정과 APP_KEY 를 `.env` 가 아니라 `storage/installer/runtime.php` 에 잠깐 둔다.
 * 설치가 끝나면 그 값을 `.env` 에 합치고 파일을 지우는데, 지우지 못한 채 남으면 그 파일이 `.env` 를
 * 이겨 서버를 옮기고 `.env` 를 고쳐도 반영되지 않았다.
 *
 * 이 클래스는 부팅 초기에(설정·DB 이전) 불리므로 파사드나 컨테이너를 쓰지 않는다 — 파일만 읽는다.
 */
final class InstallerResidual
{
    /**
     * 인스톨러 런타임 파일 경로 (앱 루트 기준).
     */
    public const RUNTIME_PATH_RELATIVE = 'storage/installer/runtime.php';

    /**
     * 런타임 파일이 담는 `.env` 키 (쓰기 연결 · 읽기 연결 · 접두사 · 앱 키).
     *
     * @var list<string>
     */
    public const ENV_KEYS = [
        'DB_WRITE_HOST', 'DB_WRITE_PORT', 'DB_WRITE_DATABASE', 'DB_WRITE_USERNAME', 'DB_WRITE_PASSWORD',
        'DB_READ_HOST', 'DB_READ_PORT', 'DB_READ_DATABASE', 'DB_READ_USERNAME', 'DB_READ_PASSWORD',
        'DB_PREFIX', 'APP_KEY',
    ];

    /**
     * 설치가 끝난 사이트인지 디스크만 보고 판정합니다.
     *
     * `.env` 의 `INSTALLER_COMPLETED` 가 참이거나 `storage/app/g7_installed` 가 있으면 완료입니다.
     * 설정 캐시·프로세스 환경변수는 보지 않습니다 — 업데이트 중인 프로세스는 옛 값을 들고 있을 수 있습니다.
     *
     * @param  string  $basePath  앱 루트 경로
     * @return bool 설치 완료면 true
     */
    public static function isInstallCompleted(string $basePath): bool
    {
        $base = rtrim($basePath, '/\\');

        if (is_file($base.'/storage/app/g7_installed')) {
            return true;
        }

        $envPath = $base.'/.env';
        if (! is_file($envPath)) {
            return false;
        }

        return self::isTruthy(self::parseEnvFile($envPath)['INSTALLER_COMPLETED'] ?? '');
    }

    /**
     * `.env` 의 참·거짓 값을 판정합니다.
     *
     * @param  string  $raw  원본 값
     * @return bool true · 1 · yes 면 true
     */
    public static function isTruthy(string $raw): bool
    {
        return in_array(strtolower(trim($raw, " \t\"'")), ['true', '1', 'yes'], true);
    }

    /**
     * `.env` 파일을 줄 단위로 직접 읽어 키-값 배열로 돌려줍니다.
     *
     * `parse_ini_file` 은 `.env` 와 완전히 호환되지 않아(예약어 값·일부 특수문자) 쓰지 않습니다.
     *   - 빈 줄, `#`·`;` 로 시작하는 주석 줄은 건너뜁니다.
     *   - 첫 `=` 기준으로 나눕니다 (값에 `=` 가 들어갈 수 있습니다: APP_KEY=base64:...=).
     *   - 바깥 따옴표는 벗깁니다.
     *   - 키 이름은 [A-Za-z_][A-Za-z0-9_]* 만 인정합니다.
     *
     * @param  string  $envPath  .env 파일 경로
     * @return array<string, string> 키-값 배열 (읽지 못하면 빈 배열)
     */
    public static function parseEnvFile(string $envPath): array
    {
        $content = @file_get_contents($envPath);
        if ($content === false) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false || $eqPos === 0) {
                continue;
            }
            $key = rtrim(substr($trimmed, 0, $eqPos));
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            // 줄 끝 공백 제거 (따옴표 안의 내용은 그대로 둔다)
            $value = rtrim(substr($trimmed, $eqPos + 1), " \t");
            $result[$key] = self::unwrapEnvValue($value);
        }

        return $result;
    }

    /**
     * `.env` 값의 바깥 따옴표(작은·큰)를 벗깁니다.
     *
     * @param  string  $value  원본 값
     * @return string 따옴표를 벗긴 값
     */
    public static function unwrapEnvValue(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, $length - 2);
            }
        }

        return $value;
    }

    /**
     * 런타임 배열을 `.env` 키 이름으로 펼칩니다.
     *
     * 런타임에 읽기 연결이 따로 없으면 읽기 키는 넣지 않습니다(쓰기 연결을 따라갑니다).
     *
     * @param  array<string, mixed>  $runtime  런타임 파일의 내용
     * @return array<string, string> `.env` 키 => 값 (런타임에 있는 것만)
     */
    public static function runtimeToEnvMap(array $runtime): array
    {
        $map = [];

        foreach (['write' => 'DB_WRITE_', 'read' => 'DB_READ_'] as $role => $prefix) {
            $connection = $runtime['db'][$role] ?? null;
            if (! is_array($connection) || empty($connection['host'])) {
                continue;
            }
            foreach (['host' => 'HOST', 'port' => 'PORT', 'database' => 'DATABASE', 'username' => 'USERNAME', 'password' => 'PASSWORD'] as $field => $suffix) {
                if (array_key_exists($field, $connection) && is_scalar($connection[$field])) {
                    $map[$prefix.$suffix] = (string) $connection[$field];
                }
            }
        }

        if (isset($runtime['db']['prefix']) && is_scalar($runtime['db']['prefix'])) {
            $map['DB_PREFIX'] = (string) $runtime['db']['prefix'];
        }

        $key = $runtime['app']['key'] ?? null;
        if (is_string($key) && $key !== '') {
            $map['APP_KEY'] = $key;
        }

        return $map;
    }
}
