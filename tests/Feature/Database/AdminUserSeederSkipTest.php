<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 설치가 끝난 사이트의 `php artisan db:seed --sample --force` 가 관리자 시더에서 멈추지 않는다 (W3 실측).
 *
 * 관리자 시더는 설치 마법사가 넣는 환경 변수(`INSTALLER_ADMIN_EMAIL`·`INSTALLER_ADMIN_PASSWORD`)를 생성자에서 요구해, 설치 뒤
 * 개발 문서의 「설치 + 샘플」 명령으로 샘플 회원을 만들려 하면 예외로 전체가 멈췄다. 또 환경 변수가 있으면 회원 전체를 지우고
 * 관리자를 새로 만들어, 운영 중인 사이트에서 실행하면 회원이 모두 사라질 수 있었다. 이제 최고관리자가 이미 있거나 환경 변수가
 * 없으면 안내만 하고 건너뛴다. 빈 DB 에 환경 변수를 넣고 부르는 설치 경로는 종전과 같다.
 */
class AdminUserSeederSkipTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 이 검사가 다루는 설치 환경 변수 */
    private const KEYS = ['INSTALLER_ADMIN_EMAIL', 'INSTALLER_ADMIN_PASSWORD'];

    /** @var array<string, array{process: string|false, env: string|null, server: string|null}> 원래 환경 변수 */
    private array $originalEnv = [];

    /**
     * 설치 환경 변수를 기억합니다.
     *
     * 시더는 인스톨러의 `installerPutEnv()` 와 같은 세 통로(`$_ENV` → `$_SERVER` → `getenv()`)로 읽는다.
     * 그래서 프로세스 환경만 기억하면 phpunit.xml 이 `$_ENV` 에 넣은 값이 판정을 가로챈다 — 세 통로를 모두 기억한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->originalEnv[$key] = [
                'process' => getenv($key),
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
        }
    }

    /**
     * 설치 환경 변수를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            putenv($value['process'] === false ? $key : "{$key}={$value['process']}");

            unset($_ENV[$key], $_SERVER[$key]);

            if ($value['env'] !== null) {
                $_ENV[$key] = $value['env'];
            }
            if ($value['server'] !== null) {
                $_SERVER[$key] = $value['server'];
            }
        }

        parent::tearDown();
    }

    /**
     * 설치 환경 변수를 인스톨러(`installerPutEnv()`)처럼 세 통로에 함께 넣거나, 세 통로에서 함께 지웁니다.
     *
     * @param  string  $key  환경 변수 이름
     * @param  string|null  $value  값 (null 이면 지운다)
     * @return void 반환값 없음
     */
    private function setInstallerEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * 환경 변수가 없으면 예외 없이 건너뛰고 회원을 건드리지 않는다.
     *
     * @scenario admin_exists=no, installer_env=absent
     *
     * @effects admin_seeder_skips_without_installer_env
     *
     * @return void 반환값 없음
     */
    public function test_skips_without_installer_env(): void
    {
        $this->setInstallerEnv('INSTALLER_ADMIN_EMAIL', null);
        $this->setInstallerEnv('INSTALLER_ADMIN_PASSWORD', null);
        $member = User::factory()->create();
        $before = User::count();

        $this->seed(AdminUserSeeder::class);

        $this->assertSame($before, User::count());
        $this->assertNotNull(User::find($member->id));
    }

    /**
     * 최고관리자가 이미 있으면 환경 변수가 있어도 회원을 지우지 않고 건너뛴다.
     *
     * @scenario admin_exists=yes, installer_env=present
     *
     * @effects admin_seeder_keeps_existing_users_when_super_admin_exists
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_super_admin_exists(): void
    {
        $this->setInstallerEnv('INSTALLER_ADMIN_EMAIL', 'seed-again@example.test');
        $this->setInstallerEnv('INSTALLER_ADMIN_PASSWORD', 'Passw0rd!again');
        $admin = User::factory()->create(['is_super' => true]);
        $member = User::factory()->create();
        $before = User::count();

        $this->seed(AdminUserSeeder::class);

        $this->assertSame($before, User::count(), '설치가 끝난 사이트에서 회원을 지우면 안 된다');
        $this->assertNotNull(User::find($admin->id));
        $this->assertNotNull(User::find($member->id));
        $this->assertFalse(User::where('email', 'seed-again@example.test')->exists());
    }

    /**
     * 설치 경로 — 회원이 없는 DB 에 환경 변수를 넣으면 최고관리자를 만든다(종전과 같음).
     *
     * @scenario admin_exists=no, installer_env=present
     *
     * @effects admin_seeder_creates_super_admin_on_install
     *
     * @return void 반환값 없음
     */
    public function test_creates_super_admin_on_install_path(): void
    {
        DB::table('users')->delete();
        $this->setInstallerEnv('INSTALLER_ADMIN_EMAIL', 'install-path@example.test');
        $this->setInstallerEnv('INSTALLER_ADMIN_PASSWORD', 'Passw0rd!install');

        $this->seed(AdminUserSeeder::class);

        $user = User::where('email', 'install-path@example.test')->firstOrFail();
        $this->assertTrue((bool) $user->is_super);
    }
}
