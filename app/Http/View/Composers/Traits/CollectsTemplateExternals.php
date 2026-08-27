<?php

namespace App\Http\View\Composers\Traits;

use App\Extension\TemplateManager;
use App\Support\TemplateExternals;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 외부 리소스 수집 Trait
 *
 * TemplateComposer와 UserTemplateComposer에서 공통으로 사용하는
 * externals manifest 수집/정규화 로직을 제공합니다.
 *
 * @property TemplateManager $templateManager
 */
trait CollectsTemplateExternals
{
    /**
     * 템플릿의 외부 리소스 정보를 수집합니다.
     *
     * template.json의 externals 배열만 사용하며 legacy key fallback은 제공하지 않습니다.
     *
     * 항목이 `asset`(템플릿이 자체 제공하는 `dist/` 이하 경로)을 선언하면 식별자와
     * 캐시 버전으로 자산 URL 을 만든다 — 그래서 둘을 함께 받는다.
     *
     * @param  string|null  $templateIdentifier  템플릿 식별자
     * @param  int|string|null  $version  캐시 무효화 버전 (`asset` 항목의 `?v`)
     * @return array<int, array<string, mixed>>
     */
    private function collectTemplateExternals(?string $templateIdentifier, int|string|null $version = null): array
    {
        if (empty($templateIdentifier)) {
            return [];
        }

        try {
            $template = $this->templateManager->getTemplate($templateIdentifier);

            if (! $template || empty($template['externals']) || ! is_array($template['externals'])) {
                return [];
            }

            return TemplateExternals::normalize($template['externals'], $templateIdentifier, $version);
        } catch (\Exception $e) {
            Log::warning('Failed to collect template externals: '.$e->getMessage());

            return [];
        }
    }
}
