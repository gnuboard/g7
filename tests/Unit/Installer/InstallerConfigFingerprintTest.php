<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * DB 연결설정 HMAC 지문 회귀 테스트.
 *
 * DB 테스트를 통과한 연결설정과 Step 3 에 제출한 연결설정이 다른데도 통과하던 결함(검사 대상과
 * 사용 대상의 불일치)을 막는 지문의 정규화·민감도를 고정한다.
 */
class InstallerConfigFingerprintTest extends TestCase
{
    /** 지문 키 (세션 소유 토큰 자리) */
    private const KEY = 'fingerprint-test-key';

    /**
     * 순수 함수 로드.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/public/install/includes/installer-ownership.php';
    }

    /**
     * 기준 write 연결설정.
     *
     * @return array<string, string> 연결설정
     */
    private function writeConn(): array
    {
        return [
            'host' => 'db.internal',
            'port' => '3306',
            'database' => 'g7',
            'username' => 'g7_user',
            'password' => 'pw-1',
            'prefix' => 'g7_',
        ];
    }

    /**
     * 같은 설정·같은 키면 지문이 같다.
     *
     * @effects step3_rejects_config_changed_after_db_test
     *
     * @return void 반환값 없음
     */
    public function test_same_connection_yields_same_fingerprint(): void
    {
        $this->assertSame(
            installer_db_fingerprint($this->writeConn(), self::KEY),
            installer_db_fingerprint($this->writeConn(), self::KEY)
        );
    }

    /**
     * 연결설정 어느 항목이 바뀌어도 지문이 달라진다 (특히 비밀번호).
     *
     * @effects step3_rejects_config_changed_after_db_test
     *
     * @return void 반환값 없음
     */
    public function test_any_connection_field_change_changes_fingerprint(): void
    {
        $base = installer_db_fingerprint($this->writeConn(), self::KEY);

        foreach (['host' => 'attacker.example', 'port' => '3307', 'database' => 'other', 'username' => 'other_user', 'password' => 'pw-2', 'prefix' => 'x_'] as $field => $value) {
            $changed = $this->writeConn();
            $changed[$field] = $value;
            $this->assertNotSame($base, installer_db_fingerprint($changed, self::KEY), "{$field} 변경이 지문에 반영돼야 한다");
        }
    }

    /**
     * 키(세션 소유 토큰)가 다르면 지문이 다르다 — 다른 세션의 지문을 재사용할 수 없다.
     *
     * @effects step3_rejects_config_changed_after_db_test
     *
     * @return void 반환값 없음
     */
    public function test_different_key_changes_fingerprint(): void
    {
        $this->assertNotSame(
            installer_db_fingerprint($this->writeConn(), self::KEY),
            installer_db_fingerprint($this->writeConn(), 'other-session-key')
        );
    }

    /**
     * 빈 접두사는 'g7_' 로, 빈 host/port 는 기본값으로 정규화된다 — 프론트 DB 테스트
     * (`db_prefix || 'g7_'`, 서버 host/port 기본값) 와 같은 규칙이라야 정상 제출이 거부되지 않는다.
     *
     * @effects fingerprint_normalization_matches_frontend
     *
     * @return void 반환값 없음
     */
    public function test_normalization_matches_frontend_defaults(): void
    {
        $withDefaults = $this->writeConn();
        $withDefaults['prefix'] = 'g7_';
        $withDefaults['host'] = 'localhost';
        $withDefaults['port'] = '3306';

        $blank = $withDefaults;
        $blank['prefix'] = '';
        $blank['host'] = '';
        $blank['port'] = '';

        $this->assertSame(
            installer_db_fingerprint($withDefaults, self::KEY),
            installer_db_fingerprint($blank, self::KEY)
        );
    }

    /**
     * use_read_db=false 면 폼에 남은 read 값은 지문 대상이 아니다 (이슈 #63 회귀 가드).
     *
     * @effects fingerprint_ignores_read_when_read_db_disabled
     *
     * @return void 반환값 없음
     */
    public function test_read_connection_ignored_when_read_db_disabled(): void
    {
        $form = [
            'db_write_host' => 'w', 'db_write_port' => '3306', 'db_write_database' => 'd',
            'db_write_username' => 'u', 'db_write_password' => 'p', 'db_prefix' => 'g7_',
            'use_read_db' => false,
            'db_read_host' => 'stale-read-host', 'db_read_password' => 'stale',
        ];

        $this->assertNull(installer_step3_db_connections($form)['read']);

        $form['use_read_db'] = true;
        $read = installer_step3_db_connections($form)['read'];
        $this->assertSame('stale-read-host', $read['host']);
        $this->assertArrayNotHasKey('prefix', $read, 'read 지문에는 접두사가 없다 (DB 테스트 read 입력과 동일)');
    }

    /**
     * Step 3 폼에서 꺼낸 write 연결은 DB 테스트가 지문을 만든 입력과 같은 항목을 가진다.
     *
     * @effects fingerprint_normalization_matches_frontend
     *
     * @return void 반환값 없음
     */
    public function test_step3_write_connection_matches_db_test_shape(): void
    {
        $form = [
            'db_write_host' => 'db.internal', 'db_write_port' => '3306', 'db_write_database' => 'g7',
            'db_write_username' => 'g7_user', 'db_write_password' => 'pw-1', 'db_prefix' => 'g7_',
        ];

        $this->assertSame(
            installer_db_fingerprint($this->writeConn(), self::KEY),
            installer_db_fingerprint(installer_step3_db_connections($form)['write'], self::KEY)
        );
    }
}
