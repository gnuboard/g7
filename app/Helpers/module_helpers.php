<?php

use App\Services\ModuleSettingsService;

if (! function_exists('module_setting')) {
    /**
     * 모듈 설정값 조회 헬퍼 함수
     *
     * ModuleSettingsService를 통해 모듈 설정을 조회합니다.
     * 모듈별 설정 서비스(예: EcommerceSettingsService)가 존재하면 자동으로 위임합니다.
     *
     * @param string $moduleIdentifier 모듈 식별자 (예: 'sirsoft-ecommerce')
     * @param string|null $key 설정 키 (null이면 전체 설정, 도트 노테이션 지원)
     * @param mixed $default 기본값
     * @return mixed 설정값
     *
     * @example
     * // 특정 설정값 조회
     * $itemsPerPage = module_setting('sirsoft-ecommerce', 'product.items_per_page', 20);
     *
     * @example
     * // 전체 설정 조회
     * $allSettings = module_setting('sirsoft-ecommerce');
     */
    function module_setting(string $moduleIdentifier, ?string $key = null, mixed $default = null): mixed
    {
        try {
            $service = app(ModuleSettingsService::class);

            return $service->get($moduleIdentifier, $key, $default);
        } catch (Throwable) {
            return $default;
        }
    }
}

if (! function_exists('module_settings')) {
    /**
     * 모듈 전체 설정 조회 헬퍼 함수
     *
     * @param string $moduleIdentifier 모듈 식별자 (예: 'sirsoft-ecommerce')
     * @param string|null $category 카테고리명 (null이면 전체 설정)
     * @return array 설정값 배열
     *
     * @example
     * // 전체 설정 조회
     * $allSettings = module_settings('sirsoft-ecommerce');
     *
     * @example
     * // 상품 카테고리 설정만 조회
     * $productSettings = module_settings('sirsoft-ecommerce', 'product');
     */
    function module_settings(string $moduleIdentifier, ?string $category = null): array
    {
        try {
            $service = app(ModuleSettingsService::class);

            if ($category !== null) {
                return $service->get($moduleIdentifier, $category, []);
            }

            return $service->get($moduleIdentifier) ?? [];
        } catch (Throwable) {
            return [];
        }
    }
}
