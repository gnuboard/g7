<?php

namespace Plugins\Zztest\Seedprov;

use App\Extension\AbstractPlugin;
use Plugins\Zztest\Seedprov\Database\Seeders\ProbeSeeder;

/**
 * 설치 시더 프로바이더 시험 플러그인 (시험 픽스처).
 *
 * 시더가 프로바이더 `$repositories` 에만 있는 저장소 인터페이스를 해석한다.
 * 환경변수 `ZZTEST_SEEDPROV_MODE` 로 시더 동작을 바꾼다: ok(기본) · throw(시더 예외) · missing(선언 시더 클래스 없음).
 */
class Plugin extends AbstractPlugin
{
    /**
     * 권한 정의 — 되돌림 시험용 권한 1개.
     *
     * @return array<string, mixed> 권한 정의
     */
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => '시더 시험', 'en' => 'Seeder test'],
            'description' => ['ko' => '시더 시험 권한', 'en' => 'Seeder test permissions'],
            'categories' => [
                [
                    'identifier' => 'probes',
                    'name' => ['ko' => '프로브', 'en' => 'Probes'],
                    'description' => ['ko' => '프로브 권한', 'en' => 'Probe permissions'],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => ['ko' => '프로브 조회', 'en' => 'Read probes'],
                            'description' => ['ko' => '프로브 조회', 'en' => 'Read probes'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 설치 시더 목록.
     *
     * @return array<int, class-string> 시더 클래스 목록
     */
    public function getSeeders(): array
    {
        if (getenv('ZZTEST_SEEDPROV_MODE') === 'missing') {
            return ['Plugins\\Zztest\\Seedprov\\Database\\Seeders\\MissingSeeder'];
        }

        return [ProbeSeeder::class];
    }
}
