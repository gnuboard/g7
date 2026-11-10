<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestingSchema\ColumnOrder;
use Tests\Support\TestingSchema\StructureChecksum;

/**
 * 클레임 컬럼 확장 마이그레이션의 되돌리기 가드 테스트 (P15)
 *
 * MySQL 은 DDL 을 롤백하지 않으므로 up() 이 중간에 죽으면 컬럼은 만들어진 채 인덱스·외래키는
 * 없는 상태가 남는다. up() 은 그 3축을 각각 가드하는데 down() 이 **컬럼 존재만으로** 인덱스·
 * 외래키 드롭까지 묶으면, 그 설치본에서 down() 이 없는 인덱스를 지우려다 죽어 되돌리기 자체가
 * 막힌다. 그 반쪽 상태를 만들어 놓고 down() 이 통과하는지 본다.
 */
class ClaimColumnsMigrationDownTest extends ModuleTestCase
{
    /** 테스트 시작 시점의 테스트 DB 구조 지문 */
    private string $structureBefore = '';

    /**
     * 테스트 시작 시점의 두 테이블 컬럼 순서
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $columnOrder = [];

    /**
     * 테스트 DB 구조 지문과 컬럼 순서를 기록합니다 — 테스트 스키마는 실행 사이에 재사용되므로 끝날 때 같은 구조로 되돌려야 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->structureBefore = StructureChecksum::compute(DB::connection(), DB::connection()->getDatabaseName());
        $this->columnOrder = ColumnOrder::capture(DB::connection(), ['ecommerce_mileage_transactions', 'ecommerce_order_refunds']);
    }

    /**
     * down() 으로 내린 컬럼·인덱스·외래키를 up() 으로 되살리고(3축 각각 가드), 컬럼 순서까지 되돌린 뒤 구조가 시작 전과 같은지 확인합니다.
     *
     * 이 마이그레이션은 컬럼을 after() 로 끼워 넣는데, 뒤 마이그레이션이 같은 자리에 컬럼을 더 넣었으므로 up() 만 다시 돌리면
     * 컬럼 순서가 달라진다. 되돌리지 않으면 다음 실행이 매번 테스트 스키마를 처음부터 다시 만든다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->migration()->up();
        ColumnOrder::restore(DB::connection(), $this->columnOrder);
        $after = StructureChecksum::compute(DB::connection(), DB::connection()->getDatabaseName());

        parent::tearDown();

        $this->assertSame($this->structureBefore, $after, 'down() 뒤 복구한 테스트 DB 구조가 시작 전과 달라졌다');
    }

    /**
     * 대상 마이그레이션 인스턴스를 반환합니다.
     *
     * @return Migration 클레임 컬럼 확장 마이그레이션(2026_08_20_000008) 인스턴스
     */
    private function migration(): Migration
    {
        return require dirname(__DIR__, 3)
            .'/database/migrations/2026_08_20_000008_add_claim_columns_to_existing_tables.php';
    }

    /**
     * up() 이 인덱스·외래키 단계에서 죽은 설치본을 흉내 냅니다. (컬럼만 남은 상태)
     *
     * @return void 반환값 없음
     */
    private function simulatePartialUp(): void
    {
        Schema::table('ecommerce_mileage_transactions', function (Blueprint $table) {
            $table->dropForeign('fk_mt_claim_payment_id');
            $table->dropIndex('ecommerce_mileage_trx_claim_payment_index');
        });

        Schema::table('ecommerce_order_refunds', function (Blueprint $table) {
            $table->dropForeign('fk_rf_requested_by');
            $table->dropIndex('ecommerce_order_refunds_requested_by_index');
        });
    }

    /**
     * 컬럼만 남고 인덱스·외래키가 없는 설치본에서도 down() 이 통과한다.
     *
     * @scenario case=partially_applied_up
     *
     * @effects down_skips_missing_index_and_foreign_key, down_drops_claim_columns
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function down_survives_a_partially_applied_up(): void
    {
        $this->simulatePartialUp();

        $this->migration()->down();

        $this->assertFalse(
            Schema::hasColumn('ecommerce_mileage_transactions', 'claim_payment_id'),
            'down() 이 끝까지 돌았다면 컬럼도 지워져 있어야 한다'
        );
        $this->assertFalse(Schema::hasColumn('ecommerce_order_refunds', 'requested_by'));
        $this->assertFalse(Schema::hasColumn('ecommerce_order_refunds', 'refund_source_type'));
    }

    /**
     * 정상 up() 상태(3축 모두 존재)에서도 down() 이 통과한다 — 대조군.
     *
     * @scenario case=fully_applied_up
     *
     * @effects down_drops_claim_columns
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function down_succeeds_on_a_fully_applied_up(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('ecommerce_mileage_transactions', 'claim_payment_id'));
        $this->assertFalse(Schema::hasColumn('ecommerce_mileage_transactions', 'order_refund_id'));
        $this->assertFalse(Schema::hasColumn('ecommerce_order_refunds', 'requested_by'));
    }
}
