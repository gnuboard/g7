<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 IDV 이력 파기 검증.
 *
 * older_than_days 파라미터의 범위를 제한합니다.
 */
class AdminIdentityLogPurgeRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'older_than_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
