<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 고객·회원 응답의 관리자 내부 메모 노출 회귀 테스트
 *
 * 주문 상세와 마일리지 내역은 관리자 화면과 고객(마이페이지) 화면이 같은 Resource 를 쓴다.
 * 관리자 메모(`admin_memo`)·마일리지 처리 메모(`memo`)·처리 관리자(`granted_by`)는 운영자가
 * 내부용으로 남긴 값이라, 그 주문·마일리지의 주인인 고객에게도 보여서는 안 된다.
 *
 * 기존 테스트는 비회원 주문 경로만 `admin_memo` 부재를 확인해, 회원 경로의 누출이 살아남았다.
 * 차단만 단언하면 "전부 null" 로 고장 난 구현도 통과하므로 관리자 원문(양성 대조)을 쌍으로 둔다.
 */
class AdminNoteExposureTest extends ModuleTestCase
{
    private const ADMIN_MEMO = '내부 메모: 고객 블랙리스트 검토 중';

    private const MILEAGE_MEMO = '내부 메모: CS 보상 건 — 담당자 확인 필요';

    private const MILEAGE_DESCRIPTION = '고객 안내용 적립 사유';

    private User $member;

    /**
     * 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->createUser();

        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        app(EcommerceSettingsService::class)->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);
    }

    /**
     * 회원이 주문번호로 자기 주문 상세를 열면 관리자 메모가 null 이다(키는 유지).
     *
     * @scenario case=order_member_by_number
     *
     * @effects order_admin_memo_null_for_member
     *
     * @return void 반환값 없음
     */
    public function test_member_order_detail_by_number_hides_admin_memo(): void
    {
        $order = OrderFactory::new()->create([
            'user_id' => $this->member->id,
            'admin_memo' => self::ADMIN_MEMO,
        ]);

        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}");

        $response->assertOk();
        $this->assertArrayHasKey('admin_memo', $response->json('data'), '응답 구조가 바뀌었다 — 키는 유지하고 값만 비워야 한다');
        $this->assertNull($response->json('data.admin_memo'));
        $this->assertStringNotContainsString(self::ADMIN_MEMO, $response->getContent(), '고객 주문 상세 응답에 관리자 메모가 실렸다');
    }

    /**
     * 회원이 주문 ID 로 자기 주문 상세를 열어도 관리자 메모가 null 이다.
     *
     * @scenario case=order_member_by_id
     *
     * @effects order_admin_memo_null_for_member
     *
     * @return void 반환값 없음
     */
    public function test_member_order_detail_by_id_hides_admin_memo(): void
    {
        $order = OrderFactory::new()->create([
            'user_id' => $this->member->id,
            'admin_memo' => self::ADMIN_MEMO,
        ]);

        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertOk();
        $this->assertNull($response->json('data.admin_memo'));
        $this->assertStringNotContainsString(self::ADMIN_MEMO, $response->getContent(), '고객 주문 상세(ID) 응답에 관리자 메모가 실렸다');
    }

    /**
     * 관리자 주문 상세에는 관리자 메모 원문이 그대로 실린다(양성 대조).
     *
     * @scenario case=order_admin
     *
     * @effects order_admin_memo_visible_to_admin
     *
     * @return void 반환값 없음
     */
    public function test_admin_order_detail_still_shows_admin_memo(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = OrderFactory::new()->create([
            'user_id' => $this->member->id,
            'admin_memo' => self::ADMIN_MEMO,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}");

        $response->assertOk();
        $this->assertSame(self::ADMIN_MEMO, $response->json('data.admin_memo'), '관리자 주문 상세에서 관리자 메모가 사라졌다 (관리자 화면이 이 값을 쓴다)');
    }

    /**
     * 회원 마일리지 내역에는 처리 메모·처리 관리자가 null 이고 고객용 사유는 그대로 보인다.
     *
     * @scenario case=mileage_member
     *
     * @effects mileage_memo_and_granted_by_null_for_member, mileage_description_visible_to_member
     *
     * @return void 반환값 없음
     */
    public function test_member_mileage_history_hides_memo_and_granted_by(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.mileage.read']);
        $this->createAdminNoteTransaction($admin);

        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/user/mileage/history');

        $response->assertOk();
        $row = $response->json('data.transactions.data.0');
        $this->assertNotNull($row, '회원 마일리지 내역에서 거래가 사라졌다');
        $this->assertArrayHasKey('memo', $row, '응답 구조가 바뀌었다 — 키는 유지하고 값만 비워야 한다');
        $this->assertNull($row['memo']);
        $this->assertNull($row['granted_by']);
        $this->assertSame(self::MILEAGE_DESCRIPTION, $row['description'], '고객용 사유까지 가려졌다 (과잉 차단)');
        $this->assertStringNotContainsString(self::MILEAGE_MEMO, $response->getContent(), '회원 마일리지 응답에 처리 메모가 실렸다');
    }

    /**
     * 관리자 마일리지 목록에는 처리 메모·처리 관리자 원문이 그대로 실린다(양성 대조).
     *
     * @scenario case=mileage_admin
     *
     * @effects mileage_memo_and_granted_by_visible_to_admin
     *
     * @return void 반환값 없음
     */
    public function test_admin_mileage_list_still_shows_memo_and_granted_by(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.mileage.read']);
        $this->createAdminNoteTransaction($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/admin/mileage-transactions');

        $response->assertOk();
        $row = collect($response->json('data.data'))->firstWhere('user_id', $this->member->id);
        $this->assertNotNull($row);
        $this->assertSame(self::MILEAGE_MEMO, $row['memo'], '관리자 마일리지 목록에서 처리 메모가 사라졌다');
        $this->assertSame($admin->id, $row['granted_by'], '관리자 마일리지 목록에서 처리 관리자가 사라졌다');
    }

    /**
     * 관리자가 수동 적립한 마일리지 거래를 만듭니다(처리 메모·처리 관리자 포함).
     *
     * @param  User  $admin  처리 관리자
     * @return MileageTransaction 생성된 거래
     */
    private function createAdminNoteTransaction(User $admin): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $this->member->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000,
            'remaining_amount' => 1000,
            'balance_after' => 1000,
            'description' => self::MILEAGE_DESCRIPTION,
            'memo' => self::MILEAGE_MEMO,
            'granted_by' => $admin->id,
        ]);
    }
}
