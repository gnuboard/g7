<?php

namespace App\Extension\Traits;

use App\Exceptions\StateContractConflictException;
use App\Support\StateContracts;

/**
 * 전역 상태 공개 계약 충돌 검증 트레이트
 *
 * 설치·활성화 때 확장이 선언한 `state_contracts` 이름을 코어 예약 계약이나 다른 활성 확장이 이미 소유하는지
 * 확인합니다. 두 확장이 같은 전역 상태를 「내 것」이라고 선언하면, 한쪽이 쓴 값을 다른 쪽이 자기 모양으로
 * 읽어 조용히 깨집니다 — 그래서 설치 단계에서 막습니다.
 *
 * 자기 자신의 재설치·재활성화는 막지 않습니다(같은 소유자). 같은 유형의 활성 템플릿을 갈아끼우는 경우에는
 * 교체될 템플릿의 선언을 빼고 검사합니다 — 같은 계약을 선언한 새 사용자 템플릿이 막히면 안 됩니다.
 *
 * @since 7.1.0
 */
trait ValidatesStateContracts
{
    /**
     * 선언한 상태 계약이 다른 소유자와 겹치지 않는지 검증합니다.
     *
     * @param  string  $ownerKey  선언하는 확장의 소유자 키 (`module:{id}`·`plugin:{id}`·`template:{id}`)
     * @param  array<string, mixed>  $declared  선언한 계약 (이름 → 정의)
     * @param  array<int, string>  $ignoredOwners  검사에서 뺄 소유자 키 (교체될 템플릿 등)
     * @return void 반환값 없음
     *
     * @throws StateContractConflictException 이름을 다른 소유자가 이미 가진 경우
     */
    protected function validateStateContracts(string $ownerKey, array $declared, array $ignoredOwners = []): void
    {
        if ($declared === []) {
            return;
        }

        $owners = [];
        foreach (array_keys(StateContracts::CORE_CONTRACTS) as $name) {
            $owners[$name] = StateContracts::CORE_OWNER;
        }

        foreach (StateContracts::declaredByActiveExtensions() as $owner => $contracts) {
            if ($owner === $ownerKey || in_array($owner, $ignoredOwners, true)) {
                continue;
            }

            foreach (array_keys($contracts) as $name) {
                $owners[$name] ??= $owner;
            }
        }

        foreach (array_keys($declared) as $name) {
            if (isset($owners[$name])) {
                throw new StateContractConflictException(__('extensions.errors.state_contract_conflict', [
                    'name' => $name,
                    'owner' => $owners[$name],
                    'extension' => $ownerKey,
                ]));
            }
        }
    }
}
