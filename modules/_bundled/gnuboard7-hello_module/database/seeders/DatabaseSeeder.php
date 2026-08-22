<?php

namespace Modules\Gnuboard7\HelloModule\Database\Seeders;

use App\Traits\HasSampleSeeders;
use Illuminate\Database\Seeder;
use Modules\Gnuboard7\HelloModule\Database\Seeders\Sample\MemoSampleSeeder;

/**
 * Hello 모듈 메인 시더
 *
 * 설치 시더:
 * - MemoSeeder - 기본 메모 2건
 *
 * 샘플 시더 (--sample 옵션 시):
 * - MemoSampleSeeder - 메모 20건
 *
 * 실행 방법:
 *   php artisan module:seed gnuboard7-hello_module             # 설치 시더만
 *   php artisan module:seed gnuboard7-hello_module --sample    # 설치 + 샘플
 */
class DatabaseSeeder extends Seeder
{
    use HasSampleSeeders;

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('=== Hello 모듈 시더 실행 시작 ===');

        $this->call([
            MemoSeeder::class,
        ]);

        if ($this->shouldIncludeSample()) {
            $this->command->info('--- Hello 모듈 샘플 시더 실행 ---');
            $this->call(MemoSampleSeeder::class);
        }

        $this->command->info('=== Hello 모듈 시더 실행 완료 ===');
    }
}
