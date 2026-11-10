<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\UpgradeFiles;

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

    /**
     * 1.0.0 백필 마이그레이션 파일을 불러와 인스턴스와 업그레이드 컨텍스트를 준비하고 로케일을 ko 로 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        UpgradeFiles::requireOnce(dirname(__DIR__, 3)
            .'/upgrades/data/1.0.0/migrations/BackfillDeliveryMemoLabel.php');

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_0_0\\Migrations\\BackfillDeliveryMemoLabel';
        $this->migration = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.0.0-beta.4',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        );

        config(['app.locale' => 'ko']);
    }

    /**
     * 주어진 배송 메모를 가진 배송지 행을 만들고 라벨을 비워 백필 대상 상태로 둔다.
     *
     * @param  string|null  $memo  배송 메모(프리셋 키·자유 텍스트·null)
     * @return int 생성된 배송지 행 ID
     */
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

    /**
     * 프리셋 키(security)로 저장된 메모는 현재 로케일의 한국어 문구로 라벨이 채워진다.
     *
     * @scenario case=preset_key
     *
     * @effects preset_key_backfilled_with_locale_label
     *
     * @return void 반환값 없음
     */
    public function test_backfills_preset_key_with_korean_label(): void
    {
        $id = $this->makeAddress('security');

        $this->migration->run($this->context);

        $this->assertSame(
            '경비실에 맡겨주세요',
            DB::table('ecommerce_order_addresses')->where('id', $id)->value('delivery_memo_label')
        );
    }

    /**
     * 프리셋 키가 아닌 자유 텍스트 메모는 원문 그대로 라벨에 복사된다.
     *
     * @scenario case=custom_text
     *
     * @effects custom_text_copied_as_label
     *
     * @return void 반환값 없음
     */
    public function test_backfills_custom_text_as_original(): void
    {
        $id = $this->makeAddress('문 앞 신발장 위에 두세요');

        $this->migration->run($this->context);

        $this->assertSame(
            '문 앞 신발장 위에 두세요',
            DB::table('ecommerce_order_addresses')->where('id', $id)->value('delivery_memo_label')
        );
    }

    /**
     * 라벨이 이미 채워진 행은 백필이 덮어쓰지 않고 기존 라벨을 유지한다.
     *
     * @scenario case=label_already_filled
     *
     * @effects existing_label_preserved
     *
     * @return void 반환값 없음
     */
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

    /**
     * 메모가 비어 있는 행은 건너뛰어 라벨이 null 로 남는다.
     *
     * @scenario case=empty_memo
     *
     * @effects empty_memo_left_null
     *
     * @return void 반환값 없음
     */
    public function test_skips_empty_memo(): void
    {
        $id = $this->makeAddress(null);

        $this->migration->run($this->context);

        $this->assertNull(
            DB::table('ecommerce_order_addresses')->where('id', $id)->value('delivery_memo_label')
        );
    }
}
