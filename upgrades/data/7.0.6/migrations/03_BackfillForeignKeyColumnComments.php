<?php

namespace App\Upgrades\Data\V7_0_6\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 외래키 컬럼의 누락된 한국어 comment 를 채웁니다.
 *
 * 배경:
 *   `$table->foreignId('user_id')->constrained('users')->comment('사용자 ID')` 형태로 작성된
 *   컬럼은 comment 가 DB 에 생성되지 않았습니다. `constrained()` 가 컬럼 정의가 아니라 외래키
 *   정의를 돌려주기 때문에, 뒤에 이어 붙인 comment 가 외래키 쪽 속성이 되어 버립니다.
 *   마이그레이션 소스는 7.0.6 에서 전부 교정했지만, 이미 설치를 마친 사이트는 마이그레이션을
 *   다시 실행하지 않으므로 해당 컬럼의 설명이 비어 있는 상태로 남습니다. 이 스텝이 채웁니다.
 *
 * 멱등: 설명이 이미 있는 컬럼은 건드리지 않습니다. 재실행해도 결과가 같습니다.
 *
 * 안전:
 *   - 설명이 **비어 있을 때만** 채웁니다 — 운영자가 직접 넣어 둔 설명을 덮어쓰지 않습니다.
 *   - 컬럼의 자료형·NULL 허용·기본값·자동증가 속성을 현재 스키마에서 그대로 읽어 재적용하므로
 *     설명 외에는 아무 것도 바뀌지 않습니다.
 *   - MySQL 계열에서만 동작합니다(다른 DB 는 건너뜁니다).
 *
 * 실패 정책: 컬럼 단위로 실패를 흡수합니다. 한 컬럼이 실패해도 나머지를 계속 처리하며,
 * 설명 보정 실패가 업그레이드 전체를 막지 않습니다.
 *
 * 격리 원칙 (docs/extension/upgrade-step-guide.md §13):
 *   테이블·컬럼·설명 목록을 본 클래스에 **동결 상수**로 둡니다. 미래 버전에서 컬럼 설명이
 *   바뀌어도 이 스텝은 7.0.6 시점의 값을 기준으로 동작해야 하므로 마이그레이션 파일이나
 *   모델을 참조하지 않습니다.
 */
