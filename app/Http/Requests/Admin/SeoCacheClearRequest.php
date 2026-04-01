<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SEO 캐시 삭제 요청 검증
 *
 * 캐시 삭제 시 레이아웃/모듈 필터 파라미터를 검증합니다.
 */
class SeoCacheClearRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'layout' => ['nullable', 'string'],
            'module' => ['nullable', 'string'],
        ];
    }

    /**
     * 검증 에러 메시지를 반환합니다.
     *
     * @return array<string, string> 에러 메시지 배열
     */
    public function messages(): array
    {
        return [
            'layout.string' => __('validation.string', ['attribute' => __('validation.attributes.layout')]),
            'module.string' => __('validation.string', ['attribute' => __('validation.attributes.module')]),
        ];
    }
}
