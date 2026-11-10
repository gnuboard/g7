<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionClass;
use Tests\Support\UpgradeFiles;

/**
 * Ecommerce 모듈 1.0.0-beta.3 업그레이드 스텝의 user_overrides dot-path 변환 검증.
 */
class EcommerceUserOverridesSubKeyMigrationTest extends ModuleTestCase
{
    private object $upgrade;

    private UpgradeContext $context;

    /**
     * 1.0.0-beta.3 업그레이드 스텝 파일을 불러와 인스턴스와 업그레이드 컨텍스트를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        UpgradeFiles::requireOnce(dirname(__DIR__, 3).'/upgrades/Upgrade_1_0_0_beta_3.php');

        $class = 'Modules\\Sirsoft\\Ecommerce\\Upgrades\\Upgrade_1_0_0_beta_3';
        $this->upgrade = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.0.0-beta.2',
            toVersion: '1.0.0-beta.3',
            currentStep: '1.0.0-beta.3',
        );
    }

    /**
     * 업그레이드 스텝의 run 메서드를 리플렉션으로 호출해 변환을 실행한다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        $reflection = new ReflectionClass($this->upgrade);
        $method = $reflection->getMethod('run');
        $method->invoke($this->upgrade, $this->context);
    }

    /**
     * 클레임 사유의 최상위 키 `name` 표시가 지원 로케일별 dot-path(name.ko·name.en·name.ja)로 펼쳐진다.
     *
     * @scenario case=top_level_key
     *
     * @effects top_level_override_expanded_to_locale_dot_paths
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이미 dot-path(name.ko)인 표시는 다시 펼치지 않아 변환 결과가 그대로다.
     *
     * @scenario case=already_dot_path
     *
     * @effects existing_dot_path_override_left_unchanged
     *
     * @return void 반환값 없음
     */
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

    /**
     * 다국어 컬럼만 dot-path 로 펼치고 sort_order·is_active 같은 스칼라 컬럼 표시는 그대로 남긴다.
     *
     * @scenario case=mixed_scalar_columns
     *
     * @effects top_level_override_expanded_to_locale_dot_paths, scalar_column_override_preserved
     *
     * @return void 반환값 없음
     */
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
