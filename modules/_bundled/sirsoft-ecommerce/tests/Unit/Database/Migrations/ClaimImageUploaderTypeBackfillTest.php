<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 첨부 업로드 주체 백필 검증
 *
 * 분쟁 판정을 가르는 값이라 **단정할 수 있는 규칙만** 쓰고 나머지는 `unknown` 으로 둔다.
 * 한 번 굳으면 원본이 없고, 화면은 확신 있게 "고객 첨부" 라고 말하게 되기 때문이다.
 *
 * 백필은 마이그레이션 `up()` 안에서 돈다(클레임 기능 미공개 — 업그레이드 스텝 없음).
 * 그래서 이 테스트도 그 `up()` 을 그대로 불러 실제 경로를 밟는다.
 */
class ClaimImageUploaderTypeBackfillTest extends ModuleTestCase
{
    /**
     * 백필 대상 행을 `unknown` 으로 되돌린 뒤 마이그레이션 `up()` 을 다시 돌립니다.
     *
     * @return void
     */
    private function runBackfill(): void
    {
        $this->migration()->up();
    }

    /**
     * 대상 마이그레이션 인스턴스를 가져옵니다.
     *
     * @return object 마이그레이션
     */
    private function migration(): object
    {
        return require base_path(
            'modules/_bundled/sirsoft-ecommerce/database/migrations/2026_09_14_000001_add_uploader_type_to_ecommerce_claim_images_table.php'
        );
    }

