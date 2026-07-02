<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 기본 통화 사용 규칙 게이트 테스트 (M2)
 *
 * 기본 통화(base_currency)에 대한 마일리지 통화별 사용 단위 규칙이 설정되어 있지 않으면
 * 마일리지 사용이 불가해야 한다(정책).
 * - isMileageUsable(): base 규칙 존재 시 true, 없으면 false
 * - getMaxUsable(): base 규칙 없으면 0
 * - validateUsage(): base 규칙 없으면 MileageValidationException
 */
class MileageBaseCurrencyGateTest extends ModuleTestCase
{
    private string $settingsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsDir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($this->settingsDir)) {
            mkdir($this->settingsDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (['mileage.json', 'language_currency.json'] as $f) {
            $path = $this->settingsDir.'/'.$f;
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function writeSettings(array $currencyRules, string $defaultCurrency = 'KRW'): void
    {
        file_put_contents($this->settingsDir.'/mileage.json', json_encode([
            'enabled' => true,
            'default_earn_rate' => 1,
            'currency_rules' => $currencyRules,
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->settingsDir.'/language_currency.json', json_encode([
            'default_currency' => $defaultCurrency,
        ], JSON_PRETTY_PRINT));
    }

    private function service(): UserMileageService
    {
        return app(UserMileageService::class);
    }

    public function test_usable_true_when_base_currency_rule_exists(): void
    {
        $this->writeSettings([
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100],
        ], 'KRW');

        $this->assertTrue($this->service()->isMileageUsable());
    }

    public function test_usable_false_when_no_currency_rules(): void
    {
        $this->writeSettings([], 'KRW');

        $this->assertFalse($this->service()->isMileageUsable());
    }

    public function test_usable_false_when_base_currency_rule_missing(): void
    {
        // 기본 통화는 KRW 인데 규칙에 USD 만 존재 → base 규칙 없음
        $this->writeSettings([
            ['currency_code' => 'USD', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100],
        ], 'KRW');

        $this->assertFalse($this->service()->isMileageUsable());
    }

    public function test_get_max_usable_returns_zero_when_base_rule_missing(): void
    {
        $user = User::factory()->create();
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 10000,
            'remaining_amount' => 10000,
            'balance_after' => 10000,
            'expires_at' => null,
        ]);

        $this->writeSettings([], 'KRW');

        $this->assertSame(0, $this->service()->getMaxUsable($user->id, 50000));
    }

    public function test_validate_usage_throws_when_base_rule_missing(): void
    {
        $user = User::factory()->create();
        $this->writeSettings([], 'KRW');

        $this->expectException(MileageValidationException::class);
        $this->service()->validateUsage($user->id, 1000, 50000, 'KRW');
    }
}
