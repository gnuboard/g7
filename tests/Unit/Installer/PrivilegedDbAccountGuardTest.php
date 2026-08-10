<?php

namespace Tests\Unit\Installer;

use App\Support\PrivilegedDatabaseAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 DB 최고권한 계정 차단 회귀 테스트
 *
 * 배경: 인스톨러에는 사용자명 검증이 전무했고, `.env.example` fallback 기본값이
 * `DB_WRITE_USERNAME=root` 였다. 그 결과 root 로 설치하면 설치는 성공하지만
 * 이후 코어 부팅이 확장 로딩 전체를 스킵해 깨진 화면만 남았다.
 * 설치 단계에서 미리 차단하는지 보장한다.
 *
 * 검증 축:
 *  - Step 3 폼 POST 검증: Write DB / Read DB 양쪽, 최고권한 / 빈 값 / 정상
 *  - 인스톨러가 코어와 같은 판정 SSoT 를 공유하는지 (목록 이원화 차단)
 *  - 다국어 키 존재 및 :username 치환 동작 (ko/en)
 */
class PrivilegedDbAccountGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 3));
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/functions.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/request-handler.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // lang() 은 전역 $translations 를 읽는다 (functions.php:316).
        $GLOBALS['translations'] = require dirname(__DIR__, 3).'/public/install/lang/ko.php';
    }

    /**
     * config.php 가 코어의 판정 SSoT 클래스를 로드해야 한다.
     * 인스톨러가 자체 목록을 갖게 되면 코어와 판정이 어긋난다.
     *
     * @effects installer_and_core_share_the_same_judgement_class
     */
    public function test_installer_loads_shared_judgement_class(): void
    {
        $this->assertTrue(
            class_exists(PrivilegedDatabaseAccounts::class),
            'config.php 가 PrivilegedDatabaseAccounts 를 로드하지 않았습니다.'
        );
    }

    /**
     * Step 3 폼 POST 에서 Write DB 최고권한 계정이 거부되어야 한다.
     *
     * @scenario account_kind=privileged, enforcement_point=installer_step3_write, install_state=not_completed
     *
     * @effects installer_step3_post_rejects_privileged_username
     */
    #[DataProvider('privilegedUsernameProvider')]
    public function test_step3_rejects_privileged_write_username(string $username): void
    {
        $errors = [];
        validateDbUsername($username, 'db_write_username', $errors);

        $this->assertArrayHasKey(
            'db_write_username',
            $errors,
            sprintf('사용자명 "%s" 이 거부되지 않았습니다.', $username)
        );
    }

    /**
     * Read DB 도 동일하게 차단되어야 한다 (Write 만 막으면 Read 로 우회 가능).
     *
     * @scenario account_kind=privileged, enforcement_point=installer_step3_read, install_state=not_completed
     *
     * @effects installer_step3_post_rejects_privileged_username
     */
    #[DataProvider('privilegedUsernameProvider')]
    public function test_step3_rejects_privileged_read_username(string $username): void
    {
        $errors = [];
        validateDbUsername($username, 'db_read_username', $errors);

        $this->assertArrayHasKey('db_read_username', $errors);
    }

    /**
     * 빈 사용자명(설정 누락)도 Write DB 에서 거부되어야 한다.
     *
     * @scenario account_kind=empty, enforcement_point=installer_step3_write, install_state=not_completed
     *
     * @effects installer_step3_post_rejects_empty_username
     */
    #[DataProvider('emptyUsernameProvider')]
    public function test_step3_rejects_empty_write_username(string $username): void
    {
        $errors = [];
        validateDbUsername($username, 'db_write_username', $errors);

        $this->assertArrayHasKey('db_write_username', $errors);
    }

    /**
     * 빈 사용자명은 Read DB 에서도 거부되어야 한다.
     *
     * @scenario account_kind=empty, enforcement_point=installer_step3_read, install_state=not_completed
     *
     * @effects installer_step3_post_rejects_empty_username
     */
    #[DataProvider('emptyUsernameProvider')]
    public function test_step3_rejects_empty_read_username(string $username): void
    {
        $errors = [];
        validateDbUsername($username, 'db_read_username', $errors);

        $this->assertArrayHasKey('db_read_username', $errors);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function privilegedUsernameProvider(): array
    {
        return [
            'root' => ['root'],
            'postgres' => ['postgres'],
            'sa' => ['sa'],
            'sys' => ['sys'],
            'system' => ['system'],
            '대문자 ROOT (우회 차단)' => ['ROOT'],
            '앞뒤 공백 " root " (우회 차단)' => [' root '],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function emptyUsernameProvider(): array
    {
        return [
            '빈 문자열' => [''],
            '공백만' => ['   '],
        ];
    }

    /**
     * 정상 계정은 Write DB 에서 통과해야 한다 — 특히 `admin` 오차단 방지.
     *
     * @scenario account_kind=normal, enforcement_point=installer_step3_write, install_state=not_completed
     *
     * @effects installer_step3_post_accepts_normal_username
     */
    #[DataProvider('acceptedUsernameProvider')]
    public function test_step3_accepts_normal_write_username(string $username): void
    {
        $errors = [];
        validateDbUsername($username, 'db_write_username', $errors);

        $this->assertSame(
            [],
            $errors,
            sprintf('정상 사용자명 "%s" 이 잘못 거부됐습니다.', $username)
        );
    }

    /**
     * 정상 계정은 Read DB 에서도 통과해야 한다 (과잉 차단 방지).
     *
     * @scenario account_kind=normal, enforcement_point=installer_step3_read, install_state=not_completed
     *
     * @effects installer_step3_post_accepts_normal_username
     */
    #[DataProvider('acceptedUsernameProvider')]
    public function test_step3_accepts_normal_read_username(string $username): void
    {
        $errors = [];
        validateDbUsername($username, 'db_read_username', $errors);

        $this->assertSame([], $errors);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedUsernameProvider(): array
    {
        return [
            'g7user' => ['g7user'],
            'admin (일반 권한 계정 오차단 방지)' => ['admin'],
            'rootuser' => ['rootuser'],
            'g7_root' => ['g7_root'],
            'my_site_db' => ['my_site_db'],
        ];
    }

    /**
     * 빈 값과 최고권한 계정은 서로 다른 메시지여야 한다 — 운영자가 원인을 구분해야 한다.
     *
     * @effects empty_and_privileged_produce_distinct_messages
     */
    public function test_empty_and_privileged_produce_different_messages(): void
    {
        $emptyErrors = [];
        validateDbUsername('', 'db_write_username', $emptyErrors);

        $privilegedErrors = [];
        validateDbUsername('root', 'db_write_username', $privilegedErrors);

        $this->assertNotSame(
            $emptyErrors['db_write_username'],
            $privilegedErrors['db_write_username']
        );
    }

    /**
     * 차단 메시지는 입력한 계정명을 치환해 보여줘야 한다 (:username).
     *
     * @effects installer_message_substitutes_username_and_offers_alternative
     */
    public function test_privileged_message_substitutes_username(): void
    {
        $errors = [];
        validateDbUsername('postgres', 'db_write_username', $errors);

        $message = $errors['db_write_username'];

        $this->assertStringContainsString('postgres', $message);
        $this->assertStringNotContainsString(':username', $message, '치환되지 않은 플레이스홀더가 남아 있습니다.');
    }

    /**
     * 차단만 하고 해결책이 없으면 사용자가 진행 불가 상태에 갇힌다.
     * ko/en 양쪽 문구가 대안(전용 계정 생성)을 제시하는지 확인한다.
     */
    #[DataProvider('localeProvider')]
    public function test_message_offers_an_alternative(string $locale, string $needle): void
    {
        $translations = require dirname(__DIR__, 3)."/public/install/lang/{$locale}.php";

        $this->assertArrayHasKey('error_db_username_privileged', $translations);
        $this->assertStringContainsString($needle, $translations['error_db_username_privileged']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function localeProvider(): array
    {
        return [
            'ko' => ['ko', '전용 데이터베이스 사용자'],
            'en' => ['en', 'dedicated database user'],
        ];
    }

    /**
     * OPcache 관련 다국어 키가 ko/en 양쪽에 존재해야 한다 (요구사항 카드 렌더용).
     *
     * @effects installer_opcache_lang_keys_exist_in_ko_and_en
     */
    #[DataProvider('opcacheLocaleProvider')]
    public function test_opcache_lang_keys_exist(string $locale): void
    {
        $translations = require dirname(__DIR__, 3)."/public/install/lang/{$locale}.php";

        foreach (['opcache', 'opcache_enabled', 'opcache_disabled_short', 'opcache_disabled_warning', 'opcache_unknown'] as $key) {
            $this->assertArrayHasKey($key, $translations, sprintf('%s 로케일에 %s 키가 없습니다.', $locale, $key));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function opcacheLocaleProvider(): array
    {
        return [
            'ko' => ['ko'],
            'en' => ['en'],
        ];
    }
}
