<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 기존 테이블 삭제 시 db_prefix 필터링 회귀 테스트
 *
 * 회귀 시나리오:
 * - drop_tables 동의 시 cleanupExistingTablesSSE() 가 SHOW TABLES 로 조회한
 *   DB 안의 모든 테이블을 db_prefix 필터 없이 전부 DROP 했음.
 * - 같은 DB 를 다른 애플리케이션과 공유하거나 다른 prefix 로 재설치할 때
 *   G7 과 무관한 테이블까지 삭제되는 데이터 손실 결함.
 *
 * 수정: filterTablesByPrefix() 가 입력한 prefix 로 시작하는 테이블만 선별하고,
 *       빈 prefix 는 전체 삭제 위험 방어로 빈 목록을 반환한다.
 */
class InstallerDropTablePrefixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3) . '/public/install/includes/functions.php';
    }

    public function test_filters_only_tables_with_default_prefix(): void
    {
        $tables = ['g7_users', 'g7_migrations', 'wp_posts', 'legacy_orders'];

        $result = filterTablesByPrefix($tables, 'g7_');

        $this->assertSame(['g7_users', 'g7_migrations'], $result);
    }

    public function test_preserves_non_prefixed_tables(): void
    {
        $tables = ['wp_posts', 'wp_users', 'legacy_orders'];

        $result = filterTablesByPrefix($tables, 'g7_');

        $this->assertSame([], $result, 'prefix 가 다른 테이블은 삭제 대상에서 제외되어야 한다');
    }

    public function test_supports_custom_prefix(): void
    {
        $tables = ['shop_users', 'shop_products', 'g7_users', 'other_table'];

        $result = filterTablesByPrefix($tables, 'shop_');

        $this->assertSame(['shop_users', 'shop_products'], $result);
    }

    public function test_empty_prefix_returns_no_tables(): void
    {
        $tables = ['g7_users', 'wp_posts', 'anything'];

        $result = filterTablesByPrefix($tables, '');

        $this->assertSame(
            [],
            $result,
            '빈 prefix 는 전체 일치로 모든 테이블이 삭제되는 위험이 있어 빈 목록을 반환해야 한다'
        );
    }

    public function test_empty_table_list_returns_empty(): void
    {
        $result = filterTablesByPrefix([], 'g7_');

        $this->assertSame([], $result);
    }

    public function test_returns_sequentially_indexed_array(): void
    {
        // array_filter 가 원본 키를 보존하지 않도록 재인덱싱되어야 한다 (foreach DROP 안정성)
        $tables = ['other_a', 'g7_users', 'other_b', 'g7_posts'];

        $result = filterTablesByPrefix($tables, 'g7_');

        $this->assertSame([0, 1], array_keys($result));
        $this->assertSame(['g7_users', 'g7_posts'], $result);
    }

    public function test_prefix_must_match_at_start_not_substring(): void
    {
        // 중간에 prefix 문자열을 포함하는 테이블은 삭제 대상이 아니다
        $tables = ['my_g7_data', 'g7_users'];

        $result = filterTablesByPrefix($tables, 'g7_');

        $this->assertSame(['g7_users'], $result);
    }
}
