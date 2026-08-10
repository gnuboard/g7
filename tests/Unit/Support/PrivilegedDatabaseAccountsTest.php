<?php

namespace Tests\Unit\Support;

use App\Support\PrivilegedDatabaseAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DB 최고권한 계정 판정 SSoT 단위 테스트
 *
 * 인스톨러와 코어가 같은 판정을 내려야 하므로, 이 클래스는 Laravel 부트 없이
 * 동작해야 한다. 본 테스트가 `PHPUnit\Framework\TestCase` 를 직접 상속하는 것은
 * 그 "의존성 0" 속성 자체를 검증하기 위함이다 (Tests\TestCase 사용 금지).
 *
 * 검증 축:
 *  - 계정 종류: 최고권한(root/postgres/sa/sys/system) / 일반 / 빈 값 / null
 *  - 표기 변형: 대문자 / 앞뒤 공백 (우회 차단)
 *  - isBlocked 와 isUsable 의 책임 분리 (빈 값은 blocked 아님, 그러나 usable 도 아님)
 */
class PrivilegedDatabaseAccountsTest extends TestCase
{
    /**
     * 최고권한 계정 판정 — 대문자·앞뒤 공백 변형까지 정규화해 차단한다.
     *
     * @scenario account_kind=privileged, enforcement_point=judgement_ssot, install_state=completed
     *
     * @effects privileged_account_detected_case_and_whitespace_insensitive
     * @effects admin_account_not_blocked
     */
    #[DataProvider('blockedProvider')]
    public function test_is_blocked_detects_privileged_accounts(?string $username, bool $expected): void
    {
        $this->assertSame(
            $expected,
            PrivilegedDatabaseAccounts::isBlocked($username),
            sprintf('isBlocked(%s) 판정이 기대와 다릅니다.', var_export($username, true))
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function blockedProvider(): array
    {
        return [
            'root 차단' => ['root', true],
            'postgres 차단' => ['postgres', true],
            'sa 차단' => ['sa', true],
            'sys 차단' => ['sys', true],
            'system 차단' => ['system', true],
            '대문자 ROOT 차단' => ['ROOT', true],
            '혼합 대소문자 RoOt 차단' => ['RoOt', true],
            '앞뒤 공백 " root " 차단' => [' root ', true],
            '탭 문자 포함 차단' => ["\troot\n", true],
            '일반 계정 g7user 통과' => ['g7user', false],
            'admin 은 차단 대상 아님 (오차단 방지)' => ['admin', false],
            'root 를 포함한 다른 이름 통과' => ['rootuser', false],
            'g7_root 통과' => ['g7_root', false],
            '빈 문자열은 blocked 아님' => ['', false],
            '공백만 있는 문자열은 blocked 아님' => ['   ', false],
            'null 은 blocked 아님' => [null, false],
        ];
    }

    /**
     * 사용 가능 여부 판정 — 빈 값(설정 누락)과 정상 계정을 구분한다.
     *
     * @scenario account_kind=empty, enforcement_point=judgement_ssot, install_state=completed
     *
     * @effects empty_username_rejected_as_unusable
     */
    #[DataProvider('usableProvider')]
    public function test_is_usable_rejects_privileged_and_empty(?string $username, bool $expected): void
    {
        $this->assertSame(
            $expected,
            PrivilegedDatabaseAccounts::isUsable($username),
            sprintf('isUsable(%s) 판정이 기대와 다릅니다.', var_export($username, true))
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function usableProvider(): array
    {
        return [
            '일반 계정 g7user 사용 가능' => ['g7user', true],
            'admin 사용 가능 (오차단 방지)' => ['admin', true],
            'rootuser 사용 가능' => ['rootuser', true],
            '숫자 문자열 0 도 유효한 계정명' => ['0', true],
            'root 사용 불가' => ['root', false],
            'ROOT 사용 불가' => ['ROOT', false],
            ' root  사용 불가' => [' root ', false],
            'postgres 사용 불가' => ['postgres', false],
            '빈 문자열 사용 불가 (설정 누락)' => ['', false],
            '공백만 있는 문자열 사용 불가' => ['   ', false],
            'null 사용 불가 (설정 누락)' => [null, false],
        ];
    }

    /**
     * 목록이 상수 1곳에서만 관리되는지 — 판정이 BLOCKED 상수와 어긋나면 실패한다.
     *
     * @scenario account_kind=normal, enforcement_point=judgement_ssot, install_state=completed
     *
     * @effects normal_username_accepted
     */
    public function test_every_blocked_constant_entry_is_actually_blocked(): void
    {
        $this->assertNotEmpty(PrivilegedDatabaseAccounts::BLOCKED);

        foreach (PrivilegedDatabaseAccounts::BLOCKED as $account) {
            $this->assertSame(
                strtolower($account),
                $account,
                sprintf('BLOCKED 항목 "%s" 는 소문자로 정의되어야 비교가 성립합니다.', $account)
            );
            $this->assertTrue(PrivilegedDatabaseAccounts::isBlocked($account));
            $this->assertFalse(PrivilegedDatabaseAccounts::isUsable($account));
        }
    }

    /**
     * `admin` 은 차단 대상이 아니다.
     *
     * 목록에 `admin` 을 넣으면 일반 권한으로 운영 중인 `admin` 계정 사용자가
     * 업그레이드 직후 에러 페이지에 갇힌다. 이 오차단을 구조적으로 고정한다.
     *
     * @effects admin_account_not_blocked
     */
    public function test_admin_is_not_a_blocked_account(): void
    {
        $this->assertNotContains('admin', PrivilegedDatabaseAccounts::BLOCKED);
        $this->assertFalse(PrivilegedDatabaseAccounts::isBlocked('admin'));
        $this->assertTrue(PrivilegedDatabaseAccounts::isUsable('admin'));
        $this->assertTrue(PrivilegedDatabaseAccounts::isUsable('ADMIN'));
    }

    /**
     * 인스톨러가 Laravel 오토로드 없이 `require_once` 로 로드할 수 있어야 하므로,
     * 소스에 프레임워크 심볼 참조가 없어야 한다.
     */
    public function test_source_has_no_framework_dependency(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/Support/PrivilegedDatabaseAccounts.php'
        );

        $this->assertIsString($source);

        foreach (['use Illuminate', 'Illuminate\\', 'config(', 'app(', '__(', 'trans('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf('인스톨러 공용 클래스에 프레임워크 의존("%s")이 포함됐습니다.', $forbidden)
            );
        }
    }
}
