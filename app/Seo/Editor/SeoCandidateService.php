<?php

namespace App\Seo\Editor;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;

/**
 * SEO 후보 수집 서비스 — 레이아웃 편집기 [검색엔진] 탭 후보 공급.
 *
 * page_type 은 코어 고정 enum 이 아니라 확장이 `seoVariables()` 키로 자유 정의하는 열린
 * 문자열이므로, 편집기 드롭다운 후보를 **활성 모듈/플러그인에서 런타임 수집**한다.
 * 세 가지 후보를 한 번에 공급:
 *
 *  1. page_type 후보 — 활성 확장 `seoVariables()` 키(`_common` 제외) + 각 page_type 별
 *     `seoStructuredData($pageType)` 의 `@type`(구조화 "모듈 제공" 표시 근거).
 *  2. toggle_setting 후보 — 활성 확장 `config/settings/defaults.json` `defaults.seo.*`
 *     boolean 키 + 친화 라벨(lang).
 *  3. 유효 vars 후보 — 선언 확장들의 `seoVariables()[_common] + [page_type]` 키 합집합.
 *
 * Controller 가 검증/가드(`core.templates.layouts.edit`)를 담당하고, 본 서비스는 순수
 * 수집만 한다(Service-Repository 정합 — 도메인 수집 로직 분리). 확장 declaration throw 는
 * 격리(한 확장 오류가 전체 후보 수집을 망치지 않음).
 *
 * @since 7.0.0-beta.?
 */
class SeoCandidateService
{
    public function __construct(
        private readonly ModuleManagerInterface $moduleManager,
        private readonly PluginManagerInterface $pluginManager,
    ) {}

    /**
     * 편집기 SEO 탭 후보를 수집합니다.
     *
     * @param  array  $declaredExtensions  현재 레이아웃 meta.seo.extensions (`[{type,id}]`)
     * @param  string|null  $pageType  현재 선택된 page_type (유효 vars 게이팅용)
     * @param  string  $locale  라벨 해석 로케일
     * @return array{page_types: array, toggle_settings: array, vars: array}
     */
    public function collect(array $declaredExtensions, ?string $pageType, string $locale): array
    {
        $activeExtensions = $this->resolveActiveExtensions();

        return [
            'page_types' => $this->collectPageTypes($activeExtensions, $declaredExtensions),
            'toggle_settings' => $this->collectToggleSettings($activeExtensions, $locale),
            'vars' => $this->collectVars($activeExtensions, $declaredExtensions, $pageType),
            'extensions' => $this->collectExtensions($activeExtensions, $locale),
        ];
    }

    /**
     * 확장 후보 — 확장 SEO 연동 칩(`g7le-seo-extensions`)의 활성 모듈/플러그인 목록.
     *
     * 레이아웃 `meta.seo.extensions[]` 에 `{type,id}` 로 저장될 후보를 공급한다. 각 항목은
     * 친화 라벨(`getName()` 로케일 해석값, 폴백 = id)을 달아 칩 선택지 표시에 쓴다. SEO 별도
     * 엔드포인트 신설 없이 본 후보 응답에 합류(세션 D 결선 — "활성 모듈/플러그인 후보").
     *
     * @param  array  $activeExtensions  resolveActiveExtensions() 결과
     * @param  string  $locale  라벨 해석 로케일
     * @return array<int, array{type: string, id: string, label: string}>
     */
    private function collectExtensions(array $activeExtensions, string $locale): array
    {
        $out = [];
        foreach ($activeExtensions as $ext) {
            $name = $this->safeCall($ext['instance'], 'getName', $ext['id']);
            $out[] = [
                'type' => $ext['type'],
                'id' => $ext['id'],
                'label' => $this->localizeName($name, $locale, $ext['id']),
            ];
        }

        return $out;
    }

