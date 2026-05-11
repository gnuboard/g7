<?php

namespace App\Http\Requests\Extension;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 호환성 알림 dismiss 요청 (입력 없음 — type/identifier 는 라우트 파라미터).
 *
 * @since 7.0.0-beta.4
 */
class DismissAlertRequest extends FormRequest
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
     * 입력 없음 (라우트 파라미터로 type/identifier 전달).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
