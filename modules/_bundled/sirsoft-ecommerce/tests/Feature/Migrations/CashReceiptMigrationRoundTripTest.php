<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestingSchema\StructureChecksum;

/**
 * 현금영수증 관련 마이그레이션 왕복(up → down → up) 안전성 테스트
 *
 * 모듈 삭제 시 delete_data 옵션으로 롤백이 실행되므로 down() 이 방어적이어야 한다.
 * 또한 코어 업그레이드 중 부분 적용된 상태에서 재실행되어도 SQL error 가 없어야 한다(멱등).
 */
class CashReceiptMigrationRoundTripTest extends ModuleTestCase
{
    private const MIGRATION_DIR = 'modules/_bundled/sirsoft-ecommerce/database/migrations';

    /** 테스트 시작 시점의 테스트 DB 구조 지문 */
    private string $structureBefore = '';

    /**
     * 테스트 DB 구조 지문을 기록합니다 — 테스트 스키마는 실행 사이에 재사용되므로 끝날 때 같은 구조로 되돌려야 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->structureBefore = StructureChecksum::compute(DB::connection(), DB::connection()->getDatabaseName());
    }

    /**
     * 내린 테이블·컬럼을 up() 으로 되살리고(up() 은 멱등 가드), 구조가 시작 전과 같은지 확인합니다.
     *
     * 되돌리지 않으면 다음 실행이 구조 지문 불일치로 매번 테스트 스키마를 처음부터 다시 만든다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->migrations() as $path) {
            $this->load($path)->up();
        }
        $after = StructureChecksum::compute(DB::connection(), DB::connection()->getDatabaseName());

        parent::tearDown();

        $this->assertSame($this->structureBefore, $after, '왕복 뒤 테스트 DB 구조가 시작 전과 달라졌다');
    }

    /**
     * 왕복 검사 대상인 현금영수증 마이그레이션 4종의 저장소 상대 경로를 이름별로 돌려준다.
     *
     * @return array<string, string> 마이그레이션 별칭 => 저장소 상대 파일 경로
     */
    private function migrations(): array
    {
        return [
            'cash_receipts' => self::MIGRATION_DIR.'/2026_07_09_000001_create_ecommerce_order_cash_receipts_table.php',
            'cash_equivalent' => self::MIGRATION_DIR.'/2026_07_09_000002_add_cash_equivalent_amount_to_ecommerce_orders_table.php',
            'identifier_encrypted' => self::MIGRATION_DIR.'/2026_07_09_000003_add_cash_receipt_identifier_encrypted_to_ecommerce_order_payments_table.php',
            'identifier_type' => self::MIGRATION_DIR.'/2026_07_09_000004_add_cash_receipt_identifier_type_to_ecommerce_order_payments_table.php',
        ];
    }

    /**
     * 마이그레이션 파일을 로드해 up()/down() 을 직접 호출할 수 있는 익명 마이그레이션 인스턴스를 반환합니다.
     *
     * @param  string  $relativePath  저장소 루트 기준 마이그레이션 파일 경로
     * @return object 파일이 반환한 마이그레이션 인스턴스
     */
    private function load(string $relativePath): object
    {
        return require base_path($relativePath);
    }

