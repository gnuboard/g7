<?php

namespace App\Http\Requests\LanguagePack;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 언어팩 manifest 미리보기 요청.
 *
 * 설치 전 ZIP 의 manifest 와 검증 결과만 추출하여 반환합니다 (실제 설치 X).
 */
class ManifestPreviewRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:zip', 'max:5120'],
        ];
    }
}
