<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 등록된 IDV 프로바이더 목록 조회 요청 (GET /api/identity/providers).
 *
 * 공개 메타데이터만 노출 — 인증/권한 가드 없음.
 */
class ProvidersIndexRequest extends FormRequest
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
