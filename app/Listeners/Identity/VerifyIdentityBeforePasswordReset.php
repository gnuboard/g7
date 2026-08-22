<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;

/**
 * 비밀번호 재설정 시 IDV 토큰 유효성 가드 Listener.
 *
 * `core.auth.before_reset_password` 훅을 구독합니다.
 * 정책 `core.auth.password_reset` 의 enabled 여부는 EnforceIdentityPolicy 미들웨어에서 평가되며,
 * 본 listener 는 토큰이 IDV verified 로그에 매칭되는지만 확인합니다.
 *
 * 비IDV(레거시) 토큰 호환: 토큰이 IDV 로그에 없으면 password_reset_tokens 경로로 통과.
 *
 * @since 7.0.0-beta.4
 */
class VerifyIdentityBeforePasswordReset implements HookListenerInterface
{
    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.before_reset_password' => [
                'method' => 'handle',
                'priority' => 10,
                'sync' => true, // 인라인 가드 — 실패 시 재설정 중단
            ],
        ];
    }

    /**
     * core.auth.before_reset_password 훅 핸들러.
     *
     * @param  mixed  ...$args  [0]=array{token: string, email?: string} 형태의 payload
     * @return void
     *
     * @throws \RuntimeException IDV 로그는 있으나 verified 상태가 아닐 때
     */
    public function handle(...$args): void
    {
        $payload = $args[0] ?? [];
        if (! is_array($payload)) {
            return;
        }

        $token = (string) ($payload['token'] ?? '');
        if ($token === '') {
            return;
        }

        $log = $this->logRepository->findVerifiedForToken($token, 'password_reset');

        // IDV 로그가 없으면 레거시 password_reset_tokens 경로를 그대로 통과
        if (! $log) {
            return;
        }

        if ($log->status !== IdentityVerificationStatus::Verified->value) {
            throw new \RuntimeException(__('identity.errors.invalid_verification_token'));
        }
    }
}
