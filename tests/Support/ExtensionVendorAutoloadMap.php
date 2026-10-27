<?php

namespace Tests\Support;

/**
 * PHPUnit 부트스트랩이 등록할 확장 vendor(제3자 composer 패키지) 오토로드 맵을 모은다.
 *
 * `tests/bootstrap.php` 가 이 결과로 `vendorDir` 없는 단일 로더를 만든다(그 이유는 bootstrap 주석).
 * 확장 자기 심볼(`Modules\`·`Plugins\`)은 제외한다 — 확장 코드는 bootstrap 이 `_bundled` 우선으로 따로 등록한다.
 *
 * 부트스트랩 단계(앱 부팅 전)에서 쓰므로 Laravel 헬퍼에 기대지 않는다.
 */
final class ExtensionVendorAutoloadMap
{
    /**
     * 확장 종류마다 vendor 를 찾을 디렉토리 — 앞쪽이 이긴다.
     *
     * `_bundled` 가 먼저다. 시험은 `_bundled` 코드를 검증하므로 그 코드가 기대는 패키지도 `_bundled` vendor 것이어야 한다 —
     * 활성 설치본이 먼저면 낡은 설치본 패키지를 읽거나, 설치·제거 도중 사라진 파일을 include 한다.
     *
     * @param  string  $basePath  프로젝트 루트
     * @param  string  $extensionType  modules 또는 plugins
     * @return array<int, string> 검색 디렉토리
     */
    public static function searchDirs(string $basePath, string $extensionType): array
    {
        return [$basePath.'/'.$extensionType.'/_bundled', $basePath.'/'.$extensionType];
    }

    /**
     * 확장 vendor 의 classmap·PSR-4·PSR-0·files 를 모읍니다.
     *
     * @param  string  $basePath  프로젝트 루트
     * @return array{classmap: array<string, string>, psr4: array<string, array<int, string>>, psr0: array<string, array<int, string>>, files: array<int, string>} 등록할 맵 (PSR 경로는 검색 순서)
     */
    public static function collect(string $basePath): array
    {
        $result = ['classmap' => [], 'psr4' => [], 'psr0' => [], 'files' => []];

        foreach (['modules', 'plugins'] as $extensionType) {
            foreach (self::searchDirs($basePath, $extensionType) as $searchDir) {
                if (! is_dir($searchDir)) {
                    continue;
                }

                foreach (scandir($searchDir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry === '_bundled' || $entry === '_pending') {
                        continue;
                    }

                    self::collectVendor($searchDir.'/'.$entry.'/vendor', $result);
                }
            }
        }

        return $result;
    }

    /**
     * 확장 하나의 vendor 맵을 결과에 더합니다 (먼저 모은 classmap 항목은 덮지 않는다).
     *
     * @param  string  $vendorDir  확장 vendor 디렉토리
     * @param  array{classmap: array<string, string>, psr4: array<string, array<int, string>>, psr0: array<string, array<int, string>>, files: array<int, string>}  $result  누적 결과
     * @return void 반환값 없음
     */
    private static function collectVendor(string $vendorDir, array &$result): void
    {
        $classmapFile = $vendorDir.'/composer/autoload_classmap.php';
        if (! is_dir($vendorDir) || ! file_exists($classmapFile)) {
            return;
        }

        foreach (require $classmapFile as $fqcn => $path) {
            if (! self::isExtensionOwnSymbol($fqcn) && ! isset($result['classmap'][$fqcn])) {
                $result['classmap'][$fqcn] = $path;
            }
        }

        $psr4File = $vendorDir.'/composer/autoload_psr4.php';
        if (file_exists($psr4File)) {
            foreach (require $psr4File as $prefix => $paths) {
                if (! self::isExtensionOwnSymbol($prefix)) {
                    $result['psr4'][$prefix] = array_merge($result['psr4'][$prefix] ?? [], (array) $paths);
                }
            }
        }

        // PSR-0 으로만 등록하는 패키지도 있다(HTMLPurifier). 최적화하지 않은 오토로드 — 번들 vendor
        // 압축본을 풀어 깐 vendor 가 그렇다 — 에서는 classmap 이 비어 있어, 이 맵을 읽지 않으면
        // 그 패키지 클래스가 통째로 오토로드되지 않는다.
        $psr0File = $vendorDir.'/composer/autoload_namespaces.php';
        if (file_exists($psr0File)) {
            foreach (require $psr0File as $prefix => $paths) {
                if (! self::isExtensionOwnSymbol($prefix)) {
                    $result['psr0'][$prefix] = array_merge($result['psr0'][$prefix] ?? [], (array) $paths);
                }
            }
        }

        // 패키지가 요구하는 부트스트랩 파일(HTMLPurifier 의 HTMLPURIFIER_PREFIX 정의 등).
        // vendor 디렉토리 바깥의 항목(확장 자신의 helpers)은 제외한다.
        $filesFile = $vendorDir.'/composer/autoload_files.php';
        if (file_exists($filesFile)) {
            $vendorReal = realpath($vendorDir) ?: $vendorDir;

            foreach (require $filesFile as $file) {
                if (str_starts_with(realpath($file) ?: $file, $vendorReal)) {
                    $result['files'][] = $file;
                }
            }
        }
    }

    /**
     * 확장 자기 심볼인지 판정합니다.
     *
     * @param  string  $symbol  클래스 또는 접두
     * @return bool `Modules\`·`Plugins\` 로 시작하면 true
     */
    private static function isExtensionOwnSymbol(string $symbol): bool
    {
        return str_starts_with($symbol, 'Modules\\') || str_starts_with($symbol, 'Plugins\\');
    }
}