    /**
     * 확장 `getName()` 반환(string|array 다국어)을 로케일 라벨로 해석합니다.
     *
     * 배열이면 locale → app.locale → 첫 값 순으로 폴백, 끝내 비면 식별자 폴백.
     *
     * @param  mixed  $name  getName() 반환 (string|array<string,string>)
     * @param  string  $locale  목표 로케일
     * @param  string  $fallbackId  최종 폴백 식별자
     * @return string 친화 라벨
     */
    private function localizeName(mixed $name, string $locale, string $fallbackId): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }
        if (is_array($name)) {
            foreach ([$locale, config('app.locale')] as $loc) {
                if (is_string($loc) && isset($name[$loc]) && is_string($name[$loc]) && $name[$loc] !== '') {
                    return $name[$loc];
                }
            }
            foreach ($name as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $fallbackId;
    }

    /**
     * 활성 모듈/플러그인 인스턴스를 `{type, id, instance}` 목록으로 반환합니다.
     *
     * @return array<int, array{type: string, id: string, instance: object}>
     */
    private function resolveActiveExtensions(): array
    {
        $out = [];
        foreach ($this->moduleManager->getActiveModules() as $module) {
            $out[] = ['type' => 'module', 'id' => $module->getIdentifier(), 'instance' => $module];
        }
        foreach ($this->pluginManager->getActivePlugins() as $plugin) {
            $out[] = ['type' => 'plugin', 'id' => $plugin->getIdentifier(), 'instance' => $plugin];
        }

        return $out;
    }

    /**
     * page_type 후보 — 활성 확장 seoVariables() 키(_common 제외) + owned @type.
     *
     * 선언 확장(declaredExtensions)을 우선 정렬하되, 전체 활성 확장을 fallback 으로 포함한다.
     * 같은 page_type 을 여러 확장이 선언하면 첫 소유 확장 기준으로 1건만 노출(중복 제거).
     *
     * @param  array  $activeExtensions  resolveActiveExtensions() 결과
     * @param  array  $declaredExtensions  레이아웃 선언 확장
     * @return array<int, array{value: string, owner: array, og_type?: string}>
     */
    private function collectPageTypes(array $activeExtensions, array $declaredExtensions): array
    {
        $declaredIds = $this->declaredIdSet($declaredExtensions);
        // 선언 확장 우선 정렬.
        usort($activeExtensions, function ($a, $b) use ($declaredIds) {
            $aw = isset($declaredIds[$a['type'].':'.$a['id']]) ? 0 : 1;
            $bw = isset($declaredIds[$b['type'].':'.$b['id']]) ? 0 : 1;

            return $aw <=> $bw;
        });

        $seen = [];
        $out = [];
        foreach ($activeExtensions as $ext) {
            $vars = $this->safeCall($ext['instance'], 'seoVariables', []);
            if (! is_array($vars)) {
                continue;
            }
            foreach (array_keys($vars) as $key) {
                if ($key === '_common' || ! is_string($key) || $key === '') {
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $entry = [
                    'value' => $key,
                    'owner' => ['type' => $ext['type'], 'id' => $ext['id']],
                ];
                // owned @type — page_type 별 seoStructuredData 의 @type (구조화 "모듈 제공" 표시).
                $structured = $this->safeCall($ext['instance'], 'seoStructuredData', [], [$key, [], []]);
                if (is_array($structured) && isset($structured['@type']) && is_string($structured['@type'])) {
                    $entry['og_type'] = $structured['@type'];
                }
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * toggle_setting 후보 — 활성 확장 defaults.seo.* boolean 키 + 친화 라벨.
     *
     * @param  array  $activeExtensions  resolveActiveExtensions() 결과
     * @param  string  $locale  라벨 로케일
     * @return array<int, array{ref: string, key: string, label: string, owner: array}>
     */
    private function collectToggleSettings(array $activeExtensions, string $locale): array
    {
        $out = [];
        foreach ($activeExtensions as $ext) {
            $defaults = $this->readSeoDefaults($ext['type'], $ext['id']);
            foreach ($defaults as $key => $value) {
                if (! is_bool($value)) {
                    continue;
                }
                $out[] = [
                    'ref' => '$'.$ext['type'].'_settings:'.$ext['id'].':seo.'.$key,
                    'key' => $key,
                    'label' => $this->resolveToggleLabel($ext['type'], $ext['id'], $key, $locale),
                    'owner' => ['type' => $ext['type'], 'id' => $ext['id']],
                ];
            }
        }

        return $out;
    }

    /**
     * 유효 vars 후보 — 선언 확장들의 seoVariables()[_common]+[pageType] 키 합집합.
     *
     * page_type 미선택 시 _common 만. extensions 변경 시 갱신(편집기 게이팅 ④).
     *
     * @param  array  $activeExtensions  resolveActiveExtensions() 결과
     * @param  array  $declaredExtensions  레이아웃 선언 확장(게이팅 — 미선언 확장 vars 무효)
     * @param  string|null  $pageType  선택된 page_type
     * @return array<int, array{name: string, source: string, owner: array, required?: bool}>
     */
    private function collectVars(array $activeExtensions, array $declaredExtensions, ?string $pageType): array
    {
        $declaredIds = $this->declaredIdSet($declaredExtensions);
        $seen = [];
        $out = [];
        foreach ($activeExtensions as $ext) {
            // 게이팅 — 선언 확장만 vars 유효(SeoRenderer.php:941-943 정합).
            if (! isset($declaredIds[$ext['type'].':'.$ext['id']])) {
                continue;
            }
            $vars = $this->safeCall($ext['instance'], 'seoVariables', []);
            if (! is_array($vars)) {
                continue;
            }
            $groups = [];
            if (isset($vars['_common']) && is_array($vars['_common'])) {
                $groups[] = $vars['_common'];
            }
            if ($pageType !== null && isset($vars[$pageType]) && is_array($vars[$pageType])) {
                $groups[] = $vars[$pageType];
            }
            foreach ($groups as $group) {
                foreach ($group as $name => $meta) {
                    if (! is_string($name) || isset($seen[$name])) {
                        continue;
                    }
                    $seen[$name] = true;
                    $entry = [
                        'name' => $name,
                        'source' => is_array($meta) ? (string) ($meta['source'] ?? 'data') : 'data',
                        'owner' => ['type' => $ext['type'], 'id' => $ext['id']],
                    ];
                    if (is_array($meta) && ! empty($meta['required'])) {
                        $entry['required'] = true;
                    }
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /**
     * 선언 확장 식별자 집합(`'type:id' => true`)을 만듭니다.
     *
     * @param  array  $declaredExtensions  `[{type,id}]`
     * @return array<string, bool>
     */
    private function declaredIdSet(array $declaredExtensions): array
    {
        $set = [];
        foreach ($declaredExtensions as $ext) {
            $type = is_array($ext) ? ($ext['type'] ?? null) : null;
            $id = is_array($ext) ? ($ext['id'] ?? null) : null;
            if (is_string($type) && is_string($id)) {
                $set[$type.':'.$id] = true;
            }
        }

        return $set;
    }

    /**
     * 확장의 `config/settings/defaults.json` `defaults.seo` 배열을 읽습니다.
     *
     * @param  string  $type  module|plugin
     * @param  string  $id  확장 식별자
     * @return array<string, mixed> seo 기본값 맵 (없으면 빈 배열)
     */
    private function readSeoDefaults(string $type, string $id): array
    {
        $base = $type === 'module' ? 'modules' : 'plugins';
        foreach ([base_path("{$base}/{$id}/config/settings/defaults.json"), base_path("{$base}/_bundled/{$id}/config/settings/defaults.json")] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            $seo = $decoded['defaults']['seo'] ?? null;

            return is_array($seo) ? $seo : [];
        }

        return [];
    }

    /**
     * toggle_setting 친화 라벨을 해석합니다 — lang 키 `{ext}::settings.seo.{key}` 우선, 폴백 = 키.
     *
     * @param  string  $type  module|plugin
     * @param  string  $id  확장 식별자
     * @param  string  $key  seo boolean 키
     * @param  string  $locale  로케일
     * @return string 친화 라벨
     */
    private function resolveToggleLabel(string $type, string $id, string $key, string $locale): string
    {
        $candidates = [
            "{$id}::settings.seo.{$key}",
            "{$id}::seo.{$key}",
        ];
        foreach ($candidates as $transKey) {
            $translated = __($transKey, [], $locale);
            if (is_string($translated) && $translated !== $transKey) {
                return $translated;
            }
        }

        return $key;
    }

    /**
     * 확장 메서드를 격리 호출합니다 — throw 시 fallback 반환(한 확장 오류 격리).
     *
     * @param  object  $instance  확장 인스턴스
     * @param  string  $method  메서드명
     * @param  mixed  $fallback  실패 시 반환값
     * @param  array  $args  인자
     * @return mixed 결과 또는 fallback
     */
    private function safeCall(object $instance, string $method, mixed $fallback, array $args = []): mixed
    {
        if (! method_exists($instance, $method)) {
            return $fallback;
        }
        try {
            return $instance->{$method}(...$args);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
