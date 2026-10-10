<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Traits\HasSampleSeeders;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\EcommerceUserProfile;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;

/**
 * 이커머스 모듈 메인 시더
 *
 * 설치 필수 시더는 항상 실행되며, 샘플 시더는 --sample 옵션 시에만 실행됩니다.
 * 설치 시더는 시퀀스 → 배송유형 → 배송사 → 클레임 사유 순서이고 운영 데이터를 지우지 않습니다
 * (알림 정의는 module.php::getNotificationDefinitions() SSoT — Manager 가 자동 동기화).
 * 샘플 시더는 브랜드 → 카테고리 → 상품 → 쿠폰 → 장바구니 → 주문 → 배송지 → 마일리지 → 클레임 → 리뷰 → 문의
 * 순서로 실행합니다 (의존 관계에 따른 순서). 샘플은 이전 샘플 행을 비우고 다시 만들므로 실행 전에
 * 비울 테이블 목록을 보여 주고 확인을 받습니다(--force 면 생략).
 */
class DatabaseSeeder extends Seeder
{
    use HasSampleSeeders;
    use HasSeederCounts;

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('=== 이커머스 모듈 시더 실행 시작 ===');
        $this->command->newLine();

        // 설치 필수 시더 (항상 실행)
        $this->call([
            SequenceSeeder::class,
            ShippingTypeSeeder::class,
            ShippingCarrierSeeder::class,
            ClaimReasonSeeder::class,
        ]);

        // 샘플 시더 (--sample 옵션 시에만 실행)
        if ($this->shouldIncludeSample()) {
            $this->command->newLine();
            $this->command->info('--- 이커머스 샘플 시더 실행 ---');

            if (! $this->confirmSampleReset()) {
                $this->command->warn('샘플 데이터 생성을 취소했습니다. (설치 데이터만 동기화됨)');
                $this->command->warn('대화형이 아닌 실행(자동화 스크립트·CI·파이프)에서는 --force 를 붙여야 샘플을 만듭니다.');

                return;
            }

            // 참조 데이터 샘플 시더 (count 불필요)
            $this->call([
                Sample\EcommerceUserProfileSeeder::class,
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
                Sample\UserAddressSeeder::class,
                Sample\MileageSeeder::class,
                Sample\ClaimSeeder::class,
                Sample\ProductReviewSeeder::class,
                Sample\ProductInquirySeeder::class,
                Sample\NotificationLogSeeder::class,
                Sample\IdentityVerificationLogSeeder::class,
            ]);

            // 활동 로그 샘플 시더 (모든 샘플 데이터 생성 후 마지막에 실행)
            $this->call(ActivityLogSampleSeeder::class);

            // 대시보드·통계 화면이 바로 보이도록 최근 31일을 집계한다 (샘플 주문 기간과 같음)
            $this->aggregateDashboardStats();
        }

