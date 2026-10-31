<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\AbstractUpgradeStep;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_3_0;
use PHPUnit\Framework\Attributes\Test;

/**
 * Upgrade_1_3_0 — 클레임 신설 데이터 전환 테스트
 *
 * 1) 기존 환불 이력의 발생 출처 polymorphic 백필
 * 2) 교환/반품 사유 카탈로그 시드
 * 3) claim 설정 카테고리 병합 (즉시취소 상태 승계)
 */
class Upgrade_1_3_0_Test extends ModuleTestCase
{
    /**
     * 업그레이드 스텝을 실행합니다.
     */
    private function runUpgrade(): void
    {
        $step = new Upgrade_1_3_0;
        $context = (new UpgradeContext(
            fromVersion: '1.1.1',
            toVersion: '1.3.0',
            logChannel: 'extension-upgrade',
        ))->withCurrentStep('1.3.0');

        $step->run($context);
    }

    /**
     * 취소 이력과 그에 연결된 레거시 환불 행(출처 미기록)을 만듭니다.
     *
     * @return array{0: OrderCancel, 1: OrderRefund}
     */
    private function makeLegacyCancelRefund(string $refundNumber): array
    {
        $order = OrderFactory::new()->create();
        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-'.$refundNumber,
            'cancel_type' => 'full',
            'cancel_status' => 'completed',
            'cancel_reason_type' => 'changed_mind',
            'items_snapshot' => [],
        ]);

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_number' => $refundNumber,
            'refund_status' => 'completed',
            'refund_method' => 'pg',
            'refund_amount' => 10000,
        ]);

        // 1.3.0 이전 상태 재현 — 출처 축이 비어 있다.
        DB::table('ecommerce_order_refunds')
            ->where('id', $refund->id)
            ->update(['refund_source_type' => null, 'refund_source_id' => null]);

        return [$cancel, $refund];
    }

    /**
     * 환불 행의 출처 원시값을 조회합니다.
     *
     * @return object|null 출처 축 원시값
     */
    private function rawSource(int $refundId): ?object
    {
        return DB::table('ecommerce_order_refunds')
            ->where('id', $refundId)
            ->first(['refund_source_type', 'refund_source_id']);
    }

    #[Test]
    public function 업그레이드_스텝은_abstract_upgrade_step_을_상속한다(): void
    {
        // g7_version(">=7.0.7") 이 7.0.0-beta.5 이상이므로 상속 의무가 발동한다.
        $this->assertInstanceOf(AbstractUpgradeStep::class, new Upgrade_1_3_0);
    }

    // ========================================
    // 1) 환불 출처 백필
    // ========================================

    #[Test]
    public function 취소에서_생긴_기존_환불을_cancel_출처로_백필한다(): void
    {
        [$cancel, $refund] = $this->makeLegacyCancelRefund('RF-UPG-0001');

        $this->runUpgrade();

        $source = $this->rawSource($refund->id);
        $this->assertSame(RefundSourceTypeEnum::CANCEL->value, $source->refund_source_type);
        $this->assertEquals($cancel->id, $source->refund_source_id);
    }

    #[Test]
    public function 백필_후에도_레거시_order_cancel_id_는_보존된다(): void
    {
        // 하위호환 이중 기록이 계약이다 — 기존 호출부가 그대로 동작해야 한다.
        [$cancel, $refund] = $this->makeLegacyCancelRefund('RF-UPG-0002');

        $this->runUpgrade();

        $this->assertEquals($cancel->id, $refund->refresh()->order_cancel_id);
    }

    #[Test]
    public function 출처를_단정할_수_없는_환불은_건드리지_않는다(): void
    {
        // order_cancel_id 가 비어 있으면 취소 발인지 직접 환불인지 알 수 없다.
        // direct 로 칠하면 연결이 유실된 취소 발 환불까지 직접 환불로 굳는다.
        $order = OrderFactory::new()->create();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF-UPG-0003',
            'refund_status' => 'completed',
            'refund_method' => 'bank',
            'refund_amount' => 5000,
        ]);

        $this->runUpgrade();

        $source = $this->rawSource($refund->id);
        $this->assertNull($source->refund_source_type);
        $this->assertNull($source->refund_source_id);
    }

    #[Test]
    public function 이미_백필된_행은_다시_덮어쓰지_않는다(): void
    {
        $order = OrderFactory::new()->create();
        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-RF-UPG-0004',
            'cancel_type' => 'partial',
            'cancel_status' => 'completed',
            'cancel_reason_type' => 'changed_mind',
            'items_snapshot' => [],
        ]);
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN->value,
            'refund_source_id' => 999,
            'refund_number' => 'RF-UPG-0004',
            'refund_status' => 'completed',
            'refund_method' => 'pg',
            'refund_amount' => 1000,
        ]);

        $this->runUpgrade();

        $source = $this->rawSource($refund->id);
        $this->assertSame(RefundSourceTypeEnum::RETURN->value, $source->refund_source_type);
        $this->assertEquals(999, $source->refund_source_id);
    }

    #[Test]
    public function 재실행해도_결과가_같다(): void
    {
        [$cancel, $refund] = $this->makeLegacyCancelRefund('RF-UPG-0005');

        $this->runUpgrade();
        $this->runUpgrade();

        $source = $this->rawSource($refund->id);
        $this->assertSame(RefundSourceTypeEnum::CANCEL->value, $source->refund_source_type);
        $this->assertEquals($cancel->id, $source->refund_source_id);
    }

    // ========================================
    // 2) 교환/반품 사유 시드
    // ========================================

    #[Test]
    public function 교환과_반품_사유를_시드한다(): void
    {
        DB::table('ecommerce_claim_reasons')->whereIn('type', ['exchange', 'return'])->delete();

        $this->runUpgrade();

        $this->assertSame(8, DB::table('ecommerce_claim_reasons')->where('type', 'exchange')->count());
        $this->assertSame(9, DB::table('ecommerce_claim_reasons')->where('type', 'return')->count());
        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'type' => 'exchange',
            'code' => 'defective_product',
            'fault_type' => 'seller',
        ]);
        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'type' => 'return',
            'code' => 'damaged_in_transit',
            'fault_type' => 'carrier',
        ]);
    }

    #[Test]
    public function 운영자가_고친_기존_사유를_되돌리지_않는다(): void
    {
        DB::table('ecommerce_claim_reasons')->whereIn('type', ['exchange', 'return'])->delete();
        DB::table('ecommerce_claim_reasons')->insert([
            'type' => 'exchange',
            'code' => 'etc',
            'name' => json_encode(['ko' => '운영자가 바꾼 이름'], JSON_UNESCAPED_UNICODE),
            'fault_type' => 'seller',
            'is_user_selectable' => false,
            'is_active' => false,
            'sort_order' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('ecommerce_claim_reasons')
            ->where('type', 'exchange')->where('code', 'etc')->first();

        $this->assertStringContainsString('운영자가 바꾼 이름', $row->name);
        $this->assertEquals(0, $row->is_active);
        $this->assertSame(99, (int) $row->sort_order);
    }

    #[Test]
    public function 사유_시드를_재실행해도_중복_삽입이_없다(): void
    {
        DB::table('ecommerce_claim_reasons')->whereIn('type', ['exchange', 'return'])->delete();

        $this->runUpgrade();
        $this->runUpgrade();

        $this->assertSame(8, DB::table('ecommerce_claim_reasons')->where('type', 'exchange')->count());
        $this->assertSame(9, DB::table('ecommerce_claim_reasons')->where('type', 'return')->count());
    }

    #[Test]
    public function 기존_환불_사유는_그대로_남는다(): void
    {
        $before = DB::table('ecommerce_claim_reasons')->where('type', 'refund')->count();

        $this->runUpgrade();

        $this->assertSame($before, DB::table('ecommerce_claim_reasons')->where('type', 'refund')->count());
    }

    // ========================================
    // 3) claim 설정 병합
    // ========================================

    #[Test]
    public function claim_설정_기본값을_생성한다(): void
    {
        $path = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/claim.json';
        if (File::exists($path)) {
            File::delete($path);
        }

        $this->runUpgrade();

        $this->assertTrue(File::exists($path));
        $claim = json_decode((string) File::get($path), true);

        $this->assertSame(['delivered', 'confirmed'], $claim['exchange_requestable_statuses']);
        $this->assertSame(5, $claim['max_images']);
        $this->assertTrue($claim['complete_defaults']['create_refund_request']);
        $this->assertTrue($claim['complete_defaults']['restore_stock']);
    }

    #[Test]
    public function 즉시취소_상태는_기존_취소가능_상태를_승계한다(): void
    {
        // 운영자가 좁혀 둔 취소 가능 상태가 기본값으로 덮여 넓어지면
        // 그동안 막아 온 상태에서 취소가 조용히 열린다.
        $orderSettingsPath = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/order_settings.json';
        $claimPath = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/claim.json';

        $originalOrderSettings = File::exists($orderSettingsPath) ? File::get($orderSettingsPath) : null;
        if (File::exists($claimPath)) {
            File::delete($claimPath);
        }

        File::ensureDirectoryExists(dirname($orderSettingsPath));
        File::put($orderSettingsPath, json_encode(
            ['cancellable_statuses' => ['payment_complete', 'preparing']],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));

        try {
            $this->runUpgrade();

            $claim = json_decode((string) File::get($claimPath), true);
            $this->assertSame(['payment_complete', 'preparing'], $claim['instant_cancel_statuses']);
        } finally {
            if ($originalOrderSettings === null) {
                File::delete($orderSettingsPath);
            } else {
                File::put($orderSettingsPath, $originalOrderSettings);
            }
        }
    }

    #[Test]
    public function 이미_저장된_claim_설정은_덮어쓰지_않는다(): void
    {
        $claimPath = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/claim.json';
        File::ensureDirectoryExists(dirname($claimPath));
        File::put($claimPath, json_encode(
            ['max_images' => 2, 'instant_cancel_statuses' => ['preparing']],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));

        $this->runUpgrade();

        $claim = json_decode((string) File::get($claimPath), true);
        $this->assertSame(2, $claim['max_images'], '운영자 저장값이 보존되어야 합니다.');
        $this->assertSame(['preparing'], $claim['instant_cancel_statuses']);
    }

    #[Test]
    public function 기존_설치본에_없던_클레임_시퀀스_3종을_시드한다(): void
    {
        // 1.1.1 설치본 재현 — 교환·반품·클레임결제 시퀀스는 그 버전에 존재하지 않았다.
        // 시더는 설치 시에만 돌므로 업그레이드가 이 행들을 만들지 않으면 영영 없다.
        DB::table('ecommerce_sequences')
            ->whereIn('type', ['exchange', 'order_return', 'claim_payment'])
            ->delete();

        $this->runUpgrade();

        foreach ([
            'exchange' => 'EX',
            'order_return' => 'RT',
            'claim_payment' => 'CP',
        ] as $type => $prefix) {
            $row = DB::table('ecommerce_sequences')->where('type', $type)->first();

            $this->assertNotNull(
                $row,
                "{$type} 시퀀스가 없으면 그 유형의 채번이 SequenceNotFoundException 으로 전부 실패합니다."
            );
            $this->assertSame($prefix, $row->prefix);
            $this->assertSame('timestamp', $row->algorithm);
        }
    }

    #[Test]
    public function 이미_존재하는_클레임_시퀀스의_채번_상태는_보존한다(): void
    {
        // current_value 는 운영 중 증가하는 counter 다 — 재실행이 이것을 되돌리면
        // 이미 발급된 번호를 다시 발급하게 된다.
        DB::table('ecommerce_sequences')
            ->where('type', 'claim_payment')
            ->update(['current_value' => 20260824143522, 'prefix' => 'CP']);

        $this->runUpgrade();

        $row = DB::table('ecommerce_sequences')->where('type', 'claim_payment')->first();

        $this->assertNotNull($row);
        $this->assertSame(20260824143522, (int) $row->current_value, '기존 채번 상태를 덮어쓰면 안 됩니다.');
        $this->assertSame(
            1,
            DB::table('ecommerce_sequences')->where('type', 'claim_payment')->count(),
            '재실행이 중복 행을 만들면 안 됩니다.'
        );
    }
}
