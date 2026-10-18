<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * claim 설정 병합 스텝이 실제 판정값(order_settings.cancellable_statuses)도 1.2.x 값으로 고정하는지 (7.1.0 출시 점검 EU-3)
 *
 * 런타임은 `claim.instant_cancel_statuses` 를 늘 `order_settings.cancellable_statuses` 의 별칭으로 읽는다. 저장본
 * `order_settings.json` 에 그 키가 없는 사이트는 claim 쪽 사본만으로는 보호되지 않고 1.3.0 기본값(미결제 2종 포함)으로
 * 조용히 넓어졌다.
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-settings-cancellable-statuses.yaml`
 */
class MergeClaimSettingsCancellableStatusesTest extends ModuleTestCase
{
    private object $migration;

    private UpgradeContext $context;

    private string $orderSettingsPath;

    private string $claimPath;

    private ?string $originalOrderSettings = null;

    private ?string $originalClaim = null;

    /**
     * 설정 파일을 보관하고 업그레이드 데이터 마이그레이션을 불러옵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $dir = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings');
        $this->orderSettingsPath = $dir.'/order_settings.json';
        $this->claimPath = $dir.'/claim.json';
        $this->originalOrderSettings = File::exists($this->orderSettingsPath) ? File::get($this->orderSettingsPath) : null;
        $this->originalClaim = File::exists($this->claimPath) ? File::get($this->claimPath) : null;
        File::ensureDirectoryExists($dir);

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.0/migrations/03_MergeClaimSettingsDefaults.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\MergeClaimSettingsDefaults';

        $this->migration = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.2.1',
            toVersion: '1.3.0',
            currentStep: '1.3.0',
        );
    }

    /**
     * 보관한 설정 파일을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([[$this->orderSettingsPath, $this->originalOrderSettings], [$this->claimPath, $this->originalClaim]] as [$path, $original]) {
            if ($original === null) {
                File::delete($path);
            } else {
                File::put($path, $original);
            }
        }

        parent::tearDown();
    }

    /**
     * 저장본에 취소 가능 상태 키가 없으면 1.2.x 기본값을 실제 판정 키에도 적는다.
     *
     * @scenario saved_key=missing
     *
     * @effects order_cancellable_statuses_pinned_to_legacy
     *
     * @return void 반환값 없음
     */
    public function test_pins_order_cancellable_statuses_when_key_missing(): void
    {
        File::put($this->orderSettingsPath, json_encode(['auto_cancel_days' => 3]));
        File::delete($this->claimPath);

        $this->migration->run($this->context);

        $order = json_decode((string) File::get($this->orderSettingsPath), true);
        $this->assertSame(['payment_complete'], $order['cancellable_statuses']);
        $this->assertSame(3, $order['auto_cancel_days']);
    }

    /**
     * 운영자가 저장한 취소 가능 상태는 그대로 둔다.
     *
     * @scenario saved_key=operator_saved
     *
     * @effects order_cancellable_statuses_pinned_to_legacy
     *
     * @return void 반환값 없음
     */
    public function test_keeps_operator_saved_cancellable_statuses(): void
    {
        File::put($this->orderSettingsPath, json_encode(['cancellable_statuses' => ['payment_complete', 'preparing']]));
        File::delete($this->claimPath);

        $this->migration->run($this->context);

        $order = json_decode((string) File::get($this->orderSettingsPath), true);
        $this->assertSame(['payment_complete', 'preparing'], $order['cancellable_statuses']);
    }
}
