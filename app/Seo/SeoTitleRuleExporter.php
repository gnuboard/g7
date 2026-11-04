<?php

namespace App\Seo;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\Log;

/**
 * 화면 제목 규칙(SeoTitleRule)을 브라우저에서 같은 답으로 계산하는 데 필요한 값만 모아 내보냅니다.
 *
 * 사람 화면의 브라우저 탭 제목은 엔진(`resources/js/core/routing/DocumentTitle.ts`)이 화면에 이미 있는 데이터로
 * 계산한다(이동마다 서버에 묻지 않는다). 엔진은 확장 설정을 공개 설정(`frontend_schema` expose)만 받으므로,
 * 제목 계산에 쓰는 값 — 코어 접미사·사이트 이름, 활성 확장의 SEO 변수 선언(`seoVariables()`)·제목/설명 틀
 * (`seo.meta_{page_type}_{title|description}`)·`source: setting`/`core_setting` 변수 값 — 만 따로 싣는다.
 * 공개 설정의 expose 플래그는 넓히지 않는다.
 *
 * 결과는 `window.G7Config.seoTitle` 로 들어간다(사용자 템플릿 셸 `app.blade.php`).
 */
class SeoTitleRuleExporter
{
    /**
     * @param  ModuleManager  $moduleManager  모듈 매니저
     * @param  PluginManager  $pluginManager  플러그인 매니저
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly PluginManager $pluginManager,
    ) {}

    /**
     * 제목 규칙 계산 값을 만듭니다.
     *
     * @return array{suffix: mixed, site_name: mixed, core_settings: array<string, mixed>, extensions: array<string, array>} 엔진 부팅 값
     */
    public function export(): array
    {
        $coreSettings = ['general.site_name' => g7_core_settings('general.site_name', '')];
        $extensions = [];

        foreach (['module' => $this->activeModules(), 'plugin' => $this->activePlugins()] as $type => $instances) {
            foreach ($instances as $identifier => $instance) {
                $entry = $this->exportExtension($type, (string) $identifier, $instance, $coreSettings);
                if ($entry !== null) {
                    $extensions[$type.':'.$identifier] = $entry;
                }
            }
        }

        return [
            'suffix' => g7_core_settings('seo.meta_title_suffix', '') ?? '',
            'site_name' => $coreSettings['general.site_name'] ?? '',
            'core_settings' => (object) $coreSettings,
            'extensions' => (object) $extensions,
        ];
    }

    /**
     * 관리자 화면 탭 제목 계산 값을 만듭니다 — `{화면 제목} - {사이트 이름} 관리자`.
     *
     * 관리자 화면은 검색엔진 규칙(SEO 선언·확장 제목 틀·코어 접미사 설정)을 쓰지 않는다. 사이트 이름과 관리자 접미사
     * (`seo.admin_title_suffix`)만 싣는다. 화면 안에서 언어를 바꿔도 다시 받지 않게 접미사는 지원 언어 전부의 맵이다.
     * 결과는 관리자 셸 `admin.blade.php` 의 `window.G7Config.seoTitle` 로 들어간다.
     *
     * @param  array<int, string>  $locales  지원 언어
     * @return array{suffix: array<string, string>, site_name: mixed} 엔진 부팅 값
     */
    public function exportForAdmin(array $locales): array
    {
        $suffix = [];
        foreach ($locales as $locale) {
            if (is_string($locale) && $locale !== '') {
                $suffix[$locale] = (string) __('seo.admin_title_suffix', [], $locale);
            }
        }

        return [
            'suffix' => $suffix,
            'site_name' => g7_core_settings('general.site_name', '') ?? '',
        ];
    }

    /**
     * 확장 하나의 제목 계산 값을 만듭니다.
     *
     * @param  string  $type  'module' 또는 'plugin'
     * @param  string  $identifier  확장 식별자
     * @param  object  $instance  확장 인스턴스
     * @param  array<string, mixed>  $coreSettings  코어 설정 값 모음 (core_setting 변수 키를 더한다)
     * @return array<string, mixed>|null 계산 값 (선언도 틀도 없으면 null)
     */
    private function exportExtension(string $type, string $identifier, object $instance, array &$coreSettings): ?array
    {
        $variables = [];
        try {
            $variables = method_exists($instance, 'seoVariables') ? (array) $instance->seoVariables() : [];
        } catch (\Throwable $e) {
            Log::warning('[SEO] seoVariables() failed while exporting title rule', ['extension' => $identifier, 'error' => $e->getMessage()]);
        }

        $seoSettings = $type === 'module' ? g7_module_settings($identifier, 'seo', []) : g7_plugin_settings($identifier, 'seo', []);
        $templates = [];
        foreach (is_array($seoSettings) ? $seoSettings : [] as $key => $value) {
            if (is_string($key) && preg_match('/^meta_(.+)_(title|description)$/', $key, $m) === 1) {
                $templates[$m[1]][$m[2]] = $value;
            }
        }

        $settings = [];
        foreach ($variables as $group) {
            foreach (is_array($group) ? $group : [] as $name => $definition) {
                if (! is_array($definition)) {
                    continue;
                }
                $key = (string) ($definition['key'] ?? $name);
                $source = $definition['source'] ?? 'data';
                if ($source === 'setting') {
                    $settings[$key] = $type === 'module'
                        ? g7_module_settings($identifier, $key, '')
                        : g7_plugin_settings($identifier, $key, '');
                } elseif ($source === 'core_setting' && ! array_key_exists($key, $coreSettings)) {
                    $coreSettings[$key] = g7_core_settings($key, '');
                }
            }
        }

        if ($variables === [] && $templates === []) {
            return null;
        }

        return [
            'variables' => (object) $variables,
            'templates' => (object) $templates,
            'settings' => (object) $settings,
        ];
    }

    /**
     * 활성 모듈 인스턴스를 돌려줍니다 (실패하면 빈 배열).
     *
     * @return iterable<string, object> 식별자 → 모듈
     */
    private function activeModules(): iterable
    {
        try {
            return $this->moduleManager->getActiveModules();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Failed to collect active modules for title rule: '.$e->getMessage());

            return [];
        }
    }

    /**
     * 활성 플러그인 인스턴스를 돌려줍니다 (실패하면 빈 배열).
     *
     * @return iterable<string, object> 식별자 → 플러그인
     */
    private function activePlugins(): iterable
    {
        try {
            return $this->pluginManager->getActivePlugins();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Failed to collect active plugins for title rule: '.$e->getMessage());

            return [];
        }
    }
}
