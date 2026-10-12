<?php

namespace App\Support\Filesystem;

use Symfony\Component\Filesystem\Path;

/**
 * 파일시스템 경로 정리·포함 판정 헬퍼 — OS 와 무관하게 같은 결과를 낸다.
 *
 * 경로를 손으로 쪼개 다시 붙이거나 문자열 접두(`str_starts_with`)로 "이 폴더 안인가" 를 판정하지 않는다.
 * 두 방식 모두 Windows 개발 PC 에서는 멀쩡하고 리눅스 서버에서만 틀렸다(#144):
 *
 *  - 수동 정규화가 빈 조각을 버리면서 리눅스 절대경로의 맨 앞 `/` 가 사라졌다(`/srv/x` → `srv/x`).
 *    Windows 는 첫 조각 `C:` 가 남아 드러나지 않았다.
 *  - 문자열 접두 비교는 폴더 경계를 모른다 — `…/layouts` 기준에 `…/layouts-evil/x` 가 통과했다.
 *
 * 그래서 루트(`/`·`C:/`·UNC `//server`)를 보존하는 {@see Path::canonicalize()} 와 경계를 지키는
 * {@see Path::isBasePath()} 에 위임한다. 산출 경로의 구분자는 항상 `/` 다.
 */
final class PathGuard
{
    /**
     * 경로를 정리합니다 — 구분자 `/` 통일, `.`·`..`·중복 구분자 접기, 루트 보존.
     *
     * 역슬래시는 어느 OS 에서든 구분자로 본다 — 위임 대상은 리눅스에서 역슬래시를 파일명 문자로 남기므로, 같은 입력이
     * OS 마다 다르게 정리되지 않게 먼저 `/` 로 바꾼다(종전 수동 정규화도 두 구분자를 같게 다뤘다).
     * Windows 드라이브 문자는 대문자로 맞춘다(같은 디스크를 `c:`·`C:` 로 다르게 적어도 포함 판정이 어긋나지 않게).
     * UNC 경로(`\\server\share\…`)는 앞의 두 구분자를 지킨다 — 위임 대상은 이를 한 개로 줄인다.
     *
     * @param  string  $path  경로 (상대·절대 무관)
     * @return string 정리된 경로
     */
    public static function canonicalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('#^//(?!/)#', $path) === 1) {
            return '/'.Path::canonicalize(substr($path, 1));
        }

        $canonical = Path::canonicalize($path);

        if (preg_match('#^[a-z]:#', $canonical) === 1) {
            $canonical = strtoupper($canonical[0]).substr($canonical, 1);
        }

        return $canonical;
    }

    /**
     * 실제 경로를 구합니다 — 있으면 `realpath()`, 없으면 가장 가까운 실재 상위 폴더의 실제 경로에 나머지를 잇는다.
     *
     * 아직 없는 파일(후보 경로)도 포함 판정에 쓸 수 있게 실패를 예외로 만들지 않는다. 없는 파일을 원 경로 그대로 두면
     * 실재 기준 폴더(`realpath`)와 모양이 갈라진다 — 심볼릭 링크 아래 설치된 사이트, 드라이브 문자를 생략한 Windows
     * 경로가 그 예다. `..` 는 상위 폴더를 찾기 전에 접는다(종전 수동 정규화와 같은 순서).
     *
     * @param  string  $path  경로
     * @return string 정리된 실제 경로
     */
    public static function resolve(string $path): string
    {
        $real = realpath($path);

        if ($real !== false) {
            return self::canonicalize($real);
        }

        $canonical = self::canonicalize($path);
        $current = $canonical;
        $tail = [];

        while (($parent = self::parentOf($current)) !== '' && $parent !== $current) {
            array_unshift($tail, basename($current));
            $current = $parent;
            $realParent = realpath($current);

            if ($realParent !== false) {
                return self::canonicalize($realParent.'/'.implode('/', $tail));
            }
        }

        return $canonical;
    }

    /**
     * 정리된 경로의 상위 폴더를 구합니다 — UNC 루트(`//서버/공유`)는 지키고 그 위로 올라가지 않는다.
     *
     * 위임 대상은 UNC 의 앞 두 구분자를 한 개로 줄여, 없는 UNC 경로가 현재 드라이브 루트(`C://서버/…`)에 붙어
     * 기준 폴더 밖으로 판정됐다(네트워크 공유에 설치한 Windows 사이트의 누락 조각 관용 실패).
     *
     * @param  string  $path  정리된 경로
     * @return string 상위 폴더 (더 올라갈 수 없으면 자신)
     */
    private static function parentOf(string $path): string
    {
        if (preg_match('#^//[^/]+/[^/]+$#', $path) === 1) {
            return $path;
        }

        if (preg_match('#^//(?!/)#', $path) === 1) {
            return '/'.Path::getDirectory(substr($path, 1));
        }

        return Path::getDirectory($path);
    }

    /**
     * `$path` 가 `$base` 자신이거나 그 아래인지 판정합니다 — 폴더 경계를 지킨다.
     *
     * `isWithin('/srv/x/layouts', '/srv/x/layouts-evil/a')` 는 거짓이다.
     *
     * @param  string  $base  기준 폴더
     * @param  string  $path  판정할 경로
     * @return bool 기준 폴더 자신이거나 그 아래면 true
     */
    public static function isWithin(string $base, string $path): bool
    {
        return Path::isBasePath(self::canonicalize($base), self::canonicalize($path));
    }
}