        $this->command->newLine();
        $this->command->info('=== 이커머스 모듈 시더 실행 완료 ===');
    }

    /**
     * 샘플 재시드 전에 비울 테이블을 보여 주고 확인을 받습니다. (`--force` 면 확인 없이 진행)
     *
     * 샘플 시더는 이전 샘플을 지우고 다시 만든다 — 아래 테이블은 운영 데이터와 샘플을 구분할 표식이
     * 없어 **표의 행 전체**가 지워진다(활동 로그만 샘플 표식 행으로 좁힌다). 운영 환경이 아니어도
     * 실수로 지우면 되돌릴 수 없으므로 매번 묻는다. 대화형이 아니면(파이프·CI) `--force` 가 있어야 진행한다.
     *
     * @return bool 진행하면 true
     */
    private function confirmSampleReset(): bool
    {
        $this->command->warn('샘플 데이터를 다시 만들기 위해 아래 데이터를 비웁니다 (현재 행 수):');
        foreach ($this->sampleResetTables() as $label => $count) {
            $this->command->line(sprintf('  - %s: %s건', $label, number_format($count)));
        }
        $this->command->line('  - 활동 로그: 이전 샘플 표식이 있는 행만 (실제 활동 로그는 보존)');
        $this->command->line('  ※ 회원 배송지는 지우지 않습니다. 마일리지 원장은 전체가 다시 만들어집니다.');
        $this->command->line('  ※ 삭제 외 변경: 1:1 문의게시판 설정이 비어 있으면 product-inquiry 게시판을 연결하고, 최근 31일 대시보드 통계를 다시 집계합니다.');

        if ($this->command->hasOption('force') && $this->command->option('force')) {
            $this->command->line('  --force: 확인 없이 진행합니다.');

            return true;
        }

        return $this->command->confirm('위 데이터를 비우고 샘플을 새로 만들까요?', false);
    }

    /**
     * 샘플 재시드가 비우는 데이터와 현재 행 수를 돌려줍니다.
     *
     * @return array<string, int> 라벨 => 행 수
     */
    private function sampleResetTables(): array
    {
        return [
            '상품(옵션·이미지·고시·라벨 연결 포함, 저장소 상품 이미지 폴더 전체)' => Product::withTrashed()->count(),
            '상품 문의(게시판 문의 글과 그 답변 포함)' => ProductInquiry::withTrashed()->count(),
            '주문(결제·배송·주소·취소·환불·교환·반품·추가결제·클레임 첨부·현금영수증·세금계산서 포함)' => Order::withTrashed()->count(),
            '쿠폰·쿠폰 발급 이력' => Coupon::withTrashed()->count(),
            '장바구니' => Cart::query()->count(),
            '마일리지 거래·잔액' => MileageTransaction::query()->count(),
            '상품 리뷰(리뷰 이미지 포함, 저장소 리뷰 이미지 폴더 전체)' => ProductReview::withTrashed()->count(),
            '브랜드' => Brand::withTrashed()->count(),
            '카테고리' => Category::query()->count(),
            '상품 라벨' => ProductLabel::query()->count(),
            '상품 공통정보' => ProductCommonInfo::query()->count(),
            '상품정보제공고시 템플릿' => ProductNoticeTemplate::query()->count(),
            '추가배송비 템플릿' => ExtraFeeTemplate::query()->count(),
            '배송정책(국가별 설정·클레임 회수지 포함)' => ShippingPolicy::query()->count(),
            '회원 쇼핑 프로필(선호 통화·배송국가)' => EcommerceUserProfile::query()->count(),
        ];
    }

    /**
     * 최근 31일 대시보드 집계를 만듭니다 (실패해도 샘플 생성은 끝난 것으로 둔다).
     *
     * @return void 반환값 없음
     */
    private function aggregateDashboardStats(): void
    {
        try {
            app(EcommerceDashboardService::class)->aggregateRecentDays(31);
            $this->command->info('  - 최근 31일 매출·주문 통계를 집계했습니다.');
        } catch (\Throwable $e) {
            $this->command->warn('  - 통계 집계를 건너뜁니다: '.$e->getMessage());
        }
    }

    /**
     * 카운트 옵션을 전파하며 시더를 실행합니다.
     *
     * @param  array<class-string>  $classes  시더 클래스 목록
     * @return void 반환값 없음
     */
    private function callWithCounts(array $classes): void
    {
        foreach ($classes as $class) {
            $seeder = $this->resolve($class);

            if (method_exists($seeder, 'setSeederCounts')) {
                $seeder->setSeederCounts($this->seederCounts);
            }

            // 기존 주문을 바꾸는 시더(클레임)는 이 재시드 흐름 안에서만 돈다 — 방금 만든 샘플 주문이 대상이다
            if (method_exists($seeder, 'setWithinSampleReseed')) {
                $seeder->setWithinSampleReseed(true);
            }

            $seeder->__invoke();
        }
    }
}
