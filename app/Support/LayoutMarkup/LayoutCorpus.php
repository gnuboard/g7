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
     * [AbstractModule::getLayoutExtensionsPath()](app/Extension/AbstractModule.php#L905) ·
     * [AbstractPlugin::getLayoutExtensionsPath()](app/Extension/AbstractPlugin.php#L750) ·
     * [TemplateManager](app/Extension/TemplateManager.php#L2162).
     *
     * @return array<int, string> 글롭 패턴 목록
     */
    public static function roots(): array
    {
        return [
            base_path('templates/_bundled/*/layouts'),
            base_path('modules/_bundled/*/resources/layouts'),
            base_path('plugins/_bundled/*/resources/layouts'),
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
        $needle = strtolower($suffix);
        $files = [];

        foreach (self::roots() as $glob) {
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
