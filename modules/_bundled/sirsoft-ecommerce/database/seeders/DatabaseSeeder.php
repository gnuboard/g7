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
        $this->command->info('=== '.__('sirsoft-ecommerce::messages.seeders.database.start').' ===');
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
            $this->command->info('--- '.__('sirsoft-ecommerce::messages.seeders.database.sample_start').' ---');

            if (! $this->confirmSampleReset()) {
                $this->command->warn(__('sirsoft-ecommerce::messages.seeders.database.sample_cancelled'));
                $this->command->warn(__('sirsoft-ecommerce::messages.seeders.database.sample_needs_force'));

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
        $this->command->info('=== '.__('sirsoft-ecommerce::messages.seeders.database.done').' ===');
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
        $this->command->warn(__('sirsoft-ecommerce::messages.seeders.database.reset_notice'));
        foreach ($this->sampleResetTables() as $label => $count) {
            $this->command->line('  - '.__('sirsoft-ecommerce::messages.seeders.database.reset_row', ['label' => $label, 'count' => number_format($count)]));
        }
        $this->command->line('  - '.__('sirsoft-ecommerce::messages.seeders.database.reset_activity_logs'));
        $this->command->line('  ※ '.__('sirsoft-ecommerce::messages.seeders.database.reset_note_addresses'));
        $this->command->line('  ※ '.__('sirsoft-ecommerce::messages.seeders.database.reset_note_side_effects'));

        if ($this->command->hasOption('force') && $this->command->option('force')) {
            $this->command->line('  --force: '.__('sirsoft-ecommerce::messages.seeders.database.force_proceed'));

            return true;
        }

        return $this->command->confirm(__('sirsoft-ecommerce::messages.seeders.database.confirm_reset'), false);
    }

    /**
     * 샘플 재시드가 비우는 데이터와 현재 행 수를 돌려줍니다.
     *
     * @return array<string, int> 라벨 => 행 수
     */
    private function sampleResetTables(): array
    {
        return [
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.products') => Product::withTrashed()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.inquiries') => ProductInquiry::withTrashed()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.orders') => Order::withTrashed()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.coupons') => Coupon::withTrashed()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.carts') => Cart::query()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.mileage') => MileageTransaction::query()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.reviews') => ProductReview::withTrashed()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.brands') => Brand::withTrashed()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.categories') => Category::query()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.labels') => ProductLabel::query()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.common_infos') => ProductCommonInfo::query()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.notice_templates') => ProductNoticeTemplate::query()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.extra_fee_templates') => ExtraFeeTemplate::query()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.shipping_policies') => ShippingPolicy::query()->count(),
            __('sirsoft-ecommerce::messages.seeders.database.reset_tables.user_profiles') => EcommerceUserProfile::query()->count(),
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
            $this->command->info('  - '.__('sirsoft-ecommerce::messages.seeders.database.stats_aggregated'));
        } catch (\Throwable $e) {
            $this->command->warn('  - '.__('sirsoft-ecommerce::messages.seeders.database.stats_skipped', ['error' => $e->getMessage()]));
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
