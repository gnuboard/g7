<?php

namespace App\Http\View\Composers\Traits;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Support\AssetUrl;
use App\Support\CssLayerFlattener;
use App\Support\Template\ColorSchemeDeclaration;
use App\Support\Template\ExtensionCssOverrideResolver;
use Illuminate\Support\Facades\Log;

/**
 * 확장 CSS 자기 공급 계약 — 화면 설정 수집 Trait
 *
 * TemplateComposer(관리자)·UserTemplateComposer(사용자)·SEO 렌더가 같은 값을 쓴다.
 *   - `colorScheme`: 활성 템플릿의 다크 방식 선언. 런타임이 `<html data-g7-color-scheme>` 중립 표식으로 옮긴다.
 *   - `extensionCssOverrides`: 활성 템플릿이 선언한 확장 CSS 덮기 파일 중 대상이 활성이고 버전이 맞는 것의 URL.
 *     런타임이 확장 번들 CSS 뒤, 운영자 추가 자산 앞에 붙인다.
 *
 * @property TemplateManager $templateManager
 * @property ModuleManager $moduleManager
 * @property PluginManager $pluginManager
 */
trait CollectsExtensionCssContract
{
    /**
     * 활성 템플릿 매니페스트와 설치 루트를 읽습니다.
     *
     * @param  string|null  $templateIdentifier  템플릿 식별자
     * @return array{0: array<string, mixed>|null, 1: string|null} [매니페스트, 루트]
     */
    private function readTemplateManifestForCssContract(?string $templateIdentifier): array
    {
        if (empty($templateIdentifier)) {
            return [null, null];
        }

        $template = $this->templateManager->getTemplate($templateIdentifier);
        $root = is_array($template) ? ($template['_paths']['root'] ?? null) : null;
        $root ??= base_path("templates/{$templateIdentifier}");

        if (! is_array($template)) {
            $manifestPath = $root.'/template.json';
            $template = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        }

        return [is_array($template) ? $template : null, $root];
    }

    /**
     * 활성 템플릿의 다크 방식 선언을 반환합니다(미선언·오류는 none).
     *
     * @param  string|null  $templateIdentifier  템플릿 식별자
     * @return array{strategy: string, selector?: string} 정규화된 선언
     */
    private function collectColorScheme(?string $templateIdentifier): array
    {
        try {
            [$manifest] = $this->readTemplateManifestForCssContract($templateIdentifier);

            return ColorSchemeDeclaration::fromManifest($manifest);
        } catch (\Throwable $e) {
            Log::warning('Failed to collect template color scheme: '.$e->getMessage());

            return ['strategy' => 'none'];
        }
    }

    /**
     * 화면에 실을 확장 CSS 덮기 파일 URL 목록을 반환합니다.
     *
     * @param  string|null  $templateIdentifier  템플릿 식별자
     * @param  int  $cacheVersion  확장 캐시 버전
     * @return array<int, array{target: string, url: string}> 선언 순서의 서술자
     */
    private function collectExtensionCssOverrides(?string $templateIdentifier, int $cacheVersion): array
    {
        try {
            [$manifest, $root] = $this->readTemplateManifestForCssContract($templateIdentifier);
            if ($manifest === null || $root === null || empty($manifest['extension_css_overrides'])) {
                return [];
            }

            $resolved = (new ExtensionCssOverrideResolver)->resolve(
                (string) $templateIdentifier,
                $manifest,
                $root,
                $this->activeExtensionVersionsForCssContract(),
            );

            return array_map(fn (array $item) => [
                'target' => $item['target'],
                // 정적 게시본은 서빙 가공(층 평탄화)을 거치지 않으므로 API 경로로 고정한다
                'url' => AssetUrl::templateAsset((string) $templateIdentifier, $item['path'], $cacheVersion, allowStatic: false),
            ], $resolved);
        } catch (\Throwable $e) {
            Log::warning('Failed to collect extension CSS overrides: '.$e->getMessage());

            return [];
        }
    }

    /**
     * 활성 확장의 접두 빌드 앞글자(letters) 목록을 반환합니다.
     *
     * 확장은 자기 CSS 를 `{letters}:utility` 클래스로 공급한다. 클래스 문자열을 Tailwind 이름으로 해석하는
     * 코어·템플릿 코드(`G7Core.style.splitExtensionClassPrefix`)가 이 목록으로 앞글자와 변형(`md:` 등)을
     * 가른다. letters 는 `assets.css.namespace` 에서 영문 소문자만 남긴 값이다.
     *
     * @return array<int, string> 앞글자 목록(중복 없음)
     */
    private function collectExtensionClassPrefixes(): array
    {
        try {
            $prefixes = [];
            foreach ([$this->moduleManager->getActiveModules(), $this->pluginManager->getActivePlugins()] as $extensions) {
                foreach ($extensions as $extension) {
                    $namespace = CssLayerFlattener::declaredNamespace($extension->getAssets());
                    $letters = $namespace === null ? '' : (string) preg_replace('/[^a-z]/', '', $namespace);
                    if ($letters !== '') {
                        $prefixes[$letters] = true;
                    }
                }
            }

            return array_keys($prefixes);
        } catch (\Throwable $e) {
            Log::warning('Failed to collect extension class prefixes: '.$e->getMessage());

            return [];
        }
    }

    /**
     * 활성 모듈·플러그인 식별자 → 버전.
     *
     * @return array<string, string> 버전 맵
     */
    private function activeExtensionVersionsForCssContract(): array
    {
        $versions = [];
        foreach ($this->moduleManager->getActiveModules() as $module) {
            $versions[$module->getIdentifier()] = (string) $module->getVersion();
        }
        foreach ($this->pluginManager->getActivePlugins() as $plugin) {
            $versions[$plugin->getIdentifier()] = (string) $plugin->getVersion();
        }

        return $versions;
    }
}
