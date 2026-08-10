<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 IDV 프로바이더 목록 조회 검증.
 *
 * 등록된 프로바이더 전체를 설정 스키마와 함께 반환하는 엔드포인트로, 필터/페이지네이션
 * 파라미터를 받지 않습니다. 검증 규칙은 비어 있지만, 컨트롤러가 base `Illuminate\Http\Request`
 * 를 주입받지 않도록 전용 FormRequest 를 둡니다 (규정: docs/backend/validation.md).
 *
 * 공개용 `ProvidersIndexRequest`(인증 없는 메타데이터 조회)와 달리 이쪽은 관리자 전용이며,
 * 인증/권한은 라우트의 permission 미들웨어가 담당합니다.
 */
class AdminIdentityProviderIndexRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙 (요청 파라미터 없음)
     */
    public function rules(): array
    {
        return [];
    }
}
