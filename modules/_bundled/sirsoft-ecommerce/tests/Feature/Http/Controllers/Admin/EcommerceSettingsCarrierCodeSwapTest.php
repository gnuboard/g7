<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 설정 배송 탭 — 배송사 코드 맞바꾸기 저장 거절 (S10 12차)
 *
 * 클레임 사유(`EcommerceSettingsClaimTest::swapping_codes_between_existing_reasons_is_rejected_before_the_file_write`)
 * 와 같은 결함군이다. `ecommerce_shipping_carriers.code` 는 UNIQUE 이고 `syncCarriers()` 는
 * 설정 파일 쓰기 **뒤** 행 단위로 update 하므로, 두 기존 배송사의 코드를 한 저장에서 맞바꾸면
 * 페이로드 유일성은 통과하고 파일은 기록된 채 DB 에서 죽어 500 + 반쪽 상태가 남는다. 클레임과
 * 달리 배송사 표는 기존 행의 코드 칸이 화면에서 자유 편집이라 설정 화면에서 그대로 재현된다.
 */
class EcommerceSettingsCarrierCodeSwapTest extends ModuleTestCase
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
     * @param  string  $code  배송사 코드
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed>
     */
    private function carrierRow(string $code, array $overrides = []): array
    {
        return array_merge([
            'code' => $code,
            'name' => ['ko' => '배송사 '.$code, 'en' => 'Carrier '.$code],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides);
    }

    /**
     * @scenario resource=shipping_carrier, error_class=validation
     *
     * @effects settings_carrier_code_swap_is_rejected_before_the_write
     */
    #[Test]
    public function swapping_codes_between_existing_carriers_is_rejected_before_the_file_write(): void
    {
        $a = ShippingCarrier::create($this->carrierRow('s12_carrier_a'));
        $b = ShippingCarrier::create($this->carrierRow('s12_carrier_b', ['sort_order' => 1]));

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('shipping.free_shipping_threshold');

        $response = $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'shipping',
                'shipping' => [
                    'free_shipping_threshold' => 123456,
                    'carriers' => [
                        $this->carrierRow('s12_carrier_b', ['id' => $a->id]),
                        $this->carrierRow('s12_carrier_a', ['id' => $b->id, 'sort_order' => 1]),
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shipping.carriers.0.code', 'shipping.carriers.1.code']);

        // 전용 문구의 도달 — 키가 점을 품어 점 표기 단언으로는 경로를 가리킬 수 없다.
        $this->assertSame(
            __('sirsoft-ecommerce::validation.custom.shipping.carriers.code_swap'),
            $response->json('errors')['shipping.carriers.0.code'][0] ?? null
        );

        $settings->clearCache();
        $this->assertSame($before, $settings->getSetting('shipping.free_shipping_threshold'), '거절된 저장이 배송 설정을 파일에 남겼다');
        $this->assertDatabaseHas('ecommerce_shipping_carriers', ['id' => $a->id, 'code' => 's12_carrier_a']);
        $this->assertDatabaseHas('ecommerce_shipping_carriers', ['id' => $b->id, 'code' => 's12_carrier_b']);
    }

    /**
     * 같은 저장에서 지워지는 배송사의 코드를 물려받는 것은 허용한다 — 삭제가 update 보다 먼저다.
     *
     * 맞바꾸기 거절(422)의 **음성 대조군**이다. 거절 effect 를 함께 마킹하면 그 effect 의 셀이
     * 200 단언만으로 채워져 422 가드가 회귀해도 초록이 유지된다 — 대조군 전용 effect 로 가른다(19차).
     *
     * @scenario resource=shipping_carrier, error_class=none
     *
     * @effects settings_carrier_code_takeover_from_a_deleted_row_is_allowed
     */
    #[Test]
    public function taking_over_the_code_of_a_carrier_deleted_in_the_same_save_is_allowed(): void
    {
        $keep = ShippingCarrier::create($this->carrierRow('s12_keep'));
        ShippingCarrier::create($this->carrierRow('s12_gone', ['sort_order' => 1]));

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'shipping',
                'shipping' => [
                    'carriers' => [
                        $this->carrierRow('s12_gone', ['id' => $keep->id]),
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_shipping_carriers', ['id' => $keep->id, 'code' => 's12_gone']);
        $this->assertDatabaseMissing('ecommerce_shipping_carriers', ['code' => 's12_keep']);
    }

    /**
     * 코드 형식 검사는 손대지 않은 기존 값을 면제하고, 바꾼 값은 면제하지 않습니다.
     *
     * 이 탭은 저장할 때마다 카탈로그 **전체**를 되보낸다. 그래서 형식 규칙이 좁혀진 뒤에는
     * 이미 저장된 코드 한 건이 — 운영자가 손대지 않았는데도 — 탭 전체를 영구히 422 로 만든다.
     * 클레임 사유 코드가 `ClaimReasonCodeFormat` 의 면제 목록으로 막는 것과 같은 형태인데,
     * 같은 라운드가 이름 축은 배송사·배송유형까지 넓히면서 코드 축은 3유형에 머물렀다.
     *
     * 면제는 **손대지 않은** 코드에만 걸려야 한다 — 기존 행이라는 이유로 새 위반 코드까지
     * 통과시키면 그 면제가 형식 규칙의 우회로가 된다.
     *
     * @scenario resource=shipping_carrier, error_class=validation
     *
     * @effects settings_carrier_code_format_exempts_untouched_existing_value
     */
    #[Test]
    public function code_format_exempts_an_untouched_existing_value_but_not_a_changed_one(): void
    {
        // 규칙이 좁혀지기 전에 만들어진 형태의 코드 (대문자 — 현재 정규식은 소문자만 받는다)
        $legacy = ShippingCarrier::create($this->carrierRow('S12_LEGACY'));

        $payload = fn (string $code): array => [
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    $this->carrierRow($code, ['id' => $legacy->id]),
                ],
            ],
        ];

        // ① 손대지 않은 기존 코드는 통과한다
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, $payload('S12_LEGACY'))
            ->assertOk();

        // ② 같은 행이라도 코드를 바꾸면 형식을 다시 받는다
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, $payload('S12_ANOTHER'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping.carriers.0.code']);

        // 거절된 요청이 행을 바꾸지 않았다
        $this->assertDatabaseHas('ecommerce_shipping_carriers', ['id' => $legacy->id, 'code' => 'S12_LEGACY']);
    }
}
