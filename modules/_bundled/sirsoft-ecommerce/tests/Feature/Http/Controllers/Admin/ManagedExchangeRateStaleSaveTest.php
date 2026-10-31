<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리 환율이 화면을 연 사이 갱신됐을 때의 통화 설정 저장 회귀 테스트 (#703)
 *
 * 회귀 배경: 환율 제공자가 관리하는 통화의 환율 칸은 화면에서 잠겨 있어 운영자가 바꿀 수 없다. 그런데
 * 저장할 때 화면이 받아 둔 환율을 그대로 다시 보냈고, 그 사이 제공자가 환율을 갱신했다면 저장값과 달라
 * 「관리 환율은 변경할 수 없습니다」 422 로 통화 설정 저장 전체가 막혔다. 화면은 관리 환율을 보내지 않고,
 * 서버는 빠진 관리 환율을 저장값으로 채워 검증·저장한다. 값을 명시해 바꾸려는 요청은 종전대로 거절한다.
 */
class ManagedExchangeRateStaleSaveTest extends ModuleTestCase
{
    /**
     * USD 를 관리하는 제공자를 등록·선택하고 USD 환율을 갱신된 값으로 저장해 둡니다.
     *
     * @return array<string, mixed> 저장된 통화 설정
     */
    private function selectProviderAndRefreshUsd(): array
    {
        HookManager::addFilter('sirsoft-ecommerce.currency.registered_rate_providers', fn () => [
            ['id' => 'fixture', 'name' => 'Fixture', 'managed_codes' => ['USD']],
        ]);

        $service = app(EcommerceSettingsService::class);
        $currency = $service->getSettings('language_currency');
        $currency['rate_provider'] = 'fixture';
        foreach ($currency['currencies'] as &$row) {
            if ($row['code'] === 'USD') {
                // 제공자 갱신으로 바뀐 환율 (운영자가 화면을 연 뒤에 반영된 값)
                $row['exchange_rate'] = 1.2345;
            }
        }
        unset($row);
        $service->saveSettings(['language_currency' => $currency]);

        return $service->getSettings('language_currency');
    }

    /**
     * 관리 통화의 환율을 빼고 보낸 저장은 통과하고, 저장값(갱신된 환율)이 유지된다.
     *
     * @scenario managed_rate_param=omitted, provider_refreshed=yes
     *
     * @effects omitted_managed_rate_keeps_saved_value
     *
     * @return void 없음
     */
    public function test_omitted_managed_rate_is_filled_from_saved_value(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);
        $payload = $this->selectProviderAndRefreshUsd();

        // 화면이 보내는 형태 — 관리 통화 행에서 환율을 뺀다. 운영자는 다른 칸(소수 자릿수)만 바꿨다.
        foreach ($payload['currencies'] as &$row) {
            if ($row['code'] === 'USD') {
                unset($row['exchange_rate']);
                $row['decimal_places'] = 2;
            }
        }
        unset($row);

        $response = $this->actingAs($admin)->putJson('/api/modules/sirsoft-ecommerce/admin/settings', [
            '_tab' => 'language_currency',
            'language_currency' => $payload,
        ]);

        $response->assertOk();
        $usd = collect(app(EcommerceSettingsService::class)->getSettings('language_currency')['currencies'])
            ->firstWhere('code', 'USD');
        $this->assertEqualsWithDelta(1.2345, (float) $usd['exchange_rate'], 1e-9);
    }

    /**
     * 관리 통화의 환율을 다른 값으로 명시해 보내면 종전대로 거절된다.
     *
     * @scenario managed_rate_param=changed, provider_refreshed=yes
     *
     * @effects explicit_managed_rate_change_rejected
     *
     * @return void 없음
     */
    public function test_explicit_managed_rate_change_is_still_rejected(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);
        $payload = $this->selectProviderAndRefreshUsd();

        foreach ($payload['currencies'] as &$row) {
            if ($row['code'] === 'USD') {
                $row['exchange_rate'] = 9.99;
            }
        }
        unset($row);

        $this->actingAs($admin)->putJson('/api/modules/sirsoft-ecommerce/admin/settings', [
            '_tab' => 'language_currency',
            'language_currency' => $payload,
        ])->assertStatus(422);
    }
}
