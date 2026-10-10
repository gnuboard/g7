<?php

namespace App\Http\View\Composers\Traits;

use App\Extension\TemplateManager;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 자체 CSS 링크 수집 Trait
 *
 * TemplateComposer 와 UserTemplateComposer 가 화면 HTML 에 넣을 템플릿 CSS 경로를 모은다.
 * 템플릿 매니페스트(`template.json`)의 `assets.css` 가 선언한 파일 가운데 설치본에 실재하는
 * 것만 싣는다 — 봇 화면(SeoRenderer)과 같은 기준이다.
 *
 * 종전에는 두 화면이 템플릿과 무관하게 `{템플릿}?file=css/components.css` 를 늘 링크해, CSS 를 선언하지
 * 않은 템플릿에서 모든 화면이 `GET /api/templates/assets/{id}?file=css/components.css → 422`
 * 를 남겼다.
 *
 * @property TemplateManager $templateManager
 */
trait CollectsTemplateStylesheets
{
    /**
     * 활성 템플릿이 선언하고 산출물이 있는 CSS 의 서빙 경로 목록을 반환합니다.
     *
     * 서빙 경로는 `dist/` 접두를 뗀 형태다(예: `templates/_bundled/sirsoft-basic/dist/css/components.css` → `?file=css/components.css`).
     * 자산 라우트가 `dist/` 이하만 서빙하므로 `AssetUrl::templateAsset()` 에 그대로 넘긴다.
     *
     * @param  string|null  $templateIdentifier  템플릿 식별자
     * @return array<int, string> 서빙 경로 목록 (선언 순서)
     */
    private function collectTemplateStylesheets(?string $templateIdentifier): array
    {
        if (empty($templateIdentifier)) {
            return [];
        }

        try {
            $template = $this->templateManager->getTemplate($templateIdentifier);
            $root = $template['_paths']['root'] ?? base_path("templates/{$templateIdentifier}");

            if (! is_array($template)) {
                $manifestPath = $root.'/template.json';
                $template = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
            }

            $declared = is_array($template) ? ($template['assets']['css'] ?? []) : [];
            if (! is_array($declared)) {
                return [];
            }

            $stylesheets = [];
            foreach ($declared as $cssPath) {
                // 선언만 있고 산출물이 없는 경로를 링크하면 요청 실패만 남는다
                if (! is_string($cssPath) || $cssPath === '' || ! is_file($root.'/'.$cssPath)) {
                    continue;
                }

                $stylesheets[] = preg_replace('#^dist/#', '', $cssPath);
            }

            return $stylesheets;
        } catch (\Throwable $e) {
            Log::warning('Failed to collect template stylesheets: '.$e->getMessage());

            return [];
        }
    }
}
