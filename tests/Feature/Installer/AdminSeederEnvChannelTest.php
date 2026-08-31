<?php

namespace Tests\Feature\Installer;

use Database\Seeders\AdminUserSeeder;
use Tests\TestCase;

/**
 * 관리자 시더가 인스톨러의 환경변수를 **3통로**로 읽는지 고정한다 (13차 환경#2·#3 / 18차 게이트 신설).
 *
 * ## 왜 이 검사가 필요한가
 *
 * 값을 넣는 쪽은 인스톨러의 `installerPutEnv()` 이고, 그 함수는 `$_ENV`/`$_SERVER` 를
 * **항상** 채운 뒤 `putenv` 는 **가용할 때만** 부른다 — `exec` 를 막는 호스팅은 `putenv` 도
 * 대개 함께 막기 때문이다. 즉 무CLI 환경에서 `$_ENV`/`$_SERVER` 는 **유일한 통로**이고
 * `getenv()` 는 `false` 를 돌려준다.
 *
 * 소비처가 `getenv()` 만 보면 이 시더는 「환경변수가 설정되지 않았습니다」로 죽고
 * **관리자 계정이 만들어지지 않는다** — in-process 설치의 마지막 단계에서, 그때까지
 * 전부 성공한 채로. 오류 화면에는 시더 예외만 남아 원인이 `putenv` 차단이라는 것이
 * 어디에도 드러나지 않는다.
 *
 * ## 왜 18차에 신설했는가
 *
 * 13차가 이 전환을 손으로 수행했는데 **회귀 장치가 0** 이었다. 실행 축 정적 룰 3종은
 * 전부 `appliesTo` 가 `app/**` + `public/install/**` 이라 `database/**` 를 보지 않았고,
 * producer 쪽 검사(`test_seed_task_injects_admin_env_in_same_request_and_purges_after`)는
 * 넣는 쪽만 본다. 실제로 18차 세션에서 이 파일이 우발적으로 원복됐을 때 인스톨러 테스트
 * 691건이 전부 green 이었다 — 아무것도 울지 않았다.
 *
 * 정적 룰 쪽 사각도 함께 닫았지만(모집단에 `database/**` 추가), 그 룰은 「무가드 호출」만
 * 본다. 「$_ENV 를 먼저 보는가」는 행위라서 이 검사가 소유한다.
 */
class AdminSeederEnvChannelTest extends TestCase
{
    /** @var array<string, string|false> 원래 값 복원용 */
    private array $originalEnv = [];

    /** @var array<int, string> 이 검사가 다루는 키 */
    private const KEYS = [
        'INSTALLER_ADMIN_NAME',
        'INSTALLER_ADMIN_EMAIL',
        'INSTALLER_ADMIN_PASSWORD',
        'INSTALLER_ADMIN_LANGUAGE',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);

            // `putenv` 가 막힌 환경을 재현한다 — 그 환경에서 `getenv()` 는 값을 못 준다.
            // 프로세스 환경에 남아 있으면 `getenv()` 폴백이 우연히 성공해 검사가 무의미해진다.
            if (function_exists('putenv')) {
                putenv($key);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);

            if ($this->originalEnv[$key] !== null) {
                $_ENV[$key] = $this->originalEnv[$key];
            }
        }

        parent::tearDown();
    }

    /**
     * @effects installer_admin_seeder_reads_env_without_putenv
     */
    public function test_seeder_reads_admin_credentials_from_env_superglobal(): void
    {
        // `installerPutEnv()` 가 putenv 없이 남기는 상태 그대로
        $_ENV['INSTALLER_ADMIN_NAME'] = '관리자';
        $_ENV['INSTALLER_ADMIN_EMAIL'] = 'admin@example.test';
        $_ENV['INSTALLER_ADMIN_PASSWORD'] = 'S3cret!Passw0rd';
        $_ENV['INSTALLER_ADMIN_LANGUAGE'] = 'en';

        $this->assertFalse(
            function_exists('getenv') ? getenv('INSTALLER_ADMIN_EMAIL') : false,
            '전제 조건 실패 — 프로세스 환경에 값이 남아 있으면 이 검사는 아무것도 증명하지 못한다'
        );

        $seeder = new AdminUserSeeder;
        $resolved = $this->resolvedAdminUser($seeder);

        $this->assertSame('관리자', $resolved['name']);
        $this->assertSame('admin@example.test', $resolved['email'], 'putenv 없이 전달된 이메일을 읽지 못했다 — 관리자 계정이 만들어지지 않는다');
        $this->assertSame('S3cret!Passw0rd', $resolved['password']);
        $this->assertSame('en', $resolved['language'], '관리자 언어가 조용히 기본값으로 떨어졌다 (§7 R2)');
    }

    /**
     * @effects installer_admin_seeder_reads_env_without_putenv
     */
    public function test_seeder_reads_admin_credentials_from_server_superglobal(): void
    {
        // `installerPutEnv()` 는 두 슈퍼전역을 함께 채운다. 호스팅의 `variables_order` 설정에
        // 따라 한쪽만 살아남는 배치가 있으므로 두 통로 모두 유효해야 한다.
        $_SERVER['INSTALLER_ADMIN_EMAIL'] = 'ops@example.test';
        $_SERVER['INSTALLER_ADMIN_PASSWORD'] = 'An0ther!Secret';

        $seeder = new AdminUserSeeder;
        $resolved = $this->resolvedAdminUser($seeder);

        $this->assertSame('ops@example.test', $resolved['email']);
        $this->assertSame('An0ther!Secret', $resolved['password']);

        // 이름·언어는 미지정이므로 문서화된 기본값으로 떨어진다.
        $this->assertSame('Administrator', $resolved['name']);
        $this->assertSame('ko', $resolved['language']);
    }

    public function test_seeder_still_fails_loudly_when_no_channel_carries_the_credentials(): void
    {
        // 폴백을 넓히는 변경이 「아무 값 없이도 계정을 만든다」로 새지 않아야 한다.
        $this->expectException(\RuntimeException::class);

        new AdminUserSeeder;
    }

    /**
     * 생성자가 확정한 관리자 정보를 꺼낸다 (private 속성).
     *
     * @param  AdminUserSeeder  $seeder  대상 시더
     * @return array<string, mixed> 확정된 관리자 정보
     */
    private function resolvedAdminUser(AdminUserSeeder $seeder): array
    {
        $property = (new \ReflectionClass($seeder))->getProperty('defaultAdminUser');
        $property->setAccessible(true);

        return $property->getValue($seeder);
    }
}
