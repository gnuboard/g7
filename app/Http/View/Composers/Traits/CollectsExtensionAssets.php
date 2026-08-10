<?php

namespace App\Http\View\Composers\Traits;

use App\Extension\Traits\ClearsTemplateCaches;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\Log;

/**
 * 활성 확장(모듈·플러그인)의 프론트엔드 에셋 수집 및 번들 URL 생성.
 *
 * `TemplateComposer`(admin)와 `UserTemplateComposer`(user)가 공유한다. 두 클래스는
 * 이 세 메서드를 주석까지 통째로 중복 보유하고 있었고(123줄), 그래서 한쪽만 고치면
 * 다른 쪽이 조용히 어긋나는 구조였다. 트레이트로 승격해 그 가능성을 제거한다.
 *
 * URL 생성은 전부 `AssetUrl` 을 경유한다 — 자산 URL 이중 모드(확장자 유지/제거)를
 * 한 곳에서 결정하기 위함이다.
 *
 * 사용하는 클래스는 `$moduleManager` / `$pluginManager` 프로퍼티를 가져야 한다.
 *
 * @see AssetUrl
 */
trait CollectsExtensionAssets
{
    /**
     * 확장 병합 번들 URL 을 생성합니다.
     *
     * 병합 번들은 same-origin(`/api/...`)이어야 한다. 외부 origin/CDN 은 GDPR
     * 프리블로커에 자기 차단되므로 호스팅하지 않는다.
     *
     * @param  array<string, mixed>  $moduleAssets  수집된 모듈 개별 에셋 맵
     * @param  array<string, mixed>  $pluginAssets  수집된 플러그인 개별 에셋 맵
     * @param  int  $version  확장 캐시 버전
     * @return array{moduleJs: ?string, moduleCss: ?string, pluginJs: ?string, pluginCss: ?string} 번들 URL 맵
     */
    private function buildExtensionBundleUrls(array $moduleAssets, array $pluginAssets, int $version): array
    {
        $hasJs = static fn (array $assets): bool => ! empty(array_filter($assets, fn ($a) => ! empty($a['js'])));
        $hasCss = static fn (array $assets): bool => ! empty(array_filter($assets, fn ($a) => ! empty($a['css'])));

        return [
            'moduleJs' => $hasJs($moduleAssets) ? AssetUrl::extensionBundle('modules', 'js', $version) : null,
            'moduleCss' => $hasCss($moduleAssets) ? AssetUrl::extensionBundle('modules', 'css', $version) : null,
            'pluginJs' => $hasJs($pluginAssets) ? AssetUrl::extensionBundle('plugins', 'js', $version) : null,
            'pluginCss' => $hasCss($pluginAssets) ? AssetUrl::extensionBundle('plugins', 'css', $version) : null,
        ];
    }

    /**
     * 활성화된 모듈의 프론트엔드 에셋 정보를 수집합니다.
     *
     * @return array<string, array{js?: string, css?: string, priority: int, external?: array}> 식별자별 에셋 맵
     */
    private function collectModuleAssets(): array
    {
        return $this->collectExtensionAssets(
            'modules',
            fn () => $this->moduleManager->getActiveModules(),
            'Failed to collect module assets: '
        );
    }

    /**
     * 활성화된 플러그인의 프론트엔드 에셋 정보를 수집합니다.
     *
     * @return array<string, array{js?: string, css?: string, priority: int, external?: array}> 식별자별 에셋 맵
     */
    private function collectPluginAssets(): array
    {
        return $this->collectExtensionAssets(
            'plugins',
            fn () => $this->pluginManager->getActivePlugins(),
            'Failed to collect plugin assets: '
        );
    }

    /**
     * 확장 에셋 수집 공통 구현.
     *
     * 모듈/플러그인은 수집 대상 조회 방법과 로그 문구만 다르고 나머지 로직이 동일하다.
     *
     * @param  string  $type  `modules` 또는 `plugins`
     * @param  callable  $activeResolver  활성 확장 목록을 반환하는 콜백
     * @param  string  $logPrefix  실패 시 경고 로그 접두사
     * @return array<string, array{js?: string, css?: string, priority: int, external?: array}> 식별자별 에셋 맵
     */
    private function collectExtensionAssets(string $type, callable $activeResolver, string $logPrefix): array
    {
        $assets = [];

        try {
            $active = $activeResolver();

            // 캐시 버전 조회 (브라우저 캐시 무효화용)
            $cacheVersion = ClearsTemplateCaches::getExtensionCacheVersion();

            foreach ($active as $identifier => $extension) {
                // 확장에 에셋이 있는지 확인
                if (! $extension->hasAssets()) {
                    continue;
                }

                $builtPaths = $extension->getBuiltAssetPaths();
                $loadingConfig = $extension->getAssetLoadingConfig();
                $assetConfig = $extension->getAssets();

                // global 전략인 경우에만 수집 (layout, lazy는 레이아웃에서 처리)
                if ($loadingConfig['strategy'] !== 'global') {
                    continue;
                }

                $asset = [
                    'priority' => $loadingConfig['priority'],
                ];

                // JS 빌드 경로 (캐시 버전 파라미터 추가)
                if (! empty($builtPaths['js'])) {
                    $asset['js'] = AssetUrl::extensionAsset($type, $identifier, $builtPaths['js'], $cacheVersion);
                }

                // CSS 빌드 경로 (캐시 버전 파라미터 추가)
                if (! empty($builtPaths['css'])) {
                    $asset['css'] = AssetUrl::extensionAsset($type, $identifier, $builtPaths['css'], $cacheVersion);
                }

                // 외부 스크립트 (조건부 로드용)
                if (! empty($assetConfig['external'])) {
                    $asset['external'] = $assetConfig['external'];
                }

                $assets[$identifier] = $asset;
            }

            // 우선순위 기준 정렬 (낮을수록 먼저)
            uasort($assets, fn ($a, $b) => $a['priority'] <=> $b['priority']);
        } catch (\Exception $e) {
            // 에러 발생 시 빈 배열 반환 (에셋 로드 실패해도 앱 진행)
            Log::warning($logPrefix.$e->getMessage());
        }

        return $assets;
    }
}
