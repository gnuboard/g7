<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 플러그인 테이블 4종의 구조 시험 (information_schema 실측).
 *
 * 마이그레이션 up→down→up 왕복은 DDL 이 트랜잭션을 끊어 시험 격리를 깨므로 여기서 돌리지 않는다 — 구조는 적용된
 * 시험 DB 에서 재고, down() 은 테이블을 지우는 한 줄이라 코드 검토로 갈음한다(이력 문서에 기록).
 *
 * @since 1.0.0
 */
class SchemaTest extends PluginTestCase
{
    /** 테이블 => 필수 컬럼 */
    private const TABLES = [
        'social_login_providers' => ['key', 'driver', 'type', 'enabled', 'sort_order', 'label', 'client_id', 'client_secret', 'admin_key', 'email_missing_policy', 'email_trust', 'extra'],
        'social_accounts' => ['user_id', 'provider_key', 'provider_user_id', 'email', 'email_verified', 'access_token', 'refresh_token', 'profile_raw', 'linked_at'],
        'social_login_attempts' => ['provider_key', 'user_id', 'intent', 'outcome', 'error_code', 'created_at'],
        'social_login_webhook_events' => ['provider_key', 'event_key', 'event_type', 'provider_user_id', 'payload', 'status', 'received_at'],
    ];

    /**
     * 실제 테이블 이름(접두 포함)을 반환합니다.
     *
     * @param  string  $table  논리 테이블명
     * @return string 접두가 붙은 이름
     */
    private function physical(string $table): string
    {
        return DB::getTablePrefix().$table;
    }

    /**
     * 열 정보를 읽습니다.
     *
     * @param  string  $table  논리 테이블명
     * @return array<string, object> 컬럼명 => 행
     */
    private function columns(string $table): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, COLUMN_COMMENT, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->physical($table)]
        );

        return collect($rows)->keyBy('COLUMN_NAME')->all();
    }

    /**
     * 모든 컬럼에 한국어 설명(comment)이 있는지 확인한다.
     *
     * @scenario table=all
     * @effects all_columns_have_korean_comment
     * @return void 반환값 없음
     */
    public function test_every_column_has_a_comment(): void
    {
        foreach (self::TABLES as $table => $required) {
            $columns = $this->columns($table);
            $this->assertNotEmpty($columns, "{$table} 가 시험 DB 에 없다 — 플러그인 마이그레이션을 시험 DB 에 적용해야 한다");

            foreach ($required as $column) {
                $this->assertArrayHasKey($column, $columns, "{$table}.{$column} 없음");
            }

            foreach ($columns as $name => $row) {
                // timestamps() 의 created_at·updated_at 은 데이터베이스 가이드 예시와 같이 comment 를 두지 않는다.
                if (in_array($name, ['created_at', 'updated_at'], true) && $table !== 'social_login_attempts') {
                    continue;
                }
                $this->assertNotSame('', trim((string) $row->COLUMN_COMMENT), "{$table}.{$name} 에 comment 가 없다");
            }
        }
    }

    /**
     * 제공자 사용자 ID 컬럼이 대소문자를 구분하는 utf8mb4_bin 인지 확인한다.
     *
     * @scenario table=social_accounts|social_login_webhook_events
     * @effects provider_user_id_is_case_sensitive
     * @return void 반환값 없음
     */
    public function test_provider_user_id_uses_binary_collation(): void
    {
        $this->assertSame('utf8mb4_bin', $this->columns('social_accounts')['provider_user_id']->COLLATION_NAME);
        $this->assertSame('utf8mb4_bin', $this->columns('social_login_webhook_events')['provider_user_id']->COLLATION_NAME);
    }

    /**
     * 중복을 막는 UNIQUE 키 4개가 있는지 확인한다.
     *
     * @scenario table=all
     * @effects unique_keys_present
     * @return void 반환값 없음
     */
    public function test_unique_keys(): void
    {
        $unique = fn (string $table) => collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> \'PRIMARY\' GROUP BY INDEX_NAME',
            [$this->physical($table)]
        ))->pluck('cols')->all();

        $this->assertContains('key', $unique('social_login_providers'));
        $this->assertContains('provider_key,provider_user_id', $unique('social_accounts'));
        $this->assertContains('provider_key,user_id', $unique('social_accounts'));
        $this->assertContains('event_key', $unique('social_login_webhook_events'));
    }

    /**
     * 외래키가 연결 테이블 하나뿐이고 CASCADE 가 없는지 확인한다.
     *
     * @scenario table=social_accounts|social_login_attempts
     * @effects no_cascade_foreign_keys
     * @return void 반환값 없음
     */
    public function test_foreign_keys_have_no_cascade(): void
    {
        $rows = DB::select(
            'SELECT TABLE_NAME, DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?)',
            array_map(fn ($t) => $this->physical($t), array_keys(self::TABLES))
        );

        $this->assertCount(1, $rows, '외래키는 social_accounts.user_id 하나뿐이어야 한다(시도 기록은 통계 보존 때문에 FK 없음)');
        $this->assertSame($this->physical('social_accounts'), $rows[0]->TABLE_NAME);
        $this->assertNotSame('CASCADE', $rows[0]->DELETE_RULE);
        $this->assertNotSame('CASCADE', $rows[0]->UPDATE_RULE);
    }
}
