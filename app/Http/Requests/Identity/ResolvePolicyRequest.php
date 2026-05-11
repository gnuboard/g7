<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * IDV 정책 해석 요청 (GET /api/identity/policies/resolve?scope=route&target=...).
 *
 * 프론트엔드 프리페치용 — 레이아웃 마운트 시 이 페이지에서 IDV 가 요구될 수 있는 API 를 미리 파악하기 위한 엔드포인트.
 */
class ResolvePolicyRequest extends FormRequest
{
    /**
     * 요청 권한 — optional.sanctum 라우트 미들웨어가 담당.
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
            'scope' => ['required', 'string', 'max:32'],
            'target' => ['required', 'string', 'max:255'],
        ];
    }
}
