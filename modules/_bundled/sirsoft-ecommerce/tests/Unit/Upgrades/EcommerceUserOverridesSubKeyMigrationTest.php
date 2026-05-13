<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionClass;

/**
 * Ecommerce 모듈 1.0.0-beta.3 업그레이드 스텝의 user_overrides dot-path 변환 검증.
 */
class EcommerceUserOverridesSubKeyMigrationTest extends ModuleTestCase
{
    private object $upgrade;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/upgrades/Upgrade_1_0_0_beta_3.php';

        $class = 'Modules\\Sirsoft\\Ecommerce\\Upgrades\\Upgrade_1_0_0_beta_3';
        $this->upgrade = new $class();
        $this->context = new UpgradeContext(
            fromVersion: '1.0.0-beta.2',
            toVersion: '1.0.0-beta.3',
            currentStep: '1.0.0-beta.3',
        );
    }

    private function runUpgrade(): void
    {
        $reflection = new ReflectionClass($this->upgrade);
        $method = $reflection->getMethod('run');
        $method->invoke($this->upgrade, $this->context);
    }

    public function test_claim_reasons_name_expands_to_dot_paths(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $id = DB::table('ecommerce_claim_reasons')->insertGetId([
            'type' => 'refund',
            'code' => 'test_'.uniqid(),
            'name' => json_encode(['ko' => '주문 실수', 'en' => 'Order Mistake', 'ja' => '注文ミス']),
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
            'user_overrides' => json_encode(['name']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('ecommerce_claim_reasons')->where('id', $id)->first(['user_overrides']);
        $this->assertEqualsCanonicalizing(
            ['name.ko', 'name.en', 'name.ja'],
            json_decode($row->user_overrides, true)
        );
    }

    public function test_idempotent_for_dot_path_overrides(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $id = DB::table('ecommerce_claim_reasons')->insertGetId([
            'type' => 'refund',
            'code' => 'test_'.uniqid(),
            'name' => json_encode(['ko' => '주문 실수', 'en' => 'Order Mistake']),
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
            'user_overrides' => json_encode(['name.ko']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('ecommerce_claim_reasons')->where('id', $id)->first(['user_overrides']);
        $this->assertEquals(['name.ko'], json_decode($row->user_overrides, true));
    }

    public function test_scalar_columns_preserved(): void
    {
        // sort_order/is_active 같은 scalar 필드는 변환 영향 없음
        config(['app.supported_locales' => ['ko', 'en']]);

        $id = DB::table('ecommerce_claim_reasons')->insertGetId([
            'type' => 'refund',
            'code' => 'test_'.uniqid(),
            'name' => json_encode(['ko' => '주문 실수', 'en' => 'Order Mistake']),
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
            'user_overrides' => json_encode(['name', 'sort_order', 'is_active']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('ecommerce_claim_reasons')->where('id', $id)->first(['user_overrides']);
        $overrides = json_decode($row->user_overrides, true);
        $this->assertEqualsCanonicalizing(
            ['name.ko', 'name.en', 'sort_order', 'is_active'],
            $overrides
        );
    }
}
