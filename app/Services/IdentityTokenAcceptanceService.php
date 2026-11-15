<?php

namespace App\Services;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Models\IdentityVerificationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 본인인증 결과(verification_token)를 「발급 때 묶인 조건이 맞을 때, 작업 1건에 한 번만」 인정합니다.
 *
 * 결박 조건:
 *   - purpose 가 같아야 한다.
 *   - 정책이 인증 수단을 지정했으면 그 수단으로 받은 결과여야 한다(정확히 일치). 지정하지 않았으면 어떤 수단이든 된다.
 *   - 대상: 회원이면 결과의 user_id 가 그 회원이거나(user_id 가 비었으면 대상 해시 일치), 비회원이면 대상 해시가 같아야 한다.
 *     대상 개념이 없는 비회원 정책(대상 해시도 회원도 없음)은 수단 + 1회 사용만으로 결박한다.
 *
 * 「작업 1건 = HTTP 요청 1건」 — 한 요청 안에서는 서로 다른 정책 검사(라우트 정책 + 훅 정책)도 같은 결과를 다시 인정한다.
 * 인정 기록은 현재 Request 의 attributes 에 둔다(컨테이너 scoped 인스턴스는 테스트의 연속 요청에서 비워지지 않는다).
 * 작업이 입력 오류(422)·다른 본인인증 요구(428)로 끝나면 `releaseAccepted()` 로 이 요청이 소비한 것만 되돌린다.
 *
 * @since 7.1.6
 */
class IdentityTokenAcceptanceService
{
    /**
     * Request attributes 에 인정 기록을 두는 키.
     */
    public const REQUEST_KEY = 'g7.identity.accepted_tokens';

