<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * P1 표 3개의 스키마 — subject 대소문자 구분(utf8mb4_bin), UNIQUE 2, CASCADE 0, 모든 컬럼 한국어 comment.
 *
 * @since 1.0.0
 */
class SchemaTest extends PluginTestCase
{
    /** @var array<int, string> P1 표 */
    private const TABLES = ['sso_identity_providers', 'sso_accounts', 'sso_login_attempts'];

    /**
     * 접두를 붙인 실제 표 이름.
     *
     * @param  string  $table  표
     * @return string 실제 이름
     */
    private function physical(string $table): string
    {
        return DB::getTablePrefix().$table;
    }

    /**
     * 식별자 컬럼이 대소문자를 구분하는 collation 인지 확인한다.
     *
     * @scenario column=subject|scim_external_id
     *
     * @effects collation_utf8mb4_bin
     *
     * @return void 반환값 없음
     */
    public function test_identifier_columns_are_case_sensitive(): void
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)',
            [$this->physical('sso_accounts'), 'subject', 'scim_external_id'],
        );

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('utf8mb4_bin', $row->COLLATION_NAME, $row->COLUMN_NAME);
        }
    }

    /**
     * 연결 표의 UNIQUE 키 두 개를 확인한다.
     *
     * @scenario index=unique
     *
     * @effects idp_subject_and_idp_user_unique
     *
     * @return void 반환값 없음
     */
    public function test_unique_keys(): void
    {
        $names = collect(DB::select('SHOW INDEX FROM '.$this->physical('sso_accounts').' WHERE Non_unique = 0'))
            ->pluck('Key_name')->unique()->values()->all();

        $this->assertContains('sso_accounts_idp_subject_unique', $names);
        $this->assertContains('sso_accounts_idp_user_unique', $names);
    }

    /**
     * FK 가 CASCADE 삭제를 쓰지 않는지 확인한다.
     *
     * @scenario fk=delete_rule
     *
     * @effects no_cascade
     *
     * @return void 반환값 없음
     */
    public function test_foreign_keys_do_not_cascade(): void
    {
        $rules = DB::select(
            'SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)',
            array_map(fn ($t) => $this->physical($t), self::TABLES),
        );

        $this->assertNotEmpty($rules, 'sso_accounts 는 IdP 와 회원 FK 를 둔다');
        foreach ($rules as $rule) {
            $this->assertNotSame('CASCADE', $rule->DELETE_RULE, $rule->CONSTRAINT_NAME);
        }
    }

    /**
     * 모든 컬럼에 한국어 comment 가 있는지 확인한다.
     *
     * @scenario column=all
     *
     * @effects comment_present
     *
     * @return void 반환값 없음
     */
    public function test_every_column_has_a_comment(): void
    {
        foreach (self::TABLES as $table) {
            $missing = collect(DB::select(
                'SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$this->physical($table)],
            ))->filter(fn ($c) => trim((string) $c->COLUMN_COMMENT) === '' && ! in_array($c->COLUMN_NAME, ['created_at', 'updated_at'], true))
                ->pluck('COLUMN_NAME')->all();

            $this->assertSame([], $missing, $table);
        }
    }

    /**
     * 모든 시각 컬럼(`*_at`)에 모델 datetime cast 가 있는지 확인한다 — 없으면 API 가 시간대 없는 원 문자열을 내보내 화면이 UTC 를
     * 현지 시각으로 읽는다(F1 실측 — 다른 영역 마이그레이션이 더한 `periodic_checked_at` 이 9시간 어긋났다).
     *
     * @scenario column=all_timestamps, model=identity_provider|sso_account|sso_login_attempt
     *
     * @effects timestamp_columns_cast_to_datetime
     *
     * @return void 반환값 없음
     */
    public function test_every_timestamp_column_is_cast_to_datetime(): void
    {
        $models = ['sso_identity_providers' => new IdentityProvider, 'sso_accounts' => new SsoAccount, 'sso_login_attempts' => new SsoLoginAttempt];
        $missing = [];

        foreach ($models as $table => $model) {
            $columns = collect(DB::select(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME LIKE ?',
                [$this->physical($table), '%\_at'],
            ))->pluck('COLUMN_NAME');

            foreach ($columns as $column) {
                if (! $model->hasCast($column, ['datetime', 'immutable_datetime', 'timestamp']) && ! in_array($column, $model->getDates(), true)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
