<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Repositories\CartRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 장바구니 Repository 테스트
 */
class CartRepositoryTest extends ModuleTestCase
{
    protected CartRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CartRepository(new Cart());
    }

    // ========================================
    // existsByCartKey() 테스트
    // ========================================

    public function test_exists_by_cart_key_returns_true_when_exists(): void
    {
        // Given
        $cartKey = 'ck_test_existing_key_12345678901234';
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        CartFactory::new()->withCartKey($cartKey)->forOption($option)->create();

        // When
        $exists = $this->repository->existsByCartKey($cartKey);

        // Then
        $this->assertTrue($exists);
    }

    public function test_exists_by_cart_key_returns_false_when_not_exists(): void
    {
        // Given
        $cartKey = 'ck_nonexistent_key_12345678901234';

        // When
        $exists = $this->repository->existsByCartKey($cartKey);

        // Then
        $this->assertFalse($exists);
    }

    public function test_exists_by_cart_key_returns_true_even_when_user_id_exists(): void
    {
        // Given: user_id가 있는 장바구니도 cart_key 존재 여부 확인에 포함
        $cartKey = 'ck_test_key_with_user_12345678901';
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();

        CartFactory::new()->forUser($user)->forOption($option)->create([
            'cart_key' => $cartKey,
        ]);

        // When
        $exists = $this->repository->existsByCartKey($cartKey);

        // Then: user_id가 있어도 cart_key가 존재하면 true
        $this->assertTrue($exists);
    }
}
