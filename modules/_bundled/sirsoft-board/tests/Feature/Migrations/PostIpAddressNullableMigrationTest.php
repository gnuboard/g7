<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * board_posts.ip_address nullable 마이그레이션 왕복 테스트 (이슈 #636 S5)
 *
 * 모듈 삭제(delete_data)·업데이트 재실행에서 up → down → up 이 깨지지 않아야 한다. 이 마이그레이션은
 * MODIFY COLUMN(DDL)이라 트랜잭션 격리가 무의미하다 — MySQL DDL 은 암묵 커밋이다. 그래서 이 클래스만
 * 트랜잭션을 열지 않고, 만든 행을 finally 에서 직접 지우며 마지막에 반드시 up 상태로 되돌린다.
 */
class PostIpAddressNullableMigrationTest extends ModuleTestCase
{
    private const MIGRATION = 'modules/_bundled/sirsoft-board/database/migrations/2026_09_23_000001_make_ip_address_nullable_in_board_posts_table.php';

    /**
     * DDL 이 암묵 커밋하므로 이 클래스는 트랜잭션을 열지 않는다.
     *
     * @return void 반환값 없음
     */
    public function beginDatabaseTransaction(): void {}

    /**
     * ip_address 컬럼의 NULL 허용 여부를 읽습니다.
     *
     * @return bool NULL 허용 여부
     */
    private function ipIsNullable(): bool
    {
        $prefix = DB::getTablePrefix();
        $column = DB::selectOne("SHOW COLUMNS FROM {$prefix}board_posts WHERE Field = 'ip_address'");

        return $column->Null === 'YES';
    }

    /**
     * 게시글 행 하나를 직접 넣습니다.
     *
     * @param  string|null  $ip  작성 IP
     * @return int 게시글 ID
     */
    private function insertPost(?string $ip): int
    {
        return DB::table('board_posts')->insertGetId([
            'board_id' => 0,
            'title' => 'migration probe',
            'content' => 'probe',
            'author_name' => 'probe',
            'ip_address' => $ip,
            'status' => 'published',
            'trigger_type' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * up 은 NULL 을 허용하고, down 은 NULL 을 자리표시값으로 채운 뒤 NOT NULL 로 되돌리며,
     * 다시 up 해도 깨지지 않는다. 기존 자리표시값의 정리는 마이그레이션이 아니라 1.2.0 업그레이드
     * 스텝의 몫이라(스키마 변경과 데이터 변환 분리) up 은 행 값을 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=ip, toggle=on, guest_mode=anonymize
     *
     * @effects board_post_ip_is_nullable
     */
    #[Test]
    public function migration_round_trips_without_touching_row_values(): void
    {
        $migration = require base_path(self::MIGRATION);
        $ids = [];

        try {
            $this->assertTrue($this->ipIsNullable(), '모듈 마이그레이션 적용 후 ip_address 는 nullable 이다');

            $ids[] = $placeholder = $this->insertPost('0.0.0.0');
            $ids[] = $erased = $this->insertPost(null);

            $migration->down();
            $this->assertFalse($this->ipIsNullable(), 'down 은 NOT NULL 로 되돌린다');
            $this->assertSame('0.0.0.0', DB::table('board_posts')->find($erased)->ip_address, 'NULL 행은 자리표시값으로 채워져야 되돌릴 수 있다');

            $migration->up();
            $this->assertTrue($this->ipIsNullable());
            $this->assertSame('0.0.0.0', DB::table('board_posts')->find($placeholder)->ip_address, 'up 은 행 값을 바꾸지 않는다 — 자리표시값 정리는 업그레이드 스텝의 몫');

            // 멱등: 재실행해도 SQL 오류가 없다
            $migration->up();
            $this->assertTrue($this->ipIsNullable());
        } finally {
            DB::table('board_posts')->whereIn('id', $ids)->delete();

            if (! $this->ipIsNullable()) {
                $migration->up();
            }
        }
    }
}
