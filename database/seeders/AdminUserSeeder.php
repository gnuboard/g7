<?php

namespace Database\Seeders;

use App\Enums\PasswordChangeSource;
use App\Models\User;
use App\Services\PasswordChangeService;
use App\Support\ExecutionEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 설치 마법사가 넣은 환경 변수로 첫 최고관리자 계정을 만드는 설치 시더.
 *
 * 설치 마법사는 빈 DB 에 `INSTALLER_ADMIN_*` 환경 변수를 넣고 `db:seed --force` 를 부른다 — 이 경로는 종전과 같다.
 * 설치가 끝난 사이트에서 개발 문서의 「설치 + 샘플」(`db:seed --sample --force`)을 실행하면 종전에는 환경 변수가 없다는 예외로
 * 전체가 멈췄고, 환경 변수가 있으면 회원 전체를 지우고 관리자를 새로 만들었다. 이제 최고관리자가 이미 있거나 환경 변수가 없으면
 * 안내만 하고 건너뛴다(뒤따르는 시더·샘플 시더는 그대로 돈다).
 */
class AdminUserSeeder extends Seeder
{
    /**
     * 최고관리자 계정을 만듭니다 — 이미 있거나 설치 환경 변수가 없으면 건너뜁니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        if (User::query()->where('is_super', true)->exists()) {
            $this->command?->info(__('common.console.seeders.admin_user.skip_super_exists'));

            return;
        }

        $email = $this->readInstallerEnv('INSTALLER_ADMIN_EMAIL');
        $password = $this->readInstallerEnv('INSTALLER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn(
                __('common.console.seeders.admin_user.skip_missing_env')
            );

            return;
        }

        $this->command?->info(__('common.console.seeders.admin_user.start'));

        // 최고관리자가 없는 DB(설치 경로)에서만 여기까지 온다 — 남은 행은 이전 설치 시도의 잔여다.
        $this->deleteExistingUsers();

        $this->createAdminUser([
            'name' => $this->readInstallerEnv('INSTALLER_ADMIN_NAME') ?: 'Administrator',
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'timezone' => 'Asia/Seoul',
            'language' => $this->readInstallerEnv('INSTALLER_ADMIN_LANGUAGE') ?: 'ko',
        ]);

        $this->command?->info(__('common.console.seeders.admin_user.done'));
    }

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

    /**
     * 이전 설치 시도가 남긴 사용자 행을 삭제합니다.
     *
     * @return void 반환값 없음
     */
    private function deleteExistingUsers(): void
    {
        $deletedCount = User::query()->delete();

        if ($deletedCount > 0) {
            $this->command?->info(__('common.console.seeders.admin_user.deleted', ['count' => $deletedCount]));
        }
    }

    /**
     * 기본 관리자 사용자를 생성합니다.
     *
     * 첫 관리자는 슈퍼 관리자로 생성됩니다 (is_super = true).
     * 슈퍼 관리자는 삭제할 수 없으며, 다른 관리자의 권한을 관리할 수 있습니다.
     *
     * @param  array<string, mixed>  $userData  사용자 데이터
     * @return void 반환값 없음
     */
    private function createAdminUser(array $userData): void
    {
        $user = User::create([
            'name' => $userData['name'],
            'email' => $userData['email'],
            'password' => Hash::make($userData['password']),
            'email_verified_at' => $userData['email_verified_at'],
            'timezone' => $userData['timezone'],
            'language' => $userData['language'],
            'is_super' => true,  // 첫 관리자는 슈퍼 관리자로 설정
        ]);

        // 비밀번호 만료 기준일 — 설치 시점이 첫 변경일이다. 설치자가 직접 정한 비밀번호라 변경 요구는 걸지 않는다.
        app(PasswordChangeService::class)->initializeNewUser($user, PasswordChangeSource::Install);
    }
}
