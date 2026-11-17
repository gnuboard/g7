<?php

namespace Tests\Support\Identity;

use App\Models\IdentityVerificationLog;
use App\Repositories\IdentityVerificationLogRepository;

/**
 * 「다른 요청이 같은 행을 먼저 읽은 뒤 늦게 판단하는」 경합을 단일 프로세스에서 재현하는 저장소 대역.
 *
 * 등록한 challenge 는 findById() 가 DB 의 현재 값이 아니라 등록 시점의 낡은 모델 사본을 돌려준다.
 * 쓰기 메서드는 그대로 DB 에 간다 — 그래서 「읽은 값 + 1 절대 저장」·「조건 없는 상태 전이」가
 * 수정 전에는 서로를 덮고, 조건부 갱신으로 바꾼 뒤에는 영향 행 수로 막히는지를 결정론적으로 볼 수 있다.
 */
class StaleReadIdentityLogRepository extends IdentityVerificationLogRepository
{
    /**
     * @var array<string, IdentityVerificationLog> challenge id → 낡은 모델
     */
    private array $stale = [];

    /**
     * challenge 의 지금 상태를 낡은 값으로 고정합니다.
     *
     * @param  IdentityVerificationLog  $log  고정할 모델 (현재 DB 값으로 다시 읽어 복제한다)
     * @return void 반환값 없음
     */
    public function freeze(IdentityVerificationLog $log): void
    {
        $this->stale[(string) $log->getKey()] = IdentityVerificationLog::query()->findOrFail($log->getKey());
    }

    /**
     * 고정된 challenge 는 낡은 사본을, 그 밖은 DB 값을 돌려줍니다.
     *
     * @param  string  $id  조회할 challenge 의 ID
     * @return IdentityVerificationLog|null 조회 결과
     */
    public function findById(string $id): ?IdentityVerificationLog
    {
        if (isset($this->stale[$id])) {
            return clone $this->stale[$id];
        }

        return parent::findById($id);
    }
}
