<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\DeliveryMemoPresetEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송 메모 프리셋 Enum + 라벨 변환(resolveLabel) 회귀 테스트 (U3)
 *
 * 저장된 프리셋 키(door 등)가 표시 라벨로 변환되고, 자유 텍스트(custom)는
 * 원문이 보존되는지 검증합니다.
 */
class DeliveryMemoPresetEnumTest extends ModuleTestCase
{
    public function test_has_correct_values(): void
    {
        $this->assertSame('door', DeliveryMemoPresetEnum::DOOR->value);
        $this->assertSame('security', DeliveryMemoPresetEnum::SECURITY->value);
        $this->assertSame('parcel_box', DeliveryMemoPresetEnum::PARCEL_BOX->value);
        $this->assertSame('call', DeliveryMemoPresetEnum::CALL->value);
    }

    public function test_values_returns_all_preset_keys(): void
    {
        $this->assertSame(
            ['door', 'security', 'parcel_box', 'call'],
            DeliveryMemoPresetEnum::values()
        );
    }

    public function test_resolve_label_converts_preset_key_to_korean_label(): void
    {
        app()->setLocale('ko');

        $this->assertSame('경비실에 맡겨주세요', DeliveryMemoPresetEnum::resolveLabel('security'));
        $this->assertSame('문 앞에 놓아주세요', DeliveryMemoPresetEnum::resolveLabel('door'));
        $this->assertSame('택배함에 넣어주세요', DeliveryMemoPresetEnum::resolveLabel('parcel_box'));
        $this->assertSame('배송 전 연락 부탁드립니다', DeliveryMemoPresetEnum::resolveLabel('call'));
    }

    public function test_resolve_label_converts_preset_key_to_english_label(): void
    {
        $this->assertSame('Leave with security', DeliveryMemoPresetEnum::resolveLabel('security', 'en'));
        $this->assertSame('Leave at door', DeliveryMemoPresetEnum::resolveLabel('door', 'en'));
    }

    public function test_resolve_label_preserves_custom_free_text(): void
    {
        $custom = '문 앞 신발장 위에 두세요';

        $this->assertSame($custom, DeliveryMemoPresetEnum::resolveLabel($custom));
        $this->assertSame($custom, DeliveryMemoPresetEnum::resolveLabel($custom, 'en'));
    }

    public function test_resolve_label_returns_null_for_empty(): void
    {
        $this->assertNull(DeliveryMemoPresetEnum::resolveLabel(null));
        $this->assertNull(DeliveryMemoPresetEnum::resolveLabel(''));
    }
}
