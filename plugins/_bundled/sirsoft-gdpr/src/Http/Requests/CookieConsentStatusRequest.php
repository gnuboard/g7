<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 현재 방문자의 쿠키 동의 상태 조회 요청을 검증합니다.
 *
 * 회원 식별은 optional.sanctum 미들웨어가, 게스트 식별은 쿠키/세션이 담당하므로 요청
 * 파라미터를 읽지 않습니다.
 */
class CookieConsentStatusRequest extends FormRequest
{
    /**
     * 요청 권한 — 공개 엔드포인트이므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙 (사용 파라미터 없음)
     */
    public function rules(): array
    {
        return [];
    }
}