    /**
     * 첨부 행을 직접 만듭니다 (팩토리는 신청서까지 만들어 규칙 판정을 흐린다).
     *
     * @param  string  $claimableType  귀속 유형 값
     * @param  int  $claimableId  귀속 ID
     * @param  int|null  $createdBy  업로더 회원 ID
     * @return int 만들어진 첨부 ID
     */
    private function makeImage(string $claimableType, int $claimableId, ?int $createdBy): int
    {
        return (int) DB::table('ecommerce_claim_images')->insertGetId([
            'claimable_type' => $claimableType,
            'claimable_id' => $claimableId,
            'hash' => substr(bin2hex(random_bytes(8)), 0, 12),
            'disk' => 'public',
            'path' => 'claims/test-'.uniqid().'.jpg',
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'sort_order' => 0,
            'created_by' => $createdBy,
            'uploader_type' => ClaimImageUploaderTypeEnum::UNKNOWN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 미귀속(pending) 업로드는 고객이 올린 것이다.
     *
     * @effects pending_upload_is_backfilled_as_customer
     */
    public function test_pending_upload_is_backfilled_as_customer(): void
    {
        $id = $this->makeImage(ClaimImage::PENDING_CLAIMABLE_TYPE, 1234, null);

        $this->runBackfill();

        $this->assertSame(
            ClaimImageUploaderTypeEnum::CUSTOMER->value,
            DB::table('ecommerce_claim_images')->where('id', $id)->value('uploader_type')
        );
    }

    /**
     * 업로더가 없으면 비회원 신청자다.
     *
     * @effects upload_without_a_member_id_is_backfilled_as_customer
     */
    public function test_upload_without_a_member_id_is_backfilled_as_customer(): void
    {
        $claim = $this->makeReturnClaim(requestedBy: null);
        $id = $this->makeImage(ClaimTypeEnum::RETURN->value, $claim, null);

        $this->runBackfill();

        $this->assertSame(
            ClaimImageUploaderTypeEnum::CUSTOMER->value,
            DB::table('ecommerce_claim_images')->where('id', $id)->value('uploader_type')
        );
    }

    /**
     * 업로더 == 신청자면 고객이 올린 것이다.
     *
     * @effects upload_by_the_requester_is_backfilled_as_customer
     */
    public function test_upload_by_the_requester_is_backfilled_as_customer(): void
    {
        $user = $this->createUser();
        $claim = $this->makeReturnClaim(requestedBy: $user->id);
        $id = $this->makeImage(ClaimTypeEnum::RETURN->value, $claim, $user->id);

        $this->runBackfill();

        $this->assertSame(
            ClaimImageUploaderTypeEnum::CUSTOMER->value,
            DB::table('ecommerce_claim_images')->where('id', $id)->value('uploader_type')
        );
    }

    /**
     * 업로더 != 신청자면 운영자 대리 업로드다.
     *
     * @effects upload_by_someone_other_than_the_requester_is_backfilled_as_admin
     */
    public function test_upload_by_someone_other_than_the_requester_is_backfilled_as_admin(): void
    {
        $requester = $this->createUser();
        $staff = $this->createUser();
        $claim = $this->makeReturnClaim(requestedBy: $requester->id);
        $id = $this->makeImage(ClaimTypeEnum::RETURN->value, $claim, $staff->id);

        $this->runBackfill();

        $this->assertSame(
            ClaimImageUploaderTypeEnum::ADMIN->value,
            DB::table('ecommerce_claim_images')->where('id', $id)->value('uploader_type')
        );
    }

    /**
     * 귀속 신청서를 찾지 못한 고아는 **단정하지 않는다.**
     *
     * @effects orphan_attachment_stays_unknown
     */
    public function test_orphan_attachment_stays_unknown(): void
    {
        $user = $this->createUser();
        $id = $this->makeImage(ClaimTypeEnum::RETURN->value, 99999999, $user->id);

        $this->runBackfill();

        $this->assertSame(
            ClaimImageUploaderTypeEnum::UNKNOWN->value,
            DB::table('ecommerce_claim_images')->where('id', $id)->value('uploader_type'),
            '근거 없이 주체를 단정하면 화면이 확신 있게 거짓을 말합니다.'
        );
    }

    /**
     * 두 번 돌려도 결과가 같다 — 이미 채워진 값은 건드리지 않는다.
     *
     * @effects backfill_is_idempotent
     */
    public function test_backfill_is_idempotent(): void
    {
        $requester = $this->createUser();
        $staff = $this->createUser();
        $claim = $this->makeReturnClaim(requestedBy: $requester->id);
        $customerImage = $this->makeImage(ClaimTypeEnum::RETURN->value, $claim, $requester->id);
        $adminImage = $this->makeImage(ClaimTypeEnum::RETURN->value, $claim, $staff->id);

        $this->runBackfill();
        $first = DB::table('ecommerce_claim_images')->pluck('uploader_type', 'id')->all();

        $this->runBackfill();
        $second = DB::table('ecommerce_claim_images')->pluck('uploader_type', 'id')->all();

        $this->assertSame($first, $second);
        $this->assertSame(ClaimImageUploaderTypeEnum::CUSTOMER->value, $second[$customerImage]);
        $this->assertSame(ClaimImageUploaderTypeEnum::ADMIN->value, $second[$adminImage]);
    }

    /**
     * `up()` → `down()` → `up()` 왕복이 성립하는가 (§11 S4 DoD)
     *
     * 롤백이 컬럼을 실제로 지우지 못하면 재배포가 「이미 존재하는 컬럼」으로 멈춘다.
     * 반대로 다시 올렸을 때 백필이 돌지 않으면 되살린 컬럼이 전부 `unknown` 으로 남는다.
     *
     * @effects uploader_type_migration_survives_a_rollback_roundtrip
     */
    public function test_migration_up_down_up_restores_the_column_and_the_backfill(): void
    {
        $requester = $this->createUser();
        $claim = $this->makeReturnClaim(requestedBy: $requester->id);
        $image = $this->makeImage(ClaimTypeEnum::RETURN->value, $claim, $requester->id);

        $this->runBackfill();
        $this->assertSame(
            ClaimImageUploaderTypeEnum::CUSTOMER->value,
            DB::table('ecommerce_claim_images')->where('id', $image)->value('uploader_type')
        );

        $this->migration()->down();
        $this->assertFalse(
            Schema::hasColumn('ecommerce_claim_images', 'uploader_type'),
            'down() 이 컬럼을 지우지 못하면 재배포가 「이미 존재하는 컬럼」으로 멈춥니다.'
        );

        // 되살릴 때 백필이 함께 돌아야 한다 — 안 돌면 전 행이 `unknown` 으로 굳는다.
        $this->runBackfill();
        $this->assertTrue(Schema::hasColumn('ecommerce_claim_images', 'uploader_type'));
        $this->assertSame(
            ClaimImageUploaderTypeEnum::CUSTOMER->value,
            DB::table('ecommerce_claim_images')->where('id', $image)->value('uploader_type')
        );
    }

    /**
     * 백필 판정에 필요한 최소 반품 신청서 행을 만듭니다.
     *
     * @param  int|null  $requestedBy  신청자 회원 ID
     * @return int 신청서 ID
     */
    private function makeReturnClaim(?int $requestedBy): int
    {
        return (int) DB::table('ecommerce_order_returns')->insertGetId([
            'order_id' => Order::factory()->create()->id,
            'return_number' => 'RT'.substr(bin2hex(random_bytes(6)), 0, 10),
            'return_status' => 'requested',
            'reason_code' => 'changed_mind',
            'fault_type' => 'customer',
            'shipping_fee_mode' => 'charge',
            'items_snapshot' => '[]',
            'requested_by' => $requestedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
