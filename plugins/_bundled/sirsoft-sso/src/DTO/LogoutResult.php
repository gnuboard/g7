<?php

namespace Plugins\Sirsoft\Sso\DTO;

use Plugins\Sirsoft\Sso\Models\SsoSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * IdP 발 로그아웃 요청의 처리 결과.
 *
 * @since 1.0.0
 */
final class LogoutResult
{
    /**
     * @param  array<int, SsoSession>  $sessions  끝낼 SSO 세션
     * @param  Response  $response  IdP 에 돌려줄 응답
     */
    public function __construct(
        public readonly array $sessions,
        public readonly Response $response,
    ) {}
}
