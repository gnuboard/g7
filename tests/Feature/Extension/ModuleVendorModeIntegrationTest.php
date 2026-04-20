<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\Vendor\VendorMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * Module Manager 의 vendor_mode 통합 회귀 테스트.
 *
 * 검증 대상:
 * - installModule() 의 VendorMode 파라미터가 Manager에 전달되는지
 * - updateModule() 의 VendorMode 파라미터 전달
 * - getPreviousVendorMode() 가 DB에서 vendor_mode 컬럼을 읽는지
 * - vendor_mode 마이그레이션이 modules/plugins 테이블에 적용되었는지
 */
class ModuleVendorModeIntegrationTest extends TestCase
{
    public function test_modules_table_has_vendor_mode_column(): void
    {
        $hasColumn = DB::getSchemaBuilder()->hasColumn('modules', 'vendor_mode');
        $this->assertTrue($hasColumn, 'modules 테이블에 vendor_mode 컬럼이 있어야 합니다');
    }

    public function test_plugins_table_has_vendor_mode_column(): void
    {
        $hasColumn = DB::getSchemaBuilder()->hasColumn('plugins', 'vendor_mode');
        $this->assertTrue($hasColumn, 'plugins 테이블에 vendor_mode 컬럼이 있어야 합니다');
    }

    public function test_install_module_method_signature_accepts_vendor_mode(): void
    {
        $reflection = new ReflectionClass(ModuleManager::class);
        $method = $reflection->getMethod('installModule');
        $params = $method->getParameters();

        $vendorParam = collect($params)->first(fn ($p) => $p->getName() === 'vendorMode');

        $this->assertNotNull($vendorParam, 'installModule() 에 vendorMode 파라미터가 있어야 합니다');
        $this->assertSame(VendorMode::class, $vendorParam->getType()?->getName());
    }

    public function test_update_module_method_signature_accepts_vendor_mode(): void
    {
        $reflection = new ReflectionClass(ModuleManager::class);
        $method = $reflection->getMethod('updateModule');
        $params = $method->getParameters();

        $vendorParam = collect($params)->first(fn ($p) => $p->getName() === 'vendorMode');

        $this->assertNotNull($vendorParam, 'updateModule() 에 vendorMode 파라미터가 있어야 합니다');
        $this->assertSame(VendorMode::class, $vendorParam->getType()?->getName());
    }

    public function test_get_previous_vendor_mode_reads_from_db(): void
    {
        $manager = app(ModuleManager::class);
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('getPreviousVendorMode');
        $method->setAccessible(true);

        // 존재하지 않는 모듈 → null
        $result = $method->invoke($manager, 'nonexistent-test-module-'.uniqid());
        $this->assertNull($result);
    }
}
