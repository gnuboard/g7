<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductAdditionalOptionValueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyResolver;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 적립 마일리지 절사 기준의 주문 계산 반영 테스트
 *
 * 적립 산출은 종전에 세 갈래(옵션 정액 · 옵션 정률 · 기본 적립률) 모두 `(int) floor` 로
 * 하드코딩되어 운영자가 조정할 수 없었다. 통화별 마일리지 규칙의 절사 기준이 세 갈래에
 * 빠짐없이 적용되는지, 그리고 재계산이 주문 시점 기준을 쓰는지 검증한다.
 */
class MileageEarnRoundingTest extends ModuleTestCase
{
    private OrderCalculationService $service;

    private string $settingsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($this->settingsPath)) {
            mkdir($this->settingsPath, 0755, true);
        }

        $this->writeCurrencySettings();
        $this->writeMileageSettings('1', 'floor');
    }

    protected function tearDown(): void
    {
        foreach (['language_currency.json', 'mileage.json'] as $file) {
            $path = $this->settingsPath.'/'.$file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function writeCurrencySettings(): void
    {
        file_put_contents($this->settingsPath.'/language_currency.json', json_encode([
            'default_currency' => 'KRW',
            'currencies' => [
                [
                    'code' => 'KRW',
                    'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                    'exchange_rate' => null,
                    'base_unit' => 1000,
                    'rounding_unit' => '1',
                    'rounding_method' => 'floor',
                    'decimal_places' => 0,
                    'is_default' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 마일리지 설정을 지정한 절사 기준으로 기록합니다.
     *
     * @param  string|null  $unit  절사 단위 (null 이면 키 자체를 두지 않음 — 구형 설정 재현)
     * @param  string|null  $method  절사 방식 (null 이면 키 자체를 두지 않음)
     */
    private function writeMileageSettings(?string $unit, ?string $method): void
    {
        $rule = [
            'currency_code' => 'KRW',
            'point_value' => 1,
            'min_use_amount' => 0,
            'use_unit' => 1,
            'max_use_type' => 'percent',
            'max_use_percent' => 100,
            'max_use_value' => 0,
        ];

        if ($unit !== null) {
            $rule[MileageRounding::UNIT_KEY] = $unit;
        }
        if ($method !== null) {
            $rule[MileageRounding::METHOD_KEY] = $method;
        }

        file_put_contents($this->settingsPath.'/mileage.json', json_encode([
            'enabled' => true,
            'default_earn_rate' => 1,
            'earn_trigger' => 'confirmed',
            'earn_delay_days' => 0,
            'currency_rules' => [$rule],
            'expiry_enabled' => true,
            'expiry_days' => 365,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 설정 서비스는 인스턴스 내부 캐시를 갖는다 — 파일만 바꾸면 이미 주입된 인스턴스는
        // 이전 값을 계속 읽는다. 컨테이너 인스턴스를 버리고 계산 서비스를 다시 조립한다.
        app()->forgetInstance(EcommerceSettingsService::class);
        $this->service = $this->makeService();
    }

    /**
     * 현재 설정 파일 기준으로 계산 서비스를 조립합니다.
     *
     * @return OrderCalculationService 계산 서비스
     */
    private function makeService(): OrderCalculationService
    {
        return new OrderCalculationService(
            new CurrencyConversionService,
            app(ProductOptionRepositoryInterface::class),
            app(CouponIssueRepositoryInterface::class),
            app(ShippingPolicyRepositoryInterface::class),
            app(EcommerceSettingsService::class),
            app(ProductAdditionalOptionValueRepositoryInterface::class),
            app(ShippingPolicyResolver::class)
        );
    }

    /**
     * 적립 설정이 붙은 상품 옵션을 만듭니다.
     *
     * @param  int  $price  판매가
     * @param  float|null  $mileageValue  옵션 적립값
     * @param  string|null  $mileageType  적립 방식 (fixed|percent)
     * @return int 상품옵션 ID
     */
    private function makeOption(int $price, ?float $mileageValue = null, ?string $mileageType = null): int
    {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
        ]);

        $data = ['price_adjustment' => 0, 'stock_quantity' => 100, 'is_default' => true];
        if ($mileageValue !== null) {
            $data['mileage_value'] = $mileageValue;
        }
        if ($mileageType !== null) {
            $data['mileage_type'] = $mileageType;
        }

        return ProductOptionFactory::new()->forProduct($product)->create($data)->id;
    }

    /**
     * 계산을 수행하고 첫 항목의 적립 포인트를 반환합니다.
     *
     * @param  int  $optionId  상품옵션 ID
     * @param  int  $quantity  수량
     * @param  array|null  $mileagePolicySnapshot  마일리지 정책 스냅샷 (재계산 시나리오)
     * @return float 적립 포인트
     */
    private function earnedPoints(int $optionId, int $quantity = 1, ?array $mileagePolicySnapshot = null): float
    {
        $result = $this->service->calculate(new CalculationInput(
            items: [new CalculationItem(productOptionId: $optionId, quantity: $quantity)],
            mileagePolicySnapshot: $mileagePolicySnapshot,
        ));

        return (float) $result->items[0]->pointsEarning;
    }

    #[Test]
    public function 기본_적립률_갈래에_절사_기준이_적용된다(): void
    {
        // 78,160원 × 1% = 781.6점
        $optionId = $this->makeOption(78160);

        $this->assertSame(781.0, $this->earnedPoints($optionId), '기본값(1점 버림)');

        $this->writeMileageSettings('1', 'round');
        $this->assertSame(782.0, $this->earnedPoints($optionId), '1점 반올림');

        $this->writeMileageSettings('10', 'floor');
        $this->assertSame(780.0, $this->earnedPoints($optionId), '10점 버림');

        $this->writeMileageSettings('100', 'ceil');
        $this->assertSame(800.0, $this->earnedPoints($optionId), '100점 올림');
    }

    #[Test]
    public function 옵션_정률_갈래에_절사_기준이_적용된다(): void
    {
        // 78,160원 × 3.5% = 2,735.6점
        $optionId = $this->makeOption(78160, 3.5, 'percent');

        $this->assertSame(2735.0, $this->earnedPoints($optionId), '기본값(1점 버림)');

        $this->writeMileageSettings('10', 'ceil');
        $this->assertSame(2740.0, $this->earnedPoints($optionId), '10점 올림');
    }

    #[Test]
    public function 옵션_정액_갈래에_절사_기준이_적용된다(): void
    {
        // 정액 505.5점 × 3개 = 1,516.5점
        $optionId = $this->makeOption(50000, 505.5, 'fixed');

        $this->assertSame(1516.0, $this->earnedPoints($optionId, 3), '기본값(1점 버림)');

        $this->writeMileageSettings('100', 'round');
        $this->assertSame(1500.0, $this->earnedPoints($optionId, 3), '100점 반올림');
    }

    #[Test]
    public function 재계산은_설정이_아니라_주문_시점_기준을_따른다(): void
    {
        $optionId = $this->makeOption(78160);

        // 주문 시점: 100점 올림 → 800점
        $snapshot = [
            'usable' => true,
            'currency' => 'KRW',
            'rule' => [
                MileageRounding::UNIT_KEY => '100',
                MileageRounding::METHOD_KEY => 'ceil',
            ],
        ];

        // 그 뒤 운영자가 설정을 1점 버림으로 되돌려도 이 주문의 재계산은 800점이어야 한다.
        // 설정을 다시 읽으면 취소하지 않은 잔여분의 적립액이 취소 처리만으로 달라진다.
        $this->writeMileageSettings('1', 'floor');

        $this->assertSame(800.0, $this->earnedPoints($optionId, 1, $snapshot));
    }

    #[Test]
    public function 절사_키가_없는_구형_설정은_종전_금액을_그대로_산출한다(): void
    {
        $optionId = $this->makeOption(78160);

        $this->writeMileageSettings(null, null);

        // 이 기능 이전 설치본의 mileage.json 에는 절사 키가 없다 — 백필 전에도 금액 불변.
        $this->assertSame(781.0, $this->earnedPoints($optionId));
    }

    #[Test]
    public function 주문_스냅샷에_절사_기준이_기록된다(): void
    {
        $this->writeMileageSettings('10', 'ceil');

        $snapshot = app(UserMileageService::class)->buildUsagePolicySnapshot(50000, 0, 'KRW');

        $this->assertSame('10', $snapshot['rule'][MileageRounding::UNIT_KEY]);
        $this->assertSame('ceil', $snapshot['rule'][MileageRounding::METHOD_KEY]);
    }
}
