<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * 신규 설치 마이그레이션에 옛 메시지 표 생성이 없는지 정적으로 검사합니다 (#699 W7·B1).
 *
 * 본인인증 메시지(identity_message_*)와 7.0.0-beta.2 이후 쓰이지 않는 메일 표(mail_templates·mail_send_logs)는
 * 7.1.0 신규 설치에서 아예 만들어지지 않아야 한다. 기존 설치의 정리는 업그레이드 스텝(07·08)이 맡는다.
 * migrate:fresh 로 확인하지 않는다 — 로컬 MySQL 이 파티션 DDL 에서 죽는 환경이 있어 정적 검사로 잠근다.
 */
class NoLegacyMessageTableMigrationsTest extends TestCase
{
    /** @var array<int, string> 신규 설치에서 만들면 안 되는 표 */
    private const FORBIDDEN_TABLES = [
        'identity_message_definitions',
        'identity_message_templates',
        'mail_templates',
        'mail_send_logs',
    ];

    /**
     * database/migrations 어디에도 옛 표를 만드는(또는 고치는) 마이그레이션이 없다.
     *
     * @scenario surface=upgrade
     *
     * @effects fresh_install_has_no_legacy_message_tables
     *
     * @return void 반환값 없음
     */
    public function test_no_migration_creates_legacy_message_tables(): void
    {
        $root = dirname(__DIR__, 3).'/database/migrations';
        $files = glob($root.'/*.php');
        $this->assertNotEmpty($files, '마이그레이션 모집단이 비었다 — 경로가 끊겼다');

        $offenders = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::FORBIDDEN_TABLES as $table) {
                if (preg_match("/Schema::(create|table)\\(\\s*['\"]{$table}['\"]/", $source)) {
                    $offenders[] = basename($file).' → '.$table;
                }
            }
        }

        $this->assertSame([], $offenders, '옛 메시지 표를 만드는 마이그레이션이 남아 있다');
    }
}
