<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * 가입 본인확인을 메일 인증번호로 마친 회원의 이메일을 인증으로 기록합니다.
 *
 * 두 경로를 본다 — 가입 전 확인(`core.auth.signup_before_submit`)은 가입 훅 문맥의 확인 토큰으로, 가입 후 확인
 * (`core.auth.signup_after_create`)은 본인확인 성공 훅의 요청 행으로. 메일 수단이고 확인한 대상이 그 회원의
 * 이메일일 때만 기록한다 — 휴대폰 같은 다른 수단으로 확인한 가입은 이메일 소유를 증명하지 않는다.
 *
 * 기록은 가입·본인확인의 부가 작업이다. 실패해도 경고 로그만 남기고 가입·활성화 흐름으로 예외를 넘기지 않는다.
 *
 * @since 7.2.0
 */
class RecordVerifiedSignupEmail implements HookListenerInterface
{
    /**
     * 본인확인 요청 행과 회원 저장소를 주입받습니다.
     *
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인확인 요청 행 저장소
     * @param  UserRepositoryInterface  $userRepository  회원 저장소
     */
    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * 가입 훅은 challenge 발행(5)보다 뒤에, 본인확인 성공 훅은 회원 활성화(15)보다 뒤에 돈다.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 정보
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.after_register' => [
                'method' => 'handle',
                'priority' => 20,
                'sync' => true,
            ],
            'core.identity.after_verify' => [
                'method' => 'handleVerified',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 가입 직후 — 가입 전 확인 토큰이 메일로 그 이메일을 확인한 것이면 기록합니다.
     *
     * @param  mixed  ...$args  [0]=User 가입 회원, [1]=array 문맥(verification_token 포함)
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;
        $context = is_array($args[1] ?? null) ? $args[1] : [];
        $token = (string) ($context['verification_token'] ?? '');

        if (! $user instanceof User || $token === '') {
            return;
        }

        try {
            // 가입 전 확인 리스너가 이 요청에서 토큰을 이미 소비했으므로 소비 여부와 무관하게 찾는다.
            $log = $this->logRepository->findVerifiedByToken($token, 'signup');
            if ($log !== null && $this->confirmsEmailOf($log, $user)) {
                $this->userRepository->markEmailVerified($user);
            }
        } catch (\Throwable $e) {
            Log::warning('[identity] 가입 이메일 인증 기록 실패', ['user_id' => $user->getKey(), 'error' => class_basename($e)]);
        }
    }

    /**
     * 본인확인 성공 직후 — 가입 후 확인 요청이 메일로 그 회원 이메일을 확인한 것이면 기록합니다.
     *
     * @param  mixed  ...$args  [0]=VerificationResult 결과, [1]=IdentityVerificationLog 요청 행
     * @return void 반환값 없음
     */
    public function handleVerified(...$args): void
    {
        $result = $args[0] ?? null;
        $log = $args[1] ?? null;

        if (! is_object($result) || ! ($result->success ?? false) || ! $log instanceof IdentityVerificationLog) {
            return;
        }
        if ($log->purpose !== 'signup' || $log->user_id === null) {
            return;
        }

        try {
            $user = $this->userRepository->findById((int) $log->user_id);
            if ($user !== null && $this->confirmsEmailOf($log, $user)) {
                $this->userRepository->markEmailVerified($user);
            }
        } catch (\Throwable $e) {
            Log::warning('[identity] 가입 이메일 인증 기록 실패', ['user_id' => $log->user_id, 'error' => class_basename($e)]);
        }
    }

    /**
     * 요청 행이 메일 수단으로 그 회원의 이메일을 확인한 것인지 판정합니다.
     *
     * @param  IdentityVerificationLog  $log  본인확인 요청 행
     * @param  User  $user  회원
     * @return bool 메일 수단이고 대상이 회원 이메일이면 true
     */
    private function confirmsEmailOf(IdentityVerificationLog $log, User $user): bool
    {
        return $log->provider_id === MailIdentityProvider::ID
            && (string) $user->email !== ''
            && hash_equals((string) $log->target_hash, hash('sha256', mb_strtolower((string) $user->email)));
    }
}