    /**
     * 현금영수증 마이그레이션 소스가 모듈 네임스페이스 클래스를 참조하지 않아 모듈 오토로더 없이도 실행된다.
     *
     * @scenario case=no_module_class_reference
     *
     * @effects migrations_have_no_module_class_reference
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 마이그레이션은_모듈_enum_클래스를_참조하지_않는다(): void
    {
        // `php artisan migrate` 는 모듈 오토로더 없이 실행될 수 있다 (신규 설치/모듈 비활성).
        foreach ($this->migrations() as $name => $path) {
            $source = file_get_contents(base_path($path));

            $this->assertStringNotContainsString(
                'Modules\\Sirsoft',
                $source,
                "{$name} 마이그레이션이 모듈 클래스를 참조하면 오토로더 미등록 시 실패한다",
            );
        }
    }

    /**
     * 발급/취소 이력 테이블을 down 으로 내린 뒤 up 하면 테이블과 16개 컬럼이 모두 다시 생성된다.
     *
     * @scenario case=table_round_trip
     *
     * @effects migration_up_down_up_round_trip_safe, cash_receipt_ledger_columns_restored_after_round_trip
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이력_테이블은_왕복_후에도_동일하게_생성된다(): void
    {
        $migration = $this->load($this->migrations()['cash_receipts']);

        $this->assertTrue(Schema::hasTable('ecommerce_order_cash_receipts'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('ecommerce_order_cash_receipts'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('ecommerce_order_cash_receipts'));

        foreach ([
            'order_id', 'order_payment_id', 'provider', 'receipt_key', 'transaction_type',
            'receipt_type', 'amount', 'tax_free_amount', 'identifier_masked', 'receipt_url',
            'issue_number', 'issue_status', 'error_code', 'error_message', 'issued_at', 'raw_response',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ecommerce_order_cash_receipts', $column),
                "컬럼 {$column} 이 존재해야 한다",
            );
        }
    }

    /**
     * 이력 테이블이 이미 있는 상태에서 up 을 다시 실행해도 "table already exists" 오류 없이 테이블이 유지된다.
     *
     * @scenario case=idempotent_rerun
     *
     * @effects migration_up_rerun_is_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이력_테이블_생성은_두_번_실행해도_안전하다(): void
    {
        $migration = $this->load($this->migrations()['cash_receipts']);

        // 이미 존재하는 상태에서 재실행 — 멱등 가드가 없으면 "table already exists" fatal
        $migration->up();

        $this->assertTrue(Schema::hasTable('ecommerce_order_cash_receipts'));
    }

    /**
     * 주문·주문옵션의 현금성 금액 컬럼(기본·다통화 4종)이 down 으로 사라지고 up 으로 되살아나며, up 재실행도 안전하다.
     *
     * @scenario case=column_round_trip
     *
     * @effects migration_up_down_up_round_trip_safe, migration_up_rerun_is_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 현금성_금액_컬럼은_왕복_후에도_동일하게_생성된다(): void
    {
        $migration = $this->load($this->migrations()['cash_equivalent']);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ecommerce_orders', 'total_cash_equivalent_amount'));
        $this->assertFalse(Schema::hasColumn('ecommerce_order_options', 'subtotal_cash_equivalent_amount'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('ecommerce_orders', 'total_cash_equivalent_amount'));
        $this->assertTrue(Schema::hasColumn('ecommerce_orders', 'mc_total_cash_equivalent_amount'));
        $this->assertTrue(Schema::hasColumn('ecommerce_order_options', 'subtotal_cash_equivalent_amount'));
        $this->assertTrue(Schema::hasColumn('ecommerce_order_options', 'mc_subtotal_cash_equivalent_amount'));

        // 멱등: 재실행해도 SQL error 없음
        $migration->up();
        $this->assertTrue(Schema::hasColumn('ecommerce_orders', 'total_cash_equivalent_amount'));
    }

    /**
     * 결제 테이블의 식별번호 암호문 컬럼이 down 으로 제거되고 up 으로 복원되며, up 재실행에도 그대로 남는다.
     *
     * @scenario case=column_round_trip
     *
     * @effects migration_up_down_up_round_trip_safe, migration_up_rerun_is_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 암호문_컬럼은_왕복_후에도_동일하게_생성된다(): void
    {
        $migration = $this->load($this->migrations()['identifier_encrypted']);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_encrypted'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_encrypted'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_encrypted'));
    }

    /**
     * 결제 테이블의 식별번호 종류 컬럼이 down 으로 제거되고 up 으로 복원되며, up 재실행에도 그대로 남는다.
     *
     * @scenario case=column_round_trip
     *
     * @effects migration_up_down_up_round_trip_safe, migration_up_rerun_is_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 식별번호_종류_컬럼은_왕복_후에도_동일하게_생성된다(): void
    {
        $migration = $this->load($this->migrations()['identifier_type']);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_type'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_type'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_type'));
    }

    /**
     * 이미 내려간 테이블·컬럼에 down 을 한 번 더 실행해도 예외 없이 끝나 부분 롤백 상태의 재롤백이 안전하다.
     *
     * @scenario case=defensive_down
     *
     * @effects migration_down_tolerates_missing_table
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function down_은_테이블이_없어도_예외를_던지지_않는다(): void
    {
        // 모듈 삭제 시 부분 롤백 상태에서 재롤백되는 경우
        $cashReceipts = $this->load($this->migrations()['cash_receipts']);
        $cashReceipts->down();
        $cashReceipts->down();

        $this->assertFalse(Schema::hasTable('ecommerce_order_cash_receipts'));

        // 컬럼 추가 마이그레이션도 동일
        $encrypted = $this->load($this->migrations()['identifier_encrypted']);
        $encrypted->down();
        $encrypted->down();

        $this->assertFalse(Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_encrypted'));
    }
}
