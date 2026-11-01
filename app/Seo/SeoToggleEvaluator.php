<?php

namespace App\Seo;

/**
 * 레이아웃 SEO 토글(meta.seo.toggle_setting) 판정기
 *
 * 봇 화면(SeoRenderer)과 사이트맵 정적 화면(SitemapGenerator)이 같은 기준으로 「이 화면의 SEO 가 켜져 있는가」를
 * 판정하도록 한 곳에 둡니다. 확장 설정 키(`$module_settings:`·`$plugin_settings:`)의 식별자 해석도 여기서 합니다.
 */
class SeoToggleEvaluator
{
    /**
     * 확장(모듈/플러그인) SEO가 활성화되어 있는지 확인합니다.
     *
     * 레이아웃 meta.seo.toggle_setting 선언 기반으로 판단합니다.
     * $module_settings:MODULE_ID:key 형식으로 명시적 모듈 지정도 지원합니다.
     * toggle_setting 미선언 시 무조건 활성화됩니다.
     *
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @param  string|null  $pluginIdentifier  플러그인 식별자
     * @param  array  $seoConfig  레이아웃 meta.seo 설정
     * @return bool 활성화 여부
     */
    public function isEnabled(?string $moduleIdentifier, ?string $pluginIdentifier, array $seoConfig): bool
    {
        $toggleSetting = $seoConfig['toggle_setting'] ?? null;
        if (! $toggleSetting) {
            return true;
        }

        // $module_settings:key 또는 $module_settings:module-id:key 접두사 해석
        if (str_starts_with($toggleSetting, '$module_settings:')) {
            $rest = substr($toggleSetting, strlen('$module_settings:'));
            [$effectiveModuleId, $key] = $this->parseExtensionSettingsKey($rest, $moduleIdentifier);
            if ($effectiveModuleId) {
                return (bool) g7_module_settings($effectiveModuleId, $key, true);
            }

            return true;
        }

        // $plugin_settings:key 또는 $plugin_settings:plugin-id:key 접두사 해석
        if (str_starts_with($toggleSetting, '$plugin_settings:')) {
            $rest = substr($toggleSetting, strlen('$plugin_settings:'));
            [$effectivePluginId, $key] = $this->parseExtensionSettingsKey($rest, $pluginIdentifier);
            if ($effectivePluginId) {
                return (bool) g7_plugin_settings($effectivePluginId, $key, true);
            }

            return true;
        }

        // $core_settings:key 접두사 해석
        if (str_starts_with($toggleSetting, '$core_settings:')) {
            $key = substr($toggleSetting, strlen('$core_settings:'));

            return (bool) g7_core_settings($key, true);
        }

        return true;
    }

    /**
     * 확장 설정 키를 파싱합니다.
     *
     * 'key.path' 형식이면 컨텍스트 식별자를 사용하고,
     * 'extension-id:key.path' 형식이면 명시된 확장 식별자를 사용합니다.
     * 템플릿 레벨 레이아웃에서 모듈/플러그인 설정을 참조할 때 명시적 ID가 필요합니다.
     *
     * @param  string  $rest  접두사 제거 후 나머지 문자열
     * @param  string|null  $contextIdentifier  라우트 컨텍스트에서 추출한 식별자
     * @return array{0: string|null, 1: string} [식별자, 설정 키]
     */
    public function parseExtensionSettingsKey(string $rest, ?string $contextIdentifier): array
    {
        // 'extension-id:key.path' 형식 — 명시적 확장 ID 포함
        if (str_contains($rest, ':')) {
            [$explicitId, $key] = explode(':', $rest, 2);

            return [$explicitId, $key];
        }

        // 'key.path' 형식 — 컨텍스트 식별자 사용
        return [$contextIdentifier, $rest];
    }
}
