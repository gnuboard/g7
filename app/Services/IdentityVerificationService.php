<?php

namespace App\Services;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\User;

/**
 * 본인인증 유스케이스 서비스.
 *
 * 플로우:
 *   start()  → Manager.resolveForPurpose() → Provider.requestChallenge() → Challenge 반환
 *   verify() → Provider.verify() → result
 *            → core.identity.after_verify 훅 → users.identity_verified_at 갱신
 *
 * 컨트롤러/리스너는 이 서비스를 통해서만 IDV 를 수행 — Provider 를 직접 호출하지 않음.
 *
 * @since 7.0.0-beta.4
 */
class IdentityVerificationService
{
    /**
     * @param  IdentityVerificationManager  $manager  프로바이더 레지스트리
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  로그 Repository
     * @param  UserRepositoryInterface  $userRepository  사용자 Repository
     */
    public function __construct(
        protected IdentityVerificationManager $manager,
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Challenge 를 시작합니다.
     *
     * @param  string  $purpose  signup|password_reset|self_update|sensitive_action|플러그인 등록값
     * @param  User|array<string, mixed>  $target  로그인 사용자(User) 또는 ['email' => '...'] 배열
     * @param  array<string, mixed>  $context  origin_type / origin_identifier / origin_policy_key / ip_address / user_agent
     * @return VerificationChallenge 발행된 challenge DTO
     */
    public function start(string $purpose, User|array $target, array $context = []): VerificationChallenge
    {
        $provider = $this->manager->resolveForPurpose($purpose);

        $context['purpose'] = $purpose;

        HookManager::doAction('core.identity.before_request', $purpose, $target, $context);

        $challenge = $provider->requestChallenge($target, $context);

        HookManager::doAction('core.identity.after_request', $challenge, $purpose, $target, $context);

        return $challenge;
    }

    /**
     * Challenge 를 검증합니다.
     *
     * @param  string  $challengeId  Challenge UUID
     * @param  array<string, mixed>  $input  프로바이더별 입력 (코드, 토큰 등)
     * @param  array<string, mixed>  $context  origin 정보 (origin_type/origin_identifier 등)
     * @return VerificationResult 검증 결과 DTO (success/실패 사유 포함)
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log) {
            HookManager::doAction('core.identity.before_verify', $challengeId, null, $context);
            $result = VerificationResult::failure($challengeId, 'unknown', 'NOT_FOUND', 'identity.errors.challenge_not_found');
            HookManager::doAction('core.identity.after_verify', $result, null, $context);

            return $result;
        }

        $provider = $this->manager->get($log->provider_id);

        HookManager::doAction('core.identity.before_verify', $challengeId, $log, $context);

        $result = $provider->verify($challengeId, $input, $context);

        if ($result->success && $log->user_id !== null) {
            $user = $this->userRepository->findById($log->user_id);
            if ($user !== null) {
                $this->userRepository->update($user, [
                    'identity_verified_at' => $result->verifiedAt,
                    'identity_verified_provider' => $result->providerId,
                    'identity_verified_purpose_last' => $log->purpose,
                    'identity_hash' => $result->identityHash ?: null,
                ]);
            }
        }

        HookManager::doAction('core.identity.after_verify', $result, $log, $context);

        return $result;
    }

    /**
     * Challenge 를 취소합니다.
     *
     * @param  string  $challengeId  Challenge UUID
     * @return bool 취소 성공 여부 (대상 challenge 가 없으면 false)
     */
    public function cancel(string $challengeId): bool
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log) {
            return false;
        }

        return $this->manager->get($log->provider_id)->cancel($challengeId);
    }

    /**
     * verification_token 을 소비(consume)합니다 — signup_before_submit 정책 통과 시 재사용 방지.
     *
     * @param  string  $token  IDV 발행 verification_token
     * @return bool consume 성공 여부 (verified 로그 부재 시 false)
     */
    public function consumeToken(string $token): bool
    {
        $log = $this->logRepository->findVerifiedForToken($token, 'signup');

        if (! $log) {
            return false;
        }

        return $this->logRepository->updateById($log->id, [
            'consumed_at' => now(),
        ]);
    }

    /**
     * Challenge 의 공개 상태를 조회합니다 (폴링용).
     *
     * 비동기 검증 흐름(Stripe Identity / 토스인증 push / 외부 redirect 콜백 대기) 에서 클라이언트가
     * `GET /api/identity/challenges/{id}` 로 상태를 폴링할 때 사용합니다.
     *
     * 반환 필드는 시도 횟수·코드 본체·내부 metadata 를 제외한 공개 안전 필드만:
     * - id / status / render_hint / expires_at / public_payload (요청 폴링에 필요한 최소집합)
     *
     * @param  string  $challengeId  Challenge UUID
     * @return array<string, mixed>|null 공개 상태 또는 null (없는 경우)
     * @since engine-v1.46.0
     */
    public function getStatus(string $challengeId): ?array
    {
        $log = $this->logRepository->findById($challengeId);
        if (! $log) {
            return null;
        }

        $publicPayload = [];
        if (is_array($log->metadata) && isset($log->metadata['public_payload'])) {
            $publicPayload = $log->metadata['public_payload'];
        } elseif (is_array($log->properties) && isset($log->properties['public_payload'])) {
            $publicPayload = $log->properties['public_payload'];
        }

        return [
            'id' => $log->id,
            'status' => $log->status->value,
            'render_hint' => $log->render_hint,
            'expires_at' => optional($log->expires_at)->toIso8601String(),
            'public_payload' => $publicPayload,
        ];
    }

    /**
     * 외부 IDV provider 의 redirect 콜백을 처리합니다.
     *
     * `POST /api/identity/callback/{providerId}` 진입 후 컨트롤러가 호출합니다.
     * provider 의 `verify($challengeId, $input, $context)` 위임 — 이는 Mode B verify 와 동일한 경로.
     *
     * @param  string  $providerId  콜백을 보낸 provider 식별자
     * @param  string  $challengeId  body/query 에서 추출한 Challenge UUID
     * @param  array<string, mixed>  $input  provider 가 보낸 페이로드 (code/token/state 등)
     * @param  array<string, mixed>  $context  origin 정보 (origin_type=callback)
     * @return VerificationResult provider 의 검증 결과 (challenge mismatch 시 failure)
     * @since engine-v1.46.0
     */
    public function handleProviderCallback(string $providerId, string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log) {
            return VerificationResult::failure($challengeId, $providerId, 'NOT_FOUND', 'identity.errors.challenge_not_found');
        }

        // provider 식별자 불일치 — 다른 provider 의 콜백이 잘못 라우팅된 경우 차단
        if ($log->provider_id !== $providerId) {
            return VerificationResult::failure($challengeId, $providerId, 'WRONG_PROVIDER', 'identity.errors.wrong_provider');
        }

        // 일반 verify 경로와 동일하게 위임 — verifyToken 발행, after_verify 훅 등 일관성 유지
        $context['origin_type'] = $context['origin_type'] ?? 'callback';
        $context['origin_identifier'] = $context['origin_identifier'] ?? "/api/identity/callback/{$providerId}";

        return $this->verify($challengeId, $input, $context);
    }
}
