<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Mockery;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ShippingTypeService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ShippingTypeService 단위 테스트
 */
class ShippingTypeServiceTest extends ModuleTestCase
{
    private ShippingTypeService $service;

    private $mockRepository;

    private $mockOrderShippingRepository;

    protected function setUp(): void
    {
        parent::setUp();
        ShippingType::clearCodeCache();

        $this->mockRepository = Mockery::mock(ShippingTypeRepositoryInterface::class);
        $this->mockOrderShippingRepository = Mockery::mock(OrderShippingRepositoryInterface::class);

        $this->service = new ShippingTypeService(
            $this->mockRepository,
            $this->mockOrderShippingRepository
        );
    }

    // ========================================
    // getAllTypes() 테스트
    // ========================================

    public function test_get_all_types_returns_collection(): void
    {
        $this->mockRepository
            ->shouldReceive('getAll')
            ->once()
            ->with([])
            ->andReturn(new \Illuminate\Database\Eloquent\Collection([]));

        $result = $this->service->getAllTypes();

        $this->assertCount(0, $result);
    }

    // ========================================
    // getType() 테스트
    // ========================================

    public function test_get_type_returns_type_when_found(): void
    {
        $type = new ShippingType();
        $type->id = 1;
        $type->code = 'parcel';

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($type);

        $result = $this->service->getType(1);

        $this->assertNotNull($result);
        $this->assertEquals('parcel', $result->code);
    }

    public function test_get_type_returns_null_when_not_found(): void
    {
        $this->mockRepository
            ->shouldReceive('findById')
            ->with(999)
            ->once()
            ->andReturn(null);

        $result = $this->service->getType(999);

        $this->assertNull($result);
    }

    // ========================================
    // getActiveTypes() 테스트
    // ========================================

    public function test_get_active_types_delegates_to_repository(): void
    {
        $this->mockRepository
            ->shouldReceive('getActiveTypes')
            ->with('domestic')
            ->once()
            ->andReturn(new \Illuminate\Database\Eloquent\Collection([]));

        $result = $this->service->getActiveTypes('domestic');

        $this->assertCount(0, $result);
    }

    // ========================================
    // getTypesForSettings() 테스트
    // ========================================

    public function test_get_types_for_settings_returns_formatted_array(): void
    {
        $type = new ShippingType();
        $type->id = 1;
        $type->code = 'parcel';
        $type->name = ['ko' => '택배', 'en' => 'Parcel'];
        $type->category = 'domestic';
        $type->is_active = true;
        $type->sort_order = 1;

        $this->mockRepository
            ->shouldReceive('getAll')
            ->once()
            ->andReturn(new \Illuminate\Database\Eloquent\Collection([$type]));

        $result = $this->service->getTypesForSettings();

        $this->assertCount(1, $result);
        $this->assertEquals('parcel', $result[0]['code']);
        $this->assertEquals('domestic', $result[0]['category']);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayHasKey('is_active', $result[0]);
    }

    // ========================================
    // deleteType() 테스트
    // ========================================

    public function test_delete_type_succeeds_when_not_in_use(): void
    {
        $type = new ShippingType();
        $type->id = 1;
        $type->code = 'express';
        $type->name = ['ko' => '특급', 'en' => 'Express'];

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($type);

        $this->mockOrderShippingRepository
            ->shouldReceive('countByShippingType')
            ->with('express')
            ->once()
            ->andReturn(0);

        $this->mockRepository
            ->shouldReceive('delete')
            ->with(1)
            ->once()
            ->andReturn(true);

        $result = $this->service->deleteType(1);

        $this->assertEquals(1, $result['type_id']);
    }

    public function test_delete_type_throws_when_in_use(): void
    {
        $type = new ShippingType();
        $type->id = 1;
        $type->code = 'parcel';
        $type->name = ['ko' => '택배', 'en' => 'Parcel'];

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($type);

        $this->mockOrderShippingRepository
            ->shouldReceive('countByShippingType')
            ->with('parcel')
            ->once()
            ->andReturn(5);

        $this->expectException(\Exception::class);

        $this->service->deleteType(1);
    }

    public function test_delete_type_throws_when_not_found(): void
    {
        $this->mockRepository
            ->shouldReceive('findById')
            ->with(999)
            ->once()
            ->andReturn(null);

        $this->expectException(\Exception::class);

        $this->service->deleteType(999);
    }

    // ========================================
    // syncShippingTypes() 테스트 (통합 — DB 직접 사용)
    // ========================================

    public function test_sync_creates_new_types(): void
    {
        // syncShippingTypes는 DB를 직접 사용하므로 실제 서비스 인스턴스 사용
        $service = app(ShippingTypeService::class);

        $service->syncShippingTypes([
            ['code' => 'parcel', 'name' => ['ko' => '택배', 'en' => 'Parcel'], 'category' => 'domestic', 'is_active' => true],
            ['code' => 'pickup', 'name' => ['ko' => '매장수령', 'en' => 'Pickup'], 'category' => 'domestic', 'is_active' => true],
        ]);

        $this->assertEquals(2, ShippingType::count());
        $this->assertNotNull(ShippingType::where('code', 'parcel')->first());
        $this->assertNotNull(ShippingType::where('code', 'pickup')->first());
    }

    public function test_sync_updates_existing_types(): void
    {
        $service = app(ShippingTypeService::class);

        $existing = ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $service->syncShippingTypes([
            ['id' => $existing->id, 'code' => 'parcel', 'name' => ['ko' => '일반택배', 'en' => 'Standard Parcel'], 'category' => 'domestic', 'is_active' => false],
        ]);

        $updated = ShippingType::find($existing->id);
        $this->assertEquals('일반택배', $updated->name['ko']);
        $this->assertFalse($updated->is_active);
    }

    public function test_sync_deletes_removed_types_not_in_use(): void
    {
        $service = app(ShippingTypeService::class);

        $toKeep = ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
        ]);
        $toDelete = ShippingType::create([
            'code' => 'express',
            'name' => ['ko' => '특급', 'en' => 'Express'],
            'category' => 'domestic',
        ]);

        $service->syncShippingTypes([
            ['id' => $toKeep->id, 'code' => 'parcel', 'name' => ['ko' => '택배', 'en' => 'Parcel'], 'category' => 'domestic'],
        ]);

        $this->assertEquals(1, ShippingType::count());
        $this->assertNull(ShippingType::find($toDelete->id));
    }

    public function test_sync_throws_when_deleting_type_in_use(): void
    {
        $service = app(ShippingTypeService::class);

        $inUse = ShippingType::create([
            'code' => 'parcel',
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
        ]);

        // DB에 직접 주문 배송 레코드 생성
        \Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory::new()->create();
        \Modules\Sirsoft\Ecommerce\Models\OrderShipping::factory()->create([
            'shipping_type' => 'parcel',
        ]);

        $this->expectException(\Exception::class);

        // parcel을 제외한 sync → parcel 삭제 시도 → 사용 중이므로 예외
        $service->syncShippingTypes([]);
    }
}
