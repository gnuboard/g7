<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 등록된 IDV purpose 목록 조회 요청 (GET /api/identity/purposes).
 *
 * 코어 4종 + 활성 모듈/플러그인 declarative purpose + filter 훅 확장 결과를 반환.
 */
class PurposesIndexRequest extends FormRequest
{
    /**
     * 요청 권한 — 공개 엔드포인트.
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
        return [];
    }
}
