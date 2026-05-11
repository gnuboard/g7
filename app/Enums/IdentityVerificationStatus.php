<?php

namespace App\Enums;

/**
 * 본인인증 Challenge 생명주기 상태.
 *
 * 코어 고정 8값 — 로그 분석·모니터링 호환성을 위해 플러그인 확장 불가.
 *
 * @since 7.0.0-beta.4 (engine-v1.46.0 에서 Processing 추가)
 */
enum IdentityVerificationStatus: string
{
    /** Challenge 생성 */
    case Requested = 'requested';

    /** 발송 완료 (메일·SMS 등) */
    case Sent = 'sent';

    /**
     * 외부 비동기 검증 진행 중.
     *
     * Stripe Identity / 토스인증 push / 외부 SDK redirect 콜백 등 클라이언트가 즉시 verify 응답을 받지 못하고
     * webhook/callback 으로 결과를 기다리는 흐름. 클라이언트는 GET /api/identity/challenges/{id} 폴링으로 상태 추적.
     */
    case Processing = 'processing';

    /** 검증 성공 */
    case Verified = 'verified';

    /** 검증 실패 */
    case Failed = 'failed';

    /** 만료 */
    case Expired = 'expired';

    /** 사용자/관리자가 취소 */
    case Cancelled = 'cancelled';

    /** fail_mode=log_only 인 정책이 위반되었으나 요청은 통과한 로그 */
    case PolicyViolationLogged = 'policy_violation_logged';
}
