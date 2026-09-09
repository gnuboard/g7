<?php

namespace App\Support;

/**
 * vendor 디렉토리가 개발용(require-dev 포함) 설치인지 판정하는 유틸리티.
 *
 * `composer install`(옵션 없음)은 require-dev 와 그 전이 의존성까지 설치하고, 그 목록이
 * `bootstrap/cache/packages.php` 에 provider 로 등재된다. 운영 사이트가 그 상태로 남으면
 * 이후 코어 업데이트가 vendor 를 `--no-dev` 로 교체할 때 매니페스트에만 남은 provider 를
 * 찾다 부팅이 깨진다. 그래서 인스톨러와 코어 업데이트가 이 판정으로 운영자에게 알린다.
 *
 * 프레임워크에 의존하지 않는다 — 인스톨러는 Laravel 오토로드 없이 도는 순수 PHP 라
 * 이 클래스를 `require_once` 로 직접 읽는다.
 */
final class ComposerInstallInfo
{
    /**
     * vendor 디렉토리 기준 Composer 설치 정보 파일의 상대 경로
     */
    public const INSTALLED_JSON = 'composer/installed.json';

    /**
     * vendor 디렉토리의 Composer 설치 구성을 조사합니다.
     *
     * 판정: `installed.json` 의 최상위 `dev` 가 true 이거나 `dev-package-names` 가 비어 있지
     * 않으면 개발용 설치. 두 신호를 OR 로 보는 이유는 Composer 버전에 따라 한쪽만 채워질 수
     * 있기 때문이다.
     *
     * @param  string  $vendorPath  vendor 디렉토리 절대 경로
     * @return array{dev: bool|null, packages: array<int, string>} dev 가 null 이면 판정 불가
     *                                                             (파일 부재 · JSON 오류 · Composer 1 형식)
     */
    public static function inspect(string $vendorPath): array
    {
        $unknown = ['dev' => null, 'packages' => []];

        $path = rtrim($vendorPath, '/\\').'/'.self::INSTALLED_JSON;
        if (! is_file($path)) {
            return $unknown;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $unknown;
        }

        $decoded = json_decode($raw, true);

        // Composer 1 은 최상위가 패키지 배열이라 dev 정보 자체가 없다 — 판정 불가로 둔다.
        if (! is_array($decoded) || ! array_key_exists('packages', $decoded)) {
            return $unknown;
        }

        $names = [];
        if (isset($decoded['dev-package-names']) && is_array($decoded['dev-package-names'])) {
            foreach ($decoded['dev-package-names'] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[] = $name;
                }
            }
        }

        $devFlag = $decoded['dev'] ?? null;

        return [
            'dev' => $devFlag === true || $names !== [],
            'packages' => $names,
        ];
    }

    /**
     * vendor 가 개발용 설치인지 반환합니다.
     *
     * @param  string  $vendorPath  vendor 디렉토리 절대 경로
     * @return bool|null 판정 불가 시 null
     */
    public static function isDevInstall(string $vendorPath): ?bool
    {
        return self::inspect($vendorPath)['dev'];
    }

    /**
     * vendor 에 설치된 개발용(require-dev) 패키지 이름 목록을 반환합니다.
     *
     * @param  string  $vendorPath  vendor 디렉토리 절대 경로
     * @return array<int, string> 개발용 패키지가 없거나 판정 불가면 빈 배열
     */
    public static function devPackageNames(string $vendorPath): array
    {
        return self::inspect($vendorPath)['packages'];
    }
}
