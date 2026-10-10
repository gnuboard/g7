<?php

namespace Modules\Sirsoft\Ecommerce\Providers;

use App\Extension\BaseModuleServiceProvider;
use App\Helpers\ResponseHelper;
use App\Seo\SitemapGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Console\Commands\AggregateEcommerceStatsCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\CancelOrphanClaimPaymentsCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\CancelPendingPaymentOrdersCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\EarnMileageCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\ExpireMileageCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\NotifyExpiringMileageCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\NotifyOverdueCollectionsCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\PlaywrightSeedMemberData;
use Modules\Sirsoft\Ecommerce\Console\Commands\PlaywrightSeedOrderRetention;
use Modules\Sirsoft\Ecommerce\Console\Commands\PlaywrightSeedSiteDayOrders;
use Modules\Sirsoft\Ecommerce\Console\Commands\PruneExpiredCartsCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\PruneExpiredTempOrdersCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\PrunePendingClaimImagesCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\PruneTempProductImagesCommand;
use Modules\Sirsoft\Ecommerce\Console\Commands\ReconcileMileageBalanceCommand;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Middleware\DetectDevice;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\BrandRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CartRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CategoryImageRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CategoryRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ClaimActivityLogRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ClaimImageRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ClaimPaymentRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ClaimReasonRepository;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\BrandRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimActivityLogRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceStatRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExtraFeeTemplateRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCashReceiptRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPrivacyRetentionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductAdditionalOptionValueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductCommonInfoRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductLabelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductLogRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductNoticeTemplateRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SearchPresetRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SequenceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingCarrierRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyClaimAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\TempOrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\CouponIssueRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CouponRepository;
use Modules\Sirsoft\Ecommerce\Repositories\EcommerceStatRepository;
use Modules\Sirsoft\Ecommerce\Repositories\EcommerceUserProfileRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ExtraFeeTemplateRepository;
use Modules\Sirsoft\Ecommerce\Repositories\MileageBalanceRepository;
use Modules\Sirsoft\Ecommerce\Repositories\MileageTransactionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderCancelOptionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderCancelRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderCashReceiptRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderExchangeOptionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderExchangeRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderOptionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderPaymentRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderPrivacyRetentionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRefundOptionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRefundRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderReturnOptionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderReturnRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderShippingRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductAdditionalOptionValueRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductCommonInfoRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductImageRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductInquiryRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductLabelRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductLogRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductNoticeTemplateRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductOptionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductReviewImageRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductReviewRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductWishlistRepository;
use Modules\Sirsoft\Ecommerce\Repositories\SearchPresetRepository;
use Modules\Sirsoft\Ecommerce\Repositories\SequenceRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingCarrierRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingPolicyClaimAddressRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingPolicyRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingTypeRepository;
use Modules\Sirsoft\Ecommerce\Repositories\TempOrderRepository;
use Modules\Sirsoft\Ecommerce\Repositories\UserAddressRepository;
use Modules\Sirsoft\Ecommerce\Seo\EcommerceSitemapContributor;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyResolver;

/**
 * Ecommerce 모듈 서비스 프로바이더
 *
 * Repository 인터페이스와 구현체 바인딩을 담당합니다.
 */
class EcommerceServiceProvider extends BaseModuleServiceProvider
{
    /**
     * 모듈 식별자
     */
    protected string $moduleIdentifier = 'sirsoft-ecommerce';

    /**
     * StorageInterface가 필요한 서비스 목록
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [
        ProductReviewService::class,
        // 클레임 첨부는 신청자 본인과 운영자만 볼 수 있는 권한 게이트 대상이라 공개 자산
        // 디스크(CDN)에 올리지 않는다 — 직접 URL 은 서버 서빙 경로의 권한 검사를 우회한다
        // (`storage-driver.md` "보안 전제": 완전 공개 자산 카테고리에만 공개 디스크 적용).
        // 그래서 카테고리 배선이 아니라 기본 디스크를 주입한다. 저장 경로 접두사는 종전대로
        // 서비스가 `put('images', …)` 로 넘기는 값이 정하므로 기존 행의 경로는 바뀌지 않는다.
        ClaimAttachmentService::class,
    ];

    /**
     * 카테고리별 StorageInterface가 필요한 서비스 매핑 (클래스 ⇒ 카테고리)
     *
     * 이미지 서비스는 getStorageDiskFor('images') 가 결정한 디스크(공개 자산 디스크
     * 설정 반영)를 주입받아, put/getDisk() 행 기록이 자동으로 카테고리 디스크를 따릅니다.
     *
     * 권한 검사가 걸린 첨부는 여기에 등록하지 않습니다 (위 `$storageServices` 주석 참조).
     *
     * @var array<class-string, string>
     */
    protected array $storageCategoryServices = [
        CategoryImageService::class => 'images',
        ProductImageService::class => 'images',
        ProductReviewImageService::class => 'images',
    ];

