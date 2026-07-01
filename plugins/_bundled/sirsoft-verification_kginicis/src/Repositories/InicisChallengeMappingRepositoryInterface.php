<?php

namespace Plugins\Sirsoft\VerificationKginicis\Repositories;

use Plugins\Sirsoft\VerificationKginicis\Models\InicisChallengeMapping;

/**
 * 이니시스 mTxId ↔ challenge_id 매핑 Repository 인터페이스.
 *
 * Service / Listener / Controller 는 이 인터페이스만 타입힌트하고 구체 클래스에 의존하지 않는다.
 *
 * @since 1.0.0-beta.1
 */
interface InicisChallengeMappingRepositoryInterface
{
    /**
     * mTxId 와 challenge_id 의 매핑 행을 생성한다 (INSERT).
     *
     * @param  string  $mtxid  이니시스 가맹점 거래 ID (18자)
     * @param  string  $challengeId  코어 IdentityVerificationLog UUID
     * @return InicisChallengeMapping 생성된 매핑 행
     */
    public function create(string $mtxid, string $challengeId): InicisChallengeMapping;

    /**
     * mTxId 로 매핑된 challenge_id 를 회수한다.
     *
     * @param  string  $mtxid  이니시스 가맹점 거래 ID
     * @return string|null 매핑된 challenge_id (UUID) 또는 null
     */
    public function findChallengeIdByMtxid(string $mtxid): ?string;

    /**
     * challenge_id 로 매핑 행을 조회한다 (감사용).
     *
     * @param  string  $challengeId  코어 IdentityVerificationLog UUID
     * @return InicisChallengeMapping|null
     */
    public function findByChallengeId(string $challengeId): ?InicisChallengeMapping;
}
