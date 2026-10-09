<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Privacy;

use App\Extension\HookListenerRegistrar;
use App\Models\User;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\PrivacyRetentionListener;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Privacy\OrderRetentionHandler;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 종결 주문 개인정보 보유기간 처리 × 교환·반품 기록 (#599 — develop 7.1.0 16차 병합 후속)
 *
 * 보유기간 처리(#636)는 주문·결제·환불만 알고 이 브랜치의 교환·반품 기록을 몰랐다 — 익명화 뒤에도 교환·
 * 반품 신청서의 환불 계좌와 교환 재배송지가 그대로 보였고, 삭제 뒤에는 첨부 사진 행(주문 FK 가 없는 다형
 * 연결)과 파일이 남았다. 대상 선정도 진행 중인 교환·반품을 보지 않아 처리 중인 신청서의 계좌·배송지를
 * 지울 수 있었고, 철회된 환불신청서를 진행 중으로 읽어 그 주문은 영영 대상이 되지 못했다.
 *
 * 이 클래스는 RefreshDatabase 라 다른 테스트의 주문이 섞이지 않는다.
 *
 * 소유 시나리오: `order-privacy-retention`
 */
class OrderRetentionClaimRecordsTest extends ModuleTestCase
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
     * 보유기간 처리 설정을 켜고 처리 방식을 고정합니다.
     *
     * @param  string  $mode  처리 방식 (anonymize|delete)
     * @return void 반환값 없음
     */
    private function enableRetention(string $mode): void
    {
        $values = ['privacy_retention.enabled' => true, 'privacy_retention.retention_years' => 5, 'privacy_retention.mode' => $mode];
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
     * 보유기간이 지난 구매확정 주문 하나를 배송지와 함께 만듭니다.
     *
     * @param  int  $years  구매확정 뒤 지난 햇수
     * @return Order 주문
     */
    private function closedOrder(int $years = 6): Order
    {
        $order = Order::factory()->confirmed()->create(['confirmed_at' => now()->subYears($years)]);

        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'recipient_name' => '김철수',
            'recipient_phone' => '010-8765-4321',
            'zipcode' => '06236',
            'address' => '서울특별시 강남구 테헤란로 1',
            'address_detail' => '101호',
        ]);

        DB::table('ecommerce_orders')->where('id', $order->id)->update(['updated_at' => now()->subYears($years)]);

        return $order->fresh();
    }

    /**
     * 교환 신청서를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  ExchangeStatusEnum  $status  상태
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return OrderExchange 교환 신청서
     */
    private function exchange(Order $order, ExchangeStatusEnum $status, array $attributes = []): OrderExchange
    {
        return OrderExchange::factory()->create(array_merge(['order_id' => $order->id, 'exchange_status' => $status], $attributes));
    }

    /**
     * 반품 신청서를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  ReturnStatusEnum  $status  상태
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return OrderReturn 반품 신청서
     */
    private function returnClaim(Order $order, ReturnStatusEnum $status, array $attributes = []): OrderReturn
    {
        return OrderReturn::factory()->create(array_merge(['order_id' => $order->id, 'return_status' => $status], $attributes));
    }

    /**
     * 환불(신청서) 레코드를 직접 만듭니다 — 환불 모델에는 팩토리가 없다.
     *
     * @param  Order  $order  주문
     * @param  string  $status  환불 상태
     * @param  array<string, mixed>  $attributes  덮어쓸 칸
     * @return int 환불 ID
     */
    private function refund(Order $order, string $status, array $attributes = []): int
    {
        return DB::table('ecommerce_order_refunds')->insertGetId(array_merge([
            'order_id' => $order->id,
            'refund_number' => uniqid('RF'),
            'refund_status' => $status,
            'refund_method' => 'bank',
            'refund_amount' => 1000,
            'refund_bank_holder' => '홍길동',
            'refund_bank_account' => '110-123-456789',
            'created_at' => now()->subYears(6),
            'updated_at' => now()->subYears(6),
        ], $attributes));
    }

    /**
     * 클레임 첨부 한 장을 실제 파일과 함께 만듭니다.
     *
     * @param  string  $claimableType  클레임 유형 값 또는 `pending`
     * @param  int  $claimableId  신청서 ID (미귀속이면 주문 ID)
     * @return ClaimImage 첨부
     */
    private function claimImage(string $claimableType, int $claimableId): ClaimImage
    {
        $storage = (fn () => $this->storage)->call(app(ClaimAttachmentService::class));
        $path = 'claims/'.$claimableType.'/'.$claimableId.'/'.uniqid('retention-').'.jpg';
        $storage->put('images', $path, 'bytes');

        return ClaimImage::factory()->create([
            'claimable_type' => $claimableType,
            'claimable_id' => $claimableId,
            'disk' => $storage->getDisk(),
            'path' => $path,
            'original_name' => '운송장_김철수.jpg',
        ]);
    }

    /**
     * 첨부 파일이 저장소에 있는지 봅니다.
     *
     * @param  ClaimImage  $image  첨부
     * @return bool 있으면 true
     */
    private function storedFileExists(ClaimImage $image): bool
    {
        return (fn () => $this->storage)->call(app(ClaimAttachmentService::class))->exists('images', $image->path);
    }

    /**
     * 익명화는 교환·반품 신청서의 환불 계좌 명의·번호와 상세 사유·관리자 메모를 비우고, 교환 재배송지를
     * 주문 배송지와 같은 기준으로 가린다. 신청서·은행 코드·사유 코드·첨부 사진은 청약철회 기록이라 남는다.
     *
     * 수정 전 실패 이유: 익명화가 주문·결제·환불 칸만 알아 교환·반품의 계좌·재배송지·메모가 그대로 남았다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects claim_personal_data_is_anonymized_with_the_order
     */
    #[Test]
    public function anonymize_masks_claim_bank_accounts_redelivery_address_and_free_text(): void
    {
        $this->enableRetention('anonymize');

        $order = $this->closedOrder();
        $bank = ['refund_bank_code' => '004', 'refund_bank_holder' => '홍길동', 'refund_bank_account' => '110-123-456789'];
        $notes = ['reason_detail' => '010-1111-2222 로 연락 주세요', 'admin_memo' => '고객 통화 — 새 주소 서울 서초구'];
        $exchange = $this->exchange($order, ExchangeStatusEnum::COMPLETED, $bank + $notes + [
            'completed_at' => now()->subYears(6),
            'redelivery_address_snapshot' => [
                'recipient_name' => '김철수', 'phone' => '010-8765-4321', 'zipcode' => '06236',
                'address1' => '서울특별시 강남구 테헤란로 1', 'address2' => '101호', 'country_code' => 'KR', 'changed_by_user' => true,
            ],
        ]);
        // 해외 재발송지의 도시·주는 주문 배송지의 intl_city·intl_state 처럼 지운다(S2-D8).
        $international = $this->exchange($order, ExchangeStatusEnum::COMPLETED, [
            'completed_at' => now()->subYears(6),
            'redelivery_address_snapshot' => [
                'recipient_name' => 'Jane Doe', 'phone' => '+1-212-555-0101', 'zipcode' => '10118',
                'address1' => '350 5th Ave', 'address2' => 'Suite 2', 'city' => 'New York', 'state' => 'NY',
                'country_code' => 'US', 'changed_by_user' => false,
            ],
        ]);
        $return = $this->returnClaim($order, ReturnStatusEnum::COMPLETED, $bank + $notes + ['completed_at' => now()->subYears(6)]);
        $refundId = $this->refund($order, 'completed', ['refunded_at' => now()->subYears(6), 'reason_detail' => '계좌 010-3333-4444 확인']);
        $image = $this->claimImage(ClaimTypeEnum::EXCHANGE->value, $exchange->id);

        $result = app(OrderRetentionHandler::class)->process(false);

        $this->assertSame(1, $result['count']);

        foreach ([OrderExchange::find($exchange->id), OrderReturn::find($return->id)] as $claim) {
            $this->assertNotNull($claim, '신청서 행은 청약철회 기록이라 남는다');
            $this->assertNull($claim->refund_bank_holder);
            $this->assertNull($claim->refund_bank_account);
            $this->assertNull($claim->reason_detail, '상세 사유는 자유 기재 칸이라 지운다');
            $this->assertNull($claim->admin_memo, '관리자 메모는 주문 관리자 메모와 같은 기준으로 지운다');
            $this->assertSame('004', $claim->refund_bank_code, '은행 코드만으로는 사람을 가리키지 않아 남긴다');
        }

        $this->assertSame([
            'recipient_name' => '***', 'phone' => '***', 'zipcode' => '***',
            'address1' => '***', 'address2' => null, 'country_code' => 'KR', 'changed_by_user' => true,
        ], OrderExchange::find($exchange->id)->redelivery_address_snapshot, '재배송지는 모양을 유지한 채 주문 배송지와 같은 기준으로 가린다');

        $masked = OrderExchange::find($international->id)->redelivery_address_snapshot;
        $this->assertNull($masked['city']);
        $this->assertNull($masked['state']);
        $this->assertSame('***', $masked['address1']);

        $refund = DB::table('ecommerce_order_refunds')->find($refundId);
        $this->assertNull($refund->refund_bank_holder);
        $this->assertNull($refund->refund_bank_account);
        $this->assertNull($refund->reason_detail, '환불신청서의 상세 사유도 같은 자유 기재 칸이다');

        $this->assertNotNull(ClaimImage::find($image->id), '익명화는 첨부 사진을 남긴다');
        $this->assertTrue($this->storedFileExists($image));
    }

    /**
     * 삭제는 교환·반품·신청 상품 줄·클레임 결제와 클레임 첨부(임시 첨부 포함) 행을 명시 삭제하고, 첨부 파일을
     * 커밋 뒤에 지운다. 외래키 제약을 꺼도 같은 결과다(CASCADE 에 기대지 않는다). 마일리지 거래는 남기고
     * 클레임 결제·환불 연결만 끊는다. 보유기간 안의 주문과 그 첨부는 그대로다.
     *
     * 수정 전 실패 이유: 첨부 행은 주문 FK 가 없어 삭제 뒤에도 남았고 파일도 저장소에 남았다. 신청서·결제 행은
     * 주문 FK CASCADE 로만 사라져, 외래키가 꺼진 환경에서는 주인 없는 행이 남았다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects claim_records_are_purged_with_images_without_cascade
     */
    #[Test]
    public function delete_purges_claim_records_and_image_files_without_cascade(): void
    {
        $this->enableRetention('delete');

        $order = $this->closedOrder();
        $option = OrderOption::factory()->forOrder($order)->create();
        $exchange = $this->exchange($order, ExchangeStatusEnum::COMPLETED, ['completed_at' => now()->subYears(6)]);
        OrderExchangeOption::factory()->create(['order_exchange_id' => $exchange->id, 'order_id' => $order->id, 'order_option_id' => $option->id]);
        $return = $this->returnClaim($order, ReturnStatusEnum::COMPLETED, ['completed_at' => now()->subYears(6)]);
        OrderReturnOption::factory()->create(['order_return_id' => $return->id, 'order_id' => $order->id, 'order_option_id' => $option->id]);
        $refundId = $this->refund($order, 'completed', ['refunded_at' => now()->subYears(6)]);
        $payment = ClaimPayment::factory()->create(['order_id' => $order->id, 'payable_type' => ClaimTypeEnum::EXCHANGE->value, 'payable_id' => $exchange->id]);

        $user = User::factory()->create();
        $mileageId = DB::table('ecommerce_mileage_transactions')->insertGetId([
            'user_id' => $user->id, 'order_id' => $order->id, 'claim_payment_id' => $payment->id, 'order_refund_id' => $refundId,
            'type' => 'earn', 'amount' => 100, 'balance_after' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $images = [
            $this->claimImage(ClaimTypeEnum::EXCHANGE->value, $exchange->id),
            $this->claimImage(ClaimTypeEnum::RETURN->value, $return->id),
            $this->claimImage(ClaimTypeEnum::REFUND->value, $refundId),
            $this->claimImage(ClaimImage::PENDING_CLAIMABLE_TYPE, $order->id),
        ];

        $survivor = $this->closedOrder(1);
        $survivorExchange = $this->exchange($survivor, ExchangeStatusEnum::COMPLETED, ['completed_at' => now()->subYear()]);
        $survivorImage = $this->claimImage(ClaimTypeEnum::EXCHANGE->value, $survivorExchange->id);

        Schema::disableForeignKeyConstraints();
        $result = app(OrderRetentionHandler::class)->process(false);
        Schema::enableForeignKeyConstraints();

        $this->assertSame(1, $result['count']);
        $this->assertSame('delete', $result['mode']);
        $this->assertNull(Order::withTrashed()->find($order->id));

        foreach (['ecommerce_order_exchanges', 'ecommerce_order_exchange_options', 'ecommerce_order_returns', 'ecommerce_order_return_options', 'ecommerce_claim_payments', 'ecommerce_order_refunds'] as $table) {
            $this->assertSame(0, DB::table($table)->where('order_id', $order->id)->count(), "{$table} 행이 명시 삭제된다");
        }

        foreach ($images as $image) {
            $this->assertNull(ClaimImage::find($image->id), "첨부 행({$image->claimable_type})이 지워진다");
            $this->assertFalse($this->storedFileExists($image), "첨부 파일({$image->claimable_type})도 지워진다");
        }

        $mileage = DB::table('ecommerce_mileage_transactions')->find($mileageId);
        $this->assertNotNull($mileage, '마일리지 거래는 회원 잔액의 원장이라 남는다');
        $this->assertNull($mileage->claim_payment_id, '클레임 결제 연결을 끊는다');
        $this->assertNull($mileage->order_refund_id, '환불 연결을 끊는다');

        $this->assertNotNull(OrderExchange::find($survivorExchange->id), '보유기간 안의 주문의 신청서는 그대로다');
        $this->assertNotNull(ClaimImage::find($survivorImage->id));
        $this->assertTrue($this->storedFileExists($survivorImage), '보유기간 안의 주문의 첨부 파일은 그대로다');
    }

    /**
     * 진행 중인 교환·반품이 있으면 종결이 아니고, 기준일 뒤에 완료된 교환·반품은 종결 시각을 그만큼 늦춘다.
     * 거절·철회로 끝난 신청서는 진행 중이 아니다 — 철회된 환불신청서가 주문을 영영 붙들지 않는다. 대상
     * 명단의 종결일은 늦게 완료된 교환의 완료 시각이다.
     *
     * 수정 전 실패 이유: 대상 선정이 환불만 봐 처리 중인 교환·반품 주문까지 익명화했고, 환불의 끝난 상태를
     * 완료·거절 둘로만 적어 철회된 환불신청서를 진행 중으로 읽었다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects open_claims_hold_order_closure, completed_claims_extend_closure, withdrawn_claims_do_not_hold_closure
     */
    #[Test]
    public function open_claims_hold_the_order_and_completed_claims_extend_closure(): void
    {
        $this->enableRetention('anonymize');

        $openExchange = $this->closedOrder();
        $this->exchange($openExchange, ExchangeStatusEnum::REQUESTED);

        $openReturn = $this->closedOrder();
        $this->returnClaim($openReturn, ReturnStatusEnum::COLLECTING);

        $recentReturn = $this->closedOrder();
        $this->returnClaim($recentReturn, ReturnStatusEnum::COMPLETED, ['completed_at' => now()->subYear()]);

        $withdrawnExchange = $this->closedOrder();
        $this->exchange($withdrawnExchange, ExchangeStatusEnum::WITHDRAWN, ['withdrawn_at' => now()->subYear()]);

        $withdrawnRefund = $this->closedOrder();
        $this->refund($withdrawnRefund, 'withdrawn', ['withdrawn_at' => now()->subYears(6)]);

        $lateExchange = $this->closedOrder(7);
        $late = $this->exchange($lateExchange, ExchangeStatusEnum::COMPLETED, ['completed_at' => now()->subYears(6)]);

        $handler = app(OrderRetentionHandler::class);

        $targets = collect($handler->targets(1, 50)->items());
        $this->assertEqualsCanonicalizing(
            [$withdrawnExchange->id, $withdrawnRefund->id, $lateExchange->id],
            $targets->pluck('id')->all(),
            '진행 중인 교환·반품과 기준일 뒤에 완료된 반품은 대상이 아니고, 철회로 끝난 신청서는 주문을 붙들지 않는다'
        );
        $this->assertSame(3, $handler->preview());
        $this->assertSame(
            $late->completed_at->toDateTimeString(),
            $targets->firstWhere('id', $lateExchange->id)->date->toDateTimeString(),
            '종결일은 늦게 완료된 교환의 완료 시각이다'
        );

        $this->assertSame(3, $handler->process(false)['count']);
        $this->assertSame(0, $handler->preview(), '처리 뒤에는 남은 대상이 없다');
    }

    /**
     * 전량 반품으로 끝난 주문의 종결일은 마지막 반품 완료 시각이다 — 주문이 나중에 수정돼도 밀리지 않는다.
     *
     * 수정 전 실패 이유: 대상 명단이 종결일의 바탕을 「구매확정 → 취소 → 마지막 수정 시각」으로 잡아, 반품으로
     * 끝난 주문은 반품 완료 뒤 운영자 메모 같은 수정만 있어도 그 수정 시각이 종결일로 보였다. 대상 판정은 반품
     * 완료 시각으로 하므로, 6년 전에 끝나 처리 대상인 주문이 「작년에 종결」로 표시됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=returned, toggle=on, mode=anonymize
     *
     * @effects completed_claims_extend_closure
     */
    #[Test]
    public function returned_order_closure_date_is_the_return_completion_not_a_later_edit(): void
    {
        $this->enableRetention('anonymize');

        $order = Order::factory()->create(['order_status' => 'returned', 'confirmed_at' => null, 'cancelled_at' => null]);
        $return = $this->returnClaim($order, ReturnStatusEnum::COMPLETED, ['completed_at' => now()->subYears(6)]);
        DB::table('ecommerce_orders')->where('id', $order->id)->update(['updated_at' => now()->subYear()]);

        $target = collect(app(OrderRetentionHandler::class)->targets(1, 50)->items())->firstWhere('id', $order->id);

        $this->assertNotNull($target, '반품 완료 6년 지난 주문은 대상이다');
        $this->assertSame($return->completed_at->toDateTimeString(), $target->date->toDateTimeString());
    }
}
