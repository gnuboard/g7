<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

use App\Extension\HookManager;

/**
 * 테스트용 현금영수증 발급 프로바이더 등록 헬퍼
 *
 * 현금영수증 프로바이더 선택값은 저장만으로 유효해지지 않는다 — 그 프로바이더를 실제로
 * 제공하는 확장이 `sirsoft-ecommerce.cash_receipt.registered_providers` 훅에 자신을
 * 등록해야 한다(A3). 그래서 설정값만 넣고 확장을 등록하지 않으면 "제공자 없음" 상태가 되며,
 * 이는 운영에서 플러그인을 제거한 상태와 같다.
 *
 * 프로바이더가 살아 있는 상황을 검증하려는 테스트는 이 트레이트를 사용해 레지스트리에도
 * 등록한다. 등록은 `HookManager` 정적 상태에 남지만 `ModuleTestCase` 가 테스트마다
 * 스냅샷/복원하므로 누수되지 않는다.
 */
trait RegistersTestCashReceiptProvider
{
    /**
     * 현금영수증 발급 프로바이더를 레지스트리에 등록합니다.
     *
     * @param  string  $providerId  프로바이더 ID
     */
    protected function registerCashReceiptProvider(string $providerId): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.registered_providers',
            function (array $providers) use ($providerId) {
                $providers[] = ['id' => $providerId, 'name' => strtoupper($providerId)];

                return $providers;
            }
        );
    }
}