    /**
     * Repository 인터페이스와 구현체 매핑
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        BrandRepositoryInterface::class => BrandRepository::class,
        CartRepositoryInterface::class => CartRepository::class,
        CategoryRepositoryInterface::class => CategoryRepository::class,
        CategoryImageRepositoryInterface::class => CategoryImageRepository::class,
        CouponIssueRepositoryInterface::class => CouponIssueRepository::class,
        CouponRepositoryInterface::class => CouponRepository::class,
        EcommerceStatRepositoryInterface::class => EcommerceStatRepository::class,
        ExtraFeeTemplateRepositoryInterface::class => ExtraFeeTemplateRepository::class,
        ClaimActivityLogRepositoryInterface::class => ClaimActivityLogRepository::class,
        ClaimImageRepositoryInterface::class => ClaimImageRepository::class,
        ClaimPaymentRepositoryInterface::class => ClaimPaymentRepository::class,
        OrderCancelRepositoryInterface::class => OrderCancelRepository::class,
        OrderCancelOptionRepositoryInterface::class => OrderCancelOptionRepository::class,
        OrderCashReceiptRepositoryInterface::class => OrderCashReceiptRepository::class,
        OrderOptionRepositoryInterface::class => OrderOptionRepository::class,
        OrderPaymentRepositoryInterface::class => OrderPaymentRepository::class,
        OrderRefundRepositoryInterface::class => OrderRefundRepository::class,
        OrderRefundOptionRepositoryInterface::class => OrderRefundOptionRepository::class,
        OrderExchangeRepositoryInterface::class => OrderExchangeRepository::class,
        OrderExchangeOptionRepositoryInterface::class => OrderExchangeOptionRepository::class,
        OrderPrivacyRetentionRepositoryInterface::class => OrderPrivacyRetentionRepository::class,
        OrderRepositoryInterface::class => OrderRepository::class,
        OrderReturnRepositoryInterface::class => OrderReturnRepository::class,
        OrderReturnOptionRepositoryInterface::class => OrderReturnOptionRepository::class,
        OrderShippingRepositoryInterface::class => OrderShippingRepository::class,
        ProductInquiryRepositoryInterface::class => ProductInquiryRepository::class,
        ProductLogRepositoryInterface::class => ProductLogRepository::class,
        ProductRepositoryInterface::class => ProductRepository::class,
        ProductImageRepositoryInterface::class => ProductImageRepository::class,
        ProductCommonInfoRepositoryInterface::class => ProductCommonInfoRepository::class,
        ProductLabelRepositoryInterface::class => ProductLabelRepository::class,
        ProductNoticeTemplateRepositoryInterface::class => ProductNoticeTemplateRepository::class,
        ProductOptionRepositoryInterface::class => ProductOptionRepository::class,
        ProductAdditionalOptionValueRepositoryInterface::class => ProductAdditionalOptionValueRepository::class,
        ProductReviewRepositoryInterface::class => ProductReviewRepository::class,
        ProductReviewImageRepositoryInterface::class => ProductReviewImageRepository::class,
        ClaimReasonRepositoryInterface::class => ClaimReasonRepository::class,
        SearchPresetRepositoryInterface::class => SearchPresetRepository::class,
        SequenceRepositoryInterface::class => SequenceRepository::class,
        ShippingCarrierRepositoryInterface::class => ShippingCarrierRepository::class,
        ShippingPolicyRepositoryInterface::class => ShippingPolicyRepository::class,
        ShippingPolicyClaimAddressRepositoryInterface::class => ShippingPolicyClaimAddressRepository::class,
        ShippingTypeRepositoryInterface::class => ShippingTypeRepository::class,
        TempOrderRepositoryInterface::class => TempOrderRepository::class,
        ProductWishlistRepositoryInterface::class => ProductWishlistRepository::class,
        UserAddressRepositoryInterface::class => UserAddressRepository::class,
        EcommerceUserProfileRepositoryInterface::class => EcommerceUserProfileRepository::class,
        MileageTransactionRepositoryInterface::class => MileageTransactionRepository::class,
        MileageBalanceRepositoryInterface::class => MileageBalanceRepository::class,
    ];

    /**
     * 등록할 Artisan 커맨드 목록
     *
     * @var array<int, class-string>
     */
    protected array $commands = [
        AggregateEcommerceStatsCommand::class,
        CancelOrphanClaimPaymentsCommand::class,
        CancelPendingPaymentOrdersCommand::class,
        EarnMileageCommand::class,
        ExpireMileageCommand::class,
        NotifyExpiringMileageCommand::class,
        NotifyOverdueCollectionsCommand::class,
        // E2E·실측 시드 — 3중 가드(CLI · G7_PLAYWRIGHT_BYPASS · APP_DEBUG)가 커맨드 안에 있다.
        PlaywrightSeedMemberData::class,
        PlaywrightSeedOrderRetention::class,
        PlaywrightSeedSiteDayOrders::class,
        PruneExpiredCartsCommand::class,
        PruneExpiredTempOrdersCommand::class,
        PrunePendingClaimImagesCommand::class,
        PruneTempProductImagesCommand::class,
        ReconcileMileageBalanceCommand::class,
    ];

