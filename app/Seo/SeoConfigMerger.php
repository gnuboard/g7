<?php

namespace App\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\Log;

class SeoConfigMerger
{
    /**
     * 캐시 TTL (24시간)
     */
    private const CACHE_TTL = 86400;

    /**
     * 캐시 키 접두사 (드라이버 접두사 `g7:core:` 다음에 붙음)
     */
    private const CACHE_PREFIX = 'seo.config.';

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly PluginManager $pluginManager,
        private readonly TemplateRepositoryInterface $templateRepository,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 병합된 SEO config를 반환합니다.
     *
     * 우선순위 (나중이 우선): 모듈 < 플러그인 < 템플릿
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return array 병합된 SEO 설정 배열
     */
    public function getMergedConfig(string $templateIdentifier): array
    {
        $cacheKey = self::CACHE_PREFIX.$templateIdentifier;

        return $this->cache->remember($cacheKey, function () use ($templateIdentifier) {
            return $this->buildMergedConfig($templateIdentifier);
        }, self::CACHE_TTL, ['seo']);
    }

    /**
     * SEO config 캐시를 클리어합니다.
     *
     * @param  string|null  $templateIdentifier  특정 템플릿만 클리어 (null이면 전체)
     */
    public function clearCache(?string $templateIdentifier = null): void
    {
        if ($templateIdentifier !== null) {
            $this->cache->forget(self::CACHE_PREFIX.$templateIdentifier);

            return;
        }

        // 전체 클리어: 패턴 기반 삭제가 불가능하므로
        // 태그 캐시를 사용하지 않는 한 개별 키 삭제 불가
        // → 간단히 모든 알려진 템플릿의 캐시를 삭제
        try {
            $templates = $this->templateRepository->getActive();
            foreach ($templates as $template) {
                $this->cache->forget(self::CACHE_PREFIX.$template->identifier);
            }
        } catch (\Throwable $e) {
            Log::warning('[SEO] Config cache clear failed, clearing by pattern', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모든 소스에서 SEO config를 수집하고 병합합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return array 병합된 SEO 설정 배열
     */
    private function buildMergedConfig(string $templateIdentifier): array
    {
        $base = [];

        // 1. 활성 모듈 config 수집 (알파벳순 정렬, 결정론적)
        $moduleConfigs = $this->collectModuleConfigs();
        foreach ($moduleConfigs as $config) {
            $base = $this->mergeConfigs($base, $config);
        }

        // 2. 활성 플러그인 config 수집 (알파벳순 정렬)
        $pluginConfigs = $this->collectPluginConfigs();
        foreach ($pluginConfigs as $config) {
            $base = $this->mergeConfigs($base, $config);
        }

        // 3. 템플릿 config (최종 우선)
        $templateConfig = $this->loadTemplateConfig($templateIdentifier);
        if (! empty($templateConfig)) {
            $base = $this->mergeConfigs($base, $templateConfig);
        }

        return $base;
    }

    /**
     * 활성 모듈의 seo-config.json을 수집합니다.
     *
     * @return array<string, array> 모듈 식별자 → config 배열 (알파벳순 정렬)
     */
    private function collectModuleConfigs(): array
    {
        $configs = [];

        try {
            $activeModules = $this->moduleManager->getActiveModules();

            foreach ($activeModules as $module) {
                $config = $module->getSeoConfig();
                if (! empty($config)) {
                    $configs[$module->getIdentifier()] = $config;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SEO] Module config collection failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // 알파벳순 정렬 (결정론적 병합 순서)
        ksort($configs);

        return $configs;
    }

    /**
     * 활성 플러그인의 seo-config.json을 수집합니다.
     *
     * @return array<string, array> 플러그인 식별자 → config 배열 (알파벳순 정렬)
     */
    private function collectPluginConfigs(): array
    {
        $configs = [];

        try {
            $activePlugins = $this->pluginManager->getActivePlugins();

            foreach ($activePlugins as $plugin) {
                $config = $plugin->getSeoConfig();
                if (! empty($config)) {
                    $configs[$plugin->getIdentifier()] = $config;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SEO] Plugin config collection failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // 알파벳순 정렬 (결정론적 병합 순서)
        ksort($configs);

        return $configs;
    }

    /**
     * 템플릿의 seo-config.json을 로드합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return array SEO 설정 배열
     */
    private function loadTemplateConfig(string $templateIdentifier): array
    {
        $path = base_path("templates/{$templateIdentifier}/seo-config.json");
        if (! file_exists($path)) {
            return [];
        }

        $config = json_decode(file_get_contents($path), true);

        return is_array($config) ? $config : [];
    }

    /**
     * 두 SEO config를 병합합니다.
     *
     * 병합 전략:
     * - component_map: deep merge (키 단위, 후순위 우선)
     * - render_modes: deep merge (키 단위, 후순위 우선)
     * - attr_map: shallow merge (후순위 우선)
     * - text_props: array union (중복 제거)
     * - allowed_attrs: array union (중복 제거)
     * - self_closing: array union (중복 제거)
     * - stylesheets: array append (중복 제거)
     *
     * @param  array  $base  기존 config
     * @param  array  $layer  병합할 config (후순위)
     * @return array 병합된 config
     */
    private function mergeConfigs(array $base, array $layer): array
    {
        $result = $base;

        // deep merge 키 (키 단위, 후순위 우선)
        foreach (['component_map', 'render_modes'] as $key) {
            if (isset($layer[$key])) {
                $result[$key] = array_replace_recursive(
                    $result[$key] ?? [],
                    $layer[$key]
                );
            }
        }

        // shallow merge (후순위 우선)
        foreach (['attr_map'] as $key) {
            if (isset($layer[$key])) {
                $result[$key] = array_merge(
                    $result[$key] ?? [],
                    $layer[$key]
                );
            }
        }

        // array union (중복 제거)
        foreach (['text_props', 'allowed_attrs', 'self_closing'] as $key) {
            if (isset($layer[$key])) {
                $result[$key] = array_values(array_unique(
                    array_merge($result[$key] ?? [], $layer[$key])
                ));
            }
        }

        // array append (중복 제거, 순서 유지)
        if (isset($layer['stylesheets'])) {
            $existing = $result['stylesheets'] ?? [];
            foreach ($layer['stylesheets'] as $stylesheet) {
                if (! in_array($stylesheet, $existing, true)) {
                    $existing[] = $stylesheet;
                }
            }
            $result['stylesheets'] = $existing;
        }

        // seo_overrides: shallow merge (후순위 우선)
        if (isset($layer['seo_overrides'])) {
            $result['seo_overrides'] = array_merge(
                $result['seo_overrides'] ?? [],
                $layer['seo_overrides']
            );
        }

        return $result;
    }
}
