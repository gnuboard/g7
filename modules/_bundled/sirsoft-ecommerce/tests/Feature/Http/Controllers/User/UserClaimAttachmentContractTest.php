<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 첨부 계약 — **사용자 경로**가 읽는 것이 그대로 남아 있는가 (R19 · R20)
 *
 * 첨부 리소스와 상한 봉투는 관리자와 사용자가 **같은 벌**을 쓴다. 그래서 관리자 화면을
 * 고치면서 기존 키를 별칭으로 갈아끼우거나 봉투 키 이름을 바꾸면, 관리자 화면만 보고
 * 배포했을 때 **사용자 신청 화면이 조용히 망가진다**:
 *
 * - 파일명·순서·용량 표기가 빈칸이 된다(리소스의 기존 키가 사라진 경우).
 * - 상한 가드가 0 을 읽고 **꺼진다** — 서버가 거절할 때까지 아무 안내도 나오지 않는다.
 *
 * 둘 다 예외도 로그도 남지 않으므로 이 계약을 여기서 고정한다.
 */
class UserClaimAttachmentContractTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $user;

    /**
     * 사유를 시드하고 회원을 만든 뒤 첨부 저장소를 목으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 신청 사유는 시드가 있어야 FormRequest 를 통과한다.
        $this->seedClaimReasons();
        $this->user = $this->createUser();

        // 첨부는 실제 디스크를 건드리지 않는다 — 이 테스트가 보는 것은 응답 계약이다.
        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true)->byDefault();
        $storage->allows('delete')->andReturn(true)->byDefault();
        $storage->allows('exists')->andReturn(true)->byDefault();
        $storage->allows('getDisk')->andReturn('local')->byDefault();
        $storage->allows('response')->andReturn(null)->byDefault();
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);
    }

    /**
     * 목을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 사용자 경로의 첨부 페이로드가 **기존 3키를 그대로** 싣는가.
     *
     * 업로더 호환 별칭(`original_filename`·`order`·`size_formatted`)은 **가산**이다.
     * 치환이면 이 단언이 빨개진다.
     *
     * @scenario claim_type=exchange, claim_stage=applied
     *
     * @effects user_claim_attachment_keeps_its_legacy_keys
     *
     * @return void 반환값 없음
     */
    public function test_user_claim_response_keeps_the_legacy_attachment_keys(): void
    {
        $order = $this->myDeliveredOrder();
        $image = app(ClaimAttachmentService::class)
            ->uploadPending(UploadedFile::fake()->image('evidence.jpg'), $order, $this->user->id);

        $payload = $this->actingAs($this->user)
            ->postJson($this->base()."/orders/{$order->id}/returns", [
                'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
                'charge_method' => 'deduct_from_refund',
                'image_ids' => [$image->id],
                // 이 주문은 계좌로 돈이 돌아가는 결제수단이라 반품 접수에도 계좌가 필요하다(#599 validate P3).
                // 이 테스트가 보는 것은 첨부 응답 계약이므로 계좌를 채워 접수를 통과시킨다.
                'refund_bank' => ['bank_code' => '004', 'account_number' => '11122233344', 'holder' => '홍길동'],
            ])
            ->assertCreated()
            ->json('data.claim.images.0');

        foreach (['original_name', 'sort_order', 'size'] as $legacyKey) {
            $this->assertArrayHasKey(
                $legacyKey,
                $payload,
                "사용자 경로가 읽는 기존 키 `{$legacyKey}` 가 사라졌습니다 — 신청 화면의 표기가 빈칸이 됩니다."
            );
        }

        $this->assertSame('evidence.jpg', $payload['original_name']);
    }

    /**
     * 상한 봉투의 **키 이름이 바뀌지 않았는가** — 값까지 서버와 같은가.
     *
     * 화면 가드는 이 두 키를 이름으로 읽는다. 이름이 바뀌면 0 을 읽고 가드가 꺼진다.
     *
     * @scenario claim_type=exchange, claim_stage=applying
     *
     * @effects user_claim_settings_keep_the_limit_key_names
     *
     * @return void 반환값 없음
     */
    public function test_claimable_options_settings_keep_the_limit_keys(): void
    {
        $order = $this->myDeliveredOrder();
        $service = app(ClaimAttachmentService::class);

        $this->actingAs($this->user)
            ->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.settings.max_images', $service->maxImages())
            ->assertJsonPath('data.settings.image_max_size_kb', $service->maxSizeKb());
    }

    /**
     * 이 회원의 배송완료 주문을 만듭니다.
     *
     * @return Order 배송완료 주문
     */
    protected function myDeliveredOrder(): Order
    {
        $order = $this->deliveredClaimOrder();
        $order->forceFill(['user_id' => $this->user->id])->save();

        return $order->fresh(['options', 'shippingAddress']);
    }

    /**
     * 사용자 클레임 API 기본 경로를 돌려줍니다.
     *
     * @return string 기본 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/user';
    }
}
