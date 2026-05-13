<?php

namespace App\Http\Requests\Plugin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 파일에서 플러그인 설치 요청
 */
class InstallPluginFromFileRequest extends FormRequest
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
        $maxSize = config('plugin.upload_max_size', 50) * 1024; // MB를 KB로 변환

        $rules = [
            'file' => [
                'required',
                'file',
                'mimes:zip',
                'max:'.$maxSize,
            ],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.plugin.install_from_file_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxSize = config('plugin.upload_max_size', 50);

        return [
            'file.required' => __('plugins.validation.file_required'),
            'file.file' => __('plugins.validation.file_invalid'),
            'file.mimes' => __('plugins.validation.file_must_be_zip'),
            'file.max' => __('plugins.validation.file_max_size', ['size' => $maxSize]),
        ];
    }
}
