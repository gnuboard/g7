<?php

namespace App\Http\Requests\Layout;

use App\Extension\HookManager;
use App\Rules\NoExternalUrls;
use App\Rules\ValidDataSourceMerge;
use App\Rules\ValidLayoutExtensionStructure;
use App\Rules\WhitelistedEndpoint;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃 확장 Content 업데이트 요청 검증
 *
 * 레이아웃 확장(extension_point / overlay)의 content JSON 구조를 검증합니다.
 */
class UpdateLayoutExtensionContentRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 데이터 전처리
     *
     * content가 JSON 문자열로 전송된 경우 배열로 변환합니다.
     */
    protected function prepareForValidation(): void
    {
        $content = $this->input('content');

        if (is_string($content)) {
            $decoded = json_decode($content, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge(['content' => $decoded]);
            }
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // 낙관적 잠금 — 클라이언트가 로드한 시점의 lock_version 필수 전달
            'expected_lock_version' => ['required', 'integer', 'min:0'],

            'content' => [
                'required',
                'array',
                new ValidLayoutExtensionStructure,
            ],

            // 우선순위 (선택 — content.priority 와 별개로 직접 지정 가능)
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // content 내부 priority
            'content.priority' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // 데이터소스 검증
            'content.data_sources' => ['nullable', 'array', new ValidDataSourceMerge],

            // 데이터소스 endpoint 검증
            'content.data_sources.*.endpoint' => [
                'nullable',
                'string',
                new WhitelistedEndpoint,
                new NoExternalUrls,
            ],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.layout_extension.update_content_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expected_lock_version.required' => __('validation.layout_extension.expected_lock_version.required'),
            'expected_lock_version.integer' => __('validation.layout_extension.expected_lock_version.integer'),
            'expected_lock_version.min' => __('validation.layout_extension.expected_lock_version.min'),
            'content.required' => __('validation.layout_extension.content.required'),
            'content.array' => __('validation.layout_extension.content.array'),
            'priority.integer' => __('validation.layout_extension.priority.integer'),
            'priority.min' => __('validation.layout_extension.priority.min'),
            'priority.max' => __('validation.layout_extension.priority.max'),
            'content.priority.integer' => __('validation.layout_extension.priority.integer'),
            'content.priority.min' => __('validation.layout_extension.priority.min'),
            'content.priority.max' => __('validation.layout_extension.priority.max'),
            'content.data_sources.array' => __('validation.layout_extension.data_sources.array'),
        ];
    }
}
