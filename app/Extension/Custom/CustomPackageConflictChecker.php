<?php

namespace App\Extension\Custom;

use Composer\Semver\Semver;

/**
 * custom 전용 composer(`custom/composer.lock`) 패키지가 코어 패키지와 맞는지 정적으로 판정합니다.
 *
 * composer 를 실행하지 않는다 — composer 가 없는 서버(vendor 묶음 모드)에서도 같은 판정을 한다.
 * 실행 때는 코어 오토로더가 먼저 등록되어 같은 이름 패키지는 코어 쪽 클래스가 잡히므로, 두 가지를
 * 미리 막는다.
 *
 *  1. 제약 불만족 — custom 패키지가 요구하는 패키지가 코어에 있는데, 코어가 가진 버전이 그 요구 제약을
 *     만족하지 않는다.
 *  2. 같은 패키지 다른 버전 — 같은 이름 패키지가 코어와 custom 양쪽에 다른 버전으로 있다.
 *
 * @since 7.2.0
 */
class CustomPackageConflictChecker
{
    /** 충돌 종류: 요구 제약 불만족 */
    public const TYPE_CONSTRAINT = 'constraint';

    /** 충돌 종류: 같은 패키지 다른 버전 */
    public const TYPE_DUPLICATE = 'duplicate';

    /**
     * custom lock 파일 경로를 돌려줍니다.
     *
     * @return string `custom/composer.lock` 절대 경로
     */
    public static function customLockPath(): string
    {
        return base_path('custom'.DIRECTORY_SEPARATOR.'composer.lock');
    }

    /**
     * 코어 lock 과 custom lock 파일을 읽어 충돌 목록을 돌려줍니다.
     *
     * @param  string  $coreLockPath  코어 composer.lock 경로(새 코어 배포본 또는 현재 설치본)
     * @param  string|null  $customLockPath  custom composer.lock 경로(null 이면 기본 위치)
     * @return array<int, array{package:string, installed:string, type:string, requires:string, constraint:string, core_package:string, core_version:string}>
     */
    public function checkFiles(string $coreLockPath, ?string $customLockPath = null): array
    {
        $customLock = self::readLock($customLockPath ?? self::customLockPath());
        if ($customLock === null) {
            return [];
        }

        $coreLock = self::readLock($coreLockPath);
        if ($coreLock === null) {
            return [];
        }

        return $this->check($coreLock, $customLock);
    }

    /**
     * 두 lock 배열을 비교해 충돌 목록을 돌려줍니다.
     *
     * @param  array<string, mixed>  $coreLock  코어 composer.lock 내용
     * @param  array<string, mixed>  $customLock  custom composer.lock 내용
     * @return array<int, array{package:string, installed:string, type:string, requires:string, constraint:string, core_package:string, core_version:string}>
     */
    public function check(array $coreLock, array $customLock): array
    {
        $coreVersions = self::packageVersions($coreLock);
        $conflicts = [];

        foreach ((array) ($customLock['packages'] ?? []) as $package) {
            $name = strtolower((string) ($package['name'] ?? ''));
            $version = (string) ($package['version'] ?? '');
            if ($name === '') {
                continue;
            }

            if (isset($coreVersions[$name]) && self::normalizeVersion($coreVersions[$name]) !== self::normalizeVersion($version)) {
                $conflicts[] = [
                    'package' => $name,
                    'installed' => $version,
                    'type' => self::TYPE_DUPLICATE,
                    'requires' => $name,
                    'constraint' => '',
                    'core_package' => $name,
                    'core_version' => $coreVersions[$name],
                ];
            }

            foreach ((array) ($package['require'] ?? []) as $required => $constraint) {
                $required = strtolower((string) $required);
                if (! isset($coreVersions[$required]) || self::isPlatformPackage($required)) {
                    continue;
                }
                if (! self::satisfies($coreVersions[$required], (string) $constraint)) {
                    $conflicts[] = [
                        'package' => $name,
                        'installed' => $version,
                        'type' => self::TYPE_CONSTRAINT,
                        'requires' => $required,
                        'constraint' => (string) $constraint,
                        'core_package' => $required,
                        'core_version' => $coreVersions[$required],
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * custom lock 의 패키지 이름·버전 목록을 돌려줍니다.
     *
     * @param  string|null  $customLockPath  custom composer.lock 경로(null 이면 기본 위치)
     * @return array<string, string> 패키지 이름 => 버전
     */
    public static function customPackages(?string $customLockPath = null): array
    {
        $lock = self::readLock($customLockPath ?? self::customLockPath());

        return $lock === null ? [] : self::packageVersions($lock);
    }

    /**
     * composer.lock 파일을 읽습니다.
     *
     * @param  string  $path  lock 파일 경로
     * @return array<string, mixed>|null 내용, 없거나 깨졌으면 null
     */
    public static function readLock(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * lock 의 운영 패키지(`packages`) 이름 => 버전 맵을 만듭니다.
     *
     * @param  array<string, mixed>  $lock  composer.lock 내용
     * @return array<string, string> 패키지 이름 => 버전
     */
    private static function packageVersions(array $lock): array
    {
        $out = [];
        foreach ((array) ($lock['packages'] ?? []) as $package) {
            $name = strtolower((string) ($package['name'] ?? ''));
            if ($name !== '') {
                $out[$name] = (string) ($package['version'] ?? '');
            }
        }

        return $out;
    }

    /**
     * 버전이 제약을 만족하는지 판정합니다. 판정할 수 없는 버전(dev 브랜치 등)은 만족으로 본다.
     *
     * @param  string  $version  버전
     * @param  string  $constraint  제약
     * @return bool 만족하면 true
     */
    private static function satisfies(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        // 브랜치 버전(dev-main·1.x-dev)은 정확한 판정이 안 된다 — 막지 않고 통과시킨다
        if (str_starts_with(strtolower($version), 'dev-') || str_ends_with(strtolower($version), '-dev')) {
            return true;
        }

        try {
            return Semver::satisfies($version, $constraint);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * 비교용 버전 문자열(앞 `v` 제거)을 만듭니다.
     *
     * @param  string  $version  버전
     * @return string 정규화된 버전
     */
    private static function normalizeVersion(string $version): string
    {
        return ltrim(strtolower(trim($version)), 'v');
    }

    /**
     * php·확장·composer 플러그인 API 같은 플랫폼 요구인지 판정합니다.
     *
     * @param  string  $name  요구 이름
     * @return bool 플랫폼 요구면 true
     */
    private static function isPlatformPackage(string $name): bool
    {
        return $name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')
            || $name === 'composer-plugin-api' || $name === 'composer-runtime-api';
    }
}
