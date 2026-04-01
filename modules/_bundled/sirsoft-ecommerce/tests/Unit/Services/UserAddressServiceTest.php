<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\UserAddressService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용자 배송지 서비스 테스트
 */
class UserAddressServiceTest extends ModuleTestCase
{
    protected UserAddressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UserAddressService::class);
    }

    public function test_get_user_addresses_returns_collection(): void
    {
        $user = User::factory()->create();

        UserAddress::factory()->count(3)->create(['user_id' => $user->id]);

        $addresses = $this->service->getUserAddresses($user->id);

        $this->assertCount(3, $addresses);
    }

    public function test_get_user_addresses_returns_empty_for_no_addresses(): void
    {
        $user = User::factory()->create();

        $addresses = $this->service->getUserAddresses($user->id);

        $this->assertCount(0, $addresses);
    }

    public function test_get_default_address_returns_default(): void
    {
        $user = User::factory()->create();

        UserAddress::factory()->create(['user_id' => $user->id, 'is_default' => false]);
        $defaultAddress = UserAddress::factory()->create(['user_id' => $user->id, 'is_default' => true]);

        $result = $this->service->getDefaultAddress($user->id);

        $this->assertNotNull($result);
        $this->assertEquals($defaultAddress->id, $result->id);
    }

    public function test_get_default_address_returns_null_when_no_default(): void
    {
        $user = User::factory()->create();

        UserAddress::factory()->create(['user_id' => $user->id, 'is_default' => false]);

        $result = $this->service->getDefaultAddress($user->id);

        $this->assertNull($result);
    }

    public function test_get_address_returns_address_for_owner(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user->id]);

        $result = $this->service->getAddress($user->id, $address->id);

        $this->assertNotNull($result);
        $this->assertEquals($address->id, $result->id);
    }

    public function test_get_address_returns_null_for_non_owner(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user1->id]);

        $result = $this->service->getAddress($user2->id, $address->id);

        $this->assertNull($result);
    }

    public function test_create_address_sets_first_as_default(): void
    {
        $user = User::factory()->create();

        $address = $this->service->createAddress([
            'user_id' => $user->id,
            'name' => '집',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
            'address_detail' => '101동 101호',
        ]);

        $this->assertTrue($address->is_default);
    }

    public function test_create_address_does_not_override_existing_default(): void
    {
        $user = User::factory()->create();

        // 첫 번째 배송지 (자동 기본)
        $first = $this->service->createAddress([
            'user_id' => $user->id,
            'name' => '집',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
        ]);

        // 두 번째 배송지 (기본 아님)
        $second = $this->service->createAddress([
            'user_id' => $user->id,
            'name' => '회사',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '54321',
            'address' => '서울시 서초구',
        ]);

        $first->refresh();
        $second->refresh();

        $this->assertTrue($first->is_default);
        $this->assertFalse($second->is_default);
    }

    public function test_create_address_with_is_default_clears_existing(): void
    {
        $user = User::factory()->create();

        $first = $this->service->createAddress([
            'user_id' => $user->id,
            'name' => '집',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
        ]);

        // 두 번째를 기본으로 설정
        $second = $this->service->createAddress([
            'user_id' => $user->id,
            'name' => '회사',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '54321',
            'address' => '서울시 서초구',
            'is_default' => true,
        ]);

        $first->refresh();

        $this->assertFalse($first->is_default);
        $this->assertTrue($second->is_default);
    }

    public function test_update_address_updates_fields(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '집',
        ]);

        $result = $this->service->updateAddress($user->id, $address->id, [
            'name' => '본가',
        ]);

        $this->assertNotNull($result);
        $this->assertEquals('본가', $result->name);
    }

    public function test_update_address_returns_null_for_non_owner(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user1->id]);

        $result = $this->service->updateAddress($user2->id, $address->id, [
            'name' => '변경',
        ]);

        $this->assertNull($result);
    }

    public function test_delete_address_removes_address(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user->id, 'is_default' => false]);

        $result = $this->service->deleteAddress($user->id, $address->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('ecommerce_user_addresses', ['id' => $address->id]);
    }

    public function test_delete_address_returns_false_for_non_owner(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user1->id]);

        $result = $this->service->deleteAddress($user2->id, $address->id);

        $this->assertFalse($result);
        $this->assertDatabaseHas('ecommerce_user_addresses', ['id' => $address->id]);
    }

    public function test_delete_default_address_sets_new_default(): void
    {
        $user = User::factory()->create();

        $first = UserAddress::factory()->create([
            'user_id' => $user->id,
            'is_default' => true,
        ]);

        $second = UserAddress::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $this->service->deleteAddress($user->id, $first->id);

        $second->refresh();
        $this->assertTrue($second->is_default);
    }

    public function test_set_default_address_changes_default(): void
    {
        $user = User::factory()->create();

        $first = UserAddress::factory()->create([
            'user_id' => $user->id,
            'is_default' => true,
        ]);

        $second = UserAddress::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $result = $this->service->setDefaultAddress($user->id, $second->id);

        $first->refresh();
        $second->refresh();

        $this->assertNotNull($result);
        $this->assertFalse($first->is_default);
        $this->assertTrue($second->is_default);
    }

    public function test_set_default_address_returns_null_for_non_owner(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user1->id]);

        $result = $this->service->setDefaultAddress($user2->id, $address->id);

        $this->assertNull($result);
    }

    // ===== generateUniqueName 테스트 =====

    public function test_generate_unique_name_returns_base_name_when_no_duplicate(): void
    {
        $user = User::factory()->create();

        $result = $this->service->generateUniqueName($user->id, '새 배송지');

        $this->assertEquals('새 배송지', $result);
    }

    public function test_generate_unique_name_appends_suffix_when_duplicate_exists(): void
    {
        $user = User::factory()->create();

        UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '새 배송지',
        ]);

        $result = $this->service->generateUniqueName($user->id, '새 배송지');

        $this->assertEquals('새 배송지 (2)', $result);
    }

    public function test_generate_unique_name_increments_suffix_sequentially(): void
    {
        $user = User::factory()->create();

        UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '새 배송지',
        ]);
        UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '새 배송지 (2)',
        ]);

        $result = $this->service->generateUniqueName($user->id, '새 배송지');

        $this->assertEquals('새 배송지 (3)', $result);
    }

    public function test_generate_unique_name_ignores_other_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserAddress::factory()->create([
            'user_id' => $user1->id,
            'name' => '새 배송지',
        ]);

        $result = $this->service->generateUniqueName($user2->id, '새 배송지');

        $this->assertEquals('새 배송지', $result);
    }
}
