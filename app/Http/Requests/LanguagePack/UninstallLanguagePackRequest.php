<?php

namespace App\Http\Requests\LanguagePack;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 언어팩 제거 요청.
 *
 * cascade=true 인 경우 코어 언어팩 제거 시 동일 locale 의 하위(module/plugin/template)
 * 언어팩도 비활성화 처리됩니다.
 */
class UninstallLanguagePackRequest extends FormRequest
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
            'cascade' => ['nullable', 'boolean'],
        ];
    }
}
