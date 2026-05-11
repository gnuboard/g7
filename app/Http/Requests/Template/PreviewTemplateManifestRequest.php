<?php

namespace App\Http\Requests\Template;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 템플릿 manifest 미리보기 요청.
 *
 * 설치 전 ZIP 의 template.json 과 검증 결과만 추출하여 반환합니다 (실제 설치 X).
 */
class PreviewTemplateManifestRequest extends FormRequest
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
        $maxSize = config('template.upload_max_size', 50) * 1024;

        return [
            'file' => ['required', 'file', 'mimes:zip', 'max:'.$maxSize],
        ];
    }

    /**
     * 검증 실패 메시지.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxSize = config('template.upload_max_size', 50);

        return [
            'file.required' => __('templates.validation.file_required'),
            'file.file' => __('templates.validation.file_invalid'),
            'file.mimes' => __('templates.validation.file_must_be_zip'),
            'file.max' => __('templates.validation.file_max_size', ['size' => $maxSize]),
        ];
    }
}
