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
     * 공통 PHP 바이너리 후보 경로 목록.
     *
     * @var array<string>
     */
    private const COMMON_PHP_PATHS = [
        '/usr/local/php84/bin/php',
        '/usr/local/php83/bin/php',
        '/usr/local/php82/bin/php',
        '/usr/bin/php8.4',
        '/usr/bin/php8.3',
        '/usr/bin/php8.2',
        '/usr/local/bin/php',
        '/usr/bin/php',
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
     * @return array<array{path: string, version: string}> 발견된 PHP 바이너리 목록
     */
    public static function autoDetect(): array
    {
        $found = [];
        $checkedPaths = [];

        // PHP_BINARY 상수 (현재 웹 서버가 사용하는 PHP)
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && !in_array(PHP_BINARY, $checkedPaths, true)) {
            $checkedPaths[] = PHP_BINARY;
            $result = self::validate(PHP_BINARY);
            if ($result['valid']) {
                $found[] = ['path' => PHP_BINARY, 'version' => $result['version']];
            }
        }

        // 공통 경로 스캔
        foreach (self::COMMON_PHP_PATHS as $path) {
            if (in_array($path, $checkedPaths, true)) {
                continue;
            }
            $checkedPaths[] = $path;

            if (!file_exists($path)) {
                continue;
            }

            $result = self::validate($path);
            if ($result['valid']) {
                $found[] = ['path' => $path, 'version' => $result['version']];
            }
        }

        // 시스템 PATH의 'php'
        if (!in_array('php', $checkedPaths, true)) {
            $checkedPaths[] = 'php';
            $result = self::validate('php');
            if ($result['valid']) {
                $found[] = ['path' => 'php', 'version' => $result['version']];
            }
        }

        return $found;
    }

    /**
     * 지정된 PHP 바이너리 경로의 유효성을 검증합니다.
     *
     * @param string $path PHP 바이너리 경로
     * @return array{valid: bool, version: string|null, message: string} 검증 결과
     */
    public static function validate(string $path): array
    {
        if (empty($path)) {
            return [
                'valid' => false,
                'version' => null,
                'message' => 'PHP 바이너리 경로가 비어있습니다.',
            ];
        }

        // 절대 경로인 경우 파일 존재 여부 확인
        if ($path !== 'php' && !file_exists($path)) {
            return [
                'valid' => false,
                'version' => null,
                'message' => "파일이 존재하지 않습니다: {$path}",
            ];
        }

        // 명령어 실행하여 버전 확인
        $command = escapeshellarg($path) . ' --version 2>&1';
        $output = [];
        $returnCode = -1;

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'valid' => false,
                'version' => null,
                'message' => "PHP 실행 실패: {$path}",
            ];
        }

        // 버전 파싱 (예: "PHP 8.2.15 (cli) ...")
        $outputStr = implode("\n", $output);
        if (preg_match('/PHP\s+(\d+\.\d+\.\d+)/', $outputStr, $matches)) {
            $version = $matches[1];

            if (version_compare($version, self::MIN_PHP_VERSION, '>=')) {
                return [
                    'valid' => true,
                    'version' => $version,
                    'message' => "PHP {$version} (최소 요구 버전 " . self::MIN_PHP_VERSION . ' 충족)',
                ];
            }

            return [
                'valid' => false,
                'version' => $version,
                'message' => "PHP {$version}은 최소 요구 버전 " . self::MIN_PHP_VERSION . '을 충족하지 않습니다.',
            ];
        }

        return [
            'valid' => false,
            'version' => null,
            'message' => 'PHP 버전을 파싱할 수 없습니다.',
        ];
    }

    /**
     * Composer 실행 명령어를 생성합니다.
     *
     * @param string $phpBinary PHP 바이너리 경로
     * @param string $composerBinary Composer 바이너리 경로 (빈 문자열이면 시스템 PATH 사용)
     * @return string 실행 가능한 Composer 명령어
     */
    public static function buildComposerCommand(string $phpBinary = 'php', string $composerBinary = ''): string
    {
        if ($composerBinary) {
            // 공백 포함 = 전체 실행 명령어 (예: "/usr/local/php84/bin/php /home/user/g7/composer.phar")
            if (str_contains($composerBinary, ' ')) {
                return $composerBinary;
            }

            return str_ends_with($composerBinary, '.phar')
                ? escapeshellarg($phpBinary) . ' ' . escapeshellarg($composerBinary)
                : escapeshellarg($composerBinary);
        }

        return 'composer';
    }
}
