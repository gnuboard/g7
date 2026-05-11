<?php

namespace App\Contracts\Repositories;

use App\Models\IdentityVerificationLog;

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
     * @return IdentityVerificationLog|null 매칭 로그 또는 null
     */
    public function findRecentVerified(
        string $purpose,
        ?int $userId,
        ?string $targetHash,
        int $withinMinutes,
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
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
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
