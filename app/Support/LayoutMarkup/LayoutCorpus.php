<?php

declare(strict_types=1);

namespace App\Support\LayoutMarkup;

/**
 * 번들 레이아웃 코퍼스의 단일 정의(SSoT).
 *
 * 왕복 하네스·`<json>` 예산 계량·이관 게이트가 **같은 모집단**을 봐야 한다 —
 * 스캔 글롭이 곳곳에 복제되면 한쪽만 넓힐 때 나머지가 조용히 반쪽 코퍼스로 남고,
 * 그 위에서 "escape-0" 같은 완료 판정이 나온다(실제 발생: fragment 42건이 전 웨이브에 걸쳐 계량 밖이었다).
 *
 * 모집단 = 본 레이아웃(`layouts/`) + 레이아웃 확장 fragment(`extensions/`). 설치 파이프라인이
 * 둘 다 DB 에 등록하므로 둘 다 이관 대상이다 —
 * [ValidatesLayoutFiles](app/Extension/Traits/ValidatesLayoutFiles.php) ↔
 * [RefreshesLayoutExtensions](app/Extension/Traits/RefreshesLayoutExtensions.php).
 */
final class LayoutCorpus
{
    /**
     * 코퍼스 루트 디렉토리 글롭.
     *
     * fragment 경로는 설치 경로의 조립 지점과 일치시킨다 —
     * [AbstractModule::getExtensionsPath()](app/Extension/AbstractModule.php) ·
     * [AbstractPlugin::getExtensionsPath()](app/Extension/AbstractPlugin.php) ·
     * [TemplateManager](app/Extension/TemplateManager.php).
     *
     * @return array<int, string> 글롭 패턴 목록
     */
    public static function roots(): array
    {
        return [...self::layoutRoots(), ...self::fragmentRoots()];
    }

    /**
     * 본 레이아웃(`layouts/`) 루트 글롭.
     *
     * @return array<int, string> 글롭 패턴 목록
     */
    public static function layoutRoots(): array
    {
        return [
            base_path('templates/_bundled/*/layouts'),
            base_path('modules/_bundled/*/resources/layouts'),
            base_path('plugins/_bundled/*/resources/layouts'),
        ];
    }

    /**
     * 레이아웃 확장 fragment(`extensions/`) 루트 글롭.
     *
     * **세 유형 전부**다 — 템플릿도 fragment 를 낸다
     * ([TemplateManager](app/Extension/TemplateManager.php#L2162)). 모듈·플러그인 둘만 훑으면
     * 템플릿 fragment 가 통째로 게이트 밖에 남는다(㉘ 이 등재한 사각).
     *
     * @return array<int, string> 글롭 패턴 목록
     */
    public static function fragmentRoots(): array
    {
        return [
            base_path('templates/_bundled/*/extensions'),
            base_path('modules/_bundled/*/resources/extensions'),
            base_path('plugins/_bundled/*/resources/extensions'),
        ];
    }

    /**
     * 코퍼스에서 주어진 확장자의 파일을 전량 스캔합니다.
     *
     * @param  string  $suffix  파일 접미사 (예: `.json`, `.g7.html`)
     * @return array<int, string> 정렬된 절대 경로 목록
     */
    public static function scan(string $suffix): array
    {
        return self::scanRoots(self::roots(), $suffix);
    }

    /**
     * fragment 루트만 스캔합니다 (설치 경로가 본 레이아웃과 다른 축이라 따로 게이트한다).
     *
     * @param  string  $suffix  파일 접미사 (예: `.json`, `.g7.html`)
     * @return array<int, string> 정렬된 절대 경로 목록
     */
    public static function scanFragments(string $suffix): array
    {
        return self::scanRoots(self::fragmentRoots(), $suffix);
    }

    /**
     * 본 레이아웃 루트만 스캔합니다 (fragment 는 제외).
     *
     * 문서 최상위 키(`permissions` 등)를 다루는 소비처는 fragment 를 대상으로 하지 않는다 —
     * fragment 는 다른 봉투이고 조각의 루트는 노드다. 그런 소비처가 `scan()` 을 쓰고 자기 힘으로
     * 걸러 내면 그 필터가 곧 사본이 되므로, 축을 여기서 나눠 준다({@see scanFragments()} 대칭).
     *
     * @param  string  $suffix  파일 접미사 (예: `.json`, `.g7.html`)
     * @return array<int, string> 정렬된 절대 경로 목록
     */
    public static function scanLayouts(string $suffix): array
    {
        return self::scanRoots(self::layoutRoots(), $suffix);
    }

    /**
     * 주어진 루트 글롭 집합을 재귀 스캔합니다.
     *
     * @param  array<int, string>  $roots  글롭 패턴 목록
     * @param  string  $suffix  파일 접미사
     * @return array<int, string> 정렬된 절대 경로 목록
     */
    private static function scanRoots(array $roots, string $suffix): array
    {
        $needle = strtolower($suffix);
        $files = [];

        foreach ($roots as $glob) {
            foreach (glob($glob, GLOB_ONLYDIR) ?: [] as $root) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                );

                foreach ($iterator as $entry) {
                    if ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), $needle)) {
                        $files[] = $entry->getPathname();
                    }
                }
            }
        }

        sort($files);

        return $files;
    }
}
