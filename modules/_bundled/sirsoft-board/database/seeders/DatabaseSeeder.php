<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use App\Traits\HasSampleSeeders;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;

/**
 * 게시판 모듈 메인 시더
 *
 * 설치 필수 시더는 항상 실행되며, 샘플 시더는 --sample 옵션 시에만 실행됩니다.
 *
 * 설치 시더:
 * - BoardTypeSeeder
 *
 * 알림 정의는 module.php::getNotificationDefinitions() 가 SSoT 로,
 * ModuleManager 가 activate/update 시 자동 동기화합니다 (시더 호출 불필요).
 *
 * 샘플 시더 (--sample 옵션 시):
 * 1. BoardSampleSeeder - 게시판 8개 (테이블 + 권한 자동 생성)
 * 2. PostSampleSeeder - 게시글/댓글 샘플 (총 160건)
 * 3. ReportSampleSeeder - 신고 샘플 15~20건
 * 4. BoardMailSendLogSeeder - 메일 발송 이력 샘플
 *
 * 실행 방법:
 * php artisan module:seed sirsoft-board            # 설치 시더만
 * php artisan module:seed sirsoft-board --sample    # 설치 + 샘플
 */
class DatabaseSeeder extends Seeder
{
    use HasSampleSeeders;
    use HasSeederCounts;

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info(' 게시판 모듈 시더 시작');
        $this->command->info('========================================');
        $this->command->info('');

        // 설치 필수 시더 (항상 실행)
        $this->command->info('[설치] 게시판 타입 생성');
        $this->call([
            BoardTypeSeeder::class,
        ]);
        $this->command->info('');

        // 샘플 시더 (--sample 옵션 시에만 실행)
        if ($this->shouldIncludeSample()) {
            $this->command->info('--- 게시판 샘플 시더 실행 ---');
            $this->command->info('');

            // 1. 게시판 샘플 생성 (테이블 + 권한 자동, count 불필요)
            $this->command->info('[1/5] 게시판 샘플 생성');
            $this->call(Sample\BoardSampleSeeder::class);
            $this->command->info('');

            // 2. 게시글/댓글 샘플 생성 (count-aware)
            $this->command->info('[2/5] 게시글/댓글 샘플 생성');
            $this->callWithCounts(Sample\PostSampleSeeder::class);
            $this->command->info('');

            // 3. 신고 샘플 생성 (count 불필요)
            $this->command->info('[3/6] 신고 샘플 생성');
            $this->call(Sample\ReportSampleSeeder::class);
            $this->command->info('');

            // 4. 알림 발송 이력 샘플 (count-aware)
            $this->command->info('[4/6] 알림 발송 이력 샘플 생성');
            $this->callWithCounts(Sample\NotificationLogSeeder::class);
            $this->command->info('');

            // 5. 본인인증 이력 샘플 (count-aware)
            $this->command->info('[5/6] 본인인증 이력 샘플 생성');
            $this->callWithCounts(Sample\IdentityVerificationLogSeeder::class);
            $this->command->info('');

            // 6. 활동 로그 샘플 (모든 샘플 데이터 생성 후 마지막에 실행)
            $this->command->info('[6/6] 활동 로그 샘플 생성');
            $this->call(ActivityLogSampleSeeder::class);
            $this->command->info('');
        }

        $this->command->info('========================================');
        $this->command->info(' 게시판 모듈 시더 완료!');
        $this->command->info('========================================');
        $this->command->info('');
    }

    /**
     * 카운트 옵션을 전파하며 단일 시더를 실행합니다.
     *
     * @param  class-string  $class  시더 클래스
     */
    private function callWithCounts(string $class): void
    {
        $seeder = $this->resolve($class);

        if (method_exists($seeder, 'setSeederCounts')) {
            $seeder->setSeederCounts($this->seederCounts);
        }

        $seeder->__invoke();
    }
}
