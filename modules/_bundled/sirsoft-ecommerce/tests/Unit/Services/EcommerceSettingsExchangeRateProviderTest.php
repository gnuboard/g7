<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSettingRequest;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

class EcommerceSettingsExchangeRateProviderTest extends ModuleTestCase
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
     * 제공자를 자동 선택하지 않고 관리 환율을 수치로 비교하는지 검증합니다.
     *
     * @effects providers_are_not_selected_automatically_and_managed_rate_guard_is_numeric
     *
     * @return void 반환값 없음
     */
    public function test_providers_are_not_selected_automatically_and_managed_rate_guard_is_numeric(): void
    {
        $service = app(EcommerceSettingsService::class);
        HookManager::addFilter('sirsoft-ecommerce.currency.registered_rate_providers', fn ($providers) => [
            ['id' => 'fixture', 'name' => 'Fixture', 'managed_codes' => ['USD']], ['id' => 'second', 'managed_codes' => []],
        ]);
        $this->assertNull($service->getActiveRateProvider());
        $currency = $service->getSettings('language_currency');
        $currency['rate_provider'] = 'fixture';
        $service->saveSettings(['language_currency' => $currency]);
        $this->assertSame('fixture', $service->getActiveRateProvider()['id']);
        $current = $service->getSettings('language_currency');
        foreach ($current['currencies'] as &$row) {
            if ($row['code'] === 'USD') {
                $row['exchange_rate'] = (string) $row['exchange_rate'];
            }
        }
        unset($row);
        $this->assertFalse($this->validator($current)->fails());
        foreach ($current['currencies'] as &$row) {
            if ($row['code'] === 'USD') {
                $row['exchange_rate'] = 123;
            }
        }
        unset($row);
        $this->assertTrue($this->validator($current)->fails());
    }

    /**
     * 미등록 신규 선택은 거부하고 비활성 기존 선택은 보존하는지 검증합니다.
     *
     * @effects unregistered_new_provider_is_rejected_but_disabled_saved_selection_is_preserved
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_new_provider_is_rejected_but_disabled_saved_selection_is_preserved(): void
    {
        $service = app(EcommerceSettingsService::class);
        $currency = $service->getSettings('language_currency');
        $currency['rate_provider'] = 'absent';
        $this->assertTrue($this->validator($currency)->fails());
        $service->saveSettings(['language_currency' => $currency]);
        $this->assertNull($service->getActiveRateProvider());
        $this->assertFalse($this->validator($currency)->fails());
    }

    /**
     * 설정 요청의 검증 규칙과 입력을 결합합니다.
     *
     * @param  array  $currency  currency 입력값
     * @return \Illuminate\Validation\Validator 처리 결과
     */
    private function validator(array $currency): \Illuminate\Validation\Validator
    {
        $payload = ['language_currency' => $currency];
        $request = new StoreEcommerceSettingsRequest($payload);
        $request->setContainer(app());
        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);

        return $validator;
    }

    /**
     * 단건 저장도 관리 환율을 보호하며 다른 설정은 편집 가능한지 검증합니다.
     *
     * @effects single_key_cannot_bypass_managed_rate_guard_and_other_settings_remain_editable
     *
     * @return void 반환값 없음
     */
    public function test_single_key_cannot_bypass_managed_rate_guard_and_other_settings_remain_editable(): void
    {
        $service = app(EcommerceSettingsService::class);
        HookManager::addFilter('sirsoft-ecommerce.currency.registered_rate_providers', fn () => [['id' => 'fixture', 'managed_codes' => ['USD']]]);
        $currency = $service->getSettings('language_currency');
        $currency['rate_provider'] = 'fixture';
        $service->saveSettings(['language_currency' => $currency]);
        $index = array_search('USD', array_column($currency['currencies'], 'code'), true);
        foreach ([['key' => "language_currency.currencies.$index.exchange_rate", 'value' => 123, 'fails' => true],
            ['key' => "language_currency.currencies.$index.symbol", 'value' => 'US$', 'fails' => false],
            ['key' => 'language_currency.rate_provider', 'value' => null, 'fails' => false],
            ['key' => 'general.store_name', 'value' => 'Fixture', 'fails' => false]] as $case) {
            $request = new UpdateSettingRequest($case);
            $request->setContainer(app());
            $validator = Validator::make($case, $request->rules(), $request->messages());
            $request->withValidator($validator);
            $this->assertSame($case['fails'], $validator->fails(), $case['key']);
        }
    }

    /**
     * 모듈 실행 코드가 특정 환율 업체에 의존하지 않는지 검증합니다.
     *
     * 첫 항목이 검증 대상이고 나머지 PG 플러그인 식별자는 이미 0 인 대조군이다 —
     * 순회가 비면 어떤 토큰도 걸리지 않아 주장이 공회전하므로 주사 파일 수도 함께 못박는다.
     *
     * @effects provider_contract_has_no_vendor_dependency_in_module_runtime_sources
     *
     * @return void 반환값 없음
     */
    public function test_provider_contract_has_no_vendor_dependency_in_module_runtime_sources(): void
    {
        $root = dirname(__DIR__, 3);
        $vendors = ['koreaexim', 'sirsoft-tosspayments', 'sirsoft-pay_kginicis', 'sirsoft-pay_nhnkcp', 'sirsoft-pay_nicepayments'];
        $scanned = 0;
        foreach (['src', 'resources', 'config'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $scanned++;
                    $contents = file_get_contents($file->getPathname());
                    foreach ($vendors as $vendor) {
                        $this->assertStringNotContainsStringIgnoringCase($vendor, $contents, $file->getPathname().' / '.$vendor);
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $scanned);
    }

    /**
     * 환율 제공자 접합면이 확장 개발자가 읽는 AGENTS.md 에 서술돼 있는지 검증합니다.
     *
     * 모집단은 실행 코드에서 파생한다 — 훅 이름은 서비스 소스에서, 적용 메서드는 리플렉션으로,
     * 응답 키는 컨트롤러 소스에서 뽑아 이름이 바뀌면 문서 단언이 함께 red 가 되게 한다.
     *
     * @effects provider_contract_surface_is_documented_for_extension_authors
     *
     * @return void 반환값 없음
     */
    public function test_provider_contract_surface_is_documented_for_extension_authors(): void
    {
        $root = dirname(__DIR__, 3);
        $service = file_get_contents($root.'/src/Services/EcommerceSettingsService.php');
        // 응답 조립은 관리자 설정 서비스로 옮겨졌다(#599 validate P3) — 두 소스를 함께 본다.
        $controller = file_get_contents($root.'/src/Http/Controllers/Admin/EcommerceSettingsController.php')
            .file_get_contents($root.'/src/Services/EcommerceSettingsAdminService.php');
        preg_match_all("/'(sirsoft-ecommerce\.currency\.[a-z_]+)'/", $service, $hooks);
        preg_match_all("/'(available_rate_providers|active_rate_provider)'/", $controller, $keys);
        $surface = array_values(array_unique(array_merge(
            $hooks[1],
            $keys[1],
            [(new \ReflectionMethod(EcommerceSettingsService::class, 'applyExchangeRates'))->getName()],
            ['language_currency.rate_provider'],
        )));
        $this->assertGreaterThanOrEqual(5, count($surface));
        $agents = file_get_contents($root.'/AGENTS.md');
        foreach ($surface as $token) {
            $this->assertStringContainsString($token, $agents, 'AGENTS.md 에 '.$token.' 서술이 없습니다.');
        }
    }
}
