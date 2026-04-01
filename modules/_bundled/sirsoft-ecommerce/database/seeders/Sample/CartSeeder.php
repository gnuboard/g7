<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 장바구니 더미 데이터 시더
 */
class CartSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 회원 장바구니를 생성할 기본 사용자 수
     */
    private const USER_COUNT = 10;

    /**
     * 사용자당 장바구니 아이템 최소 수
     */
    private const MIN_ITEMS_PER_USER = 20;

    /**
     * 사용자당 장바구니 아이템 최대 수
     */
    private const MAX_ITEMS_PER_USER = 30;

    /**
     * 비회원 장바구니 수
     */
    private const GUEST_CART_COUNT = 5;

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('장바구니 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingCarts();
        $this->createCarts();

        $count = Cart::count();
        $this->command->info("장바구니 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 장바구니 삭제
     */
    private function deleteExistingCarts(): void
    {
        $deletedCount = Cart::count();

        if ($deletedCount > 0) {
            Cart::query()->delete();
            $this->command->warn("기존 장바구니 데이터 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 배송비 부여 상품 최소 비중 (60%)
     */
    private const MIN_SHIPPING_PRODUCT_RATIO = 0.6;

    /**
     * 장바구니 생성
     */
    private function createCarts(): void
    {
        // 상품 옵션 목록 가져오기
        $productOptions = ProductOption::with('product')
            ->whereHas('product', function ($query) {
                $query->where('sales_status', 'on_sale')
                    ->where('display_status', 'visible');
            })
            ->where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->get();

        if ($productOptions->isEmpty()) {
            $this->command->error('판매 가능한 상품 옵션이 없습니다. ProductSeeder를 먼저 실행해주세요.');

            return;
        }

        // 배송정책 유무에 따라 분류
        $optionsWithShipping = $productOptions->filter(
            fn ($option) => $option->product->shipping_policy_id !== null
        );
        $optionsWithoutShipping = $productOptions->filter(
            fn ($option) => $option->product->shipping_policy_id === null
        );

        $this->command->line("  - 판매 가능한 상품 옵션 {$productOptions->count()}개를 찾았습니다.");
        $this->command->line("    - 배송비 부여: {$optionsWithShipping->count()}개");
        $this->command->line("    - 배송비 없음: {$optionsWithoutShipping->count()}개");

        if ($optionsWithShipping->isEmpty()) {
            $this->command->warn('배송정책이 있는 상품이 없습니다. 모든 상품으로 장바구니를 생성합니다.');
            $optionsWithShipping = $productOptions;
            $optionsWithoutShipping = collect();
        }

        // 회원 장바구니 생성
        $userCount = $this->getSeederCount('cart_users', self::USER_COUNT);
        $this->createMemberCarts($optionsWithShipping, $optionsWithoutShipping, $userCount);

        // 비회원 장바구니 생성
        $guestCount = $this->getSeederCount('cart_guests', self::GUEST_CART_COUNT);
        $this->createGuestCarts($optionsWithShipping, $optionsWithoutShipping, $guestCount);
    }

    /**
     * 회원 장바구니 생성
     *
     * @param  \Illuminate\Support\Collection  $optionsWithShipping  배송정책이 있는 상품 옵션
     * @param  \Illuminate\Support\Collection  $optionsWithoutShipping  배송정책이 없는 상품 옵션
     */
    private function createMemberCarts($optionsWithShipping, $optionsWithoutShipping, int $userCount): void
    {
        // 사용자 목록 가져오기 (없으면 생성)
        $users = User::take($userCount)->get();

        if ($users->isEmpty()) {
            $this->command->line("  - 사용자가 없어 {$userCount}명 생성합니다.");
            $users = User::factory()->count($userCount)->create();
        }

        $this->command->line('  - 회원 장바구니 생성 중...');

        $totalItems = 0;
        foreach ($users as $user) {
            $itemCount = rand(self::MIN_ITEMS_PER_USER, self::MAX_ITEMS_PER_USER);

            // 배송비 부여 상품 60% 이상 비중으로 선택
            $selectedOptions = $this->selectOptionsWithShippingRatio(
                $optionsWithShipping,
                $optionsWithoutShipping,
                $itemCount
            );

            // 회원용 cart_key 생성
            $cartKey = 'ck_'.Str::random(32);

            foreach ($selectedOptions as $option) {
                $this->createCartItem($option, $user->id, $cartKey);
                $totalItems++;
            }
        }

        $this->command->line("    - 회원 장바구니 {$totalItems}건 생성 완료");
    }

    /**
     * 비회원 장바구니 생성
     *
     * @param  \Illuminate\Support\Collection  $optionsWithShipping  배송정책이 있는 상품 옵션
     * @param  \Illuminate\Support\Collection  $optionsWithoutShipping  배송정책이 없는 상품 옵션
     */
    private function createGuestCarts($optionsWithShipping, $optionsWithoutShipping, int $guestCount): void
    {
        $this->command->line('  - 비회원 장바구니 생성 중...');

        $totalItems = 0;
        for ($i = 0; $i < $guestCount; $i++) {
            // 비회원용 cart_key 생성
            $cartKey = 'ck_'.Str::random(32);

            $itemCount = rand(self::MIN_ITEMS_PER_USER, self::MAX_ITEMS_PER_USER);

            // 배송비 부여 상품 60% 이상 비중으로 선택
            $selectedOptions = $this->selectOptionsWithShippingRatio(
                $optionsWithShipping,
                $optionsWithoutShipping,
                $itemCount
            );

            foreach ($selectedOptions as $option) {
                $this->createCartItem($option, null, $cartKey);
                $totalItems++;
            }
        }

        $this->command->line("    - 비회원 장바구니 {$totalItems}건 생성 완료");
    }

    /**
     * 배송비 부여 상품 비중을 60% 이상으로 유지하며 옵션 선택
     *
     * @param  \Illuminate\Support\Collection  $optionsWithShipping  배송정책이 있는 상품 옵션
     * @param  \Illuminate\Support\Collection  $optionsWithoutShipping  배송정책이 없는 상품 옵션
     * @param  int  $totalCount  선택할 총 개수
     * @return \Illuminate\Support\Collection 선택된 상품 옵션 컬렉션
     */
    private function selectOptionsWithShippingRatio($optionsWithShipping, $optionsWithoutShipping, int $totalCount)
    {
        // 배송비 부여 상품 최소 개수 (60% 이상)
        $minShippingCount = (int) ceil($totalCount * self::MIN_SHIPPING_PRODUCT_RATIO);

        // 실제 선택 가능한 배송비 부여 상품 수
        $availableShippingCount = $optionsWithShipping->count();
        $shippingCount = min($minShippingCount, $availableShippingCount);

        // 나머지는 배송비 없는 상품으로 채움
        $remainingCount = $totalCount - $shippingCount;
        $availableWithoutShippingCount = $optionsWithoutShipping->count();
        $withoutShippingCount = min($remainingCount, $availableWithoutShippingCount);

        // 배송비 없는 상품이 부족한 경우 배송비 부여 상품으로 추가
        if ($withoutShippingCount < $remainingCount) {
            $additionalShipping = $remainingCount - $withoutShippingCount;
            $shippingCount = min($shippingCount + $additionalShipping, $availableShippingCount);
        }

        // 랜덤 선택
        $selectedWithShipping = $optionsWithShipping->random(min($shippingCount, $availableShippingCount));
        $selectedWithoutShipping = $optionsWithoutShipping->isNotEmpty() && $withoutShippingCount > 0
            ? $optionsWithoutShipping->random(min($withoutShippingCount, $availableWithoutShippingCount))
            : collect();

        // Collection이 아닌 단일 모델이 반환될 수 있음
        if (! ($selectedWithShipping instanceof \Illuminate\Support\Collection)) {
            $selectedWithShipping = collect([$selectedWithShipping]);
        }
        if (! ($selectedWithoutShipping instanceof \Illuminate\Support\Collection)) {
            $selectedWithoutShipping = collect([$selectedWithoutShipping]);
        }

        // 합치고 섞기
        return $selectedWithShipping->merge($selectedWithoutShipping)->shuffle();
    }

    /**
     * 장바구니 아이템 생성
     *
     * @param  ProductOption  $option  상품 옵션
     * @param  int|null  $userId  사용자 ID (회원인 경우)
     * @param  string|null  $cartKey  장바구니 키 (비회원인 경우)
     */
    private function createCartItem(ProductOption $option, ?int $userId, ?string $cartKey): void
    {
        // 재고 수량 내에서 랜덤 수량 설정
        $maxQuantity = min($option->stock_quantity, 10);
        $quantity = rand(1, max(1, $maxQuantity));

        Cart::create([
            'cart_key' => $cartKey,
            'user_id' => $userId,
            'product_id' => $option->product_id,
            'product_option_id' => $option->id,
            'quantity' => $quantity,
        ]);
    }
}
