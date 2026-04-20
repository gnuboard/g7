<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ShippingType 모델 테스트
 */
class ShippingTypeTest extends ModuleTestCase
{
    private function createTestTypes(): void
    {
        ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        ShippingType::create([
            'code' => 'international_ems',
            'name' => ['ko' => '국제EMS', 'en' => 'International EMS'],
            'category' => 'international',
            'is_active' => false,
            'sort_order' => 8,
        ]);
        ShippingType::create([
            'code' => 'pickup',
            'name' => ['ko' => '매장수령', 'en' => 'Store Pickup'],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => 5,
        ]);
        ShippingType::create([
            'code' => 'digital',
            'name' => ['ko' => '디지털상품', 'en' => 'Digital'],
            'category' => 'other',
            'is_active' => false,
            'sort_order' => 11,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        ShippingType::clearCodeCache();
    }

    public function test_shipping_type_can_be_created(): void
    {
        $type = ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->assertNotNull($type->id);
        $this->assertEquals('parcel', $type->code);
        $this->assertEquals('domestic', $type->category);
        $this->assertTrue($type->is_active);
    }

    public function test_name_is_cast_to_array(): void
    {
        $type = ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
        ]);

        $this->assertIsArray($type->name);
        $this->assertEquals('택배', $type->name['ko']);
        $this->assertEquals('Parcel', $type->name['en']);
    }

    public function test_get_localized_name_returns_current_locale(): void
    {
        app()->setLocale('ko');

        $type = ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
        ]);

        $this->assertEquals('택배', $type->getLocalizedName());
        $this->assertEquals('Parcel', $type->getLocalizedName('en'));
    }

    public function test_get_localized_name_falls_back_to_ko(): void
    {
        app()->setLocale('ja');

        $type = ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
        ]);

        $this->assertEquals('택배', $type->getLocalizedName());
    }

    public function test_is_domestic_returns_true_for_domestic_category(): void
    {
        $type = ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
        ]);

        $this->assertTrue($type->isDomestic());
        $this->assertFalse($type->isInternational());
    }

    public function test_is_international_returns_true_for_international_category(): void
    {
        $type = ShippingType::create([
            'code' => 'international_ems',
            'name' => ['ko' => '국제EMS', 'en' => 'International EMS'],
            'category' => 'international',
        ]);

        $this->assertTrue($type->isInternational());
        $this->assertFalse($type->isDomestic());
    }

    public function test_scope_active_filters_active_types(): void
    {
        $this->createTestTypes();

        $activeTypes = ShippingType::active()->get();

        $this->assertCount(2, $activeTypes);
        $this->assertTrue($activeTypes->every(fn ($t) => $t->is_active));
    }

    public function test_scope_ordered_sorts_by_sort_order(): void
    {
        $this->createTestTypes();

        $types = ShippingType::ordered()->get();

        $this->assertEquals('parcel', $types->first()->code);
        $this->assertEquals('digital', $types->last()->code);
    }

    public function test_scope_of_category_filters_by_category(): void
    {
        $this->createTestTypes();

        $domestic = ShippingType::ofCategory('domestic')->get();
        $international = ShippingType::ofCategory('international')->get();
        $other = ShippingType::ofCategory('other')->get();

        $this->assertCount(2, $domestic);
        $this->assertCount(1, $international);
        $this->assertCount(1, $other);
    }

    public function test_get_cached_by_code_returns_correct_type(): void
    {
        $this->createTestTypes();

        $type = ShippingType::getCachedByCode('parcel');

        $this->assertNotNull($type);
        $this->assertEquals('parcel', $type->code);
        $this->assertEquals('domestic', $type->category);
    }

    public function test_get_cached_by_code_returns_null_for_unknown_code(): void
    {
        $this->createTestTypes();

        $type = ShippingType::getCachedByCode('nonexistent');

        $this->assertNull($type);
    }

    public function test_clear_code_cache_invalidates_cache(): void
    {
        $this->createTestTypes();

        // 캐시 로드
        $type1 = ShippingType::getCachedByCode('parcel');
        $this->assertNotNull($type1);

        // 새 타입 추가
        ShippingType::create([
            'code' => 'express',
            'name' => ['ko' => '특급', 'en' => 'Express'],
            'category' => 'domestic',
        ]);

        // 캐시 클리어 전 — 캐시에 없음
        $cached = ShippingType::getCachedByCode('express');
        $this->assertNull($cached);

        // 캐시 클리어 후 — 조회 가능
        ShippingType::clearCodeCache();
        $fresh = ShippingType::getCachedByCode('express');
        $this->assertNotNull($fresh);
        $this->assertEquals('express', $fresh->code);
    }

    public function test_code_is_unique(): void
    {
        ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배2', 'en' => 'Parcel2'],
            'category' => 'domestic',
        ]);
    }
}
