<?php

namespace App\Contracts\Repositories;

use App\Models\IdentityVerificationLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * identity_verification_logs 테이블 Repository 계약.
 *
 * Challenge 생명주기(requested/sent/verified/failed/expired/cancelled/policy_violation_logged)의
 * 적재·조회·보관주기 파기 책임을 갖습니다.
 *
 * @since 7.0.0-beta.4
 */
interface IdentityVerificationLogRepositoryInterface
{
    /**
     * 로그를 생성합니다.
     *
     * @param  array<string, mixed>  $attributes  로그 속성
     * @return IdentityVerificationLog 생성된 로그
     */
    public function create(array $attributes): IdentityVerificationLog;

    /**
     * id(UUID) 로 조회합니다.
     *
     * @param  string  $id  로그 UUID
     * @return IdentityVerificationLog|null
     */
    public function findById(string $id): ?IdentityVerificationLog;

    /**
     * id 기준 업데이트.
     *
     * @param  string  $id  로그 UUID
     * @param  array<string, mixed>  $attributes  변경할 속성
     * @return bool 1건 이상 업데이트되었는지 여부
     */
    public function updateById(string $id, array $attributes): bool;

    /**
     * target_hash + purpose 조합으로 최근 성공한 challenge 를 조회합니다.
     * grace_minutes 내 재사용 가능 여부 판정에 사용합니다.
     *
     * @param  string  $purpose  IDV 목적
     * @param  int|null  $userId  로그인 상태에서는 user_id 우선 매칭
     * @param  string|null  $targetHash  user_id 가 null 일 때 대신 매칭하는 sha256 해시
     * @param  int  $withinMinutes  grace_minutes (이 분 내 verified 만 매칭)
     * @param  string|null  $providerId  정책이 지정한 인증 수단 — 주면 그 수단으로 받은 결과만 매칭(7.1.6)
     * @return IdentityVerificationLog|null 매칭 로그 또는 null
     */
    public function findRecentVerified(
        string $purpose,
        ?int $userId,
        ?string $targetHash,
        int $withinMinutes,
        ?string $providerId = null,
    ): ?IdentityVerificationLog;

    /**
     * 진행 중 challenge 의 시도 횟수를 상한 안에서만 1 올립니다.
     *
     * `attempts = attempts + 1` 을 DB 가 계산한다 — 읽은 값에 1 을 더해 저장하면 동시 오답이 서로를 덮어
     * 시도가 사라진다(KVE-2026-3190).
     *
     * @param  string  $id  challenge id
     * @return int|null 올린 뒤 시도 횟수 (상한 도달·진행 중 아님이면 null)
     *
     * @since 7.1.6
     */
    public function incrementAttemptsIfAllowed(string $id): ?int;

    /**
     * 진행 중(requested·sent·processing)이고 아직 확인되지 않은 challenge 만 확인 완료로 바꿉니다.
     *
     * 영향 행 수로 판정한다 — 같은 challenge 에 맞는 코드를 동시에 내도 한 요청만 true 를 받는다.
     *
     * @param  string  $id  challenge id
     * @param  array<string, mixed>  $attributes  함께 저장할 값 (status·verified_at·verification_token 등)
     * @return bool 이번 호출이 바꿨으면 true
     *
     * @since 7.1.6
     */
    public function markVerifiedIfPending(string $id, array $attributes): bool;

    /**
     * 진행 중(requested·sent)인 challenge 만 실패(잠금)로 바꿉니다.
     *
     * @param  string  $id  challenge id
     * @return bool 이번 호출이 바꿨으면 true
     *
     * @since 7.1.6
     */
    public function markFailedIfPending(string $id): bool;

    /**
     * 진행 중(requested·sent·processing)인 challenge 만 취소로 바꿉니다 — 이미 확인된 challenge 는 그대로 둔다.
     *
     * @param  string  $id  challenge id
     * @return bool 이번 호출이 바꿨으면 true
     *
     * @since 7.1.6
     */
    public function markCancelledIfPending(string $id): bool;

    /**
     * 확인 완료·미소비·미만료 결과만 소비(consumed_at)로 표시합니다.
     *
     * @param  string  $id  challenge id
     * @param  \DateTimeInterface  $consumedAt  소비 시각 (해제 때 같은 값으로 짝을 찾는다 — 초 단위로 넘긴다)
     * @return bool 이번 호출이 소비했으면 true (다른 요청이 먼저 썼으면 false)
     *
     * @since 7.1.6
     */
    public function consumeIfUnconsumed(string $id, \DateTimeInterface $consumedAt): bool;

