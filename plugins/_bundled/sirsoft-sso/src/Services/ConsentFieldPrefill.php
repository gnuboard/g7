<?php

namespace Plugins\Sirsoft\Sso\Services;

use Illuminate\Http\Request;

/**
 * 첫 로그인 동의 창의 회원 항목에 채울 초기값과 잠금을, 지금 요청의 묶음 쿠키로 확인해 돌려줍니다.
 *
 * 가입 항목 조회(`GET /api/auth/register/fields`)는 코어 API 라 대기 토큰만 문맥으로 넘어온다. 대기는 시작한 브라우저의
 * 묶음 쿠키에 묶여 있으므로(다른 브라우저에 신원을 내주지 않는다) 그 대조에 지금 요청이 필요하다 — 훅 구독자가 요청을 직접
 * 만지지 않도록 이 서비스가 요청을 받아 넘긴다.
 *
 * @since 1.0.0
 */
class ConsentFieldPrefill
{
    /**
     * @param  LinkVerificationService  $pending  동의 대기 서비스
     * @param  Request  $request  지금 요청 (묶음 쿠키 대조)
     */
    public function __construct(
        private readonly LinkVerificationService $pending,
        private readonly Request $request,
    ) {}

    /**
     * 대기 토큰의 초기값과 잠긴 항목을 돌려줍니다.
     *
     * @param  string  $token  동의 대기 토큰
     * @return array{values: array<string, string>, locked: array<int, string>}|null 항목 키 => 초기값, 잠긴 항목 키 (대기가 없거나 다른 브라우저면 null)
     */
    public function forToken(string $token): ?array
    {
        return $this->pending->consentFieldValues($token, $this->request);
    }
}
