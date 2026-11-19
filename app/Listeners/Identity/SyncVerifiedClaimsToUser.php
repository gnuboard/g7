<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityClaimsService;
use Illuminate\Support\Facades\Log;

/**
 * 본인인증 결과를 회원의 잠금 항목에 맞춥니다.
 *
 * | 훅 | 하는 일 |
 * | --- | --- |
 * | `core.identity.after_verify` | 로그인 회원이 다시 본인인증을 마치면 「인증 값으로 채우고 잠금」 항목을 새 인증 값으로 덮어쓴다 |
 * | `core.auth.after_register` | 인증 값을 주는 수단으로 인증하고 가입한 회원에게 그 인증을 기록한다 (내 정보 수정의 잠금이 읽는다) |
 *
 * 수단이 인증 값을 주지 않으면(메일 인증번호 등) 아무것도 하지 않는다. 동기화 실패가 본인인증이나 가입을 깨지 않도록 예외는 기록만 한다.
 *
 * @since 7.2.0
 */
class SyncVerifiedClaimsToUser implements HookListenerInterface
{
    /**
     * @param  IdentityClaimsService  $claims  본인인증 값 Service
     * @param  UserRepositoryInterface  $users  회원 저장소
     */
    public function __construct(
        private readonly IdentityClaimsService $claims,
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.identity.after_verify' => ['method' => 'handleVerified', 'priority' => 30, 'sync' => true],
            // 본인인증 플러그인이 가입 직후 자기 기록을 옮기는 순서(50)보다 뒤에서 돈다
            'core.auth.after_register' => ['method' => 'handleRegistered', 'priority' => 60, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 — 훅마다 전용 메서드를 쓰므로 호출되지 않습니다.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 훅별 메서드를 쓴다
    }

    /**
     * 본인인증 성공 직후 — 회원에게 묶인 요청이면 잠금 항목을 새 인증 값으로 맞춥니다.
     *
     * @param  mixed  ...$args  [0]=VerificationResult 결과, [1]=IdentityVerificationLog 요청 행
     * @return void 반환값 없음
     */
    public function handleVerified(...$args): void
    {
        $result = $args[0] ?? null;
        $log = $args[1] ?? null;

        if (! is_object($result) || ! ($result->success ?? false) || ! $log instanceof IdentityVerificationLog || $log->user_id === null) {
            return;
        }

        try {
            $user = $this->users->findById((int) $log->user_id);
            // 프로바이더가 방금 상태를 바꿨으므로 요청 행을 다시 읽는다.
            $fresh = $log->fresh();
            $claims = $user !== null && $fresh !== null ? $this->claims->forLog($fresh, $user) : null;

            if ($user !== null && $claims !== null) {
                $this->claims->syncToUser($user, $claims);
            }
        } catch (\Throwable $e) {
            Log::warning('[identity] 본인인증 값 동기화 실패', ['user_id' => $log->user_id, 'error' => class_basename($e)]);
        }
    }

    /**
     * 가입 직후 — 가입 요청에 실린 본인인증 토큰의 인증을 회원에게 기록합니다.
     *
     * @param  mixed  ...$args  [0]=User 가입 회원, [1]=array 문맥(verification_token 포함)
     * @return void 반환값 없음
     */
    public function handleRegistered(...$args): void
    {
        $user = $args[0] ?? null;
        $context = is_array($args[1] ?? null) ? $args[1] : [];
        $token = (string) ($context['verification_token'] ?? '');

        if (! $user instanceof User || $token === '') {
            return;
        }

        try {
            $this->claims->recordSignupVerification($user, $token);
        } catch (\Throwable $e) {
            Log::warning('[identity] 가입 본인인증 기록 실패', ['user_id' => $user->getKey(), 'error' => class_basename($e)]);
        }
    }
}
