<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Repositories;

use App\Models\IdentityVerificationLog;

/**
 * 본 plugin 이 발행한 코어 IDV 로그의 보조 조회/갱신 계약.
 *
 * 코어 표준 Repository 가 제공하지 않는 두 가지를 담당한다:
 *  - consumed_at 무관 verified 로그 조회 (코어 listener 가 consume 한 뒤 후속 listener 가 조회)
 *  - 탈퇴/삭제 시 로그 익명화 (user_id 해제 + metadata 식별자 해시 파기)
 *
 * @since 1.0.0
 */
interface KcpIdentityLogQueryRepositoryInterface
{
    /**
     * 아직 쓰지 않았고 만료되지 않은 verified IDV 로그를 token + purpose 로 조회한다.
     *
     * 1.1.4 부터 소비·만료된 결과는 돌려주지 않는다(KVE 동형 B6) — 가입 경로는 코어 `IdentityTokenAcceptanceService` 로
     * 같은 요청에서 인정한 결과를 받는다.
     *
     * @param  string  $token  verification_token
     * @param  string  $purpose  IDV purpose
     * @return IdentityVerificationLog|null 매칭 로그 또는 null
     */
    public function findVerifiedLogForToken(string $token, string $purpose): ?IdentityVerificationLog;

    /**
     * 탈퇴/삭제 사용자의 본 plugin 발행 로그를 익명화한다.
     *
     * @param  int  $userId  탈퇴/삭제 사용자 id
     * @return int 익명화된 row 수
     */
    public function anonymizeUserId(int $userId): int;

    /**
     * 로그의 metadata JSON 에 추가 키를 병합한다 (기존 키 보존).
     *
     * @param  string  $logId  대상 로그 id
     * @param  array<string, mixed>  $patch  병합할 metadata 키
     * @return bool 업데이트 성공 여부
     */
    public function appendMetadata(string $logId, array $patch): bool;
}