    /**
     * 이 요청이 남긴 소비 표시만 되돌립니다 (작업이 입력 오류로 끝나 인증 결과를 돌려줄 때).
     *
     * @param  string  $id  challenge id
     * @param  \DateTimeInterface  $consumedAt  소비할 때 쓴 시각
     * @return bool 되돌렸으면 true
     *
     * @since 7.1.6
     */
    public function releaseConsumption(string $id, \DateTimeInterface $consumedAt): bool;

    /**
     * challenge 를 행 잠금으로 읽습니다 — 트랜잭션 안에서 읽고 고쳐 쓸 때 쓴다.
     *
     * @param  string  $id  challenge id
     * @return IdentityVerificationLog|null 조회 결과
     *
     * @since 7.1.6
     */
    public function findByIdForUpdate(string $id): ?IdentityVerificationLog;

    /**
     * 같은 프로바이더·수신 대상·목적으로 주어진 시각 이후에 발행된 가장 최근 challenge 를 찾습니다.
     * 발송이 실패로 끝난 challenge 는 제외합니다 — 받지 못한 메일 때문에 재요청을 막지 않습니다.
     * 재요청 대기 판정에 사용합니다.
     *
     * @param  string  $providerId  프로바이더 id
     * @param  string  $targetHash  수신 대상 sha256 해시
     * @param  string  $purpose  IDV 목적
     * @param  \DateTimeInterface  $since  이 시각 이후 발행분만
     * @return IdentityVerificationLog|null 매칭 로그 또는 null
     */
    public function findLatestRequestedSince(
        string $providerId,
        string $targetHash,
        string $purpose,
        \DateTimeInterface $since,
    ): ?IdentityVerificationLog;

    /**
     * 특정 토큰(verification_token) 으로 verified 상태의 challenge 를 찾습니다.
     * IdvTokenRule 이 register 검증 시 사용합니다.
     *
     * @param  string  $token  verification_token
     * @param  string  $purpose  IDV 목적 (예: signup)
     * @return IdentityVerificationLog|null 매칭 로그 또는 null
     */
    public function findVerifiedForToken(string $token, string $purpose): ?IdentityVerificationLog;

    /**
     * 만료 경과 challenge 를 일괄 expire 처리합니다.
     *
     * @return int 처리된 행 수
     */
    public function expirePastDue(): int;

    /**
     * 만료 시각이 지난 진행 중(requested·sent) challenge 를 ID 순으로 나눠 콜백에 넘깁니다.
     *
     * 건별로 만료 훅을 발화해야 하는 호출자가 쓴다 — 일괄 update 는 어느 건이 만료됐는지 돌려주지 않는다.
     *
     * @param  int  $size  한 번에 넘길 건수
     * @param  callable(Collection<int, IdentityVerificationLog>): void  $callback  청크 콜백
     * @return void
     */
    public function chunkPendingPastDue(int $size, callable $callback): void;

    /**
     * challenge 가 아직 진행 중(requested·sent)일 때만 만료로 바꿉니다.
     *
     * 조회와 변경 사이에 인증이 끝난 건을 만료로 덮지 않도록 상태 조건을 다시 건다.
     *
     * @param  IdentityVerificationLog  $log  대상 challenge
     * @return bool 이번 호출로 만료로 바뀌었으면 true
     */
    public function expireIfPending(IdentityVerificationLog $log): bool;

    /**
     * 보관주기 경과 로그를 일괄 삭제합니다.
     *
     * @param  int  $days  보관 일수 (이보다 오래된 로그가 삭제됨)
     * @return int 삭제된 행 수
     */
    public function purgeOlderThan(int $days): int;

    /**
     * 목록 조회 (관리자 인증 이력 화면).
     *
     * @param  array<string, mixed>  $filters  provider_id/purpose/status/user_id/date_from/date_to 등
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator
     */
    public function search(array $filters, int $perPage = 20);

    /**
     * user_id 백필 (signup 흐름에서 pre-signup challenge → 가입 성공 후 user_id 채움).
     *
     * @param  string  $id  로그 UUID
     * @param  int  $userId  채울 user_id
     * @return bool 백필 성공 여부 (이미 user_id 가 있으면 false)
     */
    public function backfillUserId(string $id, int $userId): bool;
}
