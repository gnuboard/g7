<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\ExecutionEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    private array $defaultAdminUser;

    /**
     * 인스톨러가 넘긴 관리자 계정 환경변수를 읽습니다.
     *
     * `getenv()` 만 보면 안 된다. 이 값을 넣는 쪽은 인스톨러의 `installerPutEnv()` 이고,
     * 그 함수는 `$_ENV`/`$_SERVER` 를 **항상** 채운 뒤 `putenv` 는 **가용할 때만** 부른다 —
     * `exec` 를 막는 호스팅은 `putenv` 도 대개 함께 막기 때문이다. 즉 무CLI 환경에서는
     * `$_ENV`/`$_SERVER` 가 유일한 통로이고, `getenv()` 는 `false` 를 돌려준다.
     * 그러면 이 시더는 「환경변수가 설정되지 않았습니다」로 죽고 **관리자 계정이 만들어지지
     * 않는다** — in-process 설치의 마지막 단계에서, 그때까지 전부 성공한 채로.
     *
     * 그리고 `getenv` 자신도 차단 대상이라 무가드로 부르면 그 자리에서 치명 종료한다.
     * 읽는 순서는 인스톨러의 짝 함수(`installerReadEnv`)와 같다.
     *
     * @param  string  $key  환경변수 이름
     * @return string|null 값 (없으면 null)
     */
    private function readInstallerEnv(string $key): ?string
    {
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }

        if (! ExecutionEnvironment::hasFunction('getenv')) {
            return null;
        }

        $value = getenv($key);

        return $value === false ? null : (string) $value;
    }

    public function __construct()
    {
        $name = $this->readInstallerEnv('INSTALLER_ADMIN_NAME');
        $email = $this->readInstallerEnv('INSTALLER_ADMIN_EMAIL');
        $password = $this->readInstallerEnv('INSTALLER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            throw new \RuntimeException(
                '관리자 계정 환경변수가 설정되지 않았습니다. '
                .'INSTALLER_ADMIN_EMAIL, INSTALLER_ADMIN_PASSWORD 환경변수를 설정해주세요.'
            );
        }

        $this->defaultAdminUser = [
            'name' => $name ?: 'Administrator',
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'timezone' => 'Asia/Seoul',
            'language' => $this->readInstallerEnv('INSTALLER_ADMIN_LANGUAGE') ?: 'ko',
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('기본 관리자 사용자 생성을 시작합니다.');

        // 기존 데이터 삭제
        $this->deleteExistingUsers();

        // 새 데이터 생성
        $this->createAdminUser($this->defaultAdminUser);

        $this->command->info('기본 관리자 사용자가 성공적으로 생성되었습니다.');
    }

    /**
     * 기존 관리자 사용자를 삭제합니다.
     */
    private function deleteExistingUsers(): void
    {
        $deletedCount = User::query()->delete();

        if ($deletedCount > 0) {
            $this->command->info("기존 관리자 사용자 {$deletedCount}개가 삭제되었습니다.");
        }
    }

    /**
     * 기본 관리자 사용자를 생성합니다.
     *
     * 첫 관리자는 슈퍼 관리자로 생성됩니다 (is_super = true).
     * 슈퍼 관리자는 삭제할 수 없으며, 다른 관리자의 권한을 관리할 수 있습니다.
     *
     * @param  array  $userData  사용자 데이터
     */
    private function createAdminUser(array $userData): void
    {
        User::create([
            'name' => $userData['name'],
            'email' => $userData['email'],
            'password' => Hash::make($userData['password']),
            'email_verified_at' => $userData['email_verified_at'],
            'timezone' => $userData['timezone'],
            'language' => $userData['language'],
            'is_super' => true,  // 첫 관리자는 슈퍼 관리자로 설정
        ]);
    }
}
