<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 새 설치 기본 상태의 주문설정 저장 (7.1.1 출시 전 실측)
 *
 * 기본값(`config/settings/defaults.json`)은 무통장입금이 켜져 있고 입금 계좌 목록에 **빈 자리표시 행 하나**
 * (은행만 골라 둔 「사용 안 함」 행)가 들어 있다. 관리자 화면은 주문설정 탭을 통째로 돌려보내므로, 그 빈 행이
 * 계좌번호·예금주 필수 검사에 걸리고, 행을 지워도 「무통장입금을 쓰려면 사용 중인 계좌가 하나 이상」 검사에
 * 걸려, 새 설치 사이트는 계좌를 등록하거나 무통장을 끄기 전에는 주문설정의 **어떤 값도** 저장할 수 없었다
 * (7.1.0 부터, 422 「계좌번호는 필수 항목입니다. (외 3개 오류)」).
 *
 * 고친 규칙:
 * - 사용 안 함이고 계좌번호·예금주가 모두 빈 행은 자리표시 행으로 보고 검사·저장에서 뺀다.
 * - 「무통장 켜짐 + 사용 중 계좌 0」 은 이번 요청이 그 상태를 **새로 만들 때만** 막는다 — 무통장을 새로 켜거나
 *   계좌 목록을 바꾸는 요청. 저장된 상태 그대로 다른 값만 바꾸는 저장은 막지 않는다.
 *
 * 축 요약(마커 아님 — 평문): surface=admin_settings_save 의 active_account none·present. 테스트별 조합·효과는 각 메서드 docblock 에 있다.
 */
class EcommerceSettingsDefaultBankAccountTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private string $storagePath;

    private User $adminUser;

    /**
     * 저장소를 비워 기본값 상태에서 시작합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
        app(EcommerceSettingsService::class)->clearCache();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 저장소를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * 화면이 받는 주문설정(기본값 상태)을 읽습니다.
     *
     * @return array<string, mixed> 주문설정
     */
    private function currentOrderSettings(): array
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();

        return $settings->getSettings('order_settings');
    }

    /**
     * 기본 상태 그대로 다른 값 하나만 바꿔 주문설정 탭을 통째로 저장하면 200 이다.
     *
     * @scenario surface=admin_settings_save, active_account=none
     *
     * @effects settings_default_state_order_settings_saves
     *
     * @return void 반환값 없음
     */
    public function test_기본_상태_그대로_다른_값만_바꿔_저장하면_통과한다(): void
    {
        $order = $this->currentOrderSettings();

        // 전제: 기본값이 「무통장 켜짐 + 빈 자리표시 계좌 행」 이다.
        $dbank = collect($order['payment_methods'] ?? [])->firstWhere('id', 'dbank');
        $this->assertTrue((bool) ($dbank['is_active'] ?? false), '전제: 기본값에서 무통장입금이 켜져 있다');
        $this->assertNotEmpty($order['bank_accounts'] ?? [], '전제: 기본값에 자리표시 계좌 행이 있다');

        $order['auto_cancel_days'] = 5;

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => $order,
        ])->assertOk();

        $saved = $this->currentOrderSettings();
        $this->assertSame(5, (int) $saved['auto_cancel_days']);
    }

    /**
     * 사용 안 함이고 계좌번호·예금주가 빈 행은 저장되지 않는다(자리표시 행).
     *
     * @scenario surface=admin_settings_save, active_account=present
     *
     * @effects settings_blank_inactive_bank_account_row_is_dropped
     *
     * @return void 반환값 없음
     */
    public function test_빈_자리표시_계좌_행은_저장에서_빠진다(): void
    {
        $order = $this->currentOrderSettings();
        $order['bank_accounts'] = [
            ['bank_code' => '004', 'account_number' => '', 'account_holder' => '', 'is_active' => false, 'is_default' => false],
            ['bank_code' => '088', 'account_number' => '110-123-456789', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
        ];

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => $order,
        ])->assertOk();

        $accounts = $this->currentOrderSettings()['bank_accounts'];
        $this->assertCount(1, $accounts);
        $this->assertSame('110-123-456789', $accounts[0]['account_number']);
    }

    /**
     * 사용 중인데 칸이 빈 계좌 행은 종전대로 필수 검사에 걸린다(자리표시 행이 아니다).
     *
     * @scenario surface=admin_settings_save, active_account=none
     *
     * @effects settings_active_blank_bank_account_row_still_required
     *
     * @return void 반환값 없음
     */
    public function test_사용_중인_빈_계좌_행은_필수_검사에_걸린다(): void
    {
        $order = $this->currentOrderSettings();
        $order['bank_accounts'] = [
            ['bank_code' => '004', 'account_number' => '', 'account_holder' => '', 'is_active' => true, 'is_default' => true],
        ];

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => $order,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['order_settings.bank_accounts.0.account_number']);
    }

    /**
     * 무통장을 이번 요청에서 새로 켜는데 사용 중 계좌가 없으면 종전대로 422 다.
     *
     * @scenario surface=admin_settings_save, active_account=none
     *
     * @effects settings_dbank_newly_enabled_without_account_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_무통장을_새로_켜면서_계좌가_없으면_거절한다(): void
    {
        // 먼저 무통장을 끈 상태를 저장해 둔다(기본값은 무통장만 켜져 있어 화면 저장으로는 끌 수 없으므로 서비스로 둔다).
        $order = $this->currentOrderSettings();
        $order['payment_methods'] = collect($order['payment_methods'])
            ->map(fn (array $m) => $m['id'] === 'dbank' ? array_merge($m, ['is_active' => false]) : $m)
            ->all();
        app(EcommerceSettingsService::class)->saveSettings(['order_settings' => $order]);

        // 계좌 없이 다시 켠다.
        $order = $this->currentOrderSettings();
        $order['payment_methods'] = collect($order['payment_methods'])
            ->map(fn (array $m) => $m['id'] === 'dbank' ? array_merge($m, ['is_active' => true]) : $m)
            ->all();

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => $order,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['order_settings.bank_accounts']);
    }

    /**
     * 무통장이 켜진 채로 계좌 목록을 바꾸면서 사용 중 계좌를 하나도 남기지 않으면 종전대로 422 다.
     *
     * @scenario surface=admin_settings_save, active_account=none
     *
     * @effects settings_dbank_newly_enabled_without_account_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_무통장이_켜진_채_계좌를_바꿔_사용_중_계좌가_없어지면_거절한다(): void
    {
        $order = $this->currentOrderSettings();
        $order['bank_accounts'] = [
            ['bank_code' => '088', 'account_number' => '110-123-456789', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
        ];
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => $order,
        ])->assertOk();

        $order = $this->currentOrderSettings();
        $order['bank_accounts'] = [];

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => $order,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['order_settings.bank_accounts']);
    }
}
