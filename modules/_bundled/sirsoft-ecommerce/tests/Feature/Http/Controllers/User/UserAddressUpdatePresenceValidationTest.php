<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송지 수정 요청의 주소 필수 조합 검증
 *
 * StoreUserAddressRequest 는 "국내(우편번호+주소) 또는 해외(주소+도시+우편번호)" 중
 * 한 조합을 required_with/required_without 로 강제하지만, UpdateUserAddressRequest 에는
 * 그 규칙이 없어 수정 경로로 주소를 통째로 비울 수 있었습니다(검증 우회).
 *
 * 다만 Store 규칙을 그대로 옮기면 부분 수정이 깨집니다. 검증은 "요청 페이로드"가 아니라
 * "기존 레코드 + 페이로드를 합친 결과 상태"를 기준으로 해야 하며, 이 테스트가 그 계약을
 * 양방향(우회 차단 / 부분 수정 보존)으로 고정합니다.
 */
class UserAddressUpdatePresenceValidationTest extends ModuleTestCase
{
    private string $baseUrl = '/api/modules/sirsoft-ecommerce/user/addresses';

    /**
     * 기본 배송지 여부만 바꾸는 요청은 주소 규칙을 발화시키지 않습니다.
     *
     * 주소 필드를 전혀 건드리지 않는 요청이 422 가 되면 부분 수정이 통째로 깨집니다.
     */
    public function test_update_only_is_default_passes(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", ['is_default' => true]);

        $response->assertStatus(200);
    }

    /**
     * 국내 주소를 통째로 비우는 수정은 거부됩니다.
     */
    public function test_update_blanking_domestic_address_fails(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", [
                'zipcode' => '',
                'address' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zipcode', 'address']);
    }

    /**
     * 우편번호만 비우는 수정은 거부됩니다.
     */
    public function test_update_blanking_zipcode_only_fails(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", ['zipcode' => '']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zipcode']);
    }

    /**
     * 우편번호만 유효한 값으로 바꾸는 수정은 통과합니다.
     *
     * 비우기(`''`)는 위 test_update_blanking_zipcode_only_fails 가 막지만, 그 반대 방향인
     * "우편번호만 새 값으로 교체" 는 별개 경로다. 결과 상태 판정이 페이로드에 없는 필드를
     * 기존 레코드로 메우지 못하면 이 정상 수정도 422 가 되어 부분 수정이 깨진다.
     */
    public function test_update_zipcode_only_passes(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create([
            'user_id' => $user->id,
            'zipcode' => '06236',
            'address' => '서울시 강남구 테헤란로 100',
        ]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", ['zipcode' => '13529']);

        $response->assertStatus(200);

        $this->assertSame('13529', $address->fresh()->zipcode, '변경한 우편번호가 저장되어야 한다');
        $this->assertSame(
            '서울시 강남구 테헤란로 100',
            $address->fresh()->address,
            '전송하지 않은 주소는 기존 값이 보존되어야 한다'
        );
    }

    /**
     * 도로명 주소만 바꾸는 수정은 기존 우편번호를 근거로 통과합니다.
     */
    public function test_update_address_only_passes_with_existing_zipcode(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create([
            'user_id' => $user->id,
            'zipcode' => '06236',
        ]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", [
                'address' => '서울시 강남구 테헤란로 500',
            ]);

        $response->assertStatus(200);
    }

    /**
     * 해외 배송지 수정 요청(조회 응답 형태 그대로 PUT)이 통과합니다.
     *
     * 배송지 조회 응답은 해외 주소를 city/state/postal_code 키로 내보내고 폼은 intl_*
     * 키로 제출하므로, 해외 주소를 수정할 때 요청에 intl_city/intl_postal_code 가 없는
     * 경우가 정상 경로에 존재합니다. 페이로드만 보고 required_with 를 걸면 이 경로가
     * 전부 422 가 됩니다.
     */
    public function test_update_overseas_address_without_intl_keys_passes(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create([
            'user_id' => $user->id,
            'country_code' => 'US',
            'zipcode' => null,
            'address' => null,
            'address_detail' => null,
            'address_line_1' => '350 5th Ave',
            'address_line_2' => 'Suite 1000',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10118',
        ]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", [
                'name' => '뉴욕 사무실',
                'recipient_name' => 'John Doe',
                'recipient_phone' => '010-1234-5678',
                'country_code' => 'US',
                'address_line_1' => '350 5th Ave',
                'address_line_2' => 'Suite 2000',
            ]);

        $response->assertStatus(200);

        // 전송되지 않은 해외 도시/주/우편번호는 저장된 값이 유지되어야 한다.
        // (intl_* 미전송 시 null 로 덮어써져 조용히 유실되던 회귀 방지)
        $this->assertDatabaseHas('ecommerce_user_addresses', [
            'id' => $address->id,
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10118',
            'address_line_2' => 'Suite 2000',
        ]);
    }

    /**
     * 국내 주소를 해외로 전환하면서 도시/우편번호를 빠뜨리면 거부됩니다.
     */
    public function test_update_switching_to_overseas_without_city_fails(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", [
                'country_code' => 'US',
                'zipcode' => '',
                'address' => '',
                'address_line_1' => '350 5th Ave',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['intl_city', 'intl_postal_code']);
    }

    /**
     * 해외 주소 3필드를 모두 채운 전환은 통과합니다.
     */
    public function test_update_switching_to_overseas_with_intl_fields_passes(): void
    {
        $user = User::factory()->create();
        $address = UserAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("{$this->baseUrl}/{$address->id}", [
                'country_code' => 'US',
                'zipcode' => '',
                'address' => '',
                'address_line_1' => '350 5th Ave',
                'intl_city' => 'New York',
                'intl_state' => 'NY',
                'intl_postal_code' => '10118',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('ecommerce_user_addresses', [
            'id' => $address->id,
            'country_code' => 'US',
            'city' => 'New York',
            'postal_code' => '10118',
        ]);
    }
}
