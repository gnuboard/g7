<?php

namespace Database\Seeders;

use App\Enums\PasswordChangeSource;
use App\Models\User;
use App\Services\PasswordChangeService;
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
            $this->command?->info('최고관리자 계정이 이미 있어 관리자 계정 생성을 건너뜁니다 (기존 회원은 그대로 둡니다).');

            return;
        }

        $email = getenv('INSTALLER_ADMIN_EMAIL');
        $password = getenv('INSTALLER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn(
                '관리자 계정 환경변수(INSTALLER_ADMIN_EMAIL, INSTALLER_ADMIN_PASSWORD)가 없어 관리자 계정 생성을 건너뜁니다. '
                .'새로 설치하는 경우 설치 마법사를 쓰거나 두 환경변수를 설정한 뒤 다시 실행하세요.'
            );

            return;
        }

        $this->command?->info('기본 관리자 사용자 생성을 시작합니다.');

        // 최고관리자가 없는 DB(설치 경로)에서만 여기까지 온다 — 남은 행은 이전 설치 시도의 잔여다.
        $this->deleteExistingUsers();

        $this->createAdminUser([
            'name' => getenv('INSTALLER_ADMIN_NAME') ?: 'Administrator',
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'timezone' => 'Asia/Seoul',
            'language' => getenv('INSTALLER_ADMIN_LANGUAGE') ?: 'ko',
        ]);

        $this->command?->info('기본 관리자 사용자가 성공적으로 생성되었습니다.');
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
            $this->command?->info("기존 관리자 사용자 {$deletedCount}개가 삭제되었습니다.");
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

        // 비밀번호 만료 기준일 — 설치 시점이 첫 변경일이다(#736). 설치자가 직접 정한 비밀번호라 변경 요구는 걸지 않는다.
        app(PasswordChangeService::class)->initializeNewUser($user, PasswordChangeSource::Install);
    }
}
