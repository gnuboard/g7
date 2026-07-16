<?php

namespace App\Http\Requests\LanguagePack;

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
        return [
            'file' => ['required', 'file', 'max:10240', 'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream'],
            'auto_activate' => ['nullable', 'boolean'],
        ];
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
