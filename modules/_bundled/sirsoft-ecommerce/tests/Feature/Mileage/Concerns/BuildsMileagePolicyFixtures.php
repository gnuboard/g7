<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage\Concerns;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 적립금 정책 시험 공용 픽스처 (설정 기록·지급·장바구니·주문 본문)
 */
trait BuildsMileagePolicyFixtures
{
    /**
     * 적립금 설정을 시험 설정 파일로 기록하고 설정 서비스 캐시를 비웁니다.
     *
     * 설정 서비스는 싱글톤이라 파일만 고치면 같은 시험의 다음 요청이 옛 값을 읽는다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 적립금 설정
     * @return void 반환값 없음
     */
    protected function writeMileageSettings(array $overrides = []): void
    {
        $dir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir.'/mileage.json', json_encode(array_merge([
            'enabled' => true,
            'default_earn_rate' => 10,
            'earn_trigger' => 'confirmed',
            'earn_delay_days' => 0,
            'currency_rules' => [[
                'currency_code' => 'KRW',
                'point_value' => 1,
                'min_use_amount' => 0,
                'use_unit' => 1,
                'max_use_type' => 'percent',
                'max_use_percent' => 100,
                'max_use_value' => 0,
            ]],
            'expiry_enabled' => true,
            'expiry_days' => 365,
        ], $overrides), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        file_put_contents($dir.'/language_currency.json', json_encode(['default_currency' => 'KRW'], JSON_PRETTY_PRINT));

        app(EcommerceSettingsService::class)->clearCache();
    }

    /**
     * 회원에게 적립금을 지급합니다 (관리자 지급 한 건).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $amount  지급 금액
     * @return MileageTransaction 생성된 지급 거래
     */
    protected function grantMileage(int $userId, int $amount): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $amount,
            'description' => 'test grant',
            'expires_at' => null,
        ]);
    }

    /**
     * 판매가를 지정한 상품 한 개를 회원 장바구니에 담습니다 (배송비 0).
     *
     * @param  User  $user  회원
     * @param  int  $sellingPrice  판매가
     * @return Cart 장바구니 항목
     */
    protected function cartFor(User $user, int $sellingPrice): Cart
    {
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => $sellingPrice,
            'selling_price' => $sellingPrice,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => $sellingPrice,
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'mileage_value' => null,
            'mileage_type' => null,
        ]);

        return CartFactory::new()->forOption($option)->forUser($user)->create(['quantity' => 1]);
    }

    /**
     * 회원 주문 생성(무통장입금) 요청 본문을 만듭니다.
     *
     * @param  int  $expectedTotalAmount  결제예정금액
     * @return array<string, mixed> 주문 생성 요청 본문
     */
    protected function memberOrderPayload(int $expectedTotalAmount): array
    {
        return [
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'member@test.com'],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => $expectedTotalAmount,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ];
    }
}
