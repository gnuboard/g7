<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Repositories;

use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpIdentityRecord;

/**
 * `nhnkcp_identity_records` 테이블 Repository 구현체.
 *
 * 본 plugin 의 ServiceProvider register() 에서 인터페이스 바인딩으로 등록된다.
 *
 * @since 1.0.0
 */
class KcpIdentityRecordRepository implements KcpIdentityRecordRepositoryInterface
{
    /**
     * 허용 검색 컬럼 화이트리스트 — 외부 입력이 컬럼명으로 흘러드는 것을 차단한다.
     *
     * @var array<int, string>
     */
    private const HASH_COLUMNS = ['di_hash', 'ci_hash'];

    /**
     * user_id 로 레코드를 조회한다.
     *
     * @param  int  $userId  사용자 ID
     * @return KcpIdentityRecord|null 레코드 또는 null
     */
    public function findByUserId(int $userId): ?KcpIdentityRecord
    {
        return KcpIdentityRecord::query()->where('user_id', $userId)->first();
    }

    /**
     * DI keyed-hash 로 동일인을 검색한다.
     *
     * @param  string  $diHash  DI keyed-hash
     * @return KcpIdentityRecord|null 매칭 레코드 또는 null
     */
    public function findByDiHash(string $diHash): ?KcpIdentityRecord
    {
        return KcpIdentityRecord::query()->where('di_hash', $diHash)->first();
    }

    /**
     * CI keyed-hash 로 동일인을 검색한다.
     *
     * @param  string  $ciHash  CI keyed-hash
     * @return KcpIdentityRecord|null 매칭 레코드 또는 null
     */
    public function findByCiHash(string $ciHash): ?KcpIdentityRecord
    {
        return KcpIdentityRecord::query()->where('ci_hash', $ciHash)->first();
    }

    /**
     * 지정 사용자를 제외하고 DI/CI keyed-hash 로 동일인을 검색한다.
     *
     * @param  string  $column  검색 컬럼 (di_hash | ci_hash)
     * @param  string  $hash  keyed-hash 값
     * @param  int|null  $exceptUserId  제외할 사용자 ID (본인 재인증 허용)
     * @return KcpIdentityRecord|null 매칭 레코드 또는 null
     */
    public function findByHashExceptUser(string $column, string $hash, ?int $exceptUserId = null): ?KcpIdentityRecord
    {
        if (! in_array($column, self::HASH_COLUMNS, true) || $hash === '') {
            return null;
        }

        return KcpIdentityRecord::query()
            ->where($column, $hash)
            ->when($exceptUserId !== null, fn ($query) => $query->where('user_id', '!=', $exceptUserId))
            ->first();
    }

    /**
     * 사용자 PII 레코드를 생성하거나 갱신한다 (UPSERT).
     *
     * @param  int  $userId  사용자 ID
     * @param  array<string, mixed>  $attributes  컬럼 값 (PII 평문은 호출자가 암호화 후 전달)
     * @return KcpIdentityRecord 생성/갱신된 레코드
     */
    public function upsertForUser(int $userId, array $attributes): KcpIdentityRecord
    {
        $existing = $this->findByUserId($userId);

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        $attributes['user_id'] = $userId;

        return KcpIdentityRecord::query()->create($attributes);
    }

    /**
     * user_id 로 레코드를 삭제한다 (회원 탈퇴 / 사용자 삭제 시).
     *
     * @param  int  $userId  사용자 ID
     * @return bool 삭제 성공 여부 (행이 없어도 true)
     */
    public function deleteByUserId(int $userId): bool
    {
        KcpIdentityRecord::query()->where('user_id', $userId)->delete();

        return true;
    }
}
