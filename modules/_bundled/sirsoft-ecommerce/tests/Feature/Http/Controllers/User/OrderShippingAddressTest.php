<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 배송지 변경 API 테스트
 */
class OrderShippingAddressTest extends ModuleTestCase
{
    private User $user;

    private Order $order;

    private OrderAddress $shippingAddress;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->order = Order::factory()->paid()->forUser($this->user)->create();
        $this->shippingAddress = OrderAddress::factory()->shipping()->forOrder($this->order)->create();
    }

    /**
     * 배송지 URL 생성 헬퍼
     *
     * @param int $orderId 주문 ID
     * @return string
     */
    private function url(int $orderId): string
    {
        return "/api/modules/sirsoft-ecommerce/user/orders/{$orderId}/shipping-address";
    }

    public function test_update_shipping_address_with_manual_input(): void
    {
        $data = [
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9999-8888',
            'zipcode' => '54321',
            'address' => '서울시 서초구 서초대로 123',
            'address_detail' => '456동 789호',
        ];

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), $data);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->shippingAddress->refresh();
        $this->assertEquals('김철수', $this->shippingAddress->recipient_name);
        $this->assertEquals('010-9999-8888', $this->shippingAddress->recipient_phone);
        $this->assertEquals('54321', $this->shippingAddress->zipcode);
        $this->assertEquals('서울시 서초구 서초대로 123', $this->shippingAddress->address);
    }

    public function test_update_shipping_address_with_saved_address(): void
    {
        $savedAddress = UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'recipient_name' => '박영희',
            'recipient_phone' => '010-1111-2222',
            'zipcode' => '11111',
            'address' => '부산시 해운대구',
            'address_detail' => '마린시티',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'address_id' => $savedAddress->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->shippingAddress->refresh();
        $this->assertEquals('박영희', $this->shippingAddress->recipient_name);
        $this->assertEquals('부산시 해운대구', $this->shippingAddress->address);
    }

    public function test_update_shipping_address_fails_after_shipping(): void
    {
        $order = Order::factory()->shipping()->forUser($this->user)->create();
        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $response = $this->actingAs($this->user)
            ->putJson($this->url($order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구',
            ]);

        $response->assertStatus(422);
    }

    public function test_update_shipping_address_requires_authentication(): void
    {
        $response = $this->putJson($this->url($this->order->id), [
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9999-8888',
            'zipcode' => '54321',
            'address' => '서울시 서초구',
        ]);

        $response->assertUnauthorized();
    }

    public function test_update_shipping_address_denied_for_other_user(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구',
            ]);

        $response->assertNotFound();
    }

    public function test_update_shipping_address_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_name', 'recipient_phone']);
    }

    public function test_update_shipping_address_with_saved_address_rejects_other_user_address(): void
    {
        $otherUser = User::factory()->create();
        $otherAddress = UserAddress::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'address_id' => $otherAddress->id,
            ]);

        // address_id는 DB에 존재하지만 다른 유저 소유 → FormRequest에서 검증 실패
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['address_id']);
    }

    public function test_update_shipping_address_with_delivery_memo(): void
    {
        $data = [
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9999-8888',
            'zipcode' => '54321',
            'address' => '서울시 서초구 서초대로 123',
            'address_detail' => '456동 789호',
            'delivery_memo' => '부재 시 경비실에 맡겨주세요',
        ];

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), $data);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->shippingAddress->refresh();
        $this->assertEquals('부재 시 경비실에 맡겨주세요', $this->shippingAddress->delivery_memo);
    }

    public function test_update_shipping_address_fails_when_delivered(): void
    {
        $order = Order::factory()->delivered()->forUser($this->user)->create();
        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $response = $this->actingAs($this->user)
            ->putJson($this->url($order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구',
            ]);

        $response->assertStatus(422);
    }

    public function test_update_shipping_address_fails_when_cancelled(): void
    {
        $order = Order::factory()->cancelled()->forUser($this->user)->create();
        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $response = $this->actingAs($this->user)
            ->putJson($this->url($order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구',
            ]);

        $response->assertStatus(422);
    }

    public function test_update_shipping_address_rejects_nonexistent_address_id(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'address_id' => 99999,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['address_id']);
    }

    public function test_update_shipping_address_response_contains_order_data(): void
    {
        $data = [
            'recipient_name' => '이응답',
            'recipient_phone' => '010-5555-6666',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
        ];

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), $data);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => ['order'],
            ]);
    }
}
