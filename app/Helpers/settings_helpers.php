<?php

use Illuminate\Support\Facades\Config;

if (! function_exists('g7_settings')) {
    /**
     * 그누보드7 통합 환경설정 조회 헬퍼 함수
     *
     * 코어, 모듈, 플러그인의 환경설정을 통합하여 조회합니다.
     * 부트스트랩 시점에 로드된 설정값을 Config에서 읽어옵니다.
     *
     * @param  string|null  $key  설정 키 (dot notation)
     * @param  mixed  $default  기본값
     * @return mixed 설정값 또는 전체 설정 배열
     *
     * @example
     * // 코어 메일 설정 조회
     * $mailHost = g7_settings('core.mail.host');
     *
     * @example
     * // 모듈 설정 조회
     * $shopName = g7_settings('modules.sirsoft-ecommerce.basic_info.shop_name');
     *
     * @example
     * // 플러그인 설정 조회
     * $displayMode = g7_settings('plugins.sirsoft-daum_postcode.display_mode');
     *
     * @example
     * // 전체 설정 조회
     * $allSettings = g7_settings();
     */
    function g7_settings(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return Config::get('g7_settings', []);
        }

        return Config::get("g7_settings.{$key}", $default);
    }
}

if (! function_exists('g7_core_settings')) {
    /**
     * 그누보드7 코어 환경설정 조회 헬퍼 함수
     *
     * @param  string|null  $key  설정 키 (dot notation)
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     *
     * @example
     * // 메일 호스트 조회
     * $mailHost = g7_core_settings('mail.host');
     *
     * @example
     * // 사이트명 조회
     * $siteName = g7_core_settings('general.site_name');
     */
    function g7_core_settings(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return Config::get('g7_settings.core', []);
        }

        return Config::get("g7_settings.core.{$key}", $default);
    }
}

if (! function_exists('g7_module_settings')) {
    /**
     * 그누보드7 모듈 환경설정 조회 헬퍼 함수
     *
     * @param  string  $identifier  모듈 식별자 (예: 'sirsoft-ecommerce')
     * @param  string|null  $key  설정 키 (dot notation)
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     *
     * @example
     * // 이커머스 모듈의 쇼핑몰명 조회
     * $shopName = g7_module_settings('sirsoft-ecommerce', 'basic_info.shop_name');
     *
     * @example
     * // 모듈 전체 설정 조회
     * $allSettings = g7_module_settings('sirsoft-ecommerce');
     */
    function g7_module_settings(string $identifier, ?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return Config::get("g7_settings.modules.{$identifier}", []);
        }

        return Config::get("g7_settings.modules.{$identifier}.{$key}", $default);
    }
}

if (! function_exists('g7_plugin_settings')) {
    /**
     * 그누보드7 플러그인 환경설정 조회 헬퍼 함수
     *
     * @param  string  $identifier  플러그인 식별자 (예: 'sirsoft-daum_postcode')
     * @param  string|null  $key  설정 키 (dot notation)
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     *
     * @example
     * // 다음 우편번호 플러그인의 표시 모드 조회
     * $displayMode = g7_plugin_settings('sirsoft-daum_postcode', 'display_mode');
     *
     * @example
     * // 플러그인 전체 설정 조회
     * $allSettings = g7_plugin_settings('sirsoft-daum_postcode');
     */
    function g7_plugin_settings(string $identifier, ?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return Config::get("g7_settings.plugins.{$identifier}", []);
        }

        return Config::get("g7_settings.plugins.{$identifier}.{$key}", $default);
    }
}
