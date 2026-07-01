<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 마일리지 내역 컨트롤러 테스트
 *
 * GET  /admin/mileage-transactions            - 목록
 * POST /admin/mileage-transactions            - 수동 지급/차감
 * POST /admin/mileage-transactions/extend-expiry - 일괄 유효기간 연장
 * GET  /admin/mileage-transactions/{id}/linked   - 연결 거래
 */
class MileageTransactionControllerTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/mileage-transactions';

    private User $adminUser;

    private User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.mileage.read',
            'sirsoft-ecommerce.mileage.manage',
        ]);
        $this->memberUser = $this->createUser();

        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        app(EcommerceSettingsService::class)->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);
    }

    /**
     * 목록 조회 — 페이지네이션 + 통화 옵션 응답 (배송정책/쿠폰 Collection 규약 1:1).
     *
     * 프론트(_transactions_table.json)가 transactions.data.pagination.* 와
     * _filters.json 이 transactions.data.currencies 를 읽으므로 둘 다 응답에 실려야 한다.
     */
    public function test_index_returns_paginated_list(): void
    {
        MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
        ]);

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'data',
                'abilities' => ['can_manage'],
                'currencies',
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ],
        ]);
        $response->assertJsonPath('data.pagination.total', 1);
        $response->assertJsonPath('data.currencies', ['KRW']);
    }

    /**
     * 행 레벨 abilities.can_manage 노출 — DataGrid 행 액션('수동 조정') disabled 제어.
     *
     * 행 액션 disabledField('abilities.can_manage')가 각 행 데이터의
     * abilities.can_manage 를 참조하므로, manage 권한 보유 시 행마다 true 가
     * 실려야 한다. (행 데이터에 abilities 누락 → 행 액션 영구 비활성 회귀 방지)
     */
    public function test_index_rows_expose_can_manage_ability(): void
    {
        MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
        ]);

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'data' => [
                    ['abilities' => ['can_manage']],
                ],
            ],
        ]);
        $response->assertJsonPath('data.data.0.abilities.can_manage', true);
    }

    /**
     * manage 권한이 없는 관리자는 행 abilities.can_manage 가 false 여야 한다.
     */
    public function test_index_rows_can_manage_false_without_permission(): void
    {
        $readOnlyAdmin = $this->createAdminUser([
            'sirsoft-ecommerce.mileage.read',
        ]);

        MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
        ]);

        $response = $this->actingAs($readOnlyAdmin)->getJson($this->apiBase);

        $response->assertOk();
        $response->assertJsonPath('data.data.0.abilities.can_manage', false);
    }

    /**
     * 통화 옵션은 설정 currency_rules 의 모든 통화를 노출한다.
     */
    public function test_index_currencies_reflect_settings(): void
    {
        app(EcommerceSettingsService::class)->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
            ['currency_code' => 'USD', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();
        $response->assertJsonPath('data.currencies', ['KRW', 'USD']);
    }

    /**
     * 검색 — search_field 별 분기 (member/member_id/email/order).
     */
    public function test_index_search_field_filters(): void
    {
        $alice = $this->createUser();
        $alice->forceFill(['name' => 'Alice', 'email' => 'alice@example.com'])->save();
        $bob = $this->createUser();
        $bob->forceFill(['name' => 'Bob', 'email' => 'bob@example.com'])->save();

        $this->makeTx($alice->id, MileageTransactionTypeEnum::ADMIN_EARN, 1000);
        $this->makeTx($bob->id, MileageTransactionTypeEnum::ADMIN_EARN, 2000);

        // 회원명
        $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?search_field=member&search_keyword=Alice')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.user_name', 'Alice');

        // 회원 ID
        $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?search_field=member_id&search_keyword='.$bob->id)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.user_name', 'Bob');

        // 이메일
        $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?search_field=email&search_keyword=alice@example.com')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * 필터 — 날짜 범위(start_date/end_date).
     */
    public function test_index_date_range_filter(): void
    {
        $old = $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ADMIN_EARN, 1000);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ADMIN_EARN, 2000); // 오늘

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($this->adminUser)
            ->getJson($this->apiBase."?start_date={$from}&end_date={$to}")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * 필터 — 통화.
     */
    public function test_index_currency_filter(): void
    {
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ADMIN_EARN, 1000, 'KRW');
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ADMIN_EARN, 5, 'USD');

        $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?currency=USD')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * 필터 — 거래유형 UI 4분류(earn/use/expire/adjust) → 8종 enum 역매핑.
     */
    public function test_index_type_category_filter(): void
    {
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::PURCHASE_EARN, 1000);   // earn
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ORDER_USE, -500);       // use
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ADMIN_EARN, 2000);      // adjust
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::EXPIRED, -100);         // expire

        // adjust = admin_earn 등 조정계만
        $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?type=adjust')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        // earn = purchase_earn 만 (admin_earn 은 adjust 로 분리)
        $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?type=earn')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * 정렬 — amount_asc 시 금액 오름차순.
     */
    public function test_index_sort_amount_asc(): void
    {
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ADMIN_EARN, 3000);
        $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ADMIN_EARN, 1000);

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?sort=amount_asc')
            ->assertOk();

        $amounts = array_column($response->json('data.data'), 'amount');
        $this->assertSame([1000, 3000], $amounts);
    }

    /**
     * 테스트용 거래 생성 헬퍼.
     */
    private function makeTx(int $userId, MileageTransactionTypeEnum $type, float $amount, string $currency = 'KRW'): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => $currency,
            'type' => $type->value,
            'amount' => $amount,
            'remaining_amount' => $amount > 0 ? $amount : 0,
            'balance_after' => $amount,
        ]);
    }

    /**
     * 수동 지급 — 거래 생성 + granted_by.
     */
    public function test_store_admin_earn(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, [
            'user_id' => $this->memberUser->uuid,
            'action' => 'earn',
            'amount' => 2000,
            'currency' => 'KRW',
            'memo' => '이벤트 지급',
            'use_default_expiry' => false,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'user_id' => $this->memberUser->id,
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'granted_by' => $this->adminUser->id,
        ]);
    }

    /**
     * 회원 식별자는 uuid 로 전달된다 (코어 UserResource 가 id 를 노출하지 않음 — 정수 id 전송 거부).
     *
     * 회귀: 수동 지급 모달이 코어 users/search 응답(uuid 만 보유)에서 회원을 선택하므로
     * user_id 는 uuid 여야 한다. 정수 id 를 보내면 uuid 검증 실패로 422.
     */
    public function test_store_rejects_integer_user_id(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, [
            'user_id' => $this->memberUser->id,
            'action' => 'earn',
            'amount' => 2000,
            'currency' => 'KRW',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_id');
    }

    /**
     * 수동 차감 — 잔액 초과 시 422.
     */
    public function test_store_admin_deduct_exceeds_balance_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, [
            'user_id' => $this->memberUser->uuid,
            'action' => 'deduct',
            'amount' => 5000,
            'currency' => 'KRW',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 수동 차감 — 잔액 내 정상 처리.
     */
    public function test_store_admin_deduct_within_balance(): void
    {
        MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 3000, 'remaining_amount' => 3000, 'balance_after' => 3000,
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, [
            'user_id' => $this->memberUser->uuid,
            'action' => 'deduct',
            'amount' => 1000,
            'currency' => 'KRW',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'user_id' => $this->memberUser->id,
            'type' => MileageTransactionTypeEnum::ADMIN_DEDUCT->value,
        ]);
    }

    /**
     * 일괄 유효기간 연장.
     */
    public function test_extend_expiry(): void
    {
        $lot = MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000, 'expires_at' => now()->addDays(5),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase.'/extend-expiry', [
            'user_id' => $this->memberUser->uuid,
            'lot_ids' => [$lot->id],
            'days' => 30,
        ]);

        $response->assertOk();
        $this->assertTrue($lot->fresh()->expires_at->greaterThan(now()->addDays(30)));
    }

    /**
     * mileage.manage 권한 없는 계정의 수동 지급은 403.
     */
    public function test_store_without_manage_permission_returns_403(): void
    {
        $readOnlyAdmin = $this->createAdminUser(['sirsoft-ecommerce.mileage.read']);

        $response = $this->actingAs($readOnlyAdmin)->postJson($this->apiBase, [
            'user_id' => $this->memberUser->uuid,
            'action' => 'earn',
            'amount' => 1000,
            'currency' => 'KRW',
        ]);

        $response->assertForbidden();
    }

    /**
     * 비관리자 접근 차단.
     */
    public function test_normal_user_cannot_access(): void
    {
        $response = $this->actingAs($this->memberUser)->getJson($this->apiBase);

        $response->assertForbidden();
    }

    /**
     * 적립건 편집 — 사유(memo) + 만료일(expires_at) 변경.
     */
    public function test_update_earning_edits_memo_and_expiry(): void
    {
        $lot = MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000, 'expires_at' => now()->addDays(10), 'memo' => '구',
        ]);

        $newExpiry = now()->addDays(60)->toDateString();
        $response = $this->actingAs($this->adminUser)->patchJson($this->apiBase.'/'.$lot->id, [
            'memo' => '사유 정정',
            'expires_at' => $newExpiry,
        ]);

        $response->assertOk();
        $fresh = $lot->fresh();
        $this->assertSame('사유 정정', $fresh->memo);
        $this->assertSame($newExpiry, $fresh->expires_at->toDateString());
    }

    /**
     * 적립계가 아닌 거래(사용)는 편집 불가 — 422.
     */
    public function test_update_non_earning_returns_422(): void
    {
        $use = $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ORDER_USE, -500);

        $response = $this->actingAs($this->adminUser)->patchJson($this->apiBase.'/'.$use->id, [
            'memo' => '바꿔보기',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 이미 소멸된 적립건의 만료일 변경은 거부(422), memo 만 변경은 허용.
     */
    public function test_update_expired_lot_rejects_expiry_change(): void
    {
        $lot = MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 0, 'balance_after' => 0,
            'expires_at' => now()->subDays(5), 'expired_at' => now()->subDay(),
        ]);

        // 만료일 변경 시도 → 422
        $this->actingAs($this->adminUser)->patchJson($this->apiBase.'/'.$lot->id, [
            'expires_at' => now()->addDays(30)->toDateString(),
        ])->assertStatus(422);

        // memo 만 변경 → 허용
        $this->actingAs($this->adminUser)->patchJson($this->apiBase.'/'.$lot->id, [
            'memo' => '소멸건 메모',
        ])->assertOk();
        $this->assertSame('소멸건 메모', $lot->fresh()->memo);
    }

    /**
     * 만료일을 적립일시보다 과거로 변경하면 거부(422).
     */
    public function test_update_rejects_expiry_before_earned(): void
    {
        $lot = MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000, 'expires_at' => now()->addDays(30),
        ]);
        $lot->created_at = now()->subDays(10);
        $lot->save();

        // 적립일(10일 전)보다 과거(20일 전)로 만료일 변경 시도 → 422
        $this->actingAs($this->adminUser)->patchJson($this->apiBase.'/'.$lot->id, [
            'expires_at' => now()->subDays(20)->toDateString(),
        ])->assertStatus(422);
    }

    /**
     * 존재하지 않는 거래 편집은 422 (Service not_found).
     */
    public function test_update_nonexistent_returns_422(): void
    {
        $this->actingAs($this->adminUser)->patchJson($this->apiBase.'/999999', [
            'memo' => 'x',
        ])->assertStatus(422);
    }

    /**
     * mileage.manage 권한 없는 계정의 편집은 403.
     */
    public function test_update_without_manage_permission_returns_403(): void
    {
        $readOnlyAdmin = $this->createAdminUser(['sirsoft-ecommerce.mileage.read']);
        $lot = $this->makeTx($this->memberUser->id, MileageTransactionTypeEnum::ADMIN_EARN, 1000);

        $this->actingAs($readOnlyAdmin)->patchJson($this->apiBase.'/'.$lot->id, [
            'memo' => 'x',
        ])->assertForbidden();
    }

    /**
     * 행 데이터에 can_edit ability + 소멸 집계(expired_amount/expiry_state)가 노출된다.
     */
    public function test_index_rows_expose_can_edit_and_expiry_aggregates(): void
    {
        // 적립 1000, 잔여 0, 소멸 거래 -400 연결 (부분 소멸 시뮬레이션)
        $lot = MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 600, 'balance_after' => 600, 'expires_at' => now()->subDay(), 'expired_at' => now(),
        ]);
        MileageTransaction::create([
            'user_id' => $this->memberUser->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::EXPIRED->value,
            'amount' => -400, 'remaining_amount' => 0, 'balance_after' => 600, 'source_transaction_id' => $lot->id, 'expired_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase.'?sort=amount_desc');
        $response->assertOk();

        // 적립 행(amount 1000) 찾기
        $rows = $response->json('data.data');
        $earnRow = collect($rows)->firstWhere('id', $lot->id);
        $this->assertNotNull($earnRow);
        $this->assertTrue($earnRow['abilities']['can_edit'], '적립계 + manage 권한 → can_edit true');
        $this->assertSame(400.0, (float) $earnRow['expired_amount'], '소멸액 집계 400');
        $this->assertSame('partial_expired', $earnRow['expiry_state'], '1000 중 400 소멸 → partial');

        // 소멸 거래 행은 적립계 아님 → can_edit false
        $expireRow = collect($rows)->firstWhere('type', MileageTransactionTypeEnum::EXPIRED->value);
        $this->assertFalse($expireRow['abilities']['can_edit'], '비적립계 → can_edit false');
    }
}
