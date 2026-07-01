<?php

namespace Plugins\Sirsoft\VerificationKginicis\Repositories;

use App\Models\IdentityVerificationLog;

/**
 * 본 plugin 의 동일인 검증 listener 전용 IdentityVerificationLog 조회 Repository.
 *
 * 코어 IdentityVerificationLogRepository::findVerifiedForToken() 은 consumed_at IS NULL 필터를 갖는다
 * (토큰 1회 사용 강제). 본 plugin 의 listener 들은 코어 AssertIdentityVerifiedBeforeRegister
 * (priority 10) 가 consume 한 뒤 priority 20 에서 같은 토큰으로 metadata.di_hash/ci_hash 를
 * 회수해야 하므로 consumed_at 무관 조회 메서드가 필요하다.
 *
 * 보안: status=Verified 인 row 만 반환 — 미인증/만료 토큰은 노출하지 않는다.
 *
 * @since 1.0.0-beta.1
 */
interface InicisIdentityLogQueryRepositoryInterface
{
    /**
     * verified 상태의 IDV 로그를 token + purpose 로 조회한다 (consumed_at 무관).
     *
     * @param  string  $token  verification_token
     * @param  string  $purpose  IDV purpose (signup / password_reset / sensitive_action 등)
     * @return IdentityVerificationLog|null  매칭 로그 또는 null
     */
    public function findVerifiedLogForToken(string $token, string $purpose): ?IdentityVerificationLog;

    /**
     * 탈퇴/삭제 처리된 사용자의 본 plugin 발행 로그를 익명화한다 (PIPC).
     *
     * user_id 를 NULL 로 비우는 동시에 로그 metadata 의 PII성 식별자 해시
     * (di_hash / ci_hash) 를 함께 파기한다 — CI/DI 해시는 동일인 추적에 쓰일 수 있어
     * 탈퇴자에게도 잔존하면 안 된다. matched_field 등 감사 추적 필드는 보존한다.
     *
     * 본 plugin 의 inicis provider 가 발행한 로그(provider_id='inicis') 만 처리한다 —
     * 다른 IDV provider 발행 로그는 각자의 listener 책임 영역.
     *
     * @param  int  $userId  탈퇴/삭제 사용자 id
     * @return int  익명화된 row 수
     */
    public function anonymizeUserId(int $userId): int;

    /**
     * 로그의 metadata JSON 에 추가 키를 병합한다 (감사 추적 보조).
     *
     * 동일인 중복 차단 시점에 어느 hash 컬럼(di_hash / ci_hash) 으로 매칭됐는지를
     * `metadata.matched_field` 로 보존해 운영자가 admin 인증이력 화면에서 차단 사유를
     * 추적할 수 있게 한다. 기존 metadata 키는 보존하고 신규 키만 병합.
     *
     * @param  string  $logId  대상 로그 id (uuid)
     * @param  array<string, mixed>  $patch  병합할 metadata 키 (예: ['matched_field' => 'di_hash'])
     * @return bool  업데이트 성공 여부
     */
    public function appendMetadata(string $logId, array $patch): bool;
}
