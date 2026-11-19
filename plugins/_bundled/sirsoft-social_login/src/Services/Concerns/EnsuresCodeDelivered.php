<?php

namespace Plugins\Sirsoft\SocialLogin\Services\Concerns;

use App\Enums\IdentityVerificationStatus;
use App\Services\IdentityVerificationService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Services\AttemptRecorder;

/**
 * 인증번호 메일이 실제로 나갔는지 확인합니다.
 *
 * 코어 메일 수단은 발송 실패를 예외로 던지지 않고 요청을 실패 상태로 남긴다. 그 상태를 보지 않으면 「인증번호를 보냈습니다」로
 * 답해 가입자가 오지 않을 번호를 기다린다 — 코어 로그인 2단계 인증과 같게 실패를 실패로 알린다.
 *
 * @since 1.0.0
 */
trait EnsuresCodeDelivered
{
    /**
     * 발송에 실패했으면 시도 기록에 `mail_failed` 를 남기고 503 으로 끝냅니다.
     *
     * @param  IdentityVerificationService  $identity  코어 본인인증
     * @param  AttemptRecorder  $attempts  시도 기록
     * @param  string  $challengeId  인증 요청 ID
     * @param  string  $providerKey  제공자 키
     * @param  int|null  $userId  대상 회원 ID (가입 전이면 null)
     * @return void
     *
     * @throws SocialFlowException 발송 실패(`mail_failed`, 503)
     */
    private function ensureCodeDelivered(IdentityVerificationService $identity, AttemptRecorder $attempts, string $challengeId, string $providerKey, ?int $userId = null): void
    {
        if (($identity->getStatus($challengeId)['status'] ?? null) !== IdentityVerificationStatus::Failed->value) {
            return;
        }

        Log::error('소셜 로그인 인증번호 메일 발송 실패', ['challenge_id' => $challengeId, 'provider' => $providerKey, 'user_id' => $userId]);
        $attempts->record($providerKey, AttemptIntent::Login->value, AttemptOutcome::Failed, $userId, 'mail_failed');

        throw new SocialFlowException('mail_failed', 503);
    }
}
