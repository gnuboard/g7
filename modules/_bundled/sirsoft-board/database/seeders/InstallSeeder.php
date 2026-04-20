<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 게시판 모듈 설치 시더
 *
 * 모듈 설치 시 실행되는 시더입니다.
 * 기본 알림 정의 7종을 생성합니다.
 *
 * 테스트 데이터가 필요한 경우 DatabaseSeeder를 수동으로 실행하세요:
 *   php artisan module:seed sirsoft-board
 */
class InstallSeeder extends Seeder
{
    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->call(BoardNotificationDefinitionSeeder::class);
    }
}
