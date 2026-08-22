<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송 메모 라벨 백필(DataMigration) 회귀 테스트 (U3)
 *
 * 기존 delivery_memo(프리셋 키/자유 텍스트)를 delivery_memo_label 로 백필하는
 * BackfillDeliveryMemoLabel 의 동작을 검증합니다.
 */
class BackfillDeliveryMemoLabelTest extends ModuleTestCase
{
    private object $migration;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.0.0/migrations/BackfillDeliveryMemoLabel.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_0_0\\Migrations\\BackfillDeliveryMemoLabel';
        $this->migration = new $class();
        $this->context = new UpgradeContext(
            fromVersion: '1.0.0-beta.4',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        );

        config(['app.locale' => 'ko']);
    }

    private function makeAddress(?string $memo): int
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'delivery_memo' => $memo,
        ]);

        // 백필 대상 상태로 라벨을 명시적으로 비운다 (생성 경로 라벨 스냅샷 우회)
        DB::table('ecommerce_order_addresses')
            ->where('id', $address->id)
            ->update(['delivery_memo_label' => null]);

        return $address->id;
    }

    public function test_backfills_preset_key_with_korean_label(): void
    {
        $id = $this->makeAddress('security');

        $this->migration->run($this->context);

        $this->assertSame(
            '경비실에 맡겨주세요',
            DB::table('ecommerce_order_addresses')->where('id', $id)->value('delivery_memo_label')
        );
    }

    public function test_backfills_custom_text_as_original(): void
    {
        $id = $this->makeAddress('문 앞 신발장 위에 두세요');

        $this->migration->run($this->context);

        $this->assertSame(
            '문 앞 신발장 위에 두세요',
            DB::table('ecommerce_order_addresses')->where('id', $id)->value('delivery_memo_label')
        );
    }

    public function test_skips_already_filled_label(): void
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'delivery_memo' => 'door',
            'delivery_memo_label' => '기존 라벨 유지',
        ]);

        $this->migration->run($this->context);

        $this->assertSame(
            '기존 라벨 유지',
            DB::table('ecommerce_order_addresses')->where('id', $address->id)->value('delivery_memo_label')
        );
    }

    public function test_skips_empty_memo(): void
    {
        $id = $this->makeAddress(null);

        $this->migration->run($this->context);

        $this->assertNull(
            DB::table('ecommerce_order_addresses')->where('id', $id)->value('delivery_memo_label')
        );
    }
}
