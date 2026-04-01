<?php

namespace App\Http\Requests\Extension;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 확장 Changelog 조회 요청
 *
 * 모듈/플러그인/템플릿 공통으로 사용합니다.
 */
class ChangelogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'source' => 'nullable|string|in:active,bundled,github',
            'from_version' => 'nullable|string|regex:/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+(\.[a-zA-Z0-9]+)*)?$/',
            'to_version' => 'nullable|string|regex:/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+(\.[a-zA-Z0-9]+)*)?$/|required_with:from_version',
        ];

        return HookManager::applyFilters('core.extension.changelog_rules', $rules);
    }

    /**
     * 검증 에러 메시지를 정의합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.in' => __('common.changelog_validation.source_in'),
            'from_version.regex' => __('common.changelog_validation.version_format', ['attribute' => __('validation.attributes.from_version')]),
            'to_version.regex' => __('common.changelog_validation.version_format', ['attribute' => __('validation.attributes.to_version')]),
            'to_version.required_with' => __('common.changelog_validation.to_version_required'),
        ];
    }
}
