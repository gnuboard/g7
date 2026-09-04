<?php

namespace App\Support\ExtensionDoc;

use App\Extension\Traits\CollectsFragmentSources;
use Illuminate\Support\Facades\File;

/**
 * 확장 조각(fragment) 소스 파일 목록 수집기 — 등록 경로와 같은 규약으로 고른다.
 *
 * 문서 생성기가 조각 목록을 `.json` 글롭으로 직접 만들면 `.g7.html` 만 있는 조각이 문서에서 조용히
 * 빠지고, 소스 SSoT 가 `.g7.html` 인데 문서는 `.json` 을 부른다. 선택 규약은
 * {@see CollectsFragmentSources} 한 곳에 있으므로 여기서는 그것을 호출만 한다.
 *
 * 이 클래스는 **읽지 않는다** — 파싱이 필요한 자리(대상 레이아웃·확장점)는
 * {@see ExtensionDocScaffolder} 가 `loadFragmentDefinitions()` 로 규약에 위임한다. 선택과 읽기를 한
 * 파일에 두면 그 파일의 다른 JSON 파싱(라우트·편집기 스펙)이 조각 읽기의 사본으로 오인된다.
 */
class FragmentSourceInventory
{
    use CollectsFragmentSources;

    /**
     * 조각 루트 아래의 소스 파일(절대 경로)을 규약대로 고릅니다 — `.g7.html`(있으면 우선) + 짝이 없는 `.json`.
     *
     * 템플릿의 오버라이드 조각은 `extensions/{확장식별자}/` 아래에 있으므로 디렉토리마다 규약을 적용한다.
     *
     * @param  string  $root  조각 루트 절대 경로
     * @return array<int, string> 소스 절대 경로 목록
     */
    public function sourceFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $jsonByDir = [];
        foreach (File::allFiles($root) as $file) {
            $name = $file->getFilename();
            if (! str_ends_with($name, '.g7.html') && ! str_ends_with($name, '.json')) {
                continue;
            }
            $jsonByDir[$file->getPath()] ??= [];
            if (str_ends_with($name, '.json')) {
                $jsonByDir[$file->getPath()][] = $file->getPathname();
            }
        }

        $files = [];
        foreach ($jsonByDir as $directory => $jsonFiles) {
            foreach ($this->collectFragmentSources($directory, $jsonFiles) as $source) {
                $files[] = $source['path'];
            }
        }

        return $files;
    }
}
