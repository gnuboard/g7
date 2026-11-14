<?php

namespace Plugins\Sirsoft\VerificationKginicis\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Models\User;
use App\Services\IdentityTokenAcceptanceService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;

/**
 * 비로그인 가입 시 Cache stash 된 이니시스 PII 를 회수하여 inicis_identity_records 에 기록한다.
 *
 * 흐름 (이니시스 매뉴얼 STEP4 → 코어 register → 본 listener):
 *  1. 비로그인 사용자가 이니시스 본인확인 → provider.verify() 가 PII 를 Cache 에 stash
 *     (key=`inicis:pending_record:{challenge_id}`, TTL=challenge.expires_at)
 *  2. 사용자가 verification_token 을 들고 가입 폼 제출 → AuthService::register() 성공
 *  3. AuthService 가 `core.auth.after_register` 훅 발화 (verification_token 포함)
 *  4. 본 listener: token → log 식별 → Cache 에서 PII 회수 → inicis_identity_records upsert
 *     + log.user_id backfill + Cache::forget
 *
 * sync 실행 — 가입 트랜잭션 완료 직후 PII 가 즉시 영구 저장되어야 한다.
 *
 * @since 1.0.0-beta.1
 */
class CompleteInicisRecordAfterRegister implements HookListenerInterface
{
    /**
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  코어 IDV log Repository (user_id backfill 용)
     * @param  IdentityTokenAcceptanceService  $tokenAcceptance  같은 가입 요청에서 인정한 본인인증 결과 조회 (1.1.4)
     * @param  InicisIdentityRecordRepositoryInterface  $recordRepository  본 plugin record Repository
     * @param  CacheInterface  $cache  비로그인 verify PII stash 회수 + 정리용 (PluginCacheDriver 자동 prefix)
     */
    public function __construct(
        protected readonly IdentityVerificationLogRepositoryInterface $logRepository,
        protected readonly IdentityTokenAcceptanceService $tokenAcceptance,
        protected readonly InicisIdentityRecordRepositoryInterface $recordRepository,
        protected readonly CacheInterface $cache,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.after_register' => [
                'method' => 'handle',
                'priority' => 50,
                'sync' => true,
            ],
        ];
    }

    /**
     * 훅 진입점.
     *
     * AuthService::register() 호출 시그니처:
     *   HookManager::doAction('core.auth.after_register', $user, [
     *       'registration_time' => ..., 'ip_address' => ..., 'user_agent' => ...,
     *       'signup_stage' => 'after_create', 'verification_token' => $data['verification_token'] ?? null,
     *   ]);
     *
     * @param  mixed  ...$args  [$user, $context]
     * @return void
     */
    public function handle(...$args): void
    {
        /** @var User|null $user */
        $user = $args[0] ?? null;
        /** @var array<string, mixed> $context */
        $context = is_array($args[1] ?? null) ? $args[1] : [];

        if (! $user instanceof User) {
            return;
        }

        $token = isset($context['verification_token']) ? (string) $context['verification_token'] : '';
        if ($token === '') {
            return;
        }

        // 같은 가입 요청에서 before_register 단계가 인정(1회 소비)한 결과를 받는다(1.1.4) — 소비 여부와 무관하게 토큰만으로
        // 다시 찾으면 다른 요청이 쓴 결과의 PII 를 이 회원에게 붙일 수 있었다.
        $log = $this->tokenAcceptance->acceptedInRequest($token, 'signup');
        if ($log === null) {
            return;
        }

        if ((string) $log->provider_id !== InicisIdentityProvider::PROVIDER_ID) {
            return;
        }

        $cacheKey = InicisIdentityProvider::PENDING_RECORD_CACHE_PREFIX.$log->id;
        $piiPayload = $this->cache->get($cacheKey);

        if (! is_array($piiPayload) || $piiPayload === []) {
            Log::warning('이니시스 비로그인 가입 backfill: Cache PII 부재', [
                'log_id' => $log->id,
                'user_id' => $user->id,
            ]);

            return;
        }

        $verifiedAt = $log->verified_at;

        // 저장 단계가 부분 실패하더라도 (1) 실패를 운영자가 인지할 수 있게 로깅하고
        // (2) stash 된 PII 캐시는 반드시 정리한다 (TTL 만료까지 평문성 PII 잔류 방지 — PIPC).
        // record upsert 실패는 재시도 여지가 있어 예외를 전파하지 않고(가입 트랜잭션 보호) 로깅만 한다.
        // backfill(log.user_id 역참조) 실패는 PII 저장 자체에 영향이 없어 로깅 후 계속 진행한다.
        try {
            $this->recordRepository->upsertForUser((int) $user->id, array_merge($piiPayload, [
                'latest_log_id' => $log->id,
                'verified_at' => $verifiedAt,
                're_verified_at' => $verifiedAt,
            ]));

            try {
                $this->logRepository->backfillUserId($log->id, (int) $user->id);
            } catch (\Throwable $e) {
                // 역참조(log.user_id) 누락은 PII 저장 정합성에 영향 없음 — 감사 추적 손실만 발생.
                Log::error('이니시스 비로그인 가입 backfill: log.user_id 역참조 실패', [
                    'log_id' => $log->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('이니시스 비로그인 가입 backfill: PII record 저장 실패', [
                'log_id' => $log->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // 성공/실패 무관하게 stash PII 캐시는 정리 — 잔류 시 다음 가입에서 stale PII 회수 위험.
            $this->cache->forget($cacheKey);
        }
    }
}
