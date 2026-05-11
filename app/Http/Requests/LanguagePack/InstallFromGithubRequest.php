<?php

namespace App\Http\Requests\LanguagePack;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GitHub URL 을 통한 언어팩 설치 요청.
 */
class InstallFromGithubRequest extends FormRequest
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
            'github_url' => [
                'required',
                'url',
                'regex:/^https?:\/\/(www\.)?github\.com\/[a-zA-Z0-9\-_]+\/[a-zA-Z0-9\-_]+\/?$/',
            ],
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
            'github_url.required' => __('language_packs.validation.github_url_required'),
            'github_url.url' => __('language_packs.validation.github_url_invalid'),
            'github_url.regex' => __('language_packs.validation.github_url_format'),
        ];
    }
}