    /**
     * 서비스 등록
     *
     * @return void
     */
    public function register(): void
    {
        parent::register();

        // CurrencyConversionService를 싱글톤으로 등록 (요청 내 통화 설정 캐시 유지)
        $this->app->singleton(CurrencyConversionService::class);

        // ShippingPolicyResolver를 싱글톤으로 등록 (요청 내 기본 배송정책 조회 1회 캐시)
        $this->app->singleton(ShippingPolicyResolver::class);

        // PaymentMethodResolver를 싱글톤으로 등록 (요청 내 결제수단 카탈로그 조회 1회 캐시)
        $this->app->singleton(PaymentMethodResolver::class);

        // EcommerceSettingsService를 싱글톤으로 등록 (공개 #116)
        // 위 3종과 달리 미등록이라 주입 지점마다 별개 인스턴스가 만들어졌고, 각자 자기 설정
        // 캐시를 들고 있었다. 싱글톤 리졸버가 비-싱글톤 설정 서비스를 captive 로 보유하는
        // 비대칭도 함께 생긴다. 쓰기 메서드가 모두 자기 캐시를 무효화하므로 공유해도 안전하다.
        $this->app->singleton(EcommerceSettingsService::class);
    }

    /**
     * 서비스 부트스트랩
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        $this->registerClaimMorphMap();
        $this->registerRateLimiters();

        // Artisan 커맨드 등록
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }

        // 요청 기기 유형(iOS) 감지 미들웨어(DetectDevice)는 코어 self-gate 방식으로 이관됨.
        // Module::getMiddleware() 가 targets=['/'](무명 User SSR 셸 URI 패턴)로 선언하고,
        // 코어 ExtensionMiddlewareGate 가 요청 시점에 실행한다. (SP Kernel 직접 조작 제거)

        // Sitemap 기여자 등록
        $this->app->booted(function () {
            if ($this->app->bound(SitemapGenerator::class)) {
                // Repository 주입을 위해 컨테이너로 해석합니다.
                $this->app->make(SitemapGenerator::class)->registerContributor(
                    $this->app->make(EcommerceSitemapContributor::class)
                );
            }
        });
    }

    /**
     * 비회원 공개 경로의 이름 있는 요청 빈도 리미터를 등록합니다.
     *
     * 라우트에 `throttle:N,1` 처럼 **이름 없이** 상한을 걸면 키가 요청자(IP) 하나로 정해져,
     * 같은 IP 에서 나간 모든 이름 없는 throttle 라우트가 한 카운터를 나눠 쓴다. 비회원 클레임
     * 블록(분당 120)·첨부 업로드(분당 20)·주문 조회 인증(분당 20)이 그렇게 섞여, 미리보기를
     * 스무 번 부르면 업로드와 조회 인증까지 429 가 됐다. 리미터마다 이름을 주면 키에 이름이
     * 들어가 카운터가 갈린다.
     *
     * 429 응답은 로그인 리미터와 같은 다국어 문구(`auth.too_many_attempts`)로 낸다 — 기본 응답은
     * 영문 "Too Many Attempts." 라 화면이 그대로 노출한다.
     *
     * @return void 반환값 없음
     */
    protected function registerRateLimiters(): void
    {
        $limits = [
            // 비회원 주문 조회 인증 — 무차별 대입 방어선 (#55)
            'sirsoft-ecommerce.guest-order-verify' => 20,
            // 비회원 클레임 블록 전체 — 신청·미리보기·목록·상세·수정·철회·첨부 삭제·추가결제
            'sirsoft-ecommerce.guest-claims' => 120,
            // 비회원 첨부 업로드 — 블록에서 유일한 파일 쓰기라 블록 상한 위에 좁은 상한을 겹친다
            'sirsoft-ecommerce.guest-claim-uploads' => 20,
        ];

        foreach ($limits as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
                ->by((string) $request->ip())
                ->response(fn (Request $request, array $headers) => ResponseHelper::error(
                    'auth.too_many_attempts',
                    429,
                    null,
                    // `count` 로 「1 second」/「2 seconds」 를 고른다.
                    ['seconds' => (int) ($headers['Retry-After'] ?? 60), 'count' => (int) ($headers['Retry-After'] ?? 60)]
                )->withHeaders($headers)));
        }
    }

    /**
     * 클레임 다형 관계의 morph map 을 등록합니다.
     *
     * 클레임 첨부(`ecommerce_claim_images.claimable_type`)와 추가결제
     * (`ecommerce_claim_payments.payable_type`)는 FQCN 대신 ClaimTypeEnum 값
     * (exchange/return/refund)을 저장한다. 저장값이 짧고 읽히며, 네임스페이스가
     * 바뀌어도 기존 행이 깨지지 않는다.
     *
     * enforceMorphMap 이 아니라 morphMap 을 쓴다 — 코어·타 확장이 등록하는 다형
     * 관계까지 이 목록으로 한정하면 그쪽이 조용히 깨진다.
     *
     * ## 별칭이 범용 단어다 — 충돌은 조용히 넘기지 않는다
     *
     * morph map 은 프로세스 전역 하나다. 다른 확장이 같은 별칭(`refund` 등)을 다른 모델로 먼저
     * 등록해 두었다면 나중 등록이 덮어써 **한쪽의 다형 관계가 오류 없이 엉뚱한 모델을 가리킨다.**
     * 별칭을 네임스페이스형으로 바꾸려면 저장된 모든 행(첨부·추가결제·환불 출처·활동로그)을
     * 이관해야 하는데, 1.3.0 은 미출시라 공개판 데이터에는 이 별칭이 아직 없다(1.2.x 는 이 네
     * 모델을 다형 대상으로 저장한 적이 없다). 그래서 별칭은 유지하고, 충돌하면 등록 시점에
     * 오류 로그로 드러낸다 — 어느 확장과 부딪혔는지가 로그에 남아야 운영자가 원인을 찾는다.
     *
     * @return void 반환값 없음
     */
    protected function registerClaimMorphMap(): void
    {
        $map = [
            ClaimTypeEnum::EXCHANGE->value => OrderExchange::class,
            ClaimTypeEnum::RETURN->value => OrderReturn::class,
            ClaimTypeEnum::REFUND->value => OrderRefund::class,
            // 취소 축도 등록한다(F9). 빠져 있으면 활동로그가 `loggable_type` 을 FQCN 으로
            // 저장하는데 클레임 상세의 활동로그 탭은 morph 별칭(`cancel`)으로 조회하므로,
            // 줄은 쌓이는데 화면은 **구조적으로 언제나 빈 표**가 된다.
            ClaimTypeEnum::CANCEL->value => OrderCancel::class,
        ];

        foreach ($map as $alias => $class) {
            $registered = Relation::getMorphedModel($alias);

            if ($registered !== null && $registered !== $class) {
                Log::error('[ecommerce] 클레임 morph 별칭이 다른 모델과 충돌합니다 — 나중 등록이 덮어씁니다', [
                    'alias' => $alias,
                    'registered' => $registered,
                    'ecommerce' => $class,
                ]);
            }
        }

        Relation::morphMap($map);
    }
}
