<?php

namespace Tests\Feature\Installer;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 관리자 시더가 인스톨러의 환경변수를 **3통로**로 읽는지 고정한다 (#3 / 18차 게이트 신설).
 *
 * ## 왜 이 검사가 필요한가
 *
 * 값을 넣는 쪽은 인스톨러의 `installerPutEnv()` 이고, 그 함수는 `$_ENV`/`$_SERVER` 를
 * **항상** 채운 뒤 `putenv` 는 **가용할 때만** 부른다 — `exec` 를 막는 호스팅은 `putenv` 도
 * 대개 함께 막기 때문이다. 즉 무CLI 환경에서 `$_ENV`/`$_SERVER` 는 **유일한 통로**이고
 * `getenv()` 는 `false` 를 돌려준다.
 *
 * 소비처가 `getenv()` 만 보면 이 시더는 환경변수가 없다고 판정하고
 * **관리자 계정이 만들어지지 않는다** — in-process 설치의 마지막 단계에서, 그때까지
 * 전부 성공한 채로. 원인이 `putenv` 차단이라는 것이 어디에도 드러나지 않는다.
 *
 * ## 판정 방식
 *
 * 시더는 환경변수를 `run()` 안에서 읽고, 값이 없으면 예외 대신 안내만 남기고 건너뛴다
 * (설치가 끝난 사이트의 `db:seed --sample` 이 멈추지 않게 한 변경). 그래서 이 검사는
 * 빈 회원 테이블에서 시더를 실제로 돌려 **만들어진 최고관리자 계정**으로 통로를 판정한다.
 *
 * ## 왜 신설했는가
 *
 * 앞서 이 전환을 손으로 수행했는데 **회귀 장치가 0** 이었다. 실행 축 정적 룰 3종은
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
    use DatabaseTransactions;

    /** @var array<string, array{env: string|null, server: string|null}> 원래 값 복원용 */
    private array $originalEnv = [];

    /** @var array<int, string> 이 검사가 다루는 키 */
    private const KEYS = [
        'INSTALLER_ADMIN_NAME',
        'INSTALLER_ADMIN_EMAIL',
        'INSTALLER_ADMIN_PASSWORD',
        'INSTALLER_ADMIN_LANGUAGE',
    ];

    /**
     * 관리자 환경변수 네 키의 원래 값을 보관한 뒤 $_ENV, $_SERVER, 프로세스 환경에서 모두 비우고, 회원 테이블을 비워 설치 직후 상태를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->originalEnv[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
            unset($_ENV[$key], $_SERVER[$key]);

            // `putenv` 가 막힌 환경을 재현한다 — 그 환경에서 `getenv()` 는 값을 못 준다.
            // 프로세스 환경에 남아 있으면 `getenv()` 폴백이 우연히 성공해 검사가 무의미해진다.
            if (function_exists('putenv')) {
                putenv($key);
            }
        }

        // 설치 경로 재현 — 최고관리자가 이미 있으면 시더는 통로와 무관하게 건너뛴다.
        DB::table('users')->delete();
    }

    /**
     * 테스트가 심은 관리자 환경변수를 지우고, setUp 에서 보관한 원래 $_ENV, $_SERVER 값을 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);

            if ($this->originalEnv[$key]['env'] !== null) {
                $_ENV[$key] = $this->originalEnv[$key]['env'];
            }
            if ($this->originalEnv[$key]['server'] !== null) {
                $_SERVER[$key] = $this->originalEnv[$key]['server'];
            }
        }

        parent::tearDown();
    }

    /**
     * putenv 없이 $_ENV 에만 실린 관리자 이름, 이메일, 비밀번호, 언어로 시더가 최고관리자 계정을 만드는지 확인한다.
     *
     * @scenario task=db_seed
     *
     * @effects installer_admin_seeder_reads_env_without_putenv
     *
     * @return void 반환값 없음
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

        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', 'admin@example.test')->first();
        $this->assertNotNull($admin, 'putenv 없이 전달된 이메일을 읽지 못했다 — 관리자 계정이 만들어지지 않는다');
        $this->assertTrue((bool) $admin->is_super);
        $this->assertSame('관리자', $admin->name);
        $this->assertTrue(Hash::check('S3cret!Passw0rd', $admin->password));
        $this->assertSame('en', $admin->language, '관리자 언어가 조용히 기본값으로 떨어졌다 (§7 R2)');
    }

    /**
     * $_SERVER 로만 전달된 이메일과 비밀번호로도 계정이 만들어지고, 지정하지 않은 이름과 언어는 문서화된 기본값으로 채워지는지 확인한다.
     *
     * @scenario task=db_seed
     *
     * @effects installer_admin_seeder_reads_env_without_putenv
     *
     * @return void 반환값 없음
     */
    public function test_seeder_reads_admin_credentials_from_server_superglobal(): void
    {
        // `installerPutEnv()` 는 두 슈퍼전역을 함께 채운다. 호스팅의 `variables_order` 설정에
        // 따라 한쪽만 살아남는 배치가 있으므로 두 통로 모두 유효해야 한다.
        $_SERVER['INSTALLER_ADMIN_EMAIL'] = 'ops@example.test';
        $_SERVER['INSTALLER_ADMIN_PASSWORD'] = 'An0ther!Secret';

        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', 'ops@example.test')->first();
        $this->assertNotNull($admin, '$_SERVER 로만 전달된 이메일을 읽지 못했다');
        $this->assertTrue(Hash::check('An0ther!Secret', $admin->password));

        // 이름·언어는 미지정이므로 문서화된 기본값으로 떨어진다.
        $this->assertSame('Administrator', $admin->name);
        $this->assertSame('ko', $admin->language);
    }

    /**
     * 어느 통로에도 관리자 값이 없으면 시더가 계정을 하나도 만들지 않는지 확인한다(폴백 확장이 무값 생성으로 새지 않게).
     *
     * @scenario task=db_seed
     *
     * @effects installer_admin_seeder_creates_no_account_without_credentials
     *
     * @return void 반환값 없음
     */
    public function test_seeder_creates_no_account_when_no_channel_carries_the_credentials(): void
    {
        // 폴백을 넓히는 변경이 「아무 값 없이도 계정을 만든다」로 새지 않아야 한다.
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(0, User::query()->count(), '어느 통로에도 값이 없는데 계정이 만들어졌다');
    }
}
