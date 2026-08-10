<?php

namespace App\Http\Requests\LanguagePack;

use App\Extension\HookManager;
use App\Rules\LanguagePack\RequiresActivationPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ZIP 파일 업로드를 통한 언어팩 설치 요청.
 */
class InstallFromFileRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트 미들웨어가 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 정의합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'file' => ['required', 'file', 'max:10240', 'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream'],
            // 설치 권한만으로 활성화까지 수행하지 못하게 한다 — 활성 팩만 require 경로에 배선된다.
            'auto_activate' => ['nullable', 'boolean', new RequiresActivationPermission],
        ];

        // 모듈/플러그인이 validation rules 를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.language_packs.install_from_file_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 정의합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            'file.required' => __('language_packs.validation.file_required'),
            'file.file' => __('language_packs.validation.file_invalid'),
            'file.max' => __('language_packs.validation.file_too_large'),
            'file.mimetypes' => __('language_packs.validation.file_not_zip'),
        ];
    }
}