    /**
     * 본인인증 결과 인정 서비스를 생성합니다.
     *
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인인증 이력 Repository
     */
    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
    ) {}

    /**
     * 결박 조건이 맞으면 결과를 소비하고 인정합니다 (같은 요청에서 이미 인정했으면 결박만 다시 본다).
     *
     * @param  string  $token  확인 토큰
     * @param  string  $purpose  요구 목적
     * @param  string|null  $requiredProviderId  정책이 지정한 인증 수단 (null 이면 수단 무관)
     * @param  string|null  $targetHash  대상 해시 (sha256 소문자 이메일)
     * @param  int|null  $userId  행위 회원 ID
     * @param  Request|null  $request  현재 요청 (기본: 컨테이너의 요청)
     * @return IdentityVerificationLog|null 인정한 결과 (결박 불일치·이미 소비·없음이면 null)
     */
    public function accept(
        string $token,
        string $purpose,
        ?string $requiredProviderId = null,
        ?string $targetHash = null,
        ?int $userId = null,
        ?Request $request = null,
    ): ?IdentityVerificationLog {
        if ($token === '') {
            return null;
        }

        $request ??= $this->currentRequest();

        $already = $this->acceptedInRequest($token, $purpose, $request);
        if ($already !== null) {
            return $this->bindingMatches($already, $purpose, $requiredProviderId, $targetHash, $userId) ? $already : null;
        }

        $log = $this->logRepository->findVerifiedForToken($token, $purpose);
        if ($log === null || ! $this->bindingMatches($log, $purpose, $requiredProviderId, $targetHash, $userId)) {
            return null;
        }

        // 초 단위로 남긴다 — timestamp 컬럼이 초 정밀도라 해제 때 같은 값으로 짝을 찾는다.
        $consumedAt = Carbon::now()->startOfSecond();
        if (! $this->logRepository->consumeIfUnconsumed((string) $log->getKey(), $consumedAt)) {
            // 다른 요청이 먼저 썼다
            return null;
        }

        $log->consumed_at = $consumedAt;
        $this->remember($request, $token, $log, $consumedAt);

        return $log;
    }

    /**
     * 소비 없이 판정만 합니다 (오류 메시지 구분용).
     *
     * @param  string  $token  확인 토큰
     * @param  string  $purpose  요구 목적
     * @param  string|null  $requiredProviderId  정책이 지정한 인증 수단
     * @param  string|null  $targetHash  대상 해시
     * @param  int|null  $userId  행위 회원 ID
     * @param  Request|null  $request  현재 요청
     * @return IdentityVerificationLog|null 결박이 맞는 결과 (없으면 null)
     */
    public function peek(
        string $token,
        string $purpose,
        ?string $requiredProviderId = null,
        ?string $targetHash = null,
        ?int $userId = null,
        ?Request $request = null,
    ): ?IdentityVerificationLog {
        if ($token === '') {
            return null;
        }

        $log = $this->acceptedInRequest($token, $purpose, $request ?? $this->currentRequest())
            ?? $this->logRepository->findVerifiedForToken($token, $purpose);

        return $log !== null && $this->bindingMatches($log, $purpose, $requiredProviderId, $targetHash, $userId) ? $log : null;
    }

    /**
     * 이 요청에서 이미 인정한 결과를 돌려줍니다.
     *
     * @param  string  $token  확인 토큰
     * @param  string  $purpose  목적
     * @param  Request|null  $request  현재 요청
     * @return IdentityVerificationLog|null 인정한 결과 (없으면 null)
     */
    public function acceptedInRequest(string $token, string $purpose, ?Request $request = null): ?IdentityVerificationLog
    {
        $records = $this->records($request ?? $this->currentRequest());
        $record = $records[$token] ?? null;

        if ($record === null || $record['log']->purpose !== $purpose) {
            return null;
        }

        return $record['log'];
    }

    /**
     * 이 요청이 소비한 결과를 모두 되돌립니다 (작업이 입력 오류·다른 본인인증 요구로 끝났을 때).
     *
     * @param  Request|null  $request  현재 요청
     * @return int 되돌린 건수
     */
    public function releaseAccepted(?Request $request = null): int
    {
        $request ??= $this->currentRequest();
        if ($request === null) {
            return 0;
        }

        $released = 0;
        foreach ($this->records($request) as $record) {
            if ($this->logRepository->releaseConsumption((string) $record['log']->getKey(), $record['consumed_at'])) {
                $released++;
            }
        }

        $request->attributes->remove(self::REQUEST_KEY);

        return $released;
    }

    /**
     * 결과가 목적·수단·대상 결박을 만족하는지 판정합니다.
     *
     * @param  IdentityVerificationLog  $log  결과
     * @param  string  $purpose  요구 목적
     * @param  string|null  $requiredProviderId  지정 수단
     * @param  string|null  $targetHash  대상 해시
     * @param  int|null  $userId  행위 회원 ID
     * @return bool 만족하면 true
     */
    protected function bindingMatches(
        IdentityVerificationLog $log,
        string $purpose,
        ?string $requiredProviderId,
        ?string $targetHash,
        ?int $userId,
    ): bool {
        if ($log->purpose !== $purpose) {
            return false;
        }

        if ($requiredProviderId !== null && $requiredProviderId !== '' && (string) $log->provider_id !== $requiredProviderId) {
            return false;
        }

        if ($userId !== null) {
            if ($log->user_id !== null) {
                return (int) $log->user_id === $userId;
            }

            return $targetHash !== null && hash_equals((string) $log->target_hash, $targetHash);
        }

        if ($targetHash !== null) {
            return hash_equals((string) $log->target_hash, $targetHash);
        }

        // 대상 개념이 없는 비회원 정책 — 수단 + 1회 사용만으로 결박한다
        return true;
    }

    /**
     * 인정 기록을 남깁니다.
     *
     * @param  Request|null  $request  현재 요청
     * @param  string  $token  확인 토큰
     * @param  IdentityVerificationLog  $log  인정한 결과
     * @param  Carbon  $consumedAt  소비 시각
     * @return void 반환값 없음
     */
    protected function remember(?Request $request, string $token, IdentityVerificationLog $log, Carbon $consumedAt): void
    {
        if ($request === null) {
            return;
        }

        $records = $this->records($request);
        $records[$token] = ['log' => $log, 'consumed_at' => $consumedAt];
        $request->attributes->set(self::REQUEST_KEY, $records);
    }

    /**
     * 요청의 인정 기록을 읽습니다.
     *
     * @param  Request|null  $request  현재 요청
     * @return array<string, array{log: IdentityVerificationLog, consumed_at: Carbon}> 토큰별 기록
     */
    protected function records(?Request $request): array
    {
        $records = $request?->attributes->get(self::REQUEST_KEY);

        return is_array($records) ? $records : [];
    }

    /**
     * 컨테이너의 현재 요청을 돌려줍니다 (콘솔·큐에서 바인딩이 없으면 null).
     *
     * @return Request|null 현재 요청
     */
    protected function currentRequest(): ?Request
    {
        try {
            $request = app('request');

            return $request instanceof Request ? $request : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
