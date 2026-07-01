<?php

namespace Plugins\Sirsoft\VerificationKginicis\Repositories;

use Plugins\Sirsoft\VerificationKginicis\Models\InicisIdentityRecord;

/**
 * `inicis_identity_records` 테이블 Repository 구현체.
 *
 * 본 plugin 의 boot() 에서 인터페이스 바인딩으로 등록된다 (`PluginInterface::register`).
 * Service / Listener / Controller 는 인터페이스만 의존하고 구체 클래스에 의존하지 않는다.
 *
 * @since 1.0.0-beta.1
 */
class InicisIdentityRecordRepository implements InicisIdentityRecordRepositoryInterface
{
    /**
     * user_id 로 레코드를 조회한다.
     *
     * @param  int  $userId  사용자 ID
     * @return InicisIdentityRecord|null
     */
    public function findByUserId(int $userId): ?InicisIdentityRecord
    {
        return InicisIdentityRecord::query()->where('user_id', $userId)->first();
    }

    /**
     * mTxId 로 레코드를 조회한다 (감사용).
     *
     * 본 plugin 의 InicisChallengeMapping 으로 mtxid → challenge_id 회수 후
     * 코어 IdentityVerificationLog 에서 user_id 조회 → record 검색.
     * 일반 callback 매칭은 InicisChallengeMappingRepository 직접 사용 권장.
     *
     * @param  string  $mTxId  이니시스 가맹점 거래 ID
     * @return InicisIdentityRecord|null
     */
    public function findByMTxId(string $mTxId): ?InicisIdentityRecord
    {
        $challengeId = app(InicisChallengeMappingRepositoryInterface::class)
            ->findChallengeIdByMtxid($mTxId);

        if ($challengeId === null) {
            return null;
        }

        $log = \App\Models\IdentityVerificationLog::query()
            ->whereKey($challengeId)
            ->whereNotNull('user_id')
            ->first();

        return $log ? $this->findByUserId((int) $log->user_id) : null;
    }

    /**
     * di_hash 로 동일인 검색.
     *
     * @param  string  $diHash  SHA256(userDi)
     * @return InicisIdentityRecord|null
     */
    public function findByDiHash(string $diHash): ?InicisIdentityRecord
    {
        return InicisIdentityRecord::query()->where('di_hash', $diHash)->first();
    }

    /**
     * ci_hash 로 동일인 검색 (ci2_hash 도 fallback 검색).
     *
     * @param  string  $ciHash  SHA256(userCi)
     * @return InicisIdentityRecord|null
     */
    public function findByCiHash(string $ciHash): ?InicisIdentityRecord
    {
        return InicisIdentityRecord::query()
            ->where('ci_hash', $ciHash)
            ->orWhere('ci2_hash', $ciHash)
            ->first();
    }

    /**
     * 사용자 PII 레코드를 생성하거나 갱신한다 (UPSERT).
     *
     * @param  int  $userId  사용자 ID
     * @param  array<string, mixed>  $attributes  컬럼 값 (PII 평문은 호출자가 Crypt::encrypt 처리 후 전달)
     * @return InicisIdentityRecord 생성/갱신된 레코드
     */
    public function upsertForUser(int $userId, array $attributes): InicisIdentityRecord
    {
        $existing = $this->findByUserId($userId);

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        $attributes['user_id'] = $userId;

        return InicisIdentityRecord::query()->create($attributes);
    }

    /**
     * user_id 로 레코드를 삭제한다 (회원 탈퇴/사용자 삭제 시).
     *
     * @param  int  $userId  사용자 ID
     * @return bool 삭제 성공 여부 (행이 없어도 true)
     */
    public function deleteByUserId(int $userId): bool
    {
        InicisIdentityRecord::query()->where('user_id', $userId)->delete();

        return true;
    }
}
