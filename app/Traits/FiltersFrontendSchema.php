<?php

namespace App\Traits;

use Illuminate\Support\Facades\File;

/**
 * Frontend Schema 기반 설정 필터링 Trait
 *
 * defaults.json의 frontend_schema 섹션을 읽어
 * expose: true인 카테고리/필드만 프론트엔드에 노출합니다.
 *
 * Module과 Plugin 설정 서비스에서 공통으로 사용됩니다.
 *
 * @example
 * ```php
 * class PluginSettingsService
 * {
 *     use FiltersFrontendSchema;
 *
 *     public function getAllActiveSettings(): array
 *     {
 *         $frontendSchema = $this->loadFrontendSchema($defaultsPath);
 *         $filtered = $this->filterByFrontendSchema($settings, $frontendSchema);
 *     }
 * }
 * ```
 */
trait FiltersFrontendSchema
{
    /**
     * defaults.json에서 frontend_schema를 로드합니다.
     *
     * @param  string|null  $defaultsPath  defaults.json 파일 경로
     * @return array frontend_schema 배열
     */
    protected function loadFrontendSchema(?string $defaultsPath): array
    {
        if (! $defaultsPath || ! File::exists($defaultsPath)) {
            return [];
        }

        $content = File::get($defaultsPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $data['frontend_schema'] ?? [];
    }

    /**
     * frontend_schema 기반으로 설정을 필터링합니다.
     *
     * expose: true인 카테고리와 필드만 포함합니다.
     *
     * @param  array  $settings  전체 설정 배열
     * @param  array  $frontendSchema  frontend_schema 정의
     * @return array 필터링된 설정 배열
     */
    protected function filterByFrontendSchema(array $settings, array $frontendSchema): array
    {
        // frontend_schema가 없으면 빈 배열 반환 (기본적으로 노출 안함)
        if (empty($frontendSchema)) {
            return [];
        }

        $filtered = [];

        foreach ($frontendSchema as $category => $categorySchema) {
            // 카테고리 레벨에서 expose: false이면 건너뛰기
            if (! ($categorySchema['expose'] ?? false)) {
                continue;
            }

            // 설정에 해당 카테고리가 없으면 건너뛰기
            if (! isset($settings[$category])) {
                continue;
            }

            $categorySettings = $settings[$category];
            $fields = $categorySchema['fields'] ?? null;

            // fields 키가 정의되지 않았으면 카테고리 전체 포함 (하위 호환)
            if ($fields === null) {
                $filtered[$category] = $categorySettings;

                continue;
            }

            // fields가 빈 객체/배열이면 아무 필드도 노출하지 않음
            if (empty($fields)) {
                continue;
            }

            // 필드별로 필터링
            $filteredCategory = [];
            foreach ($fields as $field => $fieldSchema) {
                // expose: true인 필드만 포함
                if (($fieldSchema['expose'] ?? false) && isset($categorySettings[$field])) {
                    $filteredCategory[$field] = $categorySettings[$field];
                }
            }

            if (! empty($filteredCategory)) {
                $filtered[$category] = $filteredCategory;
            }
        }

        return $filtered;
    }
}
