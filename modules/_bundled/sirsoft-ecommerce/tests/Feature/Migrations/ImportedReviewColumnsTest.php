<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원·주문 없는 후기를 담는 후기 표 스키마 잠금
 *
 * 스키마를 **읽기만** 한다(`Schema::getColumns`/`getForeignKeys`) — DDL 을 돌리면 MySQL 이 암묵 커밋해 테스트
 * 트랜잭션이 깨지므로 down 왕복은 마이그레이션 파일의 가드로 대신한다(기존 스키마 테스트와 같은 방식).
 */
class ImportedReviewColumnsTest extends ModuleTestCase
{
    private const TABLE = 'ecommerce_product_reviews';

    /**
     * 컬럼 정의를 이름으로 찾습니다.
     *
     * @param  string  $column  컬럼명
     * @return array<string, mixed>|null 컬럼 정의
     */
    private function columnOf(string $column): ?array
    {
        foreach (Schema::getColumns(self::TABLE) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * 주문 옵션·작성자는 NULL 허용이고 작성자 표시 이름 컬럼이 있는지 확인합니다.
     *
     * @effects imported_review_columns_nullable_with_author_name
     *
     * @return void 반환값 없음
     */
    public function test_member_and_order_columns_are_nullable_and_author_name_exists(): void
    {
        $orderOption = $this->columnOf('order_option_id');
        $this->assertNotNull($orderOption);
        $this->assertTrue($orderOption['nullable'], 'order_option_id 가 NOT NULL 이면 주문 없는 후기를 넣을 수 없습니다.');

        $user = $this->columnOf('user_id');
        $this->assertNotNull($user);
        $this->assertTrue($user['nullable'], 'user_id 가 NOT NULL 이면 회원 없는 후기를 넣을 수 없습니다.');

        $author = $this->columnOf('author_name');
        $this->assertNotNull($author, 'author_name 컬럼이 없습니다.');
        $this->assertTrue($author['nullable']);
        $this->assertStringContainsString('varchar(50)', strtolower((string) ($author['type'] ?? '')));
        $this->assertNotSame('', (string) ($author['comment'] ?? ''), '컬럼 comment 가 비어 있습니다.');
    }

    /**
     * 외래키는 NULL 허용으로 바꾼 뒤에도 그대로 남아 있는지 확인합니다.
     *
     * @effects imported_review_columns_nullable_with_author_name
     *
     * @return void 반환값 없음
     */
    public function test_foreign_keys_are_kept(): void
    {
        $columns = collect(Schema::getForeignKeys(self::TABLE))
            ->flatMap(fn (array $fk) => $fk['columns'] ?? [])
            ->all();

        $this->assertContains('order_option_id', $columns);
        $this->assertContains('user_id', $columns);
    }

    /**
     * 마이그레이션 down 이 존재 확인 뒤 지우고, NULL 행이 있으면 NOT NULL 로 되돌리지 않는지 확인합니다.
     *
     * @effects imported_review_columns_nullable_with_author_name
     *
     * @return void 반환값 없음
     */
    public function test_migration_down_is_guarded(): void
    {
        $files = glob(base_path('modules/_bundled/sirsoft-ecommerce/database/migrations/*_update_imported_review_fields_in_ecommerce_product_reviews_table.php')) ?: [];
        $this->assertCount(1, $files);

        $source = (string) file_get_contents($files[0]);
        $down = substr($source, (int) strpos($source, 'public function down'));

        $this->assertStringContainsString('Schema::hasColumn(', $down);
        $this->assertStringContainsString('dropColumn(', $down);
        $this->assertStringContainsString('whereNull(', $down, 'NULL 행이 남아 있으면 NOT NULL 로 되돌리면 안 됩니다.');
    }
}
