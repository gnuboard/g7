<?php

namespace Plugins\Sirsoft\SocialLogin\Database\Seeders;

use Illuminate\Database\Seeder;
use Plugins\Sirsoft\SocialLogin\Plugin;

/**
 * 소셜 로그인 플러그인 메인 시더.
 *
 * `php artisan plugin:seed sirsoft-social_login` 이 찾는 기본 시더다. 설치·업데이트는 `getSeeders()` 목록을 쓰지만, 언어팩을
 * 켜고 끌 때 코어는 이 명령으로 시더를 다시 돌린다 — 같은 시더를 돌려야 나중에 켠 언어의 내장 제공자 이름이 기존 행에 들어간다.
 * 목록은 사본으로 들지 않고 `getSeeders()` 를 그대로 읽는다(설치 시더가 늘면 재시딩도 같이 는다).
 *
 * @since 1.0.0
 */
class DatabaseSeeder extends Seeder
{
    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->call($this->seeders());
    }

    /**
     * 실행할 시더 목록 — 플러그인 설치 시더와 같다.
     *
     * @return array<int, class-string> 시더 클래스
     */
    public function seeders(): array
    {
        return (new Plugin)->getSeeders();
    }
}
