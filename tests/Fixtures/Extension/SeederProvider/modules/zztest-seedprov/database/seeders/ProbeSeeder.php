<?php

namespace Modules\Zztest\Seedprov\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Zztest\Seedprov\Contracts\ProbeRepositoryInterface;

/**
 * 프로바이더 저장소 바인딩을 해석하는 설치 시더 (시험 픽스처).
 */
class ProbeSeeder extends Seeder
{
    /**
     * 저장소 인터페이스를 해석해 행을 만든다. `ZZTEST_SEEDPROV_MODE=throw` 면 예외를 던진다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        if (getenv('ZZTEST_SEEDPROV_MODE') === 'throw') {
            throw new \RuntimeException('probe seeder failure');
        }

        $this->command?->info('프로브 시더 실행');
        app(ProbeRepositoryInterface::class)->create('seeded');
    }
}
