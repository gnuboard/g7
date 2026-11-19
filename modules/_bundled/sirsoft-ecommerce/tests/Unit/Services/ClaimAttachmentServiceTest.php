<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Helpers\PermissionHelper;
use App\Models\ActivityLog;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 클레임 첨부 서비스 테스트 (S5)
 *
 * 검증 축:
 * - 신청서 작성 중 업로드는 주문에 미귀속(pending)으로 묶이고 장수 상한을 지킨다
 * - 귀속은 이 주문의 미귀속 첨부만 받고, 다른 주문·다른 회원의 첨부는 거절한다
 * - 소유 판정 — 미귀속은 업로더/주문, 귀속은 신청자/주문
 * - 서빙 게이트 — 서명·운영자·본인만 통과하고 그 외는 null(404)
 */
class ClaimAttachmentServiceTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    protected ClaimAttachmentService $service;

    /** @var MockInterface&StorageInterface */
    protected $storage;

    /**
     * 모의 스토리지를 주입한 ClaimAttachmentService 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = Mockery::mock(StorageInterface::class);
        $this->storage->allows('put')->andReturn(true)->byDefault();
        $this->storage->allows('delete')->andReturn(true)->byDefault();
        $this->storage->allows('exists')->andReturn(true)->byDefault();
        $this->storage->allows('getDisk')->andReturn('local')->byDefault();
        $this->storage->allows('response')->andReturn(null)->byDefault();

        $this->app->when(ClaimAttachmentService::class)
            ->needs(StorageInterface::class)
            ->give(fn () => $this->storage);

        $this->service = app(ClaimAttachmentService::class);
    }

    /**
     * Mockery 기대를 검증하고 닫은 뒤 부모 정리를 실행합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 시험용 가짜 이미지 업로드 파일을 만듭니다.
     *
     * @param  string  $name  업로드 파일 이름
     * @return UploadedFile 가짜 이미지 업로드 파일
     */
    protected function image(string $name = 'claim.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 640, 480);
    }

    /**
     * 신청서 작성 중 올린 첨부가 미귀속 상태로 그 주문에 묶이고 업로더, 해시, 저장 경로가 기록되는지 확인
     *
     * @effects pending_upload_is_bound_to_the_order
     *
     * @return void 반환값 없음
     */
    public function test_pending_upload_is_bound_to_the_order(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();

        $image = $this->service->uploadPending($this->image(), $order, $user->id);

        $this->assertSame(ClaimImage::PENDING_CLAIMABLE_TYPE, $image->claimable_type);
        $this->assertSame($order->id, (int) $image->claimable_id);
        $this->assertSame($user->id, (int) $image->created_by);
        $this->assertSame(12, strlen($image->hash));
        $this->assertStringStartsWith('claims/pending/'.$order->id.'/', $image->path);
    }

    /**
     * 미귀속 업로드가 설정된 최대 장수를 넘으면 claim_image_limit_exceeded 로 거절되는지 확인
     *
     * @effects pending_upload_respects_the_max_images_setting
     *
     * @return void 반환값 없음
     */
    public function test_pending_upload_respects_the_max_images_setting(): void
    {
        $order = Order::factory()->forGuest()->create();
        $max = $this->service->maxImages();

        for ($i = 0; $i < $max; $i++) {
            $this->service->uploadPending($this->image("claim{$i}.jpg"), $order, null);
        }

        $this->assertClaimException('claim_image_limit_exceeded', fn () => $this->service->uploadPending($this->image('over.jpg'), $order, null));
    }

    /**
     * 미귀속 첨부를 귀속하면 교환 신청서로 옮겨지고 정렬 순서가 1부터 매겨지며 미귀속 목록이 비는지 확인
     *
     * @effects attach_pending_binds_images_to_the_claim
     *
     * @return void 반환값 없음
     */
    public function test_attach_pending_binds_images_to_the_claim(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'requested_by' => $user->id]);

        $a = $this->service->uploadPending($this->image('a.jpg'), $order, $user->id);
        $b = $this->service->uploadPending($this->image('b.jpg'), $order, $user->id);

        $attached = $this->service->attachPending($order, [$a->id, $b->id], ClaimTypeEnum::EXCHANGE, $exchange->id, $user->id);

        $this->assertCount(2, $attached);
        $this->assertSame(ClaimTypeEnum::EXCHANGE->value, $a->fresh()->claimable_type);
        $this->assertSame($exchange->id, (int) $a->fresh()->claimable_id);
        $this->assertSame([1, 2], $attached->pluck('sort_order')->all());
        $this->assertCount(0, $this->service->listPendingFor($order));
        $this->assertCount(2, $this->service->listFor(ClaimTypeEnum::EXCHANGE, $exchange->id));
    }

    /**
     * 다른 주문의 미귀속 첨부를 지목하면 claim_image_not_found 로 거절하고 그 첨부는 미귀속으로 남는지 확인
     *
     * @effects attach_pending_rejects_images_of_another_order
     *
     * @return void 반환값 없음
     */
    public function test_attach_pending_rejects_images_of_another_order(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();
        $other = Order::factory()->forUser($user)->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'requested_by' => $user->id]);

        $foreign = $this->service->uploadPending($this->image(), $other, $user->id);

        $this->assertClaimException('claim_image_not_found', fn () => $this->service->attachPending(
            $order, [$foreign->id], ClaimTypeEnum::EXCHANGE, $exchange->id, $user->id
        ));
        $this->assertSame(ClaimImage::PENDING_CLAIMABLE_TYPE, $foreign->fresh()->claimable_type);
    }

    /**
     * 다른 회원이 올린 미귀속 첨부는 주문 소유자가 귀속하려 해도 거절되는지 확인
     *
     * @effects attach_pending_rejects_images_uploaded_by_another_member
     *
     * @return void 반환값 없음
     */
    public function test_attach_pending_rejects_images_uploaded_by_another_member(): void
    {
        $owner = $this->createUser();
        $stranger = $this->createUser();
        $order = Order::factory()->forUser($owner)->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'requested_by' => $owner->id]);

        $image = $this->service->uploadPending($this->image(), $order, $stranger->id);

        $this->assertClaimException('claim_image_not_found', fn () => $this->service->attachPending(
            $order, [$image->id], ClaimTypeEnum::EXCHANGE, $exchange->id, $owner->id
        ));
    }

    /**
     * 없는 ID 와 이미 귀속된 첨부를 지목하면 claim_image_not_found 로 거절되는지 확인
     *
     * @effects attach_pending_rejects_unknown_ids_and_already_attached_images
     *
     * @return void 반환값 없음
     */
    public function test_attach_pending_rejects_unknown_ids_and_already_attached_images(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'requested_by' => $user->id]);
        $image = $this->service->uploadPending($this->image(), $order, $user->id);
        $this->service->attachPending($order, [$image->id], ClaimTypeEnum::EXCHANGE, $exchange->id, $user->id);

        $this->assertClaimException('claim_image_not_found', fn () => $this->service->attachPending($order, [999999], ClaimTypeEnum::EXCHANGE, $exchange->id, $user->id));
        // 이미 귀속된 첨부를 다시 지목하면 미귀속이 아니므로 거절한다.
        $this->assertClaimException('claim_image_not_found', fn () => $this->service->attachPending($order, [$image->id], ClaimTypeEnum::EXCHANGE, $exchange->id, $user->id));
    }

    /**
     * ID 목록이 비면 아무것도 귀속하지 않고 빈 목록을 돌려주는지 확인
     *
     * @effects attach_pending_with_no_ids_is_a_no_op
     *
     * @return void 반환값 없음
     */
    public function test_attach_pending_with_no_ids_is_a_no_op(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id]);

        $this->assertCount(0, $this->service->attachPending($order, [], ClaimTypeEnum::EXCHANGE, $exchange->id, $user->id));
    }

    /**
     * 비회원 주문의 미귀속 첨부가 회원 ID 없이 귀속되고 created_by 가 null 로 남는지 확인
     *
     * @effects guest_pending_images_attach_with_the_order_token_alone
     *
     * @return void 반환값 없음
     */
    public function test_guest_pending_images_attach_with_the_order_token_alone(): void
    {
        $order = Order::factory()->forGuest()->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'requested_by' => null]);
        $image = $this->service->uploadPending($this->image(), $order, null);

        $attached = $this->service->attachPending($order, [$image->id], ClaimTypeEnum::EXCHANGE, $exchange->id, null);

        $this->assertCount(1, $attached);
        $this->assertNull($attached->first()->created_by);
    }

    /**
     * 소유 판정이 미귀속은 업로더, 귀속은 신청자, 비회원은 주문 소속으로 갈리는지 확인
     *
     * @effects owned_by_judges_pending_by_uploader_and_attached_by_requester
     *
     * @return void 반환값 없음
     */
    public function test_owned_by_judges_pending_by_uploader_and_attached_by_requester(): void
    {
        $owner = $this->createUser();
        $stranger = $this->createUser();
        $order = Order::factory()->forUser($owner)->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'requested_by' => $owner->id]);

        $pending = $this->service->uploadPending($this->image('p.jpg'), $order, $owner->id);
        $this->assertTrue($this->service->ownedBy($pending, $owner->id));
        $this->assertFalse($this->service->ownedBy($pending, $stranger->id));
        $this->assertFalse($this->service->ownedBy($pending, null));

        $attached = $this->service->uploadFor($this->image('a.jpg'), ClaimTypeEnum::EXCHANGE, $exchange->id, $owner->id, ClaimImageUploaderTypeEnum::ADMIN);
        $this->assertTrue($this->service->ownedBy($attached, $owner->id));
        $this->assertFalse($this->service->ownedBy($attached, $stranger->id));

        // 비회원 주문 토큰은 주문 소속으로 판정한다.
        $guestOrder = Order::factory()->forGuest()->create();
        $guestPending = $this->service->uploadPending($this->image('g.jpg'), $guestOrder, null);
        $this->assertTrue($this->service->ownedBy($guestPending, null, $guestOrder));
        $this->assertFalse($this->service->ownedBy($guestPending, null, $order));
    }

    /**
     * 다운로드 게이트가 비로그인과 제3자를 막고 본인 또는 서명 요청만 통과시키며 없는 해시는 null 인지 확인
     *
     * @effects download_gate_blocks_strangers_and_allows_owner_or_signature
     *
     * @return void 반환값 없음
     */
    public function test_download_gate_blocks_strangers_and_allows_owner_or_signature(): void
    {
        $owner = $this->createUser();
        $stranger = $this->createUser();
        $order = Order::factory()->forUser($owner)->create();
        $image = $this->service->uploadPending($this->image(), $order, $owner->id);

        $this->storage->allows('response')->andReturn(new StreamedResponse(fn () => null));

        $this->assertNull($this->service->download($image->hash, false, null));
        $this->assertNull($this->service->download($image->hash, false, $stranger->id));
        $this->assertNotNull($this->service->download($image->hash, false, $owner->id));
        $this->assertNotNull($this->service->download($image->hash, true, null));
        $this->assertNull($this->service->download('000000000000', true, null));
    }

    /**
     * 클레임 조회 권한을 가진 운영자는 서명 없이 다운로드 게이트를 통과하고 권한 없는 관리자는 막히는지 확인
     *
     * @effects download_gate_lets_an_operator_with_claims_read_through_without_a_signature
     *
     * @return void 반환값 없음
     */
    public function test_download_gate_lets_an_operator_with_claims_read_through_without_a_signature(): void
    {
        // 게이트 3축 중 운영자 축만 단언이 없었다 — 형제 케이스의 '제3자' 는 권한도 없는 회원이라
        // `PermissionHelper::check()` 항을 통째로 지워도 green 이 된다(권한 슬러그 오타도 마찬가지).
        $owner = $this->createUser();
        $order = Order::factory()->forUser($owner)->create();
        $image = $this->service->uploadPending($this->image(), $order, $owner->id);

        $this->storage->allows('response')->andReturn(new StreamedResponse(fn () => null));

        // 대조군 — 관리자이지만 클레임 조회 권한이 없으면 서명 없이 열 수 없다.
        $withoutClaims = $this->createAdminUser();
        $this->actingAs($withoutClaims);
        $this->clearClaimPermissionCache();
        $this->assertNull($this->service->download($image->hash, false, $withoutClaims->id));

        // `claims.read` 보유 운영자는 서명 없이 통과한다 — 관리자 화면이 hash 직접 URL 을 쓴다.
        $operator = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);
        $this->actingAs($operator);
        $this->clearClaimPermissionCache();
        $this->assertNotNull($this->service->download($image->hash, false, $operator->id));
    }

    /**
     * 권한 판정 캐시를 비웁니다. (같은 테스트 안에서 열람자를 바꾸므로)
     *
     * @return void 반환값 없음
     */
    protected function clearClaimPermissionCache(): void
    {
        $property = (new \ReflectionClass(PermissionHelper::class))->getProperty('permissionCache');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    /**
     * 첨부 삭제가 저장 파일과 행을 함께 지우는지 확인
     *
     * @effects delete_removes_the_file_and_the_row
     *
     * @return void 반환값 없음
     */
    public function test_delete_removes_the_file_and_the_row(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();
        $image = $this->service->uploadPending($this->image(), $order, $user->id);

        $this->storage->expects('delete')->once()->andReturn(true);

        $this->assertTrue($this->service->delete($image));
        $this->assertNull(ClaimImage::find($image->id));
    }

    /**
     * 업로드·삭제 로그가 업로드 주체를 함께 남기는가 (R27)
     *
     * 개별 삭제는 파일까지 즉시 지우고 되돌릴 수 없다. 지워진 사진이 고객이 낸 증빙이었는지
     * 운영자가 대리로 올린 것이었는지가 로그에 없으면, 행이 이미 사라진 뒤에는 분쟁 판정의
     * 근거를 어디서도 되찾을 수 없다.
     *
     * @effects attachment_log_carries_the_uploader_type
     *
     * @return void 반환값 없음
     */
    public function test_attachment_logs_carry_the_uploader_type(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'requested_by' => $user->id]);

        $image = $this->service->uploadFor(
            $this->image('evidence.jpg'),
            ClaimTypeEnum::EXCHANGE,
            $exchange->id,
            $user->id,
            ClaimImageUploaderTypeEnum::ADMIN
        );

        $uploaded = ActivityLog::query()
            ->where('action', 'claim_image.upload')
            ->where('loggable_id', $image->id)
            ->firstOrFail();

        $this->assertSame(
            ClaimImageUploaderTypeEnum::ADMIN->value,
            data_get($uploaded->properties, 'uploader_type'),
            '업로드 로그가 업로드 주체를 남기지 않았다'
        );

        $this->assertTrue($this->service->delete($image));

        $deleted = ActivityLog::query()
            ->where('action', 'claim_image.delete')
            ->where('loggable_id', $image->id)
            ->firstOrFail();

        // 삭제 로그는 행이 이미 없으므로 properties 스냅샷이 유일한 출처다.
        $this->assertSame(
            ClaimImageUploaderTypeEnum::ADMIN->value,
            data_get($deleted->properties, 'uploader_type'),
            '삭제 로그가 업로드 주체를 남기지 않았다'
        );
    }

    /**
     * 신청서 단위 삭제가 그 신청서의 첨부를 모두 지우고 삭제 건수를 돌려주는지 확인
     *
     * @effects delete_for_claimable_removes_every_attachment_of_the_claim
     *
     * @return void 반환값 없음
     */
    public function test_delete_for_claimable_removes_every_attachment_of_the_claim(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'requested_by' => $user->id]);
        $this->service->uploadFor($this->image('a.jpg'), ClaimTypeEnum::EXCHANGE, $exchange->id, $user->id, ClaimImageUploaderTypeEnum::ADMIN);
        $this->service->uploadFor($this->image('b.jpg'), ClaimTypeEnum::EXCHANGE, $exchange->id, $user->id, ClaimImageUploaderTypeEnum::ADMIN);

        $this->assertSame(2, $this->service->deleteFor(ClaimTypeEnum::EXCHANGE, $exchange->id));
        $this->assertCount(0, $this->service->listFor(ClaimTypeEnum::EXCHANGE, $exchange->id));
    }

    /**
     * 주문 삭제 뒤 반품 신청서 헤더와 귀속 첨부, 미귀속 첨부가 하나도 남지 않는지 확인
     *
     * @effects deleting_an_order_leaves_no_orphan_claim_rows_or_attachments
     *
     * @return void 반환값 없음
     */
    public function test_deleting_an_order_leaves_no_orphan_claim_rows_or_attachments(): void
    {
        // 주문은 soft-delete 인데 주문옵션은 하드 삭제라, 클레임 계열을 CASCADE 에 맡기면 교환·반품의
        // **상품 행만** 사라지고 금액을 든 신청서 헤더가 상품 0개로 남는다. 첨부는 주문 FK 가 없어
        // 신청서를 지워도 파일과 행이 그대로 남고, 그 안에는 평문 환불 계좌까지 들어 있다.
        $this->seedClaimReasons();
        $order = $this->deliveredClaimOrder();
        $claims = app(UserClaimService::class);

        $pending = $this->service->uploadPending(UploadedFile::fake()->image('a.jpg'), $order, $order->user_id);
        $return = $claims->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $order->user_id,
        ], [(int) $pending->id])['claim'];

        $this->assertSame(1, OrderReturn::where('order_id', $order->id)->count());
        $this->assertSame(1, ClaimImage::where('claimable_type', 'return')->where('claimable_id', $return->id)->count());

        app(OrderService::class)->delete($order->fresh());

        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count(), '신청서 헤더가 남으면 상품 0개짜리 고아 신청서가 된다.');
        $this->assertSame(0, ClaimImage::where('claimable_type', 'return')->where('claimable_id', $return->id)->count());
        $this->assertSame(0, ClaimImage::where('claimable_type', 'pending')->where('claimable_id', $order->id)->count());
    }
}
