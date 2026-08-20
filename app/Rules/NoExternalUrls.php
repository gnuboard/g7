<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 레이아웃 JSON에서 외부 URL을 차단하는 Custom Rule
 *
 * 컴포넌트 props·actions 와 최상위 init_actions 내의 http://, https://, data:,
 * javascript: 등 위험한 URI 스킴을 감지하여 차단합니다.
 *
 * 검사 대상 구분(신뢰 경계): init_actions 는 로드 시 자동 실행되는 액션이라 외부
 * navigate/apiCall URL 이 곧 자동 리다이렉트·데이터 유출 경로가 되므로 실행 지점에서
 * 차단합니다. 반면 state/computed 는 데이터 값이며, 실제 위험은 그 값이 바인딩되는
 * sink(컴포넌트 prop = img src 등)에서 발생하고 그 sink 는 이미 여기서 검사됩니다 —
 * 예시/안내용 URL 을 담는 정당한 용례를 깨지 않기 위해 데이터 계층은 재차단하지 않습니다.
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
        // 문자열 스칼라 필드에도 부착되므로(`content.endpoint` ·
        // `content.data_sources.*.endpoint`) 그 값을 직접 검사한다. 배열만 처리하고
        // 반환하면 그 부착이 조용한 no-op 이 된다.
        if (is_string($value)) {
            $this->checkForDangerousUrl($value, $attribute, $fail);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        // components 배열을 재귀적으로 검사
        if (isset($value['components']) && is_array($value['components'])) {
            $this->validateComponents($value['components'], $fail);
        }

        // init_actions: 로드 시 자동 실행되는 액션 — 외부 navigate/apiCall URL 은 로드 시점
        // 자동 리다이렉트/데이터 유출 경로가 되므로 컴포넌트 actions 와 동일하게 검사한다.
        if (isset($value['init_actions']) && is_array($value['init_actions'])) {
            foreach ($value['init_actions'] as $i => $action) {
                if (is_array($action)) {
                    $this->validateObject($action, "init_actions[$i]", $fail);
                }
            }
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
        //
        // 브라우저 URL 파서는 파싱 전에 ASCII tab·개행을 제거하고 백슬래시를 슬래시와
        // 동등하게 처리하므로, `/\/evil.com` · `/{tab}/evil.com` 도 실제로는 외부
        // origin 이 된다. 접두 검사 전에 동일하게 정규화한다(SafeLayoutExpressions 와 동형).
        if (str_starts_with(SafeLayoutExpressions::normalizeForOriginCheck($lowerValue), '//')) {
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
