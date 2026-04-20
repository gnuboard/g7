<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용자 배송지 관리 API 테스트
 */
class UserAddressControllerTest extends ModuleTestCase
{
    private string $baseUrl = '/api/modules/sirsoft-ecommerce/user/addresses';

    public function test_index_returns_user_addresses(): void
    {
        $user = User::factory()->create();
        UserAddress::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson($this->baseUrl);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'addresses' => [
                        'data' => [
                            '*' => ['id', 'name', 'recipient_name', 'recipient_phone', 'zipcode', 'address'],
                        ],
                        'abilities',
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('data.addresses.data'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson($this->baseUrl);

        $response->assertUnauthorized();
    }

    public function test_index_returns_only_own_addresses(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserAddress::factory()->count(2)->create(['user_id' => $user1->id]);
        UserAddress::factory()->count(3)->create(['user_id' => $user2->id]);

        $response = $this->actingAs($user1)
            ->getJson($this->baseUrl);

        $response->assertOk();
        $this->assertCount(2, $response->json('data.addresses.data'));
    }

    public function test_store_creates_new_address(): void
    {
        $user = User::factory()->create();

        $data = [
            'name' => '집',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울시 강남구 테헤란로 123',
            'address_detail' => '456동 789호',
        ];

        $response = $this->actingAs($user)
            ->postJson($this->baseUrl, $data);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.address.name', '집')
            ->assertJsonPath('data.address.recipient_name', '홍길동');

        $this->assertDatabaseHas('ecommerce_user_addresses', [
            'user_id' => $user->id,
            'recipient_name' => '홍길동',
        ]);
    }

    public function test_store_first_address_becomes_default(): void
    {
        $user = User::factory()->create();

        $data = [
            'name' => '집',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
        ];

        $response = $this->actingAs($user)
            ->postJson($this->baseUrl, $data);

        $response->assertCreated();
        $this->assertTrue($response->json('data.address.is_default'));
    }

    public function test_store_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson($this->baseUrl, []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'recipient_name', 'recipient_phone']);
    }

    public function test_show_returns_address(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson("{$this->baseUrl}/{$address->id}");

        $response->assertOk()
            ->assertJsonPath('data.address.id', $address->id);
    }

    public function test_show_returns_404_for_other_user_address(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)
            ->getJson("{$this->baseUrl}/{$address->id}");

        $response->assertNotFound();
    }

    public function test_update_modifies_address(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '집',
        ]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", [
                'name' => '본가',
                'recipient_name' => $address->recipient_name,
                'recipient_phone' => $address->recipient_phone,
                'zipcode' => $address->zipcode,
                'address' => $address->address,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.address.name', '본가');

        $this->assertDatabaseHas('ecommerce_user_addresses', [
            'id' => $address->id,
        ]);
    }

    public function test_update_returns_404_for_other_user_address(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)
            ->putJson("{$this->baseUrl}/{$address->id}", [
                'name' => '변경',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'zipcode' => '12345',
                'address' => '서울시',
            ]);

        $response->assertNotFound();
    }

    public function test_destroy_deletes_address(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("{$this->baseUrl}/{$address->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('ecommerce_user_addresses', ['id' => $address->id]);
    }

    public function test_destroy_returns_404_for_other_user_address(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)
            ->deleteJson("{$this->baseUrl}/{$address->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('ecommerce_user_addresses', ['id' => $address->id]);
    }

    public function test_set_default_changes_default_address(): void
    {
        $user = User::factory()->create();

        $address1 = UserAddress::factory()->create([
            'user_id' => $user->id,
            'is_default' => true,
        ]);

        $address2 = UserAddress::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $response = $this->actingAs($user)
            ->patchJson("{$this->baseUrl}/{$address2->id}/default");

        $response->assertOk();

        $address1->refresh();
        $address2->refresh();

        $this->assertFalse($address1->is_default);
        $this->assertTrue($address2->is_default);
    }

    public function test_set_default_returns_404_for_other_user_address(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)
            ->patchJson("{$this->baseUrl}/{$address->id}/default");

        $response->assertNotFound();
    }

    public function test_store_with_is_default_clears_existing_default(): void
    {
        $user = User::factory()->create();

        $existingDefault = UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '집',
            'is_default' => true,
        ]);

        $data = [
            'name' => '회사',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '54321',
            'address' => '서울시 서초구',
            'is_default' => true,
        ];

        $response = $this->actingAs($user)
            ->postJson($this->baseUrl, $data);

        $response->assertCreated();

        $existingDefault->refresh();
        $this->assertFalse($existingDefault->is_default);
        $this->assertTrue($response->json('data.address.is_default'));
    }

    public function test_store_returns_409_on_duplicate_name(): void
    {
        $user = User::factory()->create();
        UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '집',
        ]);

        $data = [
            'name' => '집',
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9999-8888',
            'zipcode' => '54321',
            'address' => '서울시 서초구',
        ];

        $response = $this->actingAs($user)
            ->postJson($this->baseUrl, $data);

        $response->assertStatus(409);
    }

    public function test_store_with_force_overwrite_updates_existing(): void
    {
        $user = User::factory()->create();
        $existing = UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '집',
            'recipient_name' => '홍길동',
        ]);

        $data = [
            'name' => '집',
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9999-8888',
            'zipcode' => '54321',
            'address' => '서울시 서초구',
            'force_overwrite' => true,
        ];

        $response = $this->actingAs($user)
            ->postJson($this->baseUrl, $data);

        $response->assertCreated();

        $existing->refresh();
        $this->assertEquals('김철수', $existing->recipient_name);
    }
}
