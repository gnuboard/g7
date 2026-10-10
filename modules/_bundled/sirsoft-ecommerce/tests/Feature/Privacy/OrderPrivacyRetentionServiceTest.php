<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Privacy;

use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPrivacyRetentionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * 종결 주문 개인정보 보유기간 서비스 — 삭제의 클레임 첨부 파일 순서 (#599 — develop 7.1.0 16차 병합 후속)
 *
 * 삭제는 관리자 주문 삭제와 같은 순서로 첨부를 지운다 — 행이 사라지기 전에 목록을 잡아 두고, 행은
 * 트랜잭션 안에서, 파일은 커밋 뒤에. 파일 삭제는 되돌릴 수 없으므로 정리가 롤백되면 파일이 남아야
 * 살아 있는 신청서의 첨부가 비지 않는다. 처리 결과 전반(어떤 칸·테이블을 지우는가)은
 * `OrderRetentionClaimRecordsTest` 가 본다.
 *
 * 소유 시나리오: `order-privacy-retention`
 */
class OrderPrivacyRetentionServiceTest extends ModuleTestCase
{
    /**
     * 교환 신청서 첨부 한 장을 실제 파일과 함께 만듭니다.
     *
     * @param  Order  $order  주문
     * @return ClaimImage 첨부
     */
    private function exchangeImage(Order $order): ClaimImage
    {
        $exchange = OrderExchange::factory()->create(['order_id' => $order->id, 'exchange_status' => ExchangeStatusEnum::COMPLETED]);
        $storage = (fn () => $this->storage)->call(app(ClaimAttachmentService::class));
        $path = 'claims/exchange/'.$exchange->id.'/'.uniqid('retention-').'.jpg';
        $storage->put('images', $path, 'bytes');

        return ClaimImage::factory()->create([
            'claimable_type' => ClaimTypeEnum::EXCHANGE->value,
            'claimable_id' => $exchange->id,
            'disk' => $storage->getDisk(),
            'path' => $path,
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
     * 삭제 정리가 트랜잭션 안에서 실패하면 첨부 행도 파일도 그대로 남는다.
     *
     * 수정 전 실패 이유: 해당 없음(신규 경로) — 파일을 트랜잭션 안에서 지우면 롤백된 주문의 신청서가
     * 사진 없이 남는다. 이 테스트는 그 순서를 고정한다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects claim_image_files_survive_a_rolled_back_purge
     */
    #[Test]
    public function a_rolled_back_purge_keeps_claim_image_rows_and_files(): void
    {
        $order = Order::factory()->confirmed()->create();
        $image = $this->exchangeImage($order);

        $repository = Mockery::mock(OrderPrivacyRetentionRepositoryInterface::class);
        $repository->shouldReceive('optionIds')->andReturn([]);
        $repository->shouldReceive('purgeOrder')->andThrow(new RuntimeException('정리 중 실패'));
        $this->app->instance(OrderPrivacyRetentionRepositoryInterface::class, $repository);

        try {
            app(OrderPrivacyRetentionService::class)->purge($order);
            $this->fail('정리 실패가 호출자에게 전해져야 한다');
        } catch (RuntimeException $e) {
            $this->assertSame('정리 중 실패', $e->getMessage());
        }

        $this->assertNotNull(ClaimImage::find($image->id), '롤백된 정리는 첨부 행을 남긴다');
        $this->assertTrue($this->storedFileExists($image), '파일은 커밋 뒤에만 지우므로 롤백되면 남는다');
    }

    /**
     * 정리가 커밋되면 그 주문의 첨부 파일을 지운다 — 행은 저장소가 같은 트랜잭션에서 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects claim_records_are_purged_with_images_without_cascade
     */
    #[Test]
    public function a_committed_purge_deletes_claim_image_files(): void
    {
        $order = Order::factory()->confirmed()->create();
        $image = $this->exchangeImage($order);

        app(OrderPrivacyRetentionService::class)->purge($order);

        $this->assertNull(ClaimImage::find($image->id));
        $this->assertFalse($this->storedFileExists($image));
        $this->assertNull(Order::withTrashed()->find($order->id));
    }
}
