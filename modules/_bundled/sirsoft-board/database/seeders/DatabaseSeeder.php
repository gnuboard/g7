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
 * - BoardTypeSeeder - 게시판 유형 3종 (basic·gallery·card)
 * - BoardReactionTypeSeeder - 반응 유형 (like·dislike)
 *
 * 알림 정의는 module.php::getNotificationDefinitions() 가 SSoT 로,
 * ModuleManager 가 activate/update 시 자동 동기화합니다 (시더 호출 불필요).
 *
 * 샘플 시더 (--sample 옵션 시):
 * 1. BoardSampleSeeder - 샘플 게시판 9개 (상품 문의 게시판 product-inquiry 포함, 테이블 + 권한 자동 생성)
 * 2. PostSampleSeeder - 게시글·답글·댓글·첨부 (원글 기본 160건, --count=posts= 로 비율 조정)
 * 3. ReactionScrapSampleSeeder - 게시글·댓글 반응과 스크랩
 * 4. ReportSampleSeeder - 신고 케이스 (상태별·다중 신고자·재접수·자동 블라인드)
 * 5. NotificationLogSeeder - 알림 발송 이력
 * 6. IdentityVerificationLogSeeder - 본인인증 이력
 * 7. ActivityLogSampleSeeder - 활동 로그 (모든 샘플 생성 뒤)
 *
 * 재실행해도 운영 데이터는 지우지 않는다 — 게시판·게시글·반응은 이미 있으면 건너뛰고,
 * 신고·활동 로그는 샘플 표식이 있는 행만 정리한 뒤 다시 만든다. 이전 버전 시더가 표식 없이
 * 만든 신고·활동 로그는 운영 데이터와 구분할 수 없어 남는다.
 *
 * 이커머스 샘플(상품 문의)은 게시판 샘플 뒤에 돌린다 — product-inquiry 게시판이 있어야 문의 샘플이 만들어진다.
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
        $this->command->info('[설치] 게시판 타입 / 반응 유형 생성');
        $this->call([
            BoardTypeSeeder::class,
            BoardReactionTypeSeeder::class,
        ]);
        $this->command->info('');

        // 샘플 시더 (--sample 옵션 시에만 실행)
        if ($this->shouldIncludeSample()) {
            $this->command->info('--- 게시판 샘플 시더 실행 ---');
            $this->command->info('');

            // 1. 게시판 샘플 생성 (테이블 + 권한 자동, count 불필요)
            $this->command->info('[1/7] 게시판 샘플 생성');
            $this->call(Sample\BoardSampleSeeder::class);
            $this->command->info('');

            // 2. 게시글/댓글 샘플 생성 (count-aware)
            $this->command->info('[2/7] 게시글/댓글 샘플 생성');
            $this->callWithCounts(Sample\PostSampleSeeder::class);
            $this->command->info('');

            // 3. 반응·스크랩 샘플 생성 (게시글·댓글 뒤)
            $this->command->info('[3/7] 반응·스크랩 샘플 생성');
            $this->call(Sample\ReactionScrapSampleSeeder::class);
            $this->command->info('');

            // 4. 신고 샘플 생성 (count 불필요)
            $this->command->info('[4/7] 신고 샘플 생성');
            $this->call(Sample\ReportSampleSeeder::class);
            $this->command->info('');

            // 5. 알림 발송 이력 샘플 (count-aware)
            $this->command->info('[5/7] 알림 발송 이력 샘플 생성');
            $this->callWithCounts(Sample\NotificationLogSeeder::class);
            $this->command->info('');

            // 6. 본인인증 이력 샘플 (count-aware)
            $this->command->info('[6/7] 본인인증 이력 샘플 생성');
            $this->callWithCounts(Sample\IdentityVerificationLogSeeder::class);
            $this->command->info('');

            // 7. 활동 로그 샘플 (모든 샘플 데이터 생성 후 마지막에 실행)
            $this->command->info('[7/7] 활동 로그 샘플 생성');
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
