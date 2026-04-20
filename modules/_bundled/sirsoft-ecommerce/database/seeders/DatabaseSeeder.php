<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Traits\HasSampleSeeders;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;

/**
 * 이커머스 모듈 메인 시더
 *
 * 설치 필수 시더는 항상 실행되며, 샘플 시더는 --sample 옵션 시에만 실행됩니다.
 * 시퀀스 → 배송사 → 알림 정의 → 클레임 사유 순서로 설치 시더를 실행한 뒤,
 * 샘플 시더는 브랜드 → 카테고리 → 상품 → 주문 → 리뷰 → 문의 순서로 실행합니다 (의존 관계에 따른 순서).
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
        $this->command->info('=== 이커머스 모듈 시더 실행 시작 ===');
        $this->command->newLine();

        // 설치 필수 시더 (항상 실행)
        $this->call([
            SequenceSeeder::class,
            ShippingCarrierSeeder::class,
            EcommerceNotificationDefinitionSeeder::class,
            ClaimReasonSeeder::class,
            UserAddressSeeder::class,
        ]);

        // 샘플 시더 (--sample 옵션 시에만 실행)
        if ($this->shouldIncludeSample()) {
            $this->command->newLine();
            $this->command->info('--- 이커머스 샘플 시더 실행 ---');

            // 참조 데이터 샘플 시더 (count 불필요)
            $this->call([
                Sample\ProductNoticeTemplateSeeder::class,
                Sample\ProductCommonInfoSeeder::class,
                Sample\BrandSeeder::class,
                Sample\CategorySeeder::class,
                Sample\ProductLabelSeeder::class,
                Sample\ExtraFeeTemplateSeeder::class,
                Sample\ShippingPolicySeeder::class,
            ]);

            // count-aware 샘플 시더 (카운트 전파)
            $this->callWithCounts([
                Sample\ProductSeeder::class,
                Sample\CouponSeeder::class,
                Sample\CartSeeder::class,
                Sample\OrderSeeder::class,
                Sample\ProductReviewSeeder::class,
                Sample\ProductInquirySeeder::class,
            ]);

            // 활동 로그 샘플 시더 (모든 샘플 데이터 생성 후 마지막에 실행)
            $this->call(ActivityLogSampleSeeder::class);
        }

        $this->command->newLine();
        $this->command->info('=== 이커머스 모듈 시더 실행 완료 ===');
    }

    /**
     * 카운트 옵션을 전파하며 시더를 실행합니다.
     *
     * @param  array<class-string>  $classes  시더 클래스 목록
     */
    private function callWithCounts(array $classes): void
    {
        foreach ($classes as $class) {
            $seeder = $this->resolve($class);

            if (method_exists($seeder, 'setSeederCounts')) {
                $seeder->setSeederCounts($this->seederCounts);
            }

            $seeder->__invoke();
        }
    }
}
