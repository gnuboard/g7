<?php

use App\Services\PluginSettingsService;

if (! function_exists('plugin_setting')) {
    /**
     * 플러그인 설정값 조회 헬퍼 함수
     *
     * @param string $pluginIdentifier 플러그인 식별자 (예: 'sirsoft-daum_postcode')
     * @param string|null $key 설정 키 (null이면 전체 설정, 도트 노테이션 지원)
     * @param mixed $default 기본값
     * @return mixed 설정값
     *
     * @example
     * // 특정 설정값 조회
     * $displayMode = plugin_setting('sirsoft-daum_postcode', 'display_mode', 'layer');
     *
     * @example
     * // 전체 설정 조회
     * $allSettings = plugin_setting('sirsoft-daum_postcode');
     */
    function plugin_setting(string $pluginIdentifier, ?string $key = null, mixed $default = null): mixed
    {
        try {
            $service = app(PluginSettingsService::class);

            return $service->get($pluginIdentifier, $key, $default);
        } catch (Throwable) {
            return $default;
        }
    }
}

if (! function_exists('plugin_settings')) {
    /**
     * 플러그인 전체 설정 조회 헬퍼 함수
     *
     * @param string $pluginIdentifier 플러그인 식별자 (예: 'sirsoft-daum_postcode')
     * @return array 설정값 배열
     *
     * @example
     * // 전체 설정 조회
     * $settings = plugin_settings('sirsoft-daum_postcode');
     */
    function plugin_settings(string $pluginIdentifier): array
    {
        try {
            $service = app(PluginSettingsService::class);

            return $service->get($pluginIdentifier) ?? [];
        } catch (Throwable) {
            return [];
        }
    }
}
