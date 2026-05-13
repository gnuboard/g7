<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 레이아웃 JSON에서 외부 URL을 차단하는 Custom Rule
 *
 * props와 actions 내의 http://, https://, data:, javascript: 등
 * 위험한 URI 스킴을 감지하여 차단합니다.
 */
class NoExternalUrls implements ValidationRule
{
    /**
     * 차단할 위험 URI 스킴 목록
     */
    private const DANGEROUS_SCHEMES = [
        'http://',
        'https://',
        'data:',
        'javascript:',
        'vbscript:',
        'file:',
        'ftp:',
    ];

    /**
     * 검증 수행
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        // components 배열을 재귀적으로 검사
        if (isset($value['components']) && is_array($value['components'])) {
            $this->validateComponents($value['components'], $fail);
        }
    }

    /**
     * components 배열을 재귀적으로 검증
     */
    private function validateComponents(array $components, Closure $fail): void
    {
        foreach ($components as $index => $component) {
            if (! is_array($component)) {
                continue;
            }

            // props 검사
            if (isset($component['props']) && is_array($component['props'])) {
                $this->validateObject($component['props'], "components[$index].props", $fail);
            }

            // actions 검사
            if (isset($component['actions']) && is_array($component['actions'])) {
                $this->validateActions($component['actions'], $index, $fail);
            }

            // children 재귀 검사
            if (isset($component['children']) && is_array($component['children'])) {
                $this->validateComponents($component['children'], $fail);
            }
        }
    }

    /**
     * actions 배열 검증
     */
    private function validateActions(array $actions, int $componentIndex, Closure $fail): void
    {
        foreach ($actions as $actionIndex => $action) {
            if (! is_array($action)) {
                continue;
            }

            $this->validateObject($action, "components[$componentIndex].actions[$actionIndex]", $fail);
        }
    }

    /**
     * 객체(배열)의 모든 값을 재귀적으로 검사
     */
    private function validateObject(array $object, string $path, Closure $fail): void
    {
        foreach ($object as $key => $value) {
            if (is_string($value)) {
                $this->checkForDangerousUrl($value, "$path.$key", $fail);
            } elseif (is_array($value)) {
                $this->validateObject($value, "$path.$key", $fail);
            }
        }
    }

    /**
     * 문자열에서 위험한 URL 패턴 검사
     */
    private function checkForDangerousUrl(string $value, string $path, Closure $fail): void
    {
        $lowerValue = strtolower(trim($value));

        foreach (self::DANGEROUS_SCHEMES as $scheme) {
            if (str_starts_with($lowerValue, $scheme)) {
                $this->failWithScheme($scheme, $value, $path, $fail);

                return;
            }
        }

        // 추가 패턴 검사: //로 시작 (프로토콜 상대 URL)
        if (str_starts_with($lowerValue, '//')) {
            $fail(__('validation.external_url.detected_in_props', ['url' => $value]));

            return;
        }
    }

    /**
     * 스킴별 에러 메시지 출력
     */
    private function failWithScheme(string $scheme, string $url, string $path, Closure $fail): void
    {
        $scheme = rtrim($scheme, ':/');

        $messageKey = match ($scheme) {
            'http' => 'validation.external_url.http_not_allowed',
            'https' => 'validation.external_url.https_not_allowed',
            'data' => 'validation.external_url.data_uri_not_allowed',
            'javascript' => 'validation.external_url.javascript_uri_not_allowed',
            default => 'validation.external_url.dangerous_scheme_detected',
        };

        if ($messageKey === 'validation.external_url.dangerous_scheme_detected') {
            $fail(__($messageKey, ['scheme' => $scheme]));
        } else {
            $fail(__($messageKey));
        }
    }
}
