<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 설정 배송 탭 — 배송유형 코드 맞바꾸기 저장 거절 (S10 13차)
 *
 * 배송사(`EcommerceSettingsCarrierCodeSwapTest`)·클레임 사유와 같은 결함군이다.
 * `ecommerce_shipping_types.code` 는 UNIQUE 이고 `syncShippingTypes()` 는 설정 파일 쓰기 **뒤**
 * 행 단위로 update 하므로, 두 기존 배송유형의 코드를 한 저장에서 맞바꾸면 페이로드 유일성은
 * 통과하고 shipping 설정 파일은 기록된 채 DB 에서 죽어 500 + 반쪽 상태가 남는다.
 *
 * 11·12차가 사유 3종 → 배송사로 이 가드를 넓혔는데 배송유형만 남아 있었다.
 */
class EcommerceSettingsShippingTypeCodeSwapTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * @param  string  $code  배송유형 코드
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed>
     */
    private function typeRow(string $code, array $overrides = []): array
    {
        return array_merge([
            'code' => $code,
            'name' => ['ko' => '배송유형 '.$code, 'en' => 'Type '.$code],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides);
    }

    /**
     * @scenario resource=shipping_type, error_class=validation
     *
     * @effects settings_shipping_type_code_swap_is_rejected_before_the_write
     */
    #[Test]
    public function swapping_codes_between_existing_shipping_types_is_rejected_before_the_file_write(): void
    {
        $a = ShippingType::create($this->typeRow('s13_type_a'));
        $b = ShippingType::create($this->typeRow('s13_type_b', ['sort_order' => 1]));

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('shipping.free_shipping_threshold');

        $response = $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'shipping',
                'shipping' => [
                    'free_shipping_threshold' => 654321,
                    'types' => [
                        $this->typeRow('s13_type_b', ['id' => $a->id]),
                        $this->typeRow('s13_type_a', ['id' => $b->id, 'sort_order' => 1]),
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shipping.types.0.code', 'shipping.types.1.code']);

        // 전용 문구의 도달 — 키가 점을 품어 점 표기 단언으로는 경로를 가리킬 수 없다.
        $this->assertSame(
            __('sirsoft-ecommerce::validation.custom.shipping.types.code_swap'),
            $response->json('errors')['shipping.types.0.code'][0] ?? null
        );

        $settings->clearCache();
        $this->assertSame($before, $settings->getSetting('shipping.free_shipping_threshold'), '거절된 저장이 배송 설정을 파일에 남겼다');
        $this->assertDatabaseHas('ecommerce_shipping_types', ['id' => $a->id, 'code' => 's13_type_a']);
        $this->assertDatabaseHas('ecommerce_shipping_types', ['id' => $b->id, 'code' => 's13_type_b']);
    }

    /**
     * 같은 저장에서 지워지는 배송유형의 코드를 물려받는 것은 허용한다 — 삭제가 update 보다 먼저다.
     *
     * 맞바꾸기 거절(422)의 **음성 대조군**이다. 거절 effect 를 함께 마킹하면 그 effect 의 셀이
     * 200 단언만으로 채워져 422 가드가 회귀해도 초록이 유지된다 — 대조군 전용 effect 로 가른다(19차).
     *
     * @scenario resource=shipping_type, error_class=none
     *
     * @effects settings_shipping_type_code_takeover_from_a_deleted_row_is_allowed
     */
    #[Test]
    public function taking_over_the_code_of_a_shipping_type_deleted_in_the_same_save_is_allowed(): void
    {
        $keep = ShippingType::create($this->typeRow('s13_keep'));
        ShippingType::create($this->typeRow('s13_gone', ['sort_order' => 1]));

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'shipping',
                'shipping' => [
                    'types' => [
                        $this->typeRow('s13_gone', ['id' => $keep->id]),
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_types', ['id' => $keep->id, 'code' => 's13_gone']);
        $this->assertDatabaseMissing('ecommerce_shipping_types', ['code' => 's13_keep']);
    }

    /**
     * 맞바꾸기가 아닌 **평범한 코드 교정**도 그 유형이 쓰이는 중이면 막는다.
     *
     * 주문 배송행은 배송유형을 `shipping_type` 코드 문자열로만 기억한다(FK 없음). 삭제만
     * 막으면 오타 교정 한 번이 삭제와 똑같은 피해를 낸다 — 목록의 배송방법 라벨이 빈칸이
     * 되고, 그 코드가 실린 북마크·프리셋 URL 은 목록 전체를 422 로 만들며, 새 코드 기준
     * 사용량이 0 이라 **삭제 가드마저 통과**한다. 사유 카탈로그는 이 잠금을 이미 갖고 있고
     * (`ClaimReasonService::assertSyncDeletable`), 배송유형만 빠져 있었다.
     *
     * @scenario resource=shipping_type, error_class=domain
     *
     * @effects in_use_shipping_type_code_change_returns_400_with_reason
     */
    #[Test]
    public function renaming_the_code_of_an_in_use_shipping_type_is_rejected_and_leaves_no_partial_write(): void
    {
        $type = ShippingType::create($this->typeRow('s17_in_use'));
        OrderShipping::factory()->create(['shipping_type' => 's17_in_use']);

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('shipping.free_shipping_threshold');

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'shipping',
                'shipping' => [
                    'free_shipping_threshold' => 777777,
                    'types' => [$this->typeRow('s17_renamed', ['id' => $type->id])],
                ],
            ])
            ->assertStatus(400);

        // 가드가 쓰기보다 앞선다 — 거절된 저장이 설정 파일에도 DB 에도 흔적을 남기지 않는다.
        $settings->clearCache();
        $this->assertSame($before, $settings->getSetting('shipping.free_shipping_threshold'), '거절된 저장이 배송 설정을 파일에 남겼다');
        $this->assertDatabaseHas('ecommerce_shipping_types', ['id' => $type->id, 'code' => 's17_in_use']);
    }

    /**
     * 쓰이지 않는 배송유형의 코드는 그대로 고칠 수 있어야 한다.
     *
     * 잠금이 넓으면 운영자가 오타조차 고치지 못한다 — 막는 것은 "쓰이는 중" 하나다.
     *
     * 사용 중 개명 거절(400)의 **음성 대조군**이다. 거절 effect 를 함께 마킹하면 그 effect 의
     * 셀이 200 단언만으로 채워져 400 가드가 회귀해도 초록이 유지된다 — 대조군 전용 effect 로
     * 가른다(19차).
     *
     * @scenario resource=shipping_type, error_class=none
     *
     * @effects unused_shipping_type_code_change_is_allowed
     */
    #[Test]
    public function renaming_the_code_of_an_unused_shipping_type_is_allowed(): void
    {
        $type = ShippingType::create($this->typeRow('s17_unused'));

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'shipping',
                'shipping' => [
                    'types' => [$this->typeRow('s17_fixed', ['id' => $type->id])],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_types', ['id' => $type->id, 'code' => 's17_fixed']);
    }
}
