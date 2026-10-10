<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Privacy;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Privacy\PrivacyRetentionManager;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\PrivacyRetentionListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Privacy\OrderRetentionHandler;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 종결 주문 개인정보 보유기간 핸들러 테스트 (이슈 #636 S5)
 *
 * 「종결」 정의(구매확정·취소 + 진행 중 환불 없음, 종결 시각 = 상태 도달 시각과 환불 완료 시각 중
 * 늦은 것), 두 방식(익명화·삭제)의 결과, 미리보기 = 처리 건수, 재실행 멱등, 미종결 주문 제외를
 * 고정합니다. 이 클래스는 RefreshDatabase 라 다른 테스트의 주문이 섞이지 않는다.
 */
class OrderRetentionHandlerTest extends ModuleTestCase
{
    /**
     * 모듈 리스너를 실제 등록 경로로 붙입니다 — 이 테스트 베이스는 리스너를 자동 등록하지 않는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookListenerRegistrar::register(PrivacyRetentionListener::class, 'sirsoft-ecommerce');
    }

    /**
     * 이커머스 모듈 설정 판독을 지정 값으로 고정합니다.
     *
     * @param  array<string, mixed>  $values  설정 키 => 값
     * @return void 반환값 없음
     */
    private function fakeRetentionSettings(array $values): void
    {
        $service = Mockery::mock(ModuleSettingsService::class);

        $service->shouldReceive('get')
            ->andReturnUsing(function (string $identifier, ?string $key = null, mixed $default = null) use ($values) {
                if ($identifier !== 'sirsoft-ecommerce') {
                    return $default;
                }

                return array_key_exists($key, $values) ? $values[$key] : $default;
            });

        $this->app->instance(ModuleSettingsService::class, $service);
    }

    /**
     * 종결 주문 하나를 주소·결제와 함께 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  주문 속성 덮어쓰기
     * @return Order 주문
     */
    private function closedOrder(array $attributes = []): Order
    {
        $order = Order::factory()->confirmed()->create(array_merge([
            'confirmed_at' => now()->subYears(6),
            'ip_address' => '203.0.113.50',
            'admin_memo' => '고객 요청: 010-9999-8888 로 연락',
        ], $attributes));

        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'orderer_name' => '홍길동',
            'orderer_phone' => '010-1234-5678',
            'orderer_email' => 'hong@example.com',
            'recipient_name' => '김철수',
            'recipient_phone' => '010-8765-4321',
            'zipcode' => '06236',
            'address' => '서울특별시 강남구 테헤란로 1',
            'address_detail' => '101호',
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'buyer_name' => '홍길동',
            'buyer_phone' => '010-1234-5678',
            'depositor_name' => '홍길동',
            'card_number_masked' => '1234-****-****-5678',
            'card_approval_number' => '30012345',
        ]);

        // 레거시 종결 판정이 쓰는 수정 시각도 과거로 둔다(팩토리 생성 시각이 아니라)
        DB::table('ecommerce_orders')->where('id', $order->id)->update(['updated_at' => now()->subYears(6)]);

        return $order->fresh();
    }

    /**
     * 환불 레코드를 직접 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  string  $status  환불 상태
     * @param  \DateTimeInterface|null  $refundedAt  완료 시각
     * @return void 반환값 없음
     */
    private function refund(Order $order, string $status, ?\DateTimeInterface $refundedAt = null): void
    {
        DB::table('ecommerce_order_refunds')->insert([
            'order_id' => $order->id,
            'refund_number' => uniqid('RF'),
            'refund_status' => $status,
            'refund_method' => 'bank',
            'refund_amount' => 1000,
            'refund_bank_holder' => '홍길동',
            'refund_bank_account' => '110-123-456789',
            'refunded_at' => $refundedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 기본값(꺼짐)에서는 아무것도 바꾸지 않고 「꺼짐」으로 보고하며, 핸들러는 코어 수집기에 등록돼 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_is_off_by_default, order_retention_handler_is_registered_with_core
     */
    #[Test]
    public function handler_is_registered_and_off_by_default(): void
    {
        $order = $this->closedOrder();
        $handler = app(OrderRetentionHandler::class);

        $this->assertArrayHasKey('sirsoft-ecommerce.order', app(PrivacyRetentionManager::class)->handlers());
        $this->assertFalse($handler->isEnabled());
        $this->assertSame(['count' => 0, 'mode' => 'disabled'], $handler->process(false));
        $this->assertSame('홍길동', OrderAddress::where('order_id', $order->id)->value('orderer_name'));
    }

    /**
     * 익명화: 보유기간이 지난 종결 주문의 주문자·수령인·결제·환불 개인정보를 지우고 주문 기록(주문번호·
     * 금액·상태)은 남기며, 미리보기 건수 = 처리 건수, 두 번째 실행은 0건, 수정 시각은 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects order_personal_data_is_anonymized, order_record_is_preserved_when_anonymized, order_retention_preview_equals_processed, order_retention_is_idempotent
     */
    #[Test]
    public function anonymize_masks_personal_data_and_keeps_the_order_record(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.retention_years' => 5, 'privacy_retention.mode' => 'anonymize']);

        $order = $this->closedOrder();
        $this->refund($order, 'completed', now()->subYears(6));
        $updatedAt = (string) DB::table('ecommerce_orders')->where('id', $order->id)->value('updated_at');
        $memberBefore = (array) DB::table('users')->where('id', $order->user_id)->first(['name', 'email', 'updated_at']);
        $handler = app(OrderRetentionHandler::class);

        $this->assertSame(1, $handler->preview());
        $dry = $handler->process(true);
        $this->assertSame('홍길동', OrderAddress::where('order_id', $order->id)->value('orderer_name'), 'dry-run 은 데이터를 바꾸지 않는다');

        $result = $handler->process(false);

        $this->assertSame($dry['count'], $result['count'], '미리보기 건수 = 실제 처리 건수');
        $this->assertSame('anonymize', $result['mode']);

        $address = OrderAddress::where('order_id', $order->id)->first();
        $masked = OrderPrivacyRetentionService::ANONYMIZED_VALUE;
        foreach (['orderer_name', 'orderer_phone', 'recipient_name', 'recipient_phone', 'zipcode', 'address'] as $column) {
            $this->assertSame($masked, $address->{$column}, "{$column} 은 NOT NULL 이라 치환값");
        }
        $this->assertNull($address->orderer_email);
        $this->assertNull($address->address_detail);

        $payment = OrderPayment::where('order_id', $order->id)->first();
        $this->assertNull($payment->buyer_name);
        $this->assertNull($payment->buyer_phone);
        $this->assertNull($payment->depositor_name);
        $this->assertNull($payment->card_number_masked, '카드번호 앞뒤 자리도 카드를 가리키는 파생 식별자라 지운다');
        $this->assertSame('30012345', $payment->card_approval_number, '승인번호는 결제 대행사 대사에 쓰는 거래 기록이라 남는다');

        $refund = DB::table('ecommerce_order_refunds')->where('order_id', $order->id)->first();
        $this->assertNull($refund->refund_bank_holder);
        $this->assertNull($refund->refund_bank_account);

        $row = DB::table('ecommerce_orders')->where('id', $order->id)->first();
        $this->assertNull($row->ip_address);
        $this->assertNull($row->admin_memo, '상담 메모에 섞인 연락처도 지운다');
        $this->assertSame($order->order_number, $row->order_number, '주문번호는 법정 거래 기록이라 남는다');
        $this->assertSame((string) $order->total_amount, (string) $row->total_amount);
        $this->assertSame($updatedAt, (string) $row->updated_at, '익명화는 주문 변경이 아니다 — 수정 시각 보존');
        $this->assertNotSame([], $memberBefore);
        $this->assertSame(
            $memberBefore,
            (array) DB::table('users')->where('id', $order->user_id)->first(['name', 'email', 'updated_at']),
            '주문 익명화는 회원 행을 건드리지 않는다 — 회원 정보는 탈퇴·휴면 생명주기의 몫(D22)',
        );

        $this->assertSame(0, $handler->preview(), '두 번째 실행 대상은 0건');
        $this->assertSame(0, $handler->process(false)['count']);
    }

    /**
     * 익명화는 주문 메타에 남은 수령인 사본(PG 결제가 끝나지 않아 남은 저장 대기 배송지 · 배송비 계산
     * 주소)도 지우고, 주문 한 건마다 `sirsoft-ecommerce.order.after_anonymize` 를 같은 트랜잭션 안에서
     * 발행한다 — 확장 리스너가 던지면 익명화 표지까지 롤백되어 다음 실행이 다시 잡는다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=cancelled, toggle=on, mode=anonymize
     *
     * @effects order_meta_copies_are_anonymized, order_anonymize_hook_failure_rolls_back
     */
    #[Test]
    public function anonymize_scrubs_order_meta_copies_and_rolls_back_when_a_listener_fails(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.retention_years' => 5, 'privacy_retention.mode' => 'anonymize']);

        $order = $this->closedOrder(['order_meta' => [
            'cart_items' => [['cart_id' => 1, 'quantity' => 1]],
            'save_shipping_address' => true,
            'shipping_info_for_save' => ['recipient_name' => '김철수', 'recipient_phone' => '010-8765-4321', 'address' => '서울특별시 강남구 테헤란로 1'],
            'calculation_input' => [
                'use_points' => 0,
                'shipping_address' => ['country_code' => 'KR', 'zipcode' => '06236', 'region' => '서울', 'city' => '강남구', 'address' => '테헤란로 1'],
            ],
        ]]);
        $handler = app(OrderRetentionHandler::class);

        $received = [];
        $failing = function (int $orderId) use (&$received) {
            $received[] = $orderId;

            throw new \RuntimeException('확장 사본 파기 실패');
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_anonymize', $failing);

        try {
            $this->assertSame(0, $handler->process(false)['count'], '리스너가 던진 주문은 처리 건수에 들지 않는다');
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.order.after_anonymize', $failing);
        }

        $this->assertSame([$order->id], $received, '주문 한 건마다 주문 ID 로 발행한다');
        $this->assertArrayNotHasKey(OrderPrivacyRetentionService::ANONYMIZED_MARKER, $order->fresh()->order_meta, '표지까지 롤백된다');
        $this->assertSame('홍길동', OrderAddress::where('order_id', $order->id)->value('orderer_name'), '주소 익명화도 롤백된다');
        $this->assertSame(1, $handler->preview(), '다음 실행이 다시 잡는다');

        $this->assertSame(1, $handler->process(false)['count']);

        $meta = $order->fresh()->order_meta;
        $this->assertArrayNotHasKey('shipping_info_for_save', $meta, '저장 대기 배송지 사본을 지운다');
        $this->assertArrayNotHasKey('save_shipping_address', $meta);
        $this->assertSame(
            ['country_code' => 'KR', 'zipcode' => null, 'region' => null, 'city' => null, 'address' => null],
            $meta['calculation_input']['shipping_address'],
            '배송비 계산 주소는 지우고 국가 코드만 남긴다',
        );
        $this->assertSame([['cart_id' => 1, 'quantity' => 1]], $meta['cart_items'], '개인정보가 아닌 메타는 그대로');
        $this->assertArrayHasKey(OrderPrivacyRetentionService::ANONYMIZED_MARKER, $meta);
    }

    /**
     * 종결 판정: 구매확정 전·보유기간 안·진행 중 환불·기준일 뒤 환불 완료 주문은 빠지고, 종결 시각이
     * 비어 있는 레거시 취소 주문은 수정 시각으로, 휴지통 주문도 대상에 든다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=cancelled, toggle=on, mode=anonymize
     *
     * @effects unclosed_orders_are_never_processed, refund_completion_extends_closure
     */
    #[Test]
    public function only_orders_closed_before_the_cutoff_are_targeted(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.retention_years' => 5, 'privacy_retention.mode' => 'anonymize']);

        $target = $this->closedOrder();
        $legacyCancelled = $this->closedOrder(['order_status' => OrderStatusEnum::CANCELLED, 'confirmed_at' => null, 'cancelled_at' => null]);
        $trashed = $this->closedOrder();
        Order::whereKey($trashed->id)->first()->delete();

        $delivered = $this->closedOrder(['order_status' => OrderStatusEnum::DELIVERED, 'confirmed_at' => null]);
        $recent = $this->closedOrder(['confirmed_at' => now()->subYears(2)]);
        $openRefund = $this->closedOrder(['order_status' => OrderStatusEnum::CANCELLED, 'cancelled_at' => now()->subYears(6)]);
        $this->refund($openRefund, 'approved');
        $lateRefund = $this->closedOrder(['order_status' => OrderStatusEnum::CANCELLED, 'cancelled_at' => now()->subYears(6)]);
        $this->refund($lateRefund, 'completed', now()->subYears(1));

        $ids = app(OrderRetentionHandler::class)->targetQuery()->pluck('id')->all();
        sort($ids);
        $expected = [$target->id, $legacyCancelled->id, $trashed->id];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains($delivered->id, $ids, '구매확정 전 주문은 어떤 경우에도 대상이 아니다');
        $this->assertNotContains($openRefund->id, $ids, '송금 전 환불이 있으면 종결이 아니다');
        $this->assertNotContains($lateRefund->id, $ids, '환불이 기준일 뒤에 끝났으면 종결 시각이 그만큼 늦다');
        $this->assertNotContains($recent->id, $ids);
    }

    /**
     * 대상 목록: 꺼져 있어도 건수와 같은 쿼리의 명단이 나오고(주문번호·주문자·상태·종결일·상세 링크),
     * 코어 목록 API 가 이 핸들러를 식별자로 찾아 「설정 읽기 + 주문 읽기」로 검사한다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_targets_match_the_population
     */
    #[Test]
    public function target_list_matches_the_population_and_is_served_by_the_core_api(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => false, 'privacy_retention.retention_years' => 5, 'privacy_retention.mode' => 'anonymize']);

        $older = $this->closedOrder(['confirmed_at' => now()->subYears(7)]);
        $newer = $this->closedOrder();
        $this->closedOrder(['confirmed_at' => now()->subYears(2)]); // 보유기간 이내
        $trashed = $this->closedOrder();
        $trashed->delete(); // 휴지통 주문도 파기 대상이지만 관리자 상세가 열리지 않는다

        $handler = app(OrderRetentionHandler::class);
        $this->assertFalse($handler->isEnabled());

        $page = $handler->targets(1, 50);
        $this->assertSame($handler->preview(), $page->total(), '명단 수 = 건수');
        $this->assertSame(
            collect($handler->targetQuery()->pluck('id'))->sort()->values()->all(),
            collect($page->items())->pluck('id')->sort()->values()->all(),
            '명단 = 배치 쿼리',
        );

        $first = $page->items()[0];
        $this->assertSame($older->id, $first->id, '가장 먼저 종결된 주문부터');
        $this->assertSame($older->order_number, $first->title);
        $this->assertSame('홍길동', $first->subtitle);
        $this->assertSame('/admin/ecommerce/orders/'.$older->order_number, $first->url);
        $this->assertNotNull($first->date);
        $this->assertContains($newer->id, collect($page->items())->pluck('id')->all());

        $trashedRow = collect($page->items())->firstWhere('id', $trashed->id);
        $this->assertNotNull($trashedRow, '휴지통 주문도 명단에 든다(배치 모집단과 같음)');
        $this->assertNull($trashedRow->url, '휴지통 주문은 상세가 404 라 링크를 두지 않는다');

        $viewer = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.orders.read']);
        $response = $this->actingAs($viewer)
            ->getJson('/api/admin/privacy/retention-targets/sirsoft-ecommerce.order?per_page=1')
            ->assertOk();
        $this->assertSame($older->order_number, $response->json('data.data.0.title'));
        $this->assertTrue($response->json('data.pagination.has_more_pages'));
        $this->assertArrayHasKey('context', $response->json('data.columns'));

        $settingsOnly = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);
        $this->actingAs($settingsOnly)
            ->getJson('/api/admin/privacy/retention-targets/sirsoft-ecommerce.order')
            ->assertForbidden();
    }

    /**
     * 삭제: 주문과 참조 행을 명시 삭제하고 그 주문의 상품 리뷰를 이미지 파일째 지우며, 마일리지 거래는
     * 행을 남기고 주문 연결만 끊는다. 다른 주문은 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects order_is_purged_with_references, order_reviews_are_purged_with_files, order_retention_preview_equals_processed, order_retention_is_idempotent
     */
    #[Test]
    public function delete_purges_the_order_and_every_reference(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.retention_years' => 5, 'privacy_retention.mode' => 'delete']);

        $order = $this->closedOrder();
        $survivor = $this->closedOrder(['confirmed_at' => now()->subYears(1)]);
        $option = OrderOption::factory()->forOrder($order)->create();
        $review = ProductReview::factory()->create(['order_option_id' => $option->id, 'product_id' => $option->product_id]);

        $storage = (fn () => $this->storage)->call(app(ProductReviewService::class));
        $path = 'reviews/'.$review->id.'/'.uniqid('retention-').'.jpg';
        $storage->put('images', $path, 'bytes');
        $image = ProductReviewImage::factory()->create(['review_id' => $review->id, 'disk' => $storage->getDisk(), 'path' => $path]);

        $user = User::factory()->create();
        $mileageId = DB::table('ecommerce_mileage_transactions')->insertGetId([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_after' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->refund($order, 'completed', now()->subYears(6));

        $handler = app(OrderRetentionHandler::class);
        $dry = $handler->process(true);
        $result = $handler->process(false);

        $this->assertSame(1, $dry['count']);
        $this->assertSame($dry['count'], $result['count'], '미리보기 건수 = 실제 처리 건수');
        $this->assertSame('delete', $result['mode']);

        $this->assertNull(Order::withTrashed()->find($order->id), '주문이 휴지통이 아니라 영구 삭제된다');
        foreach (['ecommerce_order_addresses', 'ecommerce_order_payments', 'ecommerce_order_options', 'ecommerce_order_refunds'] as $table) {
            $this->assertSame(0, DB::table($table)->where('order_id', $order->id)->count(), "{$table} 행이 명시 삭제된다");
        }
        $this->assertNull(ProductReview::withTrashed()->find($review->id), '주문 옵션에 묶인 리뷰가 사라진다');
        $this->assertNull(ProductReviewImage::withTrashed()->find($image->id));
        $this->assertFalse($storage->exists('images', $path), '리뷰 이미지 파일도 지운다');

        $mileage = DB::table('ecommerce_mileage_transactions')->find($mileageId);
        $this->assertNotNull($mileage, '마일리지 거래는 회원 잔액의 원장이라 남는다');
        $this->assertNull($mileage->order_id, '주문 연결만 끊긴다');

        $this->assertNotNull(Order::find($survivor->id), '보유기간 안의 주문은 그대로다');
        $this->assertSame(0, $handler->process(false)['count'], '두 번째 실행은 0건');
    }

    /**
     * 삭제는 주문마다 관리자 삭제와 같은 `sirsoft-ecommerce.order.after_delete` 를 발행한다 — 주문 활동
     * 로그가 「주문 삭제」를 남기고 확장이 자기 사본을 정리할 자리다. 커밋 뒤에 발행하므로 구독자가 받는
     * 시점에 주문은 이미 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects order_purge_emits_after_delete
     */
    #[Test]
    public function delete_emits_after_delete_for_each_purged_order_after_commit(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.retention_years' => 5, 'privacy_retention.mode' => 'delete']);

        $first = $this->closedOrder();
        $second = $this->closedOrder();
        $this->closedOrder(['confirmed_at' => now()->subYears(1)]);

        $received = [];
        $recorder = function (Order $order) use (&$received) {
            $received[$order->id] = Order::withTrashed()->whereKey($order->id)->exists();
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_delete', $recorder);

        try {
            $this->assertSame(2, app(OrderRetentionHandler::class)->process(false)['count']);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.order.after_delete', $recorder);
        }

        ksort($received);
        $this->assertSame([$first->id, $second->id], array_keys($received), '지운 주문마다 한 번씩, 보유기간 안의 주문은 발행하지 않는다');
        $this->assertSame([false, false], array_values($received), '구독자가 받는 시점에 주문은 이미 지워져 있다');
    }

    /**
     * 익명화했던 주문을 삭제 모드로 바꾸면 다시 대상이 된다 — 익명화 표지는 익명화 모드에서만 제외 조건이다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects order_is_purged_with_references
     */
    #[Test]
    public function switching_from_anonymize_to_delete_retargets_anonymized_orders(): void
    {
        $order = $this->closedOrder();

        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.mode' => 'anonymize']);
        app(OrderRetentionHandler::class)->process(false);
        $this->assertNotNull(Order::find($order->id));

        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.mode' => 'delete']);
        $this->assertSame(1, app(OrderRetentionHandler::class)->preview());
        app(OrderRetentionHandler::class)->process(false);

        $this->assertNull(Order::withTrashed()->find($order->id));
    }

    /**
     * 판독부 바닥값: 저장 검증을 거치지 않은 보유기간(5년 미만)이 법정 보존기간보다 먼저 파기하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects unclosed_orders_are_never_processed
     */
    #[Test]
    public function retention_years_below_the_legal_minimum_are_clamped(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.retention_years' => 1]);

        $order = $this->closedOrder(['confirmed_at' => now()->subYears(3)]);
        DB::table('ecommerce_orders')->where('id', $order->id)->update(['updated_at' => now()->subYears(3)]);
        $handler = app(OrderRetentionHandler::class);

        $this->assertSame(5, $handler->retentionYears());
        $this->assertSame(0, $handler->preview(), '3년 전 종결 주문은 5년 바닥값 안이라 대상이 아니다');
    }

    /**
     * 연간 통지문의 주문 정보 섹션: 법정 보존 사실을 수신자 로케일로 적고, 처리를 켰다면 방식과 기간을 덧붙인다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects order_notice_section_is_contributed
     */
    #[Test]
    public function notice_section_describes_order_retention_in_the_recipient_locale(): void
    {
        $sections = HookManager::applyFilters('core.privacy.notice_sections', [], 'ko');
        $section = collect($sections)->firstWhere('title', '주문 정보');

        $this->assertNotNull($section, '이커머스가 주문 정보 섹션을 기여한다');
        $this->assertStringContainsString('5년', $section['body']);
        $this->assertStringNotContainsString('익명화', $section['body'], '처리가 꺼져 있으면 법정 보존 사실만 적는다');

        $this->fakeRetentionSettings(['privacy_retention.enabled' => true, 'privacy_retention.retention_years' => 7, 'privacy_retention.mode' => 'anonymize']);
        $english = collect(HookManager::applyFilters('core.privacy.notice_sections', [], 'en'))->firstWhere('title', 'Order information');

        $this->assertNotNull($english);
        $this->assertStringContainsString('anonymized 7 years', $english['body']);
    }

    /**
     * 관리자 직권 실행(`force`)은 꺼져 있어도 같은 모집단을 처리하고, 자동 배치(`force` 없음)는 여전히 꺼짐으로 멈춘다.
     * 직권 실행 권한은 설정 수정 + 주문 수정이며, 삭제 방식이면 주문 삭제 권한을 더한다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_manual_run_processes_while_off, order_delete_mode_manual_run_requires_order_delete_permission
     */
    #[Test]
    public function manual_run_processes_while_off_and_delete_mode_requires_order_delete(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => false, 'privacy_retention.mode' => 'anonymize']);
        $order = $this->closedOrder();
        $handler = app(OrderRetentionHandler::class);

        $this->assertSame(['count' => 0, 'mode' => 'disabled'], $handler->process(false), '자동 배치는 토글을 본다');
        $this->assertSame('홍길동', OrderAddress::where('order_id', $order->id)->value('orderer_name'));

        $this->assertSame(['count' => 1, 'mode' => 'anonymize'], $handler->process(true, true), '직권 dry-run 은 꺼져 있어도 센다');
        $this->assertSame(['count' => 1, 'mode' => 'anonymize'], $handler->process(false, true));
        $this->assertNotSame('홍길동', OrderAddress::where('order_id', $order->id)->value('orderer_name'), '직권 실행은 꺼져 있어도 익명화한다');

        $this->assertSame(['sirsoft-ecommerce.settings.update', 'sirsoft-ecommerce.orders.update'], $handler->runPermissions());

        $this->fakeRetentionSettings(['privacy_retention.enabled' => false, 'privacy_retention.mode' => 'delete']);
        $this->assertContains('sirsoft-ecommerce.orders.delete', app(OrderRetentionHandler::class)->runPermissions(), '주문을 지우는 방식은 주문 삭제 권한까지 요구한다');
    }

    /**
     * 삭제 방식의 직권 실행이 **실제로 실행 가능**하다 — 요구하는 주문 삭제 권한이 모듈에 정의돼 있고
     * 기본 관리자 역할(admin)에 부여돼 있어, admin 역할 관리자는 실행 버튼이 열린다(7.1.0 R4).
     *
     * 예전 단언은 요구 목록에 권한 이름이 들어 있는지만 봤다. 테스트 헬퍼가 없는 권한을 만들어 붙여 주므로
     * 모듈에 정의가 없어도 통과했고, 실제 사이트에서는 아무도 실행할 수 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=off, mode=delete
     *
     * @effects order_delete_mode_manual_run_is_runnable_by_the_admin_role
     */
    #[Test]
    public function delete_mode_manual_run_is_runnable_by_the_default_admin_role(): void
    {
        $this->fakeRetentionSettings(['privacy_retention.enabled' => false, 'privacy_retention.mode' => 'delete']);
        $handler = app(OrderRetentionHandler::class);

        $this->assertContains('sirsoft-ecommerce.orders.delete', $handler->runPermissions());

        // 설치·업데이트가 하는 선언형 동기화(권한 생성 + 역할 할당)를 그대로 돌린다 — 테스트 시더는 모듈 권한을 만들지 않는다.
        $manager = app(ModuleManager::class);
        $module = $manager->getModule('sirsoft-ecommerce');
        foreach (['createModulePermissions', 'assignPermissionsToRoles'] as $step) {
            $method = new \ReflectionMethod($manager, $step);
            $method->setAccessible(true);
            $method->invoke($manager, $module);
        }

        $permission = Permission::where('identifier', 'sirsoft-ecommerce.orders.delete')->first();
        $this->assertNotNull($permission, '주문 삭제 권한이 모듈 권한 정의에 없다 — 삭제 방식 직권 실행은 아무도 할 수 없다');

        $adminRole = Role::where('identifier', 'admin')->first();
        $this->assertTrue($adminRole->permissions()->whereKey($permission->id)->exists(), '주문 삭제 권한은 admin 역할에 기본 부여된다');

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole->id);

        $this->assertTrue(app(PrivacyRetentionManager::class)->canRun($handler, $admin->fresh()), 'admin 역할 관리자는 삭제 방식 직권 실행을 할 수 있다');

        $updaterOnly = $this->createAdminUser(['sirsoft-ecommerce.settings.update', 'sirsoft-ecommerce.orders.update']);
        $this->assertFalse(app(PrivacyRetentionManager::class)->canRun($handler, $updaterOnly), '주문 삭제 권한이 없으면 삭제 방식 직권 실행은 막힌다');
    }
}
