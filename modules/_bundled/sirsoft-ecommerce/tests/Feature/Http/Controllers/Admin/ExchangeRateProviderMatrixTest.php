<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSettingRequest;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

class ExchangeRateProviderMatrixTest extends ModuleTestCase
{
    /**
     * 개발 DB 재생성 없이 트랜잭션으로 테스트를 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 제공자 선택과 관리 통화 변경의 조합별 저장 허용 여부를 검증합니다.
     *
     * @effects provider_write_guard, permission_enforced
     *
     * @return void 반환값 없음
     */
    public function test_provider_write_matrix(): void
    {
        $editor = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);
        $viewer = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);
        $service = app(EcommerceSettingsService::class);
        $original = $service->getSettings('language_currency');
        $providers = [];
        HookManager::addFilter('sirsoft-ecommerce.currency.registered_rate_providers', function () use (&$providers) {
            return $providers;
        });
        $cases = require dirname(__DIR__, 4).'/fixtures/ProviderCases.php';
        foreach ($cases as [$registered, $selected, $path, $submitted, $actor]) {
            $providers = array_slice([['id' => 'fixture', 'name' => 'Fixture', 'managed_codes' => ['USD']], ['id' => 'second', 'name' => 'Second', 'managed_codes' => ['USD']]], 0, ['none' => 0, 'one' => 1, 'two' => 2][$registered]);
            $currency = $original;
            $currency['rate_provider'] = $selected === 'valid' ? ($registered === 'two' ? 'second' : 'fixture') : null;
            $service->saveSettings(['language_currency' => $currency]);
            $this->assertSame($currency['rate_provider'], $service->getActiveRateProvider()['id'] ?? null);
            $payload = $service->getSettings('language_currency');
            if ($selected === 'unregistered') {
                $payload['rate_provider'] = 'absent';
            }
            $code = ['unsupported_code' => 'CNY', 'base_row' => 'KRW'][$submitted] ?? 'USD';
            foreach ($payload['currencies'] as &$row) {
                if ($row['code'] === $code) {
                    $row['exchange_rate'] = $submitted === 'unchanged' ? (string) $row['exchange_rate'] : ($submitted === 'new_currency' ? $row['exchange_rate'] : 123);
                }
            }
            unset($row);
            if ($submitted === 'new_currency') {
                $payload['currencies'][] = ['code' => 'AUD', 'name' => ['ko' => '호주 달러'], 'exchange_rate' => 1.2];
            }
            $invalid = $selected === 'unregistered' || ($selected === 'valid' && $submitted === 'changed');
            $context = implode('/', [$registered, $selected, $path, $submitted, $actor]);
            if ($path === 'single_key') {
                $input = ['key' => 'language_currency', 'value' => $payload];
                $request = new UpdateSettingRequest($input);
                $request->setContainer(app());
                $validator = Validator::make($input, $request->rules());
                $request->withValidator($validator);
                $this->assertSame($invalid, $validator->fails(), $context);
            } else {
                $response = $this->actingAs($actor === 'update_perm' ? $editor : $viewer)->putJson('/api/modules/sirsoft-ecommerce/admin/settings', ['language_currency' => $payload]);
                $this->assertSame($actor === 'read_only' ? 403 : ($invalid ? 422 : 200), $response->status(), $context.' '.$response->getContent());
                if ($response->status() === 200) {
                    $response->assertJsonCount(count($providers), 'data.available_rate_providers');
                    $response->assertJsonStructure(['data' => ['available_rate_providers', 'active_rate_provider']]);
                    if ($currency['rate_provider'] === null) {
                        $this->assertNull($response->json('data.active_rate_provider'), $context);
                    } else {
                        $this->assertSame($currency['rate_provider'], $response->json('data.active_rate_provider.id'), $context);
                    }
                }
            }
        }
    }
}
