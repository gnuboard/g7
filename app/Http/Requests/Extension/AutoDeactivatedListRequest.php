<?php

namespace App\Http\Requests\Extension;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 자동 비활성화된 확장 목록 조회 요청 (입력 없음 — placeholder).
 *
 * @since 7.0.0-beta.4
 */
class AutoDeactivatedListRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 입력 없음 (전체 목록 반환).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
