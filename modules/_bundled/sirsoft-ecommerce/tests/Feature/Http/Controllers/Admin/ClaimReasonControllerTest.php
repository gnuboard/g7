<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\ClaimReasonException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 사유 관리자 API 테스트
 */
class ClaimReasonControllerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $adminUser;

    protected User $readOnlyUser;

    protected string $apiBase = '/api/modules/sirsoft-ecommerce/admin/claim-reasons';

    /**
     * 설정 읽기·수정 권한을 가진 관리자와 읽기 전용 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);

        $this->readOnlyUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
        ]);
    }

    /**
     * 테스트용 클레임 사유를 생성합니다.
     *
     * @param  array  $overrides  오버라이드할 속성
     * @return ClaimReason 생성된 사유
     */
    protected function createReason(array $overrides = []): ClaimReason
    {
        return ClaimReason::create(array_merge([
            'type' => 'refund',
            'code' => 'test_reason_'.uniqid(),
            'name' => ['ko' => '테스트 사유', 'en' => 'Test Reason'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * 요청 데이터를 생성합니다.
     *
     * @param  array  $overrides  오버라이드할 속성
     * @return array<string, mixed> 사유 생성·수정 요청 본문
     */
    protected function makeReasonData(array $overrides = []): array
    {
        return array_merge([
            'type' => 'refund',
            'code' => 'new_reason_'.uniqid(),
            'name' => ['ko' => '새 사유', 'en' => 'New Reason'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides);
    }

    // ==================== Index ====================

    /**
     * 유형별 사유 목록이 화면에 필요한 필드를 모두 담아 돌아온다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_lists_catalog_by_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function index_returns_claim_reasons_list(): void
    {
        $this->createReason(['code' => 'reason_a', 'sort_order' => 0]);
        $this->createReason(['code' => 'reason_b', 'sort_order' => 1]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}?type=refund");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'type',
                            'code',
                            'name',
                            'localized_name',
                            'fault_type',
                            'is_user_selectable',
                            'is_active',
                            'sort_order',
                        ],
                    ],
                ],
            ]);
    }

    /**
     * `type` 없이 불러도 500 이 아니라 환불 카탈로그를 돌려준다.
     *
     * 저장소 `getAll()` 은 `type` 이 없으면 계약 위반으로 예외를 던진다(500). 그 예외를 막는
     * 것은 요청 경계의 보상 기본값(`IndexClaimReasonRequest::filters()` 의 `??=`) **하나뿐**인데,
     * 그 장치를 지키는 단언이 어디에도 없었다 — 무타입 호출 케이스가 403 하나여서 저장소까지
     * 가지도 않았다. `??=` 가 사라지거나 `filter_list_query` 훅이 그 키를 떨어뜨리면 관리자
     * 사유 목록이 통째로 500 이 되는데 red 가 나지 않는다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_lists_catalog_by_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function index_without_type_falls_back_to_the_refund_catalog(): void
    {
        $this->createReason(['type' => 'refund', 'code' => 'refund_default']);
        $this->createReason(['type' => 'exchange', 'code' => 'exchange_only']);

        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        $response->assertOk();

        $codes = array_column($response->json('data.data'), 'code');

        $this->assertContains('refund_default', $codes, '무타입 조회가 환불 카탈로그로 해석되지 않았다.');
        $this->assertNotContains('exchange_only', $codes, '무타입 조회가 다른 유형까지 섞어 돌려줬다.');
    }

    /**
     * `/active` 도 같은 보상 기본값을 갖는다.
     *
     * 형제 엔드포인트가 같은 저장소 계약을 타므로 한쪽만 지키면 나머지가 우회로가 된다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_lists_catalog_by_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function active_without_type_falls_back_to_the_refund_catalog(): void
    {
        $this->createReason(['type' => 'refund', 'code' => 'refund_active', 'is_active' => true]);
        $this->createReason(['type' => 'exchange', 'code' => 'exchange_active', 'is_active' => true]);

        $response = $this->actingAs($this->adminUser)->getJson("{$this->apiBase}/active");

        $response->assertOk();

        $codes = collect($response->json('data.data'))->pluck('code')->all();

        $this->assertContains('refund_active', $codes, '무타입 활성 조회가 환불 카탈로그로 해석되지 않았다.');
        $this->assertNotContains('exchange_active', $codes, '무타입 활성 조회가 다른 유형까지 섞어 돌려줬다.');
    }

    /**
     * 상세 생성 경로도 설정 저장 경로와 **같은** 코드 형식을 쓴다.
     *
     * 종전에는 상세 경로만 이중·말미 언더스코어를 허용했다. 그렇게 만든 코드가 접수되면
     * 설정 탭이 영구히 422 가 되는데, 그 탭은 카탈로그 전체를 되보내므로 운영자가 손대지
     * 않은 행이 원인이 된다 — 약한 쪽이 우회로가 되는 전형이다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_creates_and_validates
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function store_rejects_a_code_that_the_settings_tab_would_reject(): void
    {
        $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $this->makeReasonData(['code' => 'bad__code']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $this->makeReasonData(['code' => 'trailing_']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    /**
     * 이미 저장된 코드는 형식 검사를 면제받아 나머지 축을 계속 고칠 수 있다.
     *
     * 형식만 통일하고 면제를 두지 않으면, 규칙이 좁혀지기 전에 만들어진 사유는 이름·귀책조차
     * 고칠 수 없다 — 사용 중이면 코드 변경도 잠겨 있어(codeLockedInUse) 손댈 방법이 없어진다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_updates_reason
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function update_exempts_the_already_stored_code_from_the_format_rule(): void
    {
        $reason = $this->createReason(['code' => 'legacy__code']);

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $this->makeReasonData([
                'code' => 'legacy__code',
                'name' => ['ko' => '이름만 수정', 'en' => 'Name only'],
            ]))
            ->assertOk();

        // 면제는 그 사유의 기존 코드 하나뿐이다 — 다른 형식 위반으로 갈아타는 것은 막는다.
        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $this->makeReasonData([
                'code' => 'another__bad',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    /**
     * 읽기 권한이 없는 관리자는 사유 목록을 볼 수 없다(403).
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_requires_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function index_requires_read_permission(): void
    {
        $noPermUser = $this->createAdminUser([]);

        $response = $this->actingAs($noPermUser)
            ->getJson($this->apiBase);

        $response->assertForbidden();
    }

    // ==================== Store ====================

    /**
     * 새 사유를 만들면 201 과 함께 저장된다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_creates_and_validates
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function store_creates_new_claim_reason(): void
    {
        $data = $this->makeReasonData([
            'code' => 'custom_reason',
            'name' => ['ko' => '커스텀 사유', 'en' => 'Custom Reason'],
            'fault_type' => 'seller',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $data);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'custom_reason')
            ->assertJsonPath('data.fault_type', 'seller');

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'code' => 'custom_reason',
            'type' => 'refund',
            'fault_type' => 'seller',
        ]);
    }

    /**
     * 생성 경로에서 확장이 도메인 예외로 거절하면 500 이 아니라 400 과 전용 사유 문구로 안내한다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_creates_and_validates
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function store_returns_400_when_the_create_path_rejects_with_a_domain_reason(): void
    {
        // 생성 경로도 확장에 열려 있다 — `filter_create_data` 리스너가 도메인 예외를 던지면
        // 운영자가 조치할 수 있는 사유다. typed catch 가 없으면 형제 3메서드(update·destroy·
        // toggleStatus)가 400 으로 안내하는 같은 거절이 이 경로에서만 500 으로 위장된다.
        HookManager::addFilter(
            'sirsoft-ecommerce.claim_reason.filter_create_data',
            function (array $data) {
                throw ClaimReasonException::inUse(3);
            }
        );

        $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $this->makeReasonData())
            ->assertStatus(400)
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 3]));
    }

    /**
     * 필수 항목이 빠지면 422 로 각 필드 오류를 돌려준다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_creates_and_validates
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'code', 'name', 'fault_type']);
    }

    /**
     * 같은 유형 안에서 코드가 겹치면 422 다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_creates_and_validates
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function store_validates_unique_code_per_type(): void
    {
        $this->createReason(['code' => 'duplicate_code', 'type' => 'refund']);

        $data = $this->makeReasonData(['code' => 'duplicate_code']);

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    /**
     * 수정 권한이 없으면 사유를 만들 수 없다(403).
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_requires_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function store_requires_update_permission(): void
    {
        $data = $this->makeReasonData();

        $response = $this->actingAs($this->readOnlyUser)
            ->postJson($this->apiBase, $data);

        $response->assertForbidden();
    }

    /**
     * 교환·반품·환불 신청서가 쓰는 사유는 지울 수 없고, 사용 건수를 담은 전용 문구로 안내한다.
     *
     * @scenario reason_type=exchange
     *
     * @effects claim_reason_api_deletes_only_unused
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function destroy_is_blocked_while_an_exchange_or_return_still_uses_the_reason(): void
    {
        // 사용 중 판정이 취소 한 표만 세면 교환·반품·환불 사유는 사용 중에도 지워지고, 그 순간
        // 해당 신청서 전건의 사유 라벨이 카탈로그를 잃어 원시 코드로 노출된다(오류·경고 없음).
        $order = $this->deliveredClaimOrder();

        $exchangeReason = $this->createReason(['type' => 'exchange', 'code' => 'in_use_exchange']);
        $returnReason = $this->createReason(['type' => 'return', 'code' => 'in_use_return']);
        $refundReason = $this->createReason(['type' => 'refund', 'code' => 'in_use_refund']);

        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $exchangeReason->code]);
        OrderReturn::factory()->create(['order_id' => $order->id, 'reason_code' => $returnReason->code]);
        // 환불 카탈로그의 사유는 **관리자 직접 환불**로만 기록된다 — 반품 발·교환 발 환불행의
        // `reason_code` 는 자기 신청서에서 복사돼 온 값이라 출처가 반품/교환 카탈로그다.
        // 출처를 비워 두면 어느 생산 경로도 만들지 않는 행이 되어 계약을 대표하지 못한다.
        OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'reason_code' => $refundReason->code,
        ]);

        foreach ([$exchangeReason, $returnReason, $refundReason] as $reason) {
            $this->actingAs($this->adminUser)
                ->deleteJson("{$this->apiBase}/{$reason->id}")
                ->assertStatus(400) // 기존 `inUse` 계약 (취소 사유가 쓰이던 상태코드를 그대로 승계)
                // 전용 키로 안내하는지 + 건수가 치환되는지 (원문 노출·키 교체 차단)
                ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1]))
                // 안내가 취소 전용 서술로 되돌아가는 것을 막는다. 판정 모집단은 교환·반품·환불
                // 전수인데 안내만 취소를 지목하면, 운영자는 안내받은 건수를 취소 이력에서 찾다가
                // 0건을 보게 된다. 위 키 단언은 기대값도 같은 파일에서 읽으므로 이 되돌림을
                // 잡지 못한다 — 어느 로케일로 렌더되든 걸리도록 3언어 어휘를 함께 본다.
                ->assertJsonPath('message', fn ($m) => is_string($m)
                    && ! str_contains($m, '취소')
                    && ! str_contains($m, 'cancellation')
                    && ! str_contains($m, 'キャンセル'));

            $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $reason->id]);
        }

        // 위 응답 단언은 요청이 렌더된 한 로케일만 본다. 원본 두 개를 직접 확인해 두면
        // 렌더되지 않은 쪽이 취소 전용 서술로 되돌아가도 잡힌다.
        $this->assertStringNotContainsString(
            '취소',
            trans('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1], 'ko')
        );
        $this->assertStringNotContainsString(
            'cancellation',
            trans('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1], 'en')
        );
    }

    /**
     * 아무 신청서도 쓰지 않는 교환 사유는 지워진다.
     *
     * @scenario reason_type=exchange
     *
     * @effects claim_reason_api_deletes_only_unused
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function destroy_removes_a_reason_that_nothing_uses(): void
    {
        $reason = $this->createReason(['type' => 'exchange', 'code' => 'unused_exchange']);

        $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/{$reason->id}")
            ->assertOk();

        $this->assertDatabaseMissing('ecommerce_claim_reasons', ['id' => $reason->id]);
    }

    // ==================== Show ====================

    /**
     * 사유 하나를 조회한다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_lists_catalog_by_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function show_returns_single_reason(): void
    {
        $reason = $this->createReason(['code' => 'show_test']);

        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/{$reason->id}");

        $response->assertOk()
            ->assertJsonPath('data.code', 'show_test')
            ->assertJsonPath('data.id', $reason->id);
    }

    /**
     * 없는 사유를 조회하면 404 다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_lists_catalog_by_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function show_returns_404_for_nonexistent(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/99999");

        $response->assertNotFound();
    }

    // ==================== Update ====================

    /**
     * 사유의 귀책 등을 고치면 저장된다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_updates_reason
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function update_modifies_claim_reason(): void
    {
        $reason = $this->createReason(['code' => 'update_test']);

        $data = $this->makeReasonData([
            'code' => 'update_test',
            'name' => ['ko' => '수정된 사유', 'en' => 'Updated Reason'],
            'fault_type' => 'carrier',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $data);

        $response->assertOk()
            ->assertJsonPath('data.fault_type', 'carrier');

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'fault_type' => 'carrier',
        ]);
    }

    /**
     * 사용 중인 사유의 코드는 상세 수정 경로에서도 바꿀 수 없고 전용 문구로 안내한다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_code_change_blocked_while_in_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function update_is_blocked_from_changing_the_code_of_a_reason_in_use(): void
    {
        // 상세 수정 경로도 설정 저장 경로와 같은 강도로 막혀야 한다 — 두 경로가 갈리면
        // 약한 쪽이 우회로가 된다. 지금까지 이 가드를 부르는 케이스가 설정 경로에만 있었다.
        $order = $this->deliveredClaimOrder();
        $reason = $this->createReason(['type' => 'exchange', 'code' => 'locked_exchange']);
        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $reason->code]);

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $this->makeReasonData([
                'type' => 'exchange',
                'code' => 'locked_exchange_renamed',
            ]))
            ->assertStatus(400)
            // 상태코드만 보면 컨트롤러가 전용 키를 버리고 generic `operation_failed` 를 돌려줘도
            // 초록이다 — 설정 탭·삭제 경로와 같은 거절이 이 경로에서만 이유 없이 보이던 결함(11차 B1)
            // 을 상태코드 단언은 잡지 못했다. 전용 키의 **도달**을 고정한다.
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_code_locked_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'code' => 'locked_exchange',
        ]);
    }

    /**
     * 사용 중인 사유는 코드를 그대로 두고 유형만 옮기는 것도 막는다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_type_change_blocked_while_in_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function update_is_blocked_from_changing_the_type_of_a_reason_in_use(): void
    {
        // 라벨 해석은 (code, type) **쌍**이다 (`ClaimReasonRepository::findByCode`). 그래서 코드를
        // 그대로 두고 유형만 옮겨도 피해는 코드 변경과 같다 — 그 사유로 접수된 전건이 카탈로그와의
        // 짝을 잃고 원시 코드가 화면에 드러난다. 게다가 유형이 바뀌면 `countUsage()` 가 새 유형의
        // 표를 세므로 그 다음엔 삭제 가드까지 통과해 지워진다(연쇄 우회).
        $order = $this->deliveredClaimOrder();
        $reason = $this->createReason(['type' => 'exchange', 'code' => 'locked_type_exchange']);
        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $reason->code]);

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $this->makeReasonData([
                'type' => 'return',
                'code' => 'locked_type_exchange',
            ]))
            ->assertStatus(400)
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_reason_type_locked_in_use', ['count' => 1]));

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'type' => 'exchange',
        ]);
    }

    /**
     * 사용 중이어도 코드·유형 밖의 항목(이름·귀책·노출·정렬)은 고칠 수 있다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_reason_remains_editable_except_code_and_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function update_still_allows_editing_everything_except_code_and_type(): void
    {
        // 가드가 넓으면 사용 중인 사유의 오타조차 고칠 수 없다. 잠그는 것은 코드와 유형 둘뿐이고
        // 이름·귀책·노출 여부·정렬은 계속 수정 가능해야 한다.
        $order = $this->deliveredClaimOrder();
        $reason = $this->createReason(['type' => 'exchange', 'code' => 'editable_exchange']);
        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $reason->code]);

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $this->makeReasonData([
                'type' => 'exchange',
                'code' => 'editable_exchange',
                'name' => ['ko' => '오타 고친 사유', 'en' => 'Typo Fixed'],
                'fault_type' => 'seller',
                'is_user_selectable' => false,
                'sort_order' => 77,
            ]))
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'fault_type' => 'seller',
            'is_user_selectable' => false,
            'sort_order' => 77,
        ]);

        // 주석이 근거로 든 대표 축은 **이름(오타 교정)** 인데 종전엔 그 축이 단언 밖이었다 —
        // 잠금 가드가 이름까지 넓어져도 200 만 나오면 초록이었다. 형제 잠금 케이스는 잠긴 축의
        // DB 불변을 정확히 단언하는데 허용 케이스만 비대칭이었다.
        $reason->refresh();
        $this->assertSame('오타 고친 사유', $reason->name['ko'] ?? null);
        $this->assertSame('Typo Fixed', $reason->name['en'] ?? null);
    }

    /**
     * 아무도 쓰지 않는 사유는 유형을 옮길 수 있다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_reason_remains_editable_except_code_and_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function update_allows_changing_type_when_the_reason_is_not_used(): void
    {
        // 아무도 쓰지 않는 사유는 유형을 옮길 수 있어야 한다 — 가드는 "사용 중" 에만 걸린다.
        $reason = $this->createReason(['type' => 'exchange', 'code' => 'unused_movable']);

        $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $this->makeReasonData([
                'type' => 'return',
                'code' => 'unused_movable',
            ]))
            ->assertOk();

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'type' => 'return',
        ]);
    }

    /**
     * 수정 권한이 없으면 사유를 고칠 수 없다(403).
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_requires_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function update_requires_update_permission(): void
    {
        $reason = $this->createReason();
        $data = $this->makeReasonData();

        $response = $this->actingAs($this->readOnlyUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $data);

        $response->assertForbidden();
    }

    // ==================== Destroy ====================

    /**
     * 쓰이지 않는 환불 사유는 지워진다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_deletes_only_unused
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function destroy_deletes_unused_reason(): void
    {
        $reason = $this->createReason(['code' => 'delete_test']);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/{$reason->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('ecommerce_claim_reasons', [
            'id' => $reason->id,
        ]);
    }

    /**
     * 수정 권한이 없으면 사유를 지울 수 없다(403).
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_requires_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function destroy_requires_update_permission(): void
    {
        $reason = $this->createReason();

        $response = $this->actingAs($this->readOnlyUser)
            ->deleteJson("{$this->apiBase}/{$reason->id}");

        $response->assertForbidden();
    }

    // ==================== Toggle Status ====================

    /**
     * 사용 여부를 바꾸면 활성 사유가 비활성이 된다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_toggles_status
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function toggle_status_changes_is_active(): void
    {
        $reason = $this->createReason(['is_active' => true]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson("{$this->apiBase}/{$reason->id}/toggle-status");

        $response->assertOk();

        $reason->refresh();
        $this->assertFalse($reason->is_active);
    }

    /**
     * 없는 사유의 사용 여부를 바꾸면 400 과 「사유 없음」 전용 문구로 안내한다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_toggles_status
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function toggle_status_of_a_missing_reason_returns_400_with_the_not_found_key(): void
    {
        // `toggleStatus` 의 typed catch 가 도달하는 유일한 경로가 대상 부재다 — 이 케이스가 없으면
        // 그 catch 를 generic 으로 되돌려도 어느 테스트도 붉어지지 않는다.
        $this->actingAs($this->adminUser)
            ->patchJson("{$this->apiBase}/999999/toggle-status")
            ->assertStatus(400)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_reason_not_found'));
    }

    /**
     * 수정 권한이 없으면 사용 여부를 바꿀 수 없다(403).
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_requires_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function toggle_status_requires_update_permission(): void
    {
        $reason = $this->createReason();

        $response = $this->actingAs($this->readOnlyUser)
            ->patchJson("{$this->apiBase}/{$reason->id}/toggle-status");

        $response->assertForbidden();
    }

    // ==================== Active ====================

    /**
     * 활성 목록에는 비활성 사유가 빠진다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_api_lists_catalog_by_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function active_returns_only_active_reasons(): void
    {
        $this->createReason(['code' => 'active_one', 'is_active' => true]);
        $this->createReason(['code' => 'inactive_one', 'is_active' => false]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/active?type=refund");

        $response->assertOk();

        $codes = collect($response->json('data.data'))->pluck('code');
        $this->assertTrue($codes->contains('active_one'));
        $this->assertFalse($codes->contains('inactive_one'));
    }

    // ==================== User Selectable ====================

    /**
     * 회원용 목록에는 회원이 고를 수 있는 활성 사유만 나온다.
     *
     * @scenario reason_type=refund
     *
     * @effects claim_reason_user_list_shows_selectable_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function user_selectable_returns_active_user_selectable_reasons(): void
    {
        $this->createReason(['code' => 'selectable', 'is_active' => true, 'is_user_selectable' => true]);
        $this->createReason(['code' => 'not_selectable', 'is_active' => true, 'is_user_selectable' => false]);

        $user = $this->createUser();

        // user-orders.cancel 권한 부여
        $userRole = $user->roles()->first();
        if ($userRole) {
            $cancelPermission = Permission::firstOrCreate(
                ['identifier' => 'sirsoft-ecommerce.user-orders.cancel'],
                [
                    'name' => ['ko' => '주문 취소', 'en' => 'Cancel Order'],
                    'type' => 'user',
                ]
            );
            $userRole->permissions()->syncWithoutDetaching([$cancelPermission->id]);
        }

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/claim-reasons?type=refund');

        $response->assertOk();

        $codes = collect($response->json('data.data') ?? $response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('selectable'));
        $this->assertFalse($codes->contains('not_selectable'));
    }
}
