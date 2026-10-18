<?php

namespace App\Extension\Helpers;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 코어 업데이트가 덮지도 지우지도 않는 「custom 예약 자리」 판정.
 *
 * 개발자가 라라벨 기본 위치(`routes/custom.php`·`resources/views/custom` 등)에 둔 파일은 그 폴더가
 * 코어 업데이트 대상(`routes`·`resources`…)의 안쪽이라도 그대로 남아야 한다. 코어는 이 자리에
 * 파일을 내놓지 않으며, 배포본에 이 자리 파일이 실리면 업데이트를 시작하지 않는다(배포 사고 차단).
 *
 * 목록은 `config('app.update.reserved_paths')` 가 SSoT 다(env `G7_UPDATE_RESERVED_PATHS`).
 *
 * @since 7.2.0
 */
class UpdateReservedPaths
{
    /**
     * 정규화된 예약 경로 목록을 돌려줍니다 (base 상대, 슬래시 구분, 앞뒤 슬래시 없음).
     *
     * @return array<int, string> 예약 경로 목록
     */
    public static function all(): array
    {
        $out = [];
        foreach ((array) config('app.update.reserved_paths', []) as $path) {
            $normalized = self::normalize((string) $path);
            if ($normalized !== '' && ! in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * base 상대 경로가 예약 경로와 같거나 그 하위인지 판정합니다.
     *
     * @param  string  $relative  base 상대 경로
     * @return bool 예약 자리면 true
     */
    public static function contains(string $relative): bool
    {
        $relative = self::normalize($relative);
        if ($relative === '') {
            return false;
        }

        foreach (self::all() as $reserved) {
            if ($relative === $reserved || str_starts_with($relative, $reserved.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 기존 skipPaths 에 예약 경로를 합쳐 돌려줍니다 (중복 제거).
     *
     * @param  array<int, string>  $skipPaths  base 상대 skipPaths
     * @return array<int, string> 예약 경로가 더해진 목록
     */
    public static function mergeInto(array $skipPaths): array
    {
        $merged = [];
        foreach (array_merge($skipPaths, self::all()) as $path) {
            $normalized = self::normalize((string) $path);
            if ($normalized !== '' && ! in_array($normalized, $merged, true)) {
                $merged[] = $normalized;
            }
        }

        return $merged;
    }

    /**
     * 업데이트 소스(배포본) 안에서 예약 자리에 실린 파일을 찾습니다.
     *
     * 코어가 예약 자리에 파일을 실으면 개발자 파일과 충돌하므로 업데이트를 시작하지 않는다.
     * 빈 디렉터리는 파일이 아니므로 세지 않는다.
     *
     * @param  string  $sourceRoot  배포본 루트(_pending 추출본 또는 --source 디렉터리)
     * @param  int  $limit  최대 수집 개수
     * @return array<int, string> 예약 자리에 있는 배포본 파일(base 상대)
     */
    public static function findInSource(string $sourceRoot, int $limit = 50): array
    {
        $found = [];
        $root = rtrim($sourceRoot, '/\\');

        foreach (self::all() as $reserved) {
            $abs = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $reserved);
            if (is_file($abs)) {
                $found[] = $reserved;
            } elseif (is_dir($abs)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $item) {
                    if ($item->isFile()) {
                        $found[] = $reserved.'/'.self::normalize(substr($item->getPathname(), strlen($abs)));
                    }
                    if (count($found) >= $limit) {
                        break;
                    }
                }
            }
            if (count($found) >= $limit) {
                break;
            }
        }

        return array_slice($found, 0, $limit);
    }

    /**
     * zip 배포본의 항목 이름 목록에서 예약 자리 파일을 찾습니다 (풀지 않고 판정).
     *
     * zip 은 최상위 폴더 하나로 감싸여 있을 수 있어(`g7-7.2.0/routes/custom.php`) 첫 구간을 벗긴
     * 경로도 함께 본다.
     *
     * @param  string  $zipPath  zip 파일 경로
     * @param  int  $limit  최대 수집 개수
     * @return array<int, string>|null 예약 자리 파일 목록, zip 을 열지 못하면 null
     */
    public static function findInZip(string $zipPath, int $limit = 50): ?array
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $found = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $normalized = self::normalize($name);
                $stripped = str_contains($normalized, '/') ? substr($normalized, strpos($normalized, '/') + 1) : $normalized;
                foreach ([$normalized, $stripped] as $candidate) {
                    if (self::contains($candidate)) {
                        $found[] = $candidate;
                        break;
                    }
                }
                if (count($found) >= $limit) {
                    break;
                }
            }
        } finally {
            $zip->close();
        }

        return $found;
    }

    /**
     * 경로를 슬래시 구분·앞뒤 슬래시 없는 형태로 정규화합니다.
     *
     * @param  string  $path  경로
     * @return string 정규화된 경로
     */
    private static function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', trim($path)), '/');
    }
}
