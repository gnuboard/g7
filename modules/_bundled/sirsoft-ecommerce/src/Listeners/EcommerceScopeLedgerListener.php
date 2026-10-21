<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\ScopedPermissionEnforcement;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController;
use Modules\Sirsoft\Ecommerce\Repositories\BrandRepository;
use Modules\Sirsoft\Ecommerce\Repositories\CartRepository;
use Modules\Sirsoft\Ecommerce\Repositories\Concerns\FiltersAdminClaimList;
use Modules\Sirsoft\Ecommerce\Repositories\CouponRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ExtraFeeTemplateRepository;
use Modules\Sirsoft\Ecommerce\Repositories\MileageTransactionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductReviewRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductWishlistRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingPolicyRepository;
use Modules\Sirsoft\Ecommerce\Repositories\UserAddressRepository;
use Modules\Sirsoft\Ecommerce\Services\BrandService;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Services\ClaimScopeGuard;
use Modules\Sirsoft\Ecommerce\Services\CouponService;
use Modules\Sirsoft\Ecommerce\Services\ExtraFeeTemplateService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductOptionService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Services\ProductWishlistService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;
use Modules\Sirsoft\Ecommerce\Services\UserAddressService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 이커머스 스코프 강제 지점 원장 리스너
 *
 * 권한 미들웨어는 라우트 파라미터 이름이 권한의 `resource_route_key` 와 같고 Model 로 바인딩될 때만
 * 스코프를 검사한다. 이 모듈의 관리자 라우트 상당수는 `{id}`·`{identifier}`·정적 `bulk` 경로라 미들웨어가
 * 건너뛰고, 서비스·저장소가 같은 판정을 재적용한다. 이 원장은 그 재적용 지점을 권한별로 적어
 * 코어 감사(`ScopedPermissionEnforcement::auditRoutes`·`auditEnforcers`)가 「바인딩도 원장도 없는 라우트」
 * 와 「이름이 바뀌어 낡은 강제 지점」을 테스트에서 잡게 한다. 런타임 동작은 바꾸지 않는다.
 *
 * 새 관리자 라우트를 스코프 대상 권한으로 열었는데 Model 바인딩이 아니면 여기에 라우트 이름과
 * 강제 메서드를 함께 적는다 — 빠뜨리면 `EcommerceScopedRouteLedgerTest` 가 실패한다.
 */
