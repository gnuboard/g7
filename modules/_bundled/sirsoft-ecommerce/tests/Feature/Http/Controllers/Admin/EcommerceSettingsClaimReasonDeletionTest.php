<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 정책 탭 저장이 사유 카탈로그를 **명시적으로만** 지우고, 생성·삭제를 활동로그에 남긴다 (#599 S0-D2).
 *
 * 통합 실측 세션0 이 만든 사유 3건이 이틀 사이 DB 에서 사라졌는데(원인 미확정), 지울 수 있는
 * 경로가 하나뿐이었다 — 설정 저장이 「DB 에는 있는데 페이로드에 없는 행」을 삭제로 읽는 것.
 * 다른 운영자가 탭을 연 뒤에 만들어진 사유는 그 탭의 페이로드에 없으므로, 그 탭에서 아무
 * 사유도 건드리지 않고 저장만 눌러도 조용히 지워진다. 응답은 200 이고 활동로그에는 「설정
 * 저장」 한 줄만 남아 누가 언제 지웠는지 되짚을 수 없다.
 *
 * 삭제는 운영자가 휴지통을 눌러 `{type}_reasons_deleted` 에 실은 id 로만 일어나고, 페이로드에
 * 없는 행은 손대지 않는다. 생성·수정·삭제는 각각 활동로그 한 줄을 남긴다.
 */
class EcommerceSettingsClaimReasonDeletionTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 운영자가 만든 사유 행을 만듭니다.
     */
    private function operatorReason(string $type, string $code): ClaimReason
    {
        return ClaimReason::create([
            'type' => $type,
            'code' => $code,
            'name' => ['ko' => '사유 '.$code, 'en' => 'Reason '.$code],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
            'created_by' => $this->adminUser->id,
        ]);
    }

    /**
     * 기존 행을 설정 탭이 되보내는 모양으로 만듭니다.
     */
    private function rowOf(ClaimReason $reason): array
    {
        return [
            'id' => $reason->id,
            'code' => $reason->code,
            'name' => $reason->name,
            'fault_type' => $reason->fault_type->value ?? $reason->fault_type,
            'is_user_selectable' => (bool) $reason->is_user_selectable,
            'is_active' => (bool) $reason->is_active,
            'sort_order' => 0,
        ];
    }

    /**
     * 페이로드에 없는 행은 지우지 않는다 — 다른 탭에서 만든 사유가 「저장」 한 번에 사라지지 않는다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_save_keeps_rows_missing_from_the_payload
     */
    #[Test]
    public function a_save_whose_payload_lacks_a_row_keeps_that_reason(): void
    {
        $kept = $this->operatorReason('refund', 'meas_kept');
        $other = $this->operatorReason('refund', 'meas_other');

        // 「다른 탭」 — kept 가 생기기 전에 열린 화면이 other 만 들고 저장한다.
        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => ['refund_reasons' => [$this->rowOf($other)]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $kept->id, 'code' => 'meas_kept']);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $other->id]);
    }

    /**
     * 휴지통으로 지목한 id 만 지우고, 삭제를 활동로그에 남긴다.
     *
     * @scenario reason_type=refund
     *
     * @effects settings_claim_explicit_deletion_removes_only_listed_ids, settings_claim_reason_deletion_is_logged
     */
    #[Test]
    public function explicit_deletion_removes_only_the_listed_ids_and_logs_it(): void
    {
        $gone = $this->operatorReason('refund', 'meas_gone');
        $kept = $this->operatorReason('refund', 'meas_kept');
        $absent = $this->operatorReason('refund', 'meas_absent');

        ActivityLog::query()->delete();

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'refund_reasons' => [$this->rowOf($kept)],
                    'refund_reasons_deleted' => [$gone->id],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('ecommerce_claim_reasons', ['id' => $gone->id]);
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $kept->id]);
        // 페이로드에 없지만 지목되지 않은 행은 그대로다.
        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $absent->id]);

        $logs = ActivityLog::where('action', 'claim_reason.delete')->get();
        $this->assertCount(1, $logs, '삭제 한 건에 활동로그 한 줄');
        $this->assertSame('meas_gone', $logs->first()->properties['code'] ?? null);
        $this->assertSame('refund', $logs->first()->properties['type'] ?? null);
    }

    /**
     * 지목했더라도 사용 중이면 파일 쓰기 전에 거절한다 — 기존 가드가 명시 삭제 경로에도 그대로 선다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_explicit_deletion_of_a_used_reason_is_rejected
     */
    #[Test]
    public function explicit_deletion_of_a_used_reason_is_rejected(): void
    {
        $used = $this->operatorReason('return', 'meas_used');
        $order = $this->deliveredClaimOrder();
        app(UserClaimService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'meas_used',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $order->user_id,
        ]);

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'return_reasons' => [],
                    'return_reasons_deleted' => [$used->id],
                ],
            ])
            ->assertStatus(400);

        $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $used->id]);
    }

    /**
     * 설정 탭에서 만든 사유는 생성 활동로그를 남긴다 — 상세 REST 경로와 같은 흔적이다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_creation_is_logged
     */
    #[Test]
    public function a_reason_created_from_the_settings_tab_is_logged(): void
    {
        ActivityLog::query()->delete();

        $this->actingAs($this->adminUser)
            ->putJson($this->apiBase, [
                '_tab' => 'claim',
                'claim' => [
                    'exchange_reasons' => [[
                        'code' => 'meas_created',
                        'name' => ['ko' => '생성 사유', 'en' => 'Created reason'],
                        'fault_type' => 'seller',
                        'is_user_selectable' => true,
                        'is_active' => true,
                        'sort_order' => 0,
                    ]],
                ],
            ])
            ->assertOk();

        $logs = ActivityLog::where('action', 'claim_reason.create')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('meas_created', $logs->first()->properties['code'] ?? null);
        $this->assertSame('exchange', $logs->first()->properties['type'] ?? null);
    }
}
