<?php

namespace App\Http\Requests\LanguagePack;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 번들(`lang-packs/_bundled/{identifier}`) 디렉토리에서 언어팩 설치 요청.
 *
 * 코어/공식 번들 언어팩을 외부 다운로드 없이 로컬 번들에서 (재)설치하는 경로.
 * 모듈/플러그인/템플릿의 `_bundled` 설치 패턴과 동일.
 */
class InstallFromBundledRequest extends FormRequest
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
            'identifier' => [
                'required',
                'string',
                'max:200',
                'regex:/^[a-zA-Z0-9._\-]+$/',
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
            'identifier.required' => __('language_packs.validation.identifier_required'),
            'identifier.regex' => __('language_packs.validation.identifier_invalid'),
        ];
    }
}