final class BackfillForeignKeyColumnComments implements DataMigration
{
    /**
     * 보정 대상 (7.0.6 시점 동결) — 테이블 => [컬럼 => 설명].
     *
     * 코어 소유 테이블만 담습니다. 모듈·플러그인 테이블은 각 확장이 자기 업그레이드 스텝에서
     * 처리합니다(확장 스키마는 확장이 소유).
     */
    private const TARGETS = [
        'activity_logs' => [
            'user_id' => '사용자 ID',
        ],
        'identity_message_templates' => [
            'definition_id' => '메시지 정의 ID (FK)',
            'updated_by' => '수정자 (사용자 삭제 시 NULL)',
        ],
        'identity_verification_logs' => [
            'user_id' => '사용자 탈퇴 시 NULL 로 유지 — 감사 이력 보존 (CASCADE 금지 규정 준수)',
        ],
        'language_packs' => [
            'installed_by' => '설치자 사용자 ID',
        ],
        'menus' => [
            'created_by' => '등록자 ID',
        ],
        'modules' => [
            'created_by' => '모듈 생성자 ID',
        ],
        'notification_logs' => [
            'recipient_user_id' => '수신자 회원 ID (회원인 경우)',
            'sender_user_id' => '발송자 회원 ID (null=시스템 자동)',
        ],
        'notification_templates' => [
            'definition_id' => '알림 정의 ID',
            'updated_by' => '수정자',
        ],
        'plugins' => [
            'created_by' => '플러그인 생성자 ID',
        ],
        'template_custom_translations' => [
            'created_by' => '생성자',
            'template_id' => '소속 템플릿 ID',
            'updated_by' => '수정자',
        ],
        'template_layout_attachments' => [
            'created_by' => '업로더 사용자 ID',
            'template_id' => '소속 템플릿 ID',
        ],
        'template_layout_extension_versions' => [
            'extension_id' => '레이아웃 확장 ID',
        ],
        'template_layout_extensions' => [
            'template_id' => '템플릿 ID (g7_templates 참조)',
        ],
        'template_layout_previews' => [
            'template_id' => '템플릿 ID',
        ],
        'template_layout_versions' => [
            'layout_id' => '레이아웃 ID',
        ],
        'template_layouts' => [
            'template_id' => '템플릿 ID',
        ],
        'user_consents' => [
            'user_id' => '사용자 ID',
        ],
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 식별자
     */
    public function name(): string
    {
        return 'BackfillForeignKeyColumnComments';
    }

    /**
     * 비어 있는 외래키 컬럼 설명을 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! $this->isMysql()) {
            $context->logger->info('[7.0.6] 컬럼 설명 보정 — MySQL 계열이 아니어서 건너뜁니다');

            return;
        }

        $filled = 0;
        $skipped = 0;
        $failed = 0;

        foreach (self::TARGETS as $table => $columns) {
            $prefixed = $context->table($table);

            foreach ($columns as $column => $comment) {
                try {
                    $meta = $this->columnMeta($prefixed, $column);

                    if ($meta === null) {
                        $skipped++;

                        continue;
                    }

                    if (trim((string) $meta->COLUMN_COMMENT) !== '') {
                        $skipped++;

                        continue;
                    }

                    DB::statement($this->buildModifyStatement($prefixed, $column, $meta, $comment));
                    $filled++;
                } catch (\Throwable $e) {
                    $failed++;
                    $context->logger->warning(sprintf(
                        '[7.0.6] 컬럼 설명 보정 실패 (계속 진행): %s.%s — %s',
                        $prefixed,
                        $column,
                        $e->getMessage(),
                    ));
                    Log::warning('7.0.6 BackfillForeignKeyColumnComments 컬럼 실패', [
                        'table' => $prefixed,
                        'column' => $column,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $context->logger->info(sprintf(
            '[7.0.6] 외래키 컬럼 설명 보정 — 채움 %d건 / 대상 아님 %d건 / 실패 %d건',
            $filled,
            $skipped,
            $failed,
        ));
    }

    /**
     * 현재 연결이 MySQL 계열인지 판정합니다.
     *
     * @return bool MySQL 계열이면 true
     */
    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * 컬럼의 현재 스키마 메타데이터를 조회합니다.
     *
     * @param  string  $table  프리픽스가 적용된 테이블명
     * @param  string  $column  컬럼명
     * @return object|null 컬럼 메타 (테이블/컬럼 부재 시 null)
     */
    private function columnMeta(string $table, string $column): ?object
    {
        $rows = DB::select(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        return $rows[0] ?? null;
    }

    /**
     * 설명만 덧붙이는 MODIFY COLUMN 문을 조립합니다.
     *
     * 자료형·NULL 허용·기본값·EXTRA 를 현재 값 그대로 다시 적어 넣어 설명 외의 변경을
     * 만들지 않습니다. 식별자는 백틱 이스케이프, 문자열 값은 PDO 인용을 사용합니다.
     *
     * @param  string  $table  프리픽스가 적용된 테이블명
     * @param  string  $column  컬럼명
     * @param  object  $meta  columnMeta() 결과
     * @param  string  $comment  넣을 설명
     * @return string 실행할 SQL
     */
    private function buildModifyStatement(string $table, string $column, object $meta, string $comment): string
    {
        $sql = sprintf(
            'ALTER TABLE %s MODIFY COLUMN %s %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column),
            $meta->COLUMN_TYPE,
        );

        $sql .= $meta->IS_NULLABLE === 'YES' ? ' NULL' : ' NOT NULL';

        // 외래키 컬럼은 기본값이 없는 것이 통례지만, 있다면 그대로 보존한다.
        if ($meta->COLUMN_DEFAULT !== null) {
            $sql .= ' DEFAULT '.$this->quoteValue((string) $meta->COLUMN_DEFAULT);
        }

        $extra = trim((string) $meta->EXTRA);
        if ($extra !== '' && stripos($extra, 'auto_increment') !== false) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql.' COMMENT '.$this->quoteValue($comment);
    }

    /**
     * 식별자를 백틱으로 감쌉니다.
     *
     * @param  string  $identifier  테이블/컬럼명
     * @return string 이스케이프된 식별자
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * 문자열 값을 SQL 리터럴로 인용합니다.
     *
     * @param  string  $value  값
     * @return string 인용된 리터럴
     */
    private function quoteValue(string $value): string
    {
        return DB::connection()->getPdo()->quote($value);
    }
}
