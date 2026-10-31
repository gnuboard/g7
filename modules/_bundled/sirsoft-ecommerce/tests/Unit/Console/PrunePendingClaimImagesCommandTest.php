<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Console;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 임시 첨부 정리 커맨드 테스트 (S6a)
 *
 * 경계 축 — 지워야 할 것(보존 기간이 지난 미귀속)만 지우고, 남겨야 할 것(귀속 첨부·보존
 * 기간 이내의 미귀속)은 남긴다. dry-run 은 아무것도 지우지 않는다.
 */
class PrunePendingClaimImagesCommandTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정과 첨부 저장소 대역을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();

        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true)->byDefault();
        $storage->allows('delete')->andReturn(true)->byDefault();
        $storage->allows('exists')->andReturn(true)->byDefault();
        $storage->allows('getDisk')->andReturn('local')->byDefault();
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);
    }

    /**
     * 테스트 통화 설정을 정리합니다.
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
     * 미귀속(pending) 첨부를 만들고 나이를 조정합니다.
     *
     * @param  Order  $order  첨부를 올린 주문
     * @param  int  $ageDays  첨부가 만들어진 지 지난 일수
     * @return ClaimImage 만든 첨부
     */
    protected function makePendingImage(Order $order, int $ageDays): ClaimImage
    {
        $image = app(ClaimAttachmentService::class)->uploadPending(UploadedFile::fake()->image('a.jpg'), $order);
        $image->forceFill(['created_at' => now()->subDays($ageDays)])->saveQuietly();

        return $image->fresh();
    }

    /**
     * 보존 기간이 지난 미귀속 첨부만 지우고 보존 기간 이내·귀속 첨부는 남기는지 확인합니다.
     *
     * @scenario command=prune_pending_claim_images, path=prune
     *
     * @effects only_stale_orphans_are_cleaned, live_or_recent_rows_are_kept
     *
     * @return void 반환값 없음
     */
    public function test_prunes_only_expired_pending_uploads(): void
    {
        $order = $this->deliveredClaimOrder();

        $expiredPending = $this->makePendingImage($order, 5);
        $freshPending = $this->makePendingImage($order, 0);

        // 귀속된 첨부는 보존 기간이 지나도 대상이 아니다 — 유형 값이 바뀌어 조회에서 빠진다.
        $attached = $this->makePendingImage($order, 5);
        $attached->forceFill([
            'claimable_type' => ClaimTypeEnum::RETURN->value,
            'claimable_id' => 1,
        ])->saveQuietly();

        $this->artisan('sirsoft-ecommerce:prune-pending-claim-images')->assertSuccessful();

        $this->assertDatabaseMissing('ecommerce_claim_images', ['id' => $expiredPending->id]);
        $this->assertDatabaseHas('ecommerce_claim_images', ['id' => $freshPending->id]);
        $this->assertDatabaseHas('ecommerce_claim_images', ['id' => $attached->id]);
    }

    /**
     * dry-run 은 삭제 대상 건수만 알리고 지우지 않는지 확인합니다.
     *
     * @scenario command=prune_pending_claim_images, path=dry_run
     *
     * @effects dry_run_changes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_dry_run_counts_targets_without_deleting(): void
    {
        $order = $this->deliveredClaimOrder();
        $expired = $this->makePendingImage($order, 5);

        $this->artisan('sirsoft-ecommerce:prune-pending-claim-images', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] 삭제 대상 1건')
            ->assertSuccessful();

        $this->assertDatabaseHas('ecommerce_claim_images', ['id' => $expired->id]);
    }

    /**
     * 보존 일수가 1 미만이면 정리를 통째로 건너뛰는지 확인합니다.
     *
     * @scenario command=prune_pending_claim_images, path=skip
     *
     * @effects non_positive_retention_skips_cleanup
     *
     * @return void 반환값 없음
     */
    public function test_days_below_one_skips_the_prune_entirely(): void
    {
        $order = $this->deliveredClaimOrder();
        $expired = $this->makePendingImage($order, 30);

        $this->artisan('sirsoft-ecommerce:prune-pending-claim-images', ['--days' => 0])->assertSuccessful();

        $this->assertDatabaseHas('ecommerce_claim_images', ['id' => $expired->id]);
    }

    /**
     * 모듈이 이 커맨드를 스케줄로 등록한다 (등록 누락 회귀 차단).
     *
     * @scenario command=prune_pending_claim_images, path=schedule
     *
     * @effects command_declared_in_module_schedules
     *
     * @return void 반환값 없음
     */
    public function test_command_is_registered_in_module_schedules(): void
    {
        $entry = collect((new Module)->getSchedules())
            ->firstWhere('command', 'sirsoft-ecommerce:prune-pending-claim-images');

        $this->assertNotNull($entry, '임시 첨부 정리 스케줄이 등록되어야 합니다.');
        // 옛 등록(daily)과 같은 시각 — 매일 자정
        $this->assertSame(['daily', '0 0 * * *'], [$entry['frequency'], $entry['expression']]);
    }
}
