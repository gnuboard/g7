<?php

namespace App\Http\Requests\LanguagePack;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 임의 URL 을 통한 언어팩 설치 요청.
 */
class InstallFromUrlRequest extends FormRequest
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
            'url' => ['required', 'url', 'max:500'],
            'checksum' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
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
            'url.required' => __('language_packs.validation.url_required'),
            'url.url' => __('language_packs.validation.url_invalid'),
            'checksum.regex' => __('language_packs.validation.checksum_invalid'),
        ];
    }
}
