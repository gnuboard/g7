<?php

namespace Modules\Sirsoft\Ecommerce\Providers;

use App\Extension\BaseModuleServiceProvider;
use Modules\Sirsoft\Ecommerce\Console\Commands\CancelPendingPaymentOrdersCommand;
use Modules\Sirsoft\Ecommerce\Repositories\BrandRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CartRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CategoryImageRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CategoryRepository;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\BrandRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExtraFeeTemplateRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\CouponIssueRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CouponRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ExtraFeeTemplateRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductInquiryRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductLogRepository;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductCommonInfoRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductLabelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductNoticeTemplateRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductLogRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SearchPresetRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SequenceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingCarrierRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\TempOrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\ClaimReasonRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderOptionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderShippingRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingCarrierRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingPolicyRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingTypeRepository;
use Modules\Sirsoft\Ecommerce\Repositories\TempOrderRepository;
use Modules\Sirsoft\Ecommerce\Repositories\UserAddressRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductImageRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductCommonInfoRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductLabelRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductNoticeTemplateRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductOptionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductReviewRepository;
use Modules\Sirsoft\Ecommerce\Repositories\SearchPresetRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductWishlistRepository;
use Modules\Sirsoft\Ecommerce\Repositories\SequenceRepository;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;

/**
 * Ecommerce 모듈 서비스 프로바이더
 *
 * Repository 인터페이스와 구현체 바인딩을 담당합니다.
 */
class EcommerceServiceProvider extends BaseModuleServiceProvider
{
    /**
     * 모듈 식별자
     *
     * @var string
     */
    protected string $moduleIdentifier = 'sirsoft-ecommerce';

    /**
     * StorageInterface가 필요한 서비스 목록
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [
        CategoryImageService::class,
        ProductImageService::class,
        ProductReviewService::class,
        ProductReviewImageService::class,
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
        ExtraFeeTemplateRepositoryInterface::class => ExtraFeeTemplateRepository::class,
        OrderOptionRepositoryInterface::class => OrderOptionRepository::class,
        OrderRepositoryInterface::class => OrderRepository::class,
        OrderShippingRepositoryInterface::class => OrderShippingRepository::class,
        ProductInquiryRepositoryInterface::class => ProductInquiryRepository::class,
        ProductLogRepositoryInterface::class => ProductLogRepository::class,
        ProductRepositoryInterface::class => ProductRepository::class,
        ProductImageRepositoryInterface::class => ProductImageRepository::class,
        ProductCommonInfoRepositoryInterface::class => ProductCommonInfoRepository::class,
        ProductLabelRepositoryInterface::class => ProductLabelRepository::class,
        ProductNoticeTemplateRepositoryInterface::class => ProductNoticeTemplateRepository::class,
        ProductOptionRepositoryInterface::class => ProductOptionRepository::class,
        ProductReviewRepositoryInterface::class => ProductReviewRepository::class,
        ClaimReasonRepositoryInterface::class => ClaimReasonRepository::class,
        SearchPresetRepositoryInterface::class => SearchPresetRepository::class,
        SequenceRepositoryInterface::class => SequenceRepository::class,
        ShippingCarrierRepositoryInterface::class => ShippingCarrierRepository::class,
        ShippingPolicyRepositoryInterface::class => ShippingPolicyRepository::class,
        ShippingTypeRepositoryInterface::class => ShippingTypeRepository::class,
        TempOrderRepositoryInterface::class => TempOrderRepository::class,
        ProductWishlistRepositoryInterface::class => ProductWishlistRepository::class,
        UserAddressRepositoryInterface::class => UserAddressRepository::class,
    ];

    /**
     * 등록할 Artisan 커맨드 목록
     *
     * @var array<int, class-string>
     */
    protected array $commands = [
        CancelPendingPaymentOrdersCommand::class,
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
    }

    /**
     * 서비스 부트스트랩
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        // Artisan 커맨드 등록
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }

        // Sitemap 기여자 등록
        $this->app->booted(function () {
            if ($this->app->bound(\App\Seo\SitemapGenerator::class)) {
                $this->app->make(\App\Seo\SitemapGenerator::class)->registerContributor(
                    new \Modules\Sirsoft\Ecommerce\Seo\EcommerceSitemapContributor()
                );
            }
        });
    }
}
