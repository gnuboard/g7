<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 현금영수증 관련 마이그레이션 왕복(up → down → up) 안전성 테스트
 *
 * 모듈 삭제 시 delete_data 옵션으로 롤백이 실행되므로 down() 이 방어적이어야 한다.
 * 또한 코어 업그레이드 중 부분 적용된 상태에서 재실행되어도 SQL error 가 없어야 한다(멱등).
 */
class CashReceiptMigrationRoundTripTest extends ModuleTestCase
{
    private const MIGRATION_DIR = 'modules/_bundled/sirsoft-ecommerce/database/migrations';

    /**
     * 마이그레이션 파일 경로 목록
     *
     * @return array<string, string>
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
     * 마이그레이션 파일을 로드해 인스턴스를 반환합니다.
     */
    private function load(string $relativePath): object
    {
        return require base_path($relativePath);
    }

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

    #[Test]
    public function 이력_테이블_생성은_두_번_실행해도_안전하다(): void
    {
        $migration = $this->load($this->migrations()['cash_receipts']);

        // 이미 존재하는 상태에서 재실행 — 멱등 가드가 없으면 "table already exists" fatal
        $migration->up();

        $this->assertTrue(Schema::hasTable('ecommerce_order_cash_receipts'));
    }

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
