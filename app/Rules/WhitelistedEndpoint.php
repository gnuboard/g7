<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * API 엔드포인트 화이트리스트 검증 Rule
 *
 * /api/(admin|auth|public)/ 패턴만 허용하고 다른 모든 엔드포인트를 차단합니다.
 * 외부 URL, 내부 API, 직접 경로 접근 등을 방지하여 보안을 강화합니다.
 *
 * 허용 패턴:
 * - /api/admin/*
 * - /api/auth/*
 * - /api/public/*
 *
 * 차단 패턴:
 * - /api/internal/*
 * - /admin/* (직접 경로)
 * - http://* (외부 URL)
 * - https://* (외부 URL)
 */
class WhitelistedEndpoint implements ValidationRule
{
    /**
     * 허용된 API 엔드포인트 패턴
     */
    private const ALLOWED_PATTERN = '/^\/api\/(admin|auth|public)\//';

    /**
     * 검증 규칙 실행
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 레이아웃 JSON 구조인 경우 재귀적으로 검사
        if (is_array($value)) {
            // data_sources나 components가 있는 레이아웃 JSON 구조
            $hasLayoutStructure = isset($value['data_sources']) || isset($value['components']);

            if ($hasLayoutStructure) {
                // data_sources 배열 검증
                if (isset($value['data_sources']) && is_array($value['data_sources'])) {
                    $this->validateDataSources($value['data_sources'], $fail);
                }

                // components 배열 검증
                if (isset($value['components']) && is_array($value['components'])) {
                    $this->validateComponents($value['components'], $fail);
                }

                return;
            }

            // 레이아웃 JSON이 아닌 일반 배열은 타입 오류로 차단
            $fail(__('validation.endpoint.must_be_string'));

            return;
        }

        // 개별 endpoint 문자열 검증
        $this->validateEndpointString($value, $fail);
    }

    /**
     * data_sources 배열 검증
     */
    private function validateDataSources(array $dataSources, Closure $fail): void
    {
        foreach ($dataSources as $dataSource) {
            if (! is_array($dataSource)) {
                continue;
            }

            // endpoint 필드 검증
            if (isset($dataSource['endpoint'])) {
                $this->validateEndpointString($dataSource['endpoint'], $fail);
            }
        }
    }

    /**
     * components 배열을 재귀적으로 검증
     */
    private function validateComponents(array $components, Closure $fail): void
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            // actions 내 endpoint 검사
            if (isset($component['actions']) && is_array($component['actions'])) {
                foreach ($component['actions'] as $action) {
                    if (is_array($action) && isset($action['endpoint'])) {
                        $this->validateEndpointString($action['endpoint'], $fail);
                    }
                }
            }

            // children 재귀 검사
            if (isset($component['children']) && is_array($component['children'])) {
                $this->validateComponents($component['children'], $fail);
            }
        }
    }

    /**
     * endpoint 문자열 검증
     */
    private function validateEndpointString(mixed $value, Closure $fail): void
    {
        // 빈 값이나 null은 다른 검증 규칙에서 처리
        if (empty($value) && $value !== '0') {
            return;
        }

        // 문자열이 아닌 경우
        if (! is_string($value)) {
            $fail(__('validation.endpoint.must_be_string'));

            return;
        }

        // 외부 URL 차단 (http://, https://)
        if (preg_match('/^https?:\/\//i', $value)) {
            $fail(__('validation.endpoint.external_url_not_allowed'));

            return;
        }

        // 화이트리스트 패턴 검증
        if (! preg_match(self::ALLOWED_PATTERN, $value)) {
            $fail(__('validation.endpoint.not_whitelisted', ['pattern' => '/api/(admin|auth|public)/']));

            return;
        }

        // 경로 트래버설 공격 방지 (../)
        if (str_contains($value, '../') || str_contains($value, '..\\')) {
            $fail(__('validation.endpoint.path_traversal_detected'));

            return;
        }
    }
}
