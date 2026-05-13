<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    private array $defaultAdminUser;

    public function __construct()
    {
        $name = getenv('INSTALLER_ADMIN_NAME');
        $email = getenv('INSTALLER_ADMIN_EMAIL');
        $password = getenv('INSTALLER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            throw new \RuntimeException(
                '관리자 계정 환경변수가 설정되지 않았습니다. '
                . 'INSTALLER_ADMIN_EMAIL, INSTALLER_ADMIN_PASSWORD 환경변수를 설정해주세요.'
            );
        }

        $this->defaultAdminUser = [
            'name' => $name ?: 'Administrator',
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'timezone' => 'Asia/Seoul',
            'language' => getenv('INSTALLER_ADMIN_LANGUAGE') ?: 'ko',
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
     * @param array $userData 사용자 데이터
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
