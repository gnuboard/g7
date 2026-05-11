<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Challenge 폴링 요청 (GET /api/identity/challenges/{challenge}).
 *
 * 비동기 검증 흐름(Stripe Identity / 외부 redirect 콜백 대기) 에서 클라이언트가 상태를 추적하기 위한 엔드포인트의 입력 컨테이너.
 * 권한 가드 없이 optional.sanctum + throttle 만 적용 — 노출 필드는 공개 안전 항목만.
 */
class ShowChallengeRequest extends FormRequest
{
    /**
     * 요청 권한 — 폴링 엔드포인트는 라우트 미들웨어가 담당.
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
