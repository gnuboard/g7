<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Validate;

use App\Contracts\Extension\StorageInterface;
use App\Enums\ActivityLogType;
use App\Extension\HookManager;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimActivityLogRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\ClaimReasonService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\ShippingTypeService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 훅·리포지토리·리스너 결함 수정 검증 (#599 validate P5)
 *
 * - 첨부 로그 조회가 지워진 첨부의 로그까지 포함한다
 * - 주문상품 상태 동기화 훅은 Service 가 발행한다(Repository 는 쓰기만)
 * - 사유 일괄 동기화는 바뀐 행만 갱신하고 before/filter 를 거치며 after 는 커밋 뒤에 쏜다
 * - 알림·활동 로그의 본문 값은 발행 시점에 캡처한다(큐 워커 시점 재조회 금지)
 * - 첨부 업로드에 before_upload 가 있다
 * - 범용 morph 별칭 충돌을 로그로 드러낸다
 * - 잠금 조회 호환 별칭과 실패 시 예외 버전이 있다
 * - 일괄 상태 동기화 로그가 주문마다 조회하지 않는다
 * - 호출처 없는 삭제 메서드를 걷어 냈다
 */
class ValidateP5HookDataTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;
    use CountsQueries;

    /**
     * 통화 설정과 클레임 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 통화 설정을 되돌리고 목을 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 활동 로그 한 줄을 직접 남깁니다.
     *
     * @param  string  $action  활동 로그 action 키
     * @param  int  $imageId  첨부 ID
     * @param  array<string, mixed>  $properties  로그 속성(귀속 스냅샷)
     * @return ActivityLog 활동 로그
     */
    private function imageLog(string $action, int $imageId, array $properties): ActivityLog
    {
        return ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'loggable_type' => ClaimImage::class,
            'loggable_id' => $imageId,
            'action' => $action,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.'.str_replace('.', '_', $action),
            'description_params' => ['original_name' => 'evidence.jpg'],
            'properties' => $properties,
        ]);
    }

    /**
     * 지워진 첨부의 업로드·삭제 로그가 신청서 활동 로그에 남는다.
     *
     * 수정 전 실패 이유: 첨부 로그를 **지금 남아 있는 첨부 ID** 로만 찾아, 첨부 행이 지워지면 그
     * 첨부의 업로드·삭제 로그가 신청서 이력에서 통째로 사라졌다.
     *
     * @scenario defect=deleted_attachment_log
     *
     * @effects claim_activity_log_keeps_logs_of_deleted_attachments
     *
     * @return void 반환값 없음
     */
    public function test_claim_activity_log_keeps_logs_of_deleted_attachments(): void
    {
        $exchange = OrderExchangeFactory::new()->create();
        $goneImageId = 2_000_000_000;

        $upload = $this->imageLog('claim_image.upload', $goneImageId, [
            'image_id' => $goneImageId, 'claimable_type' => ClaimImage::PENDING_CLAIMABLE_TYPE, 'claimable_id' => $exchange->order_id,
        ]);
        $delete = $this->imageLog('claim_image.delete', $goneImageId, [
            'image_id' => $goneImageId, 'claimable_type' => $exchange->getMorphClass(), 'claimable_id' => $exchange->id,
        ]);

        $ids = app(ClaimActivityLogRepositoryInterface::class)
            ->paginateForClaim($exchange, ['per_page' => 50])
            ->getCollection()
            ->pluck('id')
            ->all();

        $this->assertContains($delete->id, $ids, '지워진 첨부의 삭제 로그가 빠졌습니다');
        $this->assertContains($upload->id, $ids, '지워진 첨부의 업로드 로그가 빠졌습니다');
    }

    /**
     * 결제완료 상태의 주문과 주문상품을 만듭니다.
     *
     * @return Order 주문
     */
    private function paymentCompleteOrder(): Order
    {
        $order = Order::factory()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        OrderOptionFactory::new()->forOrder($order)->create(['option_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        return $order->fresh();
    }

    /**
     * 주문상품 동기화 훅 발화를 기록하는 리스너를 답니다.
     *
     * @return \ArrayObject<int, array{count: int, source: string}> 발화 기록
     */
    private function captureOptionSync(): \ArrayObject
    {
        $fired = new \ArrayObject;

        HookManager::addAction(
            'sirsoft-ecommerce.order_option.after_sync_status',
            function ($changes = [], $status = null, $source = '') use ($fired) {
                $fired->append(['count' => count($changes), 'source' => $source]);
            },
            1
        );

        return $fired;
    }

    /**
     * Repository 의 일괄 상태 변경은 훅을 쏘지 않는다.
     *
     * 수정 전 실패 이유: `OrderRepository::bulkUpdateOptionStatus()` 가 직접
     * `order_option.after_sync_status` 를 발행했다(훅은 Service 의 책임).
     *
     * @scenario defect=repository_hook
     *
     * @effects order_repository_option_sync_does_not_fire_hooks
     *
     * @return void 반환값 없음
     */
    public function test_order_repository_option_sync_does_not_fire_hooks(): void
    {
        $order = $this->paymentCompleteOrder();
        $fired = $this->captureOptionSync();

        app(OrderRepositoryInterface::class)->bulkUpdateOptionStatus([$order->id], OrderStatusEnum::PREPARING->value);

        $this->assertCount(0, $fired, 'Repository 가 훅을 발행합니다');
    }

    /**
     * 관리자 일괄 상태 변경은 Service 가 전이 내역과 함께 동기화 훅을 한 번 쏜다.
     *
     * 수정 전에도 통과한다(발행 위치만 Repository 였다) — 발행을 옮기면서 훅이 사라지거나 두 번
     * 나가지 않음을 잠그는 회귀 가드다.
     *
     * @scenario defect=repository_hook
     *
     * @effects order_service_fires_option_sync_hook_once_with_changes
     *
     * @return void 반환값 없음
     */
    public function test_order_service_fires_option_sync_hook_once_with_changes(): void
    {
        $order = $this->paymentCompleteOrder();
        $fired = $this->captureOptionSync();

        app(OrderService::class)->bulkUpdate(['ids' => [$order->id], 'order_status' => OrderStatusEnum::PREPARING->value]);

        $this->assertCount(1, $fired);
        $this->assertSame(1, $fired[0]['count']);
        $this->assertSame('bulk_update', $fired[0]['source']);
    }

    /**
     * 동기화 대상 교환 사유 한 건을 만듭니다.
     *
     * @return ClaimReason 사유
     */
    private function exchangeReason(): ClaimReason
    {
        return ClaimReason::create([
            'type' => 'exchange',
            'code' => 'validate_p5_reason',
            'name' => ['ko' => '검증 사유', 'en' => 'Validate reason'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * 설정 탭 페이로드 한 줄을 만듭니다.
     *
     * @param  ClaimReason  $reason  사유
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return array<string, mixed> 페이로드 행
     */
    private function payloadRow(ClaimReason $reason, array $overrides = []): array
    {
        return array_merge([
            'id' => $reason->id,
            'code' => $reason->code,
            // 화면은 로케일 순서를 보장하지 않는다 — 순서만 다른 이름은 같은 값이다.
            'name' => ['en' => 'Validate reason', 'ko' => '검증 사유'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
        ], $overrides);
    }

    /**
     * 아무것도 고치지 않은 저장은 사유를 갱신하지 않고 after_update 도 쏘지 않는다.
     *
     * 수정 전 실패 이유: `syncReasons()` 가 페이로드의 모든 기존 행을 update 하고 after_update 를
     * 매번 쏴, 무변경 저장 한 번이 사유 수만큼 「사유 수정」 로그를 쌓았다.
     *
     * @scenario defect=reason_sync
     *
     * @effects unchanged_claim_reason_sync_fires_no_update_hooks
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_claim_reason_sync_fires_no_update_hooks(): void
    {
        $reason = $this->exchangeReason();
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim_reason.after_update', function () use (&$fired) {
            $fired++;
        }, 1);

        app(ClaimReasonService::class)->syncReasons('exchange', [$this->payloadRow($reason)]);

        $this->assertSame(0, $fired, '바뀐 것이 없는 저장이 수정 훅을 쐈습니다');
    }

    /**
     * 바뀐 행은 상세 수정 경로와 같이 before_update → filter_update_data 를 거친다.
     *
     * 수정 전 실패 이유: 설정 저장 경로는 before/filter 를 건너뛰고 리포지토리를 바로 불러, 확장이
     * 상세 수정 경로에만 개입할 수 있었다(필터가 바꾼 값이 저장되지 않았다).
     *
     * @scenario defect=reason_sync
     *
     * @effects changed_claim_reason_sync_passes_before_and_filter_hooks
     *
     * @return void 반환값 없음
     */
    public function test_changed_claim_reason_sync_passes_before_and_filter_hooks(): void
    {
        $reason = $this->exchangeReason();
        $before = 0;
        HookManager::addAction('sirsoft-ecommerce.claim_reason.before_update', function () use (&$before) {
            $before++;
        }, 1);
        HookManager::addFilter('sirsoft-ecommerce.claim_reason.filter_update_data', function (array $data) {
            $data['name'] = ['ko' => '필터가 정한 이름', 'en' => 'Filtered name'];

            return $data;
        }, 1);

        app(ClaimReasonService::class)->syncReasons('exchange', [
            $this->payloadRow($reason, ['name' => ['ko' => '고친 이름', 'en' => 'Edited name']]),
        ]);

        $this->assertSame(1, $before);
        $this->assertSame('필터가 정한 이름', $reason->fresh()->name['ko']);
    }

    /**
     * 사유 동기화의 after 훅은 바깥 트랜잭션이 커밋된 뒤에 나간다.
     *
     * 수정 전 실패 이유: after_update 를 트랜잭션 안에서 바로 쏴, 뒤따르는 단계가 실패해 전체가
     * 되돌아가도 이미 나간 훅(활동 로그)은 남았다.
     *
     * @scenario defect=reason_sync
     *
     * @effects claim_reason_sync_after_hooks_wait_for_commit
     *
     * @return void 반환값 없음
     */
    public function test_claim_reason_sync_after_hooks_wait_for_commit(): void
    {
        $reason = $this->exchangeReason();
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim_reason.after_update', function () use (&$fired) {
            $fired++;
        }, 1);

        DB::beginTransaction();
        app(ClaimReasonService::class)->syncReasons('exchange', [
            $this->payloadRow($reason, ['is_active' => false]),
        ]);
        $this->assertSame(0, $fired, '커밋 전에 after_update 가 나갔습니다');
        DB::commit();

        $this->assertSame(1, $fired, '커밋 뒤에도 after_update 가 나가지 않았습니다');
    }

    /**
     * 교환 신청서를 만듭니다 (주문 통화 스냅샷 포함).
     *
     * @param  ExchangeStatusEnum  $status  현재 상태
     * @return OrderExchange 교환 신청서
     */
    private function exchangeIn(ExchangeStatusEnum $status): OrderExchange
    {
        $order = $this->deliveredClaimOrder();

        return OrderExchangeFactory::new()->create([
            'order_id' => $order->id,
            'exchange_status' => $status,
        ]);
    }

    /**
     * 알림 본문의 상태 라벨·미수납액은 발행 시점 스냅샷을 쓴다.
     *
     * 수정 전 실패 이유: 데이터 필터가 신청서를 다시 읽어 라벨·미수납액을 만들어, 큐가 밀린 사이
     * 신청서가 더 옮겨 가면 「승인」 알림에 「회수 완료」 라벨과 지금 미수납액이 실렸다.
     *
     * @scenario defect=publish_time_value
     *
     * @effects claim_notification_body_uses_the_publish_time_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_claim_notification_body_uses_the_publish_time_snapshot(): void
    {
        $exchange = $this->exchangeIn(ExchangeStatusEnum::COLLECTED);

        $result = app(ClaimNotificationDataListener::class)->extractData([], 'exchange_approved', [
            $exchange,
            ['status' => ExchangeStatusEnum::APPROVED->value, 'outstanding_amount' => 12345.0],
        ]);

        $this->assertSame(ExchangeStatusEnum::APPROVED->label(), $result['data']['status_label']);
        $this->assertStringContainsString('12,345', $result['data']['outstanding_amount']);
    }

    /**
     * 알림 매핑 리스너는 동기로 구독하고, 알림 훅에 발행 시점 스냅샷을 실어 넘긴다.
     *
     * 수정 전 실패 이유: 매핑 리스너가 큐로 구독되어 부분 처리 건수를 워커 시점에 셌고, 알림 훅에
     * 신청서만 넘겨 본문 값이 발송 시점에 다시 계산됐다.
     *
     * @scenario defect=publish_time_value
     *
     * @effects claim_status_notification_listener_is_sync_and_passes_a_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_claim_status_notification_listener_is_sync_and_passes_a_snapshot(): void
    {
        foreach (ClaimStatusNotificationListener::getSubscribedHooks() as $hook => $config) {
            $this->assertTrue((bool) ($config['sync'] ?? false), "{$hook} 구독이 동기가 아닙니다");
        }

        $exchange = $this->exchangeIn(ExchangeStatusEnum::COLLECTED);
        $captured = null;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.notify_approved', function (...$args) use (&$captured) {
            $captured = $args;
        }, 1);

        app(ClaimStatusNotificationListener::class)->handleStatusChange(
            $exchange,
            ExchangeStatusEnum::REQUESTED->value,
            ExchangeStatusEnum::APPROVED->value,
        );

        $this->assertIsArray($captured);
        $this->assertIsArray($captured[1] ?? null, '알림 훅에 스냅샷이 실리지 않았습니다');
        $this->assertSame(ExchangeStatusEnum::APPROVED->value, $captured[1]['status']);
        $this->assertArrayHasKey('outstanding_amount', $captured[1]);
    }

    /**
     * 모델에서 값을 읽는 활동 로그 구독은 동기다.
     *
     * 수정 전 실패 이유: 접수·상품 단위 전이·추가결제 기록이 큐로 구독되어, 워커가 신청서를 다시
     * 읽는 시점의 상태(자동 전이 뒤의 「완료」 등)가 접수 줄에 찍혔다.
     *
     * @scenario defect=publish_time_value
     *
     * @effects claim_activity_log_reads_model_values_at_publish_time
     *
     * @return void 반환값 없음
     */
    public function test_claim_activity_log_reads_model_values_at_publish_time(): void
    {
        $hooks = ClaimActivityLogListener::getSubscribedHooks();

        foreach (['exchange', 'return', 'refund', 'cancel'] as $type) {
            $this->assertTrue((bool) ($hooks["sirsoft-ecommerce.claim.{$type}.requested"]['sync'] ?? false), "{$type} 접수 구독이 동기가 아닙니다");
            $this->assertTrue((bool) ($hooks["sirsoft-ecommerce.claim.{$type}.option_status_changed"]['sync'] ?? false), "{$type} 상품 전이 구독이 동기가 아닙니다");
        }

        foreach (['exchange', 'return'] as $type) {
            $this->assertTrue((bool) ($hooks["sirsoft-ecommerce.claim.{$type}.payment_completed"]['sync'] ?? false));
            $this->assertTrue((bool) ($hooks["sirsoft-ecommerce.claim.{$type}.payment_refunded"]['sync'] ?? false));
        }
    }

    /**
     * 분할 전 수량은 발행 측이 실어 준 잔여 수량을 쓴다.
     *
     * 수정 전 실패 이유: 리스너가 잔여 행을 다시 읽어 분할 전 수량을 만들어, 잔여 행을 읽지 못하거나
     * 그 사이 또 갈리면 「3 → 2」 가 「2 → 2」 로 기록됐다.
     *
     * @scenario defect=publish_time_value
     *
     * @effects split_quantity_log_prefers_the_published_remainder_quantity
     *
     * @return void 반환값 없음
     */
    public function test_split_quantity_log_prefers_the_published_remainder_quantity(): void
    {
        $exchange = $this->exchangeIn(ExchangeStatusEnum::REQUESTED);
        $optionId = 1_900_000_001;

        app(ClaimActivityLogListener::class)->handleOptionStatusChanged($exchange, [[
            'option_id' => $optionId,
            'previous' => ExchangeStatusEnum::REQUESTED->value,
            'new' => ExchangeStatusEnum::APPROVED->value,
            'quantity' => 2,
            'remainder_option_id' => 1_900_000_002,
            'remainder_quantity' => 1,
        ]], ExchangeStatusEnum::APPROVED->value);

        $log = ActivityLog::where('action', 'claim_exchange.option_status_change')->latest('id')->firstOrFail();
        $rows = $log->changes[$optionId] ?? [];
        $quantity = collect($rows)->firstWhere('field', 'quantity');

        $this->assertNotNull($quantity, '수량 분할 줄이 없습니다');
        $this->assertSame('3', $quantity['old']);
        $this->assertSame('2', $quantity['new']);
    }

    /**
     * 첨부 업로드에 before_upload 훅이 있고, 거기서 거절하면 파일도 행도 남지 않는다.
     *
     * 수정 전 실패 이유: 클레임 첨부만 업로드 트라이어드의 before_upload 가 없어, 확장이 저장 전에
     * 거절할 자리가 없었다(업로드가 그대로 저장됐다).
     *
     * @scenario defect=before_upload
     *
     * @effects claim_image_before_upload_can_reject_the_upload
     *
     * @return void 반환값 없음
     */
    public function test_claim_image_before_upload_can_reject_the_upload(): void
    {
        $storage = Mockery::mock(StorageInterface::class);
        $storage->shouldNotReceive('put');
        $storage->allows('getDisk')->andReturn('local');
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);

        $order = Order::factory()->create();
        $args = null;
        HookManager::addAction('sirsoft-ecommerce.claim-image.before_upload', function (...$hookArgs) use (&$args) {
            $args = $hookArgs;
            throw new \RuntimeException('확장이 거절한 업로드');
        }, 1);

        try {
            app(ClaimAttachmentService::class)->uploadPending(UploadedFile::fake()->image('evidence.jpg', 64, 64), $order, null);
            $this->fail('before_upload 에서 던진 예외가 전파되어야 합니다');
        } catch (\RuntimeException $e) {
            $this->assertSame('확장이 거절한 업로드', $e->getMessage());
        }

        $this->assertSame(ClaimImage::PENDING_CLAIMABLE_TYPE, $args[1]);
        $this->assertSame($order->id, $args[2]);
        $this->assertSame('customer', $args[3]);
        $this->assertSame(0, ClaimImage::query()->where('claimable_type', ClaimImage::PENDING_CLAIMABLE_TYPE)->where('claimable_id', $order->id)->count());
    }

    /**
     * 범용 morph 별칭이 다른 모델과 부딪히면 오류 로그로 드러난다.
     *
     * 수정 전 실패 이유: `refund` 같은 별칭을 조용히 덮어써, 다른 확장의 다형 관계가 오류 없이
     * 엉뚱한 모델을 가리켜도 아무 흔적이 없었다.
     *
     * @scenario defect=morph_alias
     *
     * @effects claim_morph_alias_collision_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_claim_morph_alias_collision_is_logged(): void
    {
        $saved = Relation::morphMap();
        Log::spy();

        try {
            Relation::morphMap(['refund' => ClaimReason::class]);

            $provider = new EcommerceServiceProvider($this->app);
            $method = new \ReflectionMethod($provider, 'registerClaimMorphMap');
            $method->invoke($provider);

            Log::shouldHaveReceived('error')->withArgs(
                fn ($message, $context = []) => ($context['alias'] ?? null) === 'refund'
                    && ($context['registered'] ?? null) === ClaimReason::class
            )->once();
            // Mockery 스파이 검증은 PHPUnit 단언 수에 잡히지 않는다 — 검증이 통과했음을 센다.
            $this->addToAssertionCount(1);
        } finally {
            Relation::morphMap($saved, false);
        }
    }

    /**
     * 잠금 조회의 옛 이름이 호환 별칭으로 남아 있고, 실패 시 예외를 던지는 버전이 있다.
     *
     * 수정 전 실패 이유: 1.3.0 이 `findByIdForUpdate()` 를 `findForUpdate()` 로 바꾸며 옛 이름을
     * 지워 외부 확장이 치명 오류를 냈고, 잠금 실패 시 잠기지 않은 모델로 떨어지는 `?? $order` 대신
     * 쓸 예외 버전이 없었다.
     *
     * @scenario defect=lock_contract
     *
     * @effects order_lock_lookup_keeps_the_legacy_alias_and_offers_a_failing_variant
     *
     * @return void 반환값 없음
     */
    public function test_order_lock_lookup_keeps_the_legacy_alias_and_offers_a_failing_variant(): void
    {
        $order = Order::factory()->create();
        $repository = app(OrderRepositoryInterface::class);

        $this->assertSame($order->id, $repository->findByIdForUpdate($order->id)?->id);
        $this->assertSame($order->id, $repository->findForUpdateOrFail($order->id)->id);

        $this->expectException(ModelNotFoundException::class);
        $repository->findForUpdateOrFail(2_000_000_000);
    }

    /**
     * 일괄 상태 동기화 로그가 주문을 한 번에 읽는다.
     *
     * 수정 전 실패 이유: 주문마다 `find()` 를 불러 일괄 상태변경 주문 수만큼 조회가 늘었다(N+1).
     *
     * @scenario defect=activity_log_n_plus_one
     *
     * @effects order_option_sync_log_reads_orders_in_one_query
     *
     * @return void 반환값 없음
     */
    public function test_order_option_sync_log_reads_orders_in_one_query(): void
    {
        $changes = [];
        foreach (range(1, 3) as $i) {
            $order = Order::factory()->create();
            $changes[] = ['order_option_id' => 1_800_000_000 + $i, 'order_id' => $order->id, 'previous' => 'payment_complete', 'new' => 'preparing'];
        }

        $queries = $this->captureQueries(
            fn () => app(OrderActivityLogListener::class)->handleOrderOptionAfterSyncStatus($changes, 'preparing', 'bulk_update')
        );

        $orderLookups = array_filter($queries, fn (string $sql) => preg_match('/^select .* from [`"]?\w*ecommerce_orders[`"]? where/i', $sql) === 1);

        $this->assertLessThanOrEqual(1, count($orderLookups), '주문마다 조회합니다(N+1)');
    }

    /**
     * 호출처 없는 삭제 메서드를 계약에서 걷어 냈다.
     *
     * 수정 전 실패 이유: 어떤 경로도 부르지 않는 `deleteByClaimable` · `deleteByExchangeId` ·
     * `deleteByReturnId` 가 인터페이스에 남아, 구현 확장이 쓰지 않는 메서드를 강제로 구현해야 했다.
     *
     * @scenario defect=unused_contract
     *
     * @effects unused_claim_delete_methods_are_removed
     *
     * @return void 반환값 없음
     */
    public function test_unused_claim_delete_methods_are_removed(): void
    {
        $this->assertFalse(method_exists(ClaimImageRepositoryInterface::class, 'deleteByClaimable'));
        $this->assertFalse(method_exists(OrderExchangeOptionRepositoryInterface::class, 'deleteByExchangeId'));
        $this->assertFalse(method_exists(OrderReturnOptionRepositoryInterface::class, 'deleteByReturnId'));
    }

    /**
     * 훅 문서가 실제 계약과 맞는다 — personalize 인자·클레임 첨부 업로드 훅·알림 스냅샷.
     *
     * 수정 전 실패 이유: 코어 훅 문서가 personalize 세 번째 인자를 정의 모델로 적었고(실제는 유형
     * 문자열), 클레임 첨부 업로드 훅과 알림 훅 스냅샷 인자가 어디에도 없었다.
     *
     * @scenario defect=hook_docs
     *
     * @effects hook_docs_match_the_published_contracts
     *
     * @return void 반환값 없음
     */
    public function test_hook_docs_match_the_published_contracts(): void
    {
        $hooks = (string) file_get_contents(base_path('docs/extension/hooks.md'));
        $this->assertStringContainsString('$definition->type, $context, $args)', $hooks);
        $this->assertStringContainsString('sirsoft-ecommerce.claim-image.before_upload', $hooks);

        $points = (string) file_get_contents($this->getModuleBasePath().'/docs/extension-points.md');
        $this->assertStringContainsString('`claim-image.before_upload`', $points);
        $this->assertStringContainsString('`($claim, array $snapshot)`', $points);
        $this->assertStringContainsString('`order_option.after_sync_status`', $points);
    }

    /**
     * 설정 탭이 보내는 모양대로 현재 배송유형 전부를 페이로드로 만듭니다.
     *
     * 동기화는 페이로드에 없는 행을 삭제로 읽으므로, 테스트는 언제나 전건을 보낸다.
     *
     * @param  array<int, array<string, mixed>>  $overrides  ID 별로 바꿀 값
     * @return array<int, array<string, mixed>> 페이로드
     */
    private function shippingTypePayload(array $overrides = []): array
    {
        return ShippingType::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ShippingType $type) => array_merge([
                'id' => $type->id,
                'code' => $type->code,
                // 화면은 로케일 순서를 보장하지 않는다 — 순서만 다른 이름은 같은 값이다.
                'name' => array_reverse((array) $type->name, true),
                'category' => $type->category,
                'is_active' => (bool) $type->is_active,
            ], $overrides[$type->id] ?? []))
            ->values()
            ->all();
    }

    /**
     * 동기화 대상 배송유형 한 건을 목록 맨 앞에 만들고, 나머지 행의 정렬을 페이로드 순서와 맞춥니다.
     *
     * @return ShippingType 배송유형
     */
    private function syncableShippingType(): ShippingType
    {
        $type = ShippingType::create([
            'code' => 'validate_p5_type',
            'name' => ['ko' => '검증 배송', 'en' => 'Validate shipping'],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => -1,
        ]);

        // 동기화는 페이로드 순서로 sort_order 를 다시 매긴다 — 정렬만 어긋난 행이 「바뀐 행」으로
        // 잡히지 않게 현재 순서를 먼저 그대로 저장해 둔다.
        foreach (ShippingType::query()->orderBy('sort_order')->orderBy('id')->get()->values() as $index => $row) {
            DB::table('ecommerce_shipping_types')->where('id', $row->id)->update(['sort_order' => $index, 'updated_by' => null]);
        }

        return $type->fresh();
    }

    /**
     * 배송유형 설정 저장이 아무것도 바뀌지 않은 행은 쓰지 않는다.
     *
     * 수정 전 실패 이유: `syncShippingTypes()` 가 페이로드의 모든 기존 행을 `updated_by` 와 함께
     * update 해, 다른 운영자가 아무것도 고치지 않고 저장해도 전 행의 수정자가 그 사람으로 덮였다.
     *
     * @scenario defect=reason_sync
     *
     * @effects unchanged_shipping_type_sync_writes_no_rows
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_shipping_type_sync_writes_no_rows(): void
    {
        $type = $this->syncableShippingType();
        $this->actingAs($this->createUser());

        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.shipping_type.after_update', function () use (&$fired) {
            $fired++;
        }, 1);

        app(ShippingTypeService::class)->syncShippingTypes($this->shippingTypePayload());

        $this->assertNull($type->fresh()->updated_by, '바뀐 것이 없는 행의 수정자가 덮였습니다');
        $this->assertSame(0, $fired, '바뀐 것이 없는 저장이 수정 훅을 쐈습니다');
    }

    /**
     * 배송유형 설정 저장도 상세 수정 경로와 같이 before_update → filter_update_data → after_update(커밋 뒤)를 거친다.
     *
     * 수정 전 실패 이유: 설정 저장 경로는 훅 없이 리포지토리를 바로 불러, 확장이 상세 수정 경로에서만
     * 배송유형 변경에 개입할 수 있었다(필터가 바꾼 값이 저장되지 않고 after 훅도 나가지 않았다).
     *
     * @scenario defect=reason_sync
     *
     * @effects shipping_type_sync_passes_before_filter_and_after_commit_hooks
     *
     * @return void 반환값 없음
     */
    public function test_shipping_type_sync_passes_before_filter_and_after_commit_hooks(): void
    {
        $type = $this->syncableShippingType();

        $before = 0;
        $after = 0;
        HookManager::addAction('sirsoft-ecommerce.shipping_type.before_update', function () use (&$before) {
            $before++;
        }, 1);
        HookManager::addFilter('sirsoft-ecommerce.shipping_type.filter_update_data', function (array $data) {
            $data['name'] = ['ko' => '필터가 정한 배송', 'en' => 'Filtered shipping'];

            return $data;
        }, 1);
        HookManager::addAction('sirsoft-ecommerce.shipping_type.after_update', function () use (&$after) {
            $after++;
        }, 1);

        DB::beginTransaction();
        app(ShippingTypeService::class)->syncShippingTypes($this->shippingTypePayload([
            $type->id => ['is_active' => false],
        ]));
        $this->assertSame(0, $after, '커밋 전에 after_update 가 나갔습니다');
        DB::commit();

        $this->assertSame(1, $before);
        $this->assertSame(1, $after, '바뀐 행 하나에 after_update 한 번이어야 합니다');
        $this->assertSame('필터가 정한 배송', $type->fresh()->name['ko']);
        $this->assertFalse((bool) $type->fresh()->is_active);
    }
}
