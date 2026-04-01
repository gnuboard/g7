<?php

namespace Modules\Sirsoft\Page\Database\Seeders;

use App\Traits\HasSampleSeeders;
use Illuminate\Database\Seeder;

/**
 * 페이지 모듈 메인 시더
 *
 * 설치 필수 시더는 항상 실행되며, 샘플 시더는 --sample 옵션 시에만 실행됩니다.
 *
 * 설치 시더:
 * - PageSeeder - 기본 페이지 6개 (이용약관, 개인정보처리방침, 취소/반품/교환, 그누보드7 소개, FAQ, 문의하기)
 *
 * 샘플 시더 (--sample 옵션 시):
 * - ActivityLogSampleSeeder - 페이지 활동 로그 샘플
 *
 * 실행 방법:
 * php artisan module:seed sirsoft-page            # 설치 시더만
 * php artisan module:seed sirsoft-page --sample    # 설치 + 샘플
 */
class DatabaseSeeder extends Seeder
{
    use HasSampleSeeders;

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('=== 페이지 모듈 시더 실행 시작 ===');
        $this->command->newLine();

        // 설치 필수 시더 (항상 실행)
        $this->call([
            PageSeeder::class,
        ]);

        // 샘플 시더 (--sample 옵션 시에만 실행)
        if ($this->shouldIncludeSample()) {
            $this->command->newLine();
            $this->command->info('--- 페이지 샘플 시더 실행 ---');

            // 활동 로그 샘플 (페이지 데이터 생성 후 실행)
            $this->call(ActivityLogSampleSeeder::class);
        }

        $this->command->newLine();
        $this->command->info('=== 페이지 모듈 시더 실행 완료 ===');
    }
}