class EcommerceScopeLedgerListener implements HookListenerInterface
{
    /** 라우트 이름 접두 */
    private const R = 'api.modules.sirsoft-ecommerce.admin.';

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, type: string}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.permission.scope_enforcement_ledger' => [
                'method' => 'addLedgerEntries',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (필터 훅만 쓰므로 비어 있음)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 이커머스 원장 항목을 병합합니다.
     *
     * @param  array<string, array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}>  $ledger  지금까지의 원장
     * @return array<string, array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}> 병합된 원장
     */
    public function addLedgerEntries(array $ledger): array
    {
        return array_merge($ledger, self::entries());
    }

    /**
     * 이커머스 원장 항목 (권한 식별자 → 강제 방식·라우트·강제 메서드)
     *
     * `exempt` 에 적은 라우트는 그 권한이 지키는 소유 행을 응답하지 않아 범위로 좁힐 대상이 없다. 사유를 함께
     * 적어 감사가 「누락」 과 「의도된 비적용」 을 구분하게 한다.
     *
     * @return array<string, array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}> 원장 항목
     */
    public static function entries(): array
    {
        $r = fn (string ...$names) => array_map(fn ($n) => self::R.$n, $names);
        $presetsExempt = [self::R.'presets.*' => '검색 프리셋은 작성 관리자 본인 소유로 따로 강제되고 상품·주문·클레임 행을 응답하지 않는다'];

        return [
            // ── 상품 ──
            'sirsoft-ecommerce.products.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('products.index', 'products.options', 'products.show', 'products.show-by-code'),
                'exempt' => $presetsExempt,
                'paths' => [
                    ProductRepository::class.'::getListWithFilters',
                    ProductRepository::class.'::getOptionsGroupedByProductIds',
                    ProductService::class.'::findForAdminRead',
                ],
            ],
            'sirsoft-ecommerce.products.update' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r(
                    'products.update-by-code',
                    'products.bulk-update', 'products.bulk-status', 'products.bulk-price', 'products.bulk-stock',
                    'products.images.upload', 'products.images.delete', 'products.images.reorder', 'products.images.set-thumbnail',
                    'options.bulk-update', 'options.bulk-price', 'options.bulk-stock',
                ),
                'exempt' => $presetsExempt + [
                    self::R.'products.images.upload-temp' => '임시 업로드는 상품에 귀속되지 않은 temp_key 이미지라 상품 범위 대상이 없다',
                ],
                'paths' => [
                    ProductService::class.'::update',
                    ProductService::class.'::bulkUpdate',
                    ProductService::class.'::bulkUpdateStatus',
                    ProductService::class.'::bulkUpdatePrice',
                    ProductService::class.'::bulkUpdateStock',
                    ProductImageService::class.'::upload',
                    ProductImageService::class.'::delete',
                    ProductImageService::class.'::reorder',
                    ProductImageService::class.'::setThumbnail',
                    ProductImageService::class.'::assertProductsWithinUpdateScope',
                    ProductOptionService::class.'::bulkUpdate',
                    ProductOptionService::class.'::bulkUpdatePriceByMixedIds',
                    ProductOptionService::class.'::bulkUpdateStockByMixedIds',
                    ProductOptionService::class.'::assertOptionProductsWithinScope',
                ],
            ],

            // ── 브랜드 ──
            'sirsoft-ecommerce.brands.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('brands.index', 'brands.show'),
                'paths' => [BrandRepository::class.'::getAll', BrandService::class.'::getBrand'],
            ],
            'sirsoft-ecommerce.brands.update' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('brands.update', 'brands.toggle-status'),
                'paths' => [BrandService::class.'::updateBrand', BrandService::class.'::toggleStatus'],
            ],
            'sirsoft-ecommerce.brands.delete' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('brands.destroy'),
                'paths' => [BrandService::class.'::deleteBrand'],
            ],

            // ── 배송정책 · 추가배송비 템플릿 (같은 권한 카테고리) ──
            'sirsoft-ecommerce.shipping-policies.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r(
                    'shipping-policies.index', 'shipping-policies.show', 'shipping-policies.claim-settings.show',
                    'extra-fee-templates.index', 'extra-fee-templates.show',
                ),
                'exempt' => [
                    // 참조 선택지는 범위로 거르지 않는다 — 상세·수정은 범위 안에서만 열린다
                    self::R.'shipping-policies.active' => '참조 목록 — 상품·주문 화면의 배송정책 선택지, 상세·수정은 범위 안에서만 열림',
                    self::R.'extra-fee-templates.active-settings' => '참조 목록 — 배송정책 폼의 추가배송비 불러오기 선택지, 상세·수정은 범위 안에서만 열림',
                ],
                'paths' => [
                    ShippingPolicyRepository::class.'::getListWithFilters',
                    ShippingPolicyService::class.'::getDetailForRead',
                    ExtraFeeTemplateRepository::class.'::getListWithFilters',
                    ExtraFeeTemplateService::class.'::getDetailForRead',
                ],
            ],
            'sirsoft-ecommerce.shipping-policies.update' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r(
                    'shipping-policies.update', 'shipping-policies.toggle-active', 'shipping-policies.set-default',
                    'shipping-policies.bulk-toggle-active', 'shipping-policies.claim-settings.update',
                    'extra-fee-templates.update', 'extra-fee-templates.toggle-active', 'extra-fee-templates.bulk-toggle-active',
                ),
                'exempt' => [
                    self::R.'shipping-policies.test-api-call' => '외부 배송비 API 시험 호출 — 저장된 배송정책 행을 읽거나 바꾸지 않는다',
                ],
                'paths' => [
                    ShippingPolicyService::class.'::update',
                    ShippingPolicyService::class.'::toggleActive',
                    ShippingPolicyService::class.'::setDefault',
                    ShippingPolicyService::class.'::bulkToggleActive',
                    ShippingPolicyService::class.'::updateClaimSettings',
                    ExtraFeeTemplateService::class.'::update',
                    ExtraFeeTemplateService::class.'::toggleActive',
                    ExtraFeeTemplateService::class.'::bulkToggleActive',
                ],
            ],
            'sirsoft-ecommerce.shipping-policies.delete' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('shipping-policies.destroy', 'shipping-policies.bulk-destroy', 'extra-fee-templates.destroy', 'extra-fee-templates.bulk-destroy'),
                'paths' => [
                    ShippingPolicyService::class.'::delete',
                    ShippingPolicyService::class.'::bulkDelete',
                    ExtraFeeTemplateService::class.'::delete',
                    ExtraFeeTemplateService::class.'::bulkDelete',
                ],
            ],

            // ── 주문 ── (`{order}` 바인딩 라우트는 미들웨어가 판정하므로 적지 않는다)
            'sirsoft-ecommerce.orders.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('orders.index'),
                'exempt' => $presetsExempt,
                'paths' => [OrderRepository::class.'::newScopedListQuery'],
            ],
            'sirsoft-ecommerce.orders.update' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('orders.bulk', 'orders.bulk.manual-paid-preview'),
                'paths' => [
                    OrderService::class.'::bulkUpdate',
                    OrderService::class.'::bulkUpdateStatus',
                    OrderService::class.'::bulkUpdateShipping',
                    OrderService::class.'::previewManualPaidEffectsForBulk',
                ],
            ],

            // ── 클레임 ── (판정 대상 = 신청서의 주문, ClaimScopeGuard)
            'sirsoft-ecommerce.claims.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('claims.index', 'claims.show', 'claims.show-by-number', 'claims.logs'),
                'exempt' => $presetsExempt + [
                    self::R.'claim-reasons.active' => '클레임 사유 설정 목록 — 주문·신청서 행을 응답하지 않는다',
                ],
                'paths' => [
                    FiltersAdminClaimList::class.'::applyAdminClaimScope',
                    ClaimScopeGuard::class.'::assertAccessible',
                    ClaimScopeGuard::class.'::assertOrderAccessible',
                    ClaimController::class.'::show',
                    ClaimController::class.'::showByNumber',
                    ClaimController::class.'::logs',
                ],
            ],
            'sirsoft-ecommerce.claims.update-status' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r(
                    'claims.status', 'claims.bulk-status', 'claims.completion-preview', 'claims.options.bulk-status',
                    'claims.withdraw', 'claims.update', 'claims.options.tracking', 'claims.tracking.deliver',
                    'claims.refund-request', 'claims.items.restore-stock', 'claims.exchange-mode',
                    'claims.images.store', 'claims.images.clear', 'claims.images.destroy',
                ),
                'paths' => [
                    ClaimScopeGuard::class.'::assertIdsAccessible',
                    FiltersAdminClaimList::class.'::countWithinAdminScope',
                    ClaimController::class.'::updateStatus',
                    ClaimController::class.'::bulkStatus',
                    ClaimController::class.'::completionPreview',
                    ClaimController::class.'::bulkOptionStatus',
                    ClaimController::class.'::withdrawOnBehalf',
                    ClaimController::class.'::updateRequest',
                    ClaimController::class.'::updateItemTracking',
                    ClaimController::class.'::deliverTracking',
                    ClaimController::class.'::storeRefundRequest',
                    ClaimController::class.'::restoreStock',
                    ClaimController::class.'::updateExchangeMode',
                    ClaimController::class.'::storeImage',
                    ClaimController::class.'::destroyImages',
                    ClaimController::class.'::destroyImage',
                ],
            ],
            'sirsoft-ecommerce.claims.update-amounts' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('claims.amounts'),
                'paths' => [ClaimController::class.'::updateAmounts'],
            ],
            'sirsoft-ecommerce.claims.execute-refund' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('claims.refunds.execute'),
                'paths' => [ClaimController::class.'::executeRefund'],
            ],

            // ── 리뷰 ──
            'sirsoft-ecommerce.reviews.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('reviews.index'),
                'paths' => [ProductReviewRepository::class.'::getListWithFilters'],
            ],
            'sirsoft-ecommerce.reviews.update' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('reviews.bulk'),
                'paths' => [ProductReviewService::class.'::bulkUpdateStatus', ProductReviewService::class.'::bulkDelete'],
            ],

            // ── 쿠폰 ──
            'sirsoft-ecommerce.promotion-coupon.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('promotion-coupons.index', 'promotion-coupons.show', 'promotion-coupons.issues'),
                'paths' => [
                    CouponRepository::class.'::paginate',
                    CouponService::class.'::getCoupon',
                    CouponService::class.'::getCouponIssues',
                ],
            ],
            'sirsoft-ecommerce.promotion-coupon.update' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('promotion-coupons.update', 'promotion-coupons.bulk-status', 'promotion-coupons.issue-direct', 'promotion-coupons.issues.cancel'),
                'paths' => [
                    CouponService::class.'::updateCoupon',
                    CouponService::class.'::bulkUpdateIssueStatus',
                    CouponService::class.'::issueDirectly',
                    CouponService::class.'::cancelIssue',
                ],
            ],
            'sirsoft-ecommerce.promotion-coupon.delete' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('promotion-coupons.destroy'),
                'paths' => [CouponService::class.'::deleteCoupon'],
            ],

            // ── 회원 소유 데이터 (찜 · 장바구니 · 배송지) ──
            'sirsoft-ecommerce.wishlists.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('wishlists.index'),
                'paths' => [ProductWishlistRepository::class.'::paginateForAdmin'],
            ],
            'sirsoft-ecommerce.wishlists.manage' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('wishlists.destroy', 'wishlists.bulk-destroy'),
                'paths' => [ProductWishlistService::class.'::deleteByAdmin', ProductWishlistService::class.'::bulkDeleteByAdmin'],
            ],
            'sirsoft-ecommerce.carts.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('carts.index'),
                'paths' => [CartRepository::class.'::paginateForAdmin'],
            ],
            'sirsoft-ecommerce.carts.manage' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('carts.destroy', 'carts.bulk-destroy'),
                'paths' => [CartService::class.'::deleteByAdmin', CartService::class.'::bulkDeleteByAdmin'],
            ],
            'sirsoft-ecommerce.user-addresses.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('user-addresses.index'),
                'paths' => [UserAddressRepository::class.'::paginateForAdmin'],
            ],
            'sirsoft-ecommerce.user-addresses.manage' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('user-addresses.destroy', 'user-addresses.bulk-destroy'),
                'paths' => [UserAddressService::class.'::deleteByAdmin', UserAddressService::class.'::bulkDeleteByAdmin'],
            ],

            // ── 마일리지 ──
            'sirsoft-ecommerce.mileage.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => $r('mileage-transactions.index', 'mileage-transactions.linked'),
                'paths' => [
                    MileageTransactionRepository::class.'::paginateWithFilters',
                    UserMileageService::class.'::getLinkedTransactions',
                ],
            ],
            'sirsoft-ecommerce.mileage.manage' => [
                'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
                'routes' => $r('mileage-transactions.store', 'mileage-transactions.update', 'mileage-transactions.extend-expiry'),
                'paths' => [
                    UserMileageService::class.'::adminEarn',
                    UserMileageService::class.'::adminDeduct',
                    UserMileageService::class.'::updateAdminTransaction',
                    UserMileageService::class.'::extendLotExpiry',
                    UserMileageService::class.'::assertMemberWithinManageScope',
                ],
            ],
        ];
    }
}
