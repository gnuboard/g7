<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Extension\AbstractModule;
use App\Notifications\Identity\IdentityNotificationType;
use App\Seo\Concerns\LocalizesSeoValues;
use App\Support\SiteUrl;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Benchmark\OrderCreationBenchmark;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\SequenceSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Middleware\DetectDevice;
use Modules\Sirsoft\Ecommerce\Http\Middleware\ResolveShippingCountry;
use Modules\Sirsoft\Ecommerce\Http\Middleware\VerifyGuestOrderToken;
use Modules\Sirsoft\Ecommerce\Listeners\ActivityLogDescriptionResolver;
use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultCurrencyOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultShippingCountryOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Listeners\CategoryActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\CategoryTreeCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\Ckeditor5ReferenceSourcesListener;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Listeners\CloseUnpaidVbankOnCancelListener;
use Modules\Sirsoft\Ecommerce\Listeners\CouponActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\CouponRestoreListener;
use Modules\Sirsoft\Ecommerce\Listeners\CouponUseListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceAdminActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceUserActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\InjectAppConfigDeviceListener;
use Modules\Sirsoft\Ecommerce\Listeners\IssueCashReceiptOnDepositListener;
use Modules\Sirsoft\Ecommerce\Listeners\MergeCartOnLoginListener;
use Modules\Sirsoft\Ecommerce\Listeners\MileageTransactionListener;
use Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\OrderStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Listeners\PrivacyRetentionListener;
use Modules\Sirsoft\Ecommerce\Listeners\ProductActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\ProductInquiryBoardListener;
use Modules\Sirsoft\Ecommerce\Listeners\ProvideNecessaryStorageKeysListener;
use Modules\Sirsoft\Ecommerce\Listeners\PurgeCashReceiptIdentifierListener;
use Modules\Sirsoft\Ecommerce\Listeners\SearchProductsListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoCategoryCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoProductCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoSettingsCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\ShippingPolicyActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\ShippingPolicyCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\SyncOptionGroupsListener;
use Modules\Sirsoft\Ecommerce\Listeners\SyncProductFromOptionListener;
use Modules\Sirsoft\Ecommerce\Listeners\SystemActorContextListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserCurrencyInfoListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserEcommerceDataInfoListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserListAddressFilterListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserListMileageFilterListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserMileageCleanupListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserMileageInfoListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserPreferenceCoreSaveListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserShippingCountryInfoListener;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Support\ClaimNotificationHooks;

class Module extends AbstractModule
{
    /**
     * 환율 제공자 등록과 적용 결과의 공개 훅 계약을 선언합니다.
     *
     * @return array 환율 확장 훅 정의
     */
    public function getHooks(): array
    {
        return [
            ['name' => 'sirsoft-ecommerce.currency.registered_rate_providers', 'type' => 'filter',
                'description' => ['ko' => '환율 제공자 목록', 'en' => 'Exchange rate providers'],
                'parameters' => ['providers' => 'array - 등록할 제공자 목록'], 'return' => 'array'],
            ['name' => 'sirsoft-ecommerce.currency.after_apply_rates', 'type' => 'action',
                'description' => ['ko' => '환율 적용 후', 'en' => 'After applying exchange rates'],
                'parameters' => ['result' => 'array - 환율 적용 결과', 'source' => 'string - 환율 제공자 식별자']],
            ['name' => 'sirsoft-ecommerce.order.filter_payment_method_display_label', 'type' => 'filter',
                'description' => ['ko' => '관리자 주문 목록 결제수단 표시 라벨', 'en' => 'Admin order list payment method display label'],
                'parameters' => ['label' => 'string|null - 앞선 구독자가 채운 표시 라벨', 'payment' => 'OrderPayment - 주문 결제 레코드'],
                'return' => 'string|null'],
            ['name' => 'sirsoft-ecommerce.checkout.filter_payment_methods_for_currency', 'type' => 'filter',
                'description' => ['ko' => '결제 통화에서 고를 수 있는 결제수단 (뺄 수만 있음)', 'en' => 'Payment methods selectable for a payment currency (remove only)'],
                'parameters' => ['methodIds' => 'array - 후보 결제수단 id', 'currency' => 'string - 결제 통화 (ISO 4217)'],
                'return' => 'array'],
        ];
    }

    use LocalizesSeoValues;

    /**
     * 운영자에게도 통보하는 클레임 상태 키.
     *
     * **운영자 조작이 일으킨 전이는 여기 들어오지 않는다** — 자기가 방금 누른 결과를
     * 자기에게 메일로 되돌려 보내는 알림은 소음이다. 고객·결제가 일으킨 사건만 고른다.
     *
     * - `requested` : 고객이 신청했다 → 운영자가 심사해야 한다
     * - `payment_completed` : 추가결제가 들어왔다 → 운영자가 다음 공정을 시작해야 한다
     *
     * @var array<int, string>
     */
    private const STAFF_NOTIFIED_CLAIM_KEYS = ['requested', 'payment_completed'];

    /**
     * 모듈 역할 정의
     * 모듈 설치 시 자동 생성됨
     */
    public function getRoles(): array
    {
        return [
            [
                'identifier' => 'sirsoft-ecommerce.manager',
                'name' => [
                    'ko' => '이커머스 관리자',
                    'en' => 'Ecommerce Manager',
                ],
                'description' => [
                    'ko' => '이커머스 관리 권한을 가진 역할',
                    'en' => 'Role with ecommerce management permissions',
                ],
            ],
        ];
    }

    /**
     * 모듈 권한 목록 반환 (계층형 구조, 다국어 지원)
     *
     * 구조: 모듈(1레벨) → 카테고리(2레벨) → 개별 권한(3레벨)
     * identifier는 자동 생성됨: {module}.{category}.{action}
     */
    public function getPermissions(): array
    {
        return [
            'name' => [
                'ko' => '이커머스',
                'en' => 'Ecommerce',
            ],
            'description' => [
                'ko' => '이커머스 모듈 권한',
                'en' => 'Ecommerce module permissions',
            ],
            'categories' => [
                // 상품 관리 권한
                [
                    'identifier' => 'products',
                    'resource_route_key' => 'product',
                    'owner_key' => 'created_by',
                    'name' => [
                        'ko' => '상품 관리',
                        'en' => 'Product Management',
                    ],
                    'description' => [
                        'ko' => '상품 관리 권한',
                        'en' => 'Product management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '상품 조회',
                                'en' => 'Read Products',
                            ],
                            'description' => [
                                'ko' => '상품 목록 및 상세 조회',
                                'en' => 'Read product list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '상품 생성',
                                'en' => 'Create Product',
                            ],
                            'description' => [
                                'ko' => '새 상품 등록',
                                'en' => 'Create new product',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '상품 수정',
                                'en' => 'Update Product',
                            ],
                            'description' => [
                                'ko' => '상품 정보 수정',
                                'en' => 'Update product information',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '상품 삭제',
                                'en' => 'Delete Product',
                            ],
                            'description' => [
                                'ko' => '상품 삭제',
                                'en' => 'Delete product',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 주문 관리 권한
                [
                    'identifier' => 'orders',
                    'resource_route_key' => 'order',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '주문 관리',
                        'en' => 'Order Management',
                    ],
                    'description' => [
                        'ko' => '주문 관리 권한',
                        'en' => 'Order management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '주문 조회',
                                'en' => 'Read Orders',
                            ],
                            'description' => [
                                'ko' => '주문 목록 및 상세 조회',
                                'en' => 'Read order list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '주문 수정',
                                'en' => 'Update Order',
                            ],
                            'description' => [
                                'ko' => '주문 상태 변경',
                                'en' => 'Update order status',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        // 주문 행을 지우는 권한 — 관리자 주문 삭제 API 와 개인정보 보유기간 「삭제」 방식 직권 실행이 요구한다.
                        // 되돌릴 수 없는 파기라 기본은 admin 역할에만 준다(7.1.0). 기존 설치본은 업데이트 때 선언형 동기화가
                        // 붙이고, 업그레이드 스텝(1.3.0 · GrantOrderDeletePermissionToAdmin)이 한 번 더 확인한다.
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '주문 삭제',
                                'en' => 'Delete Order',
                            ],
                            'description' => [
                                'ko' => '주문 삭제 및 개인정보 보유기간 「삭제」 방식 직권 실행',
                                'en' => 'Delete orders and run the privacy retention "delete" mode manually',
                            ],
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                // 클레임(교환/반품/환불) 관리 권한
                //
                // 스코프 축은 **주문의 것을 물려받는다**(`orders` 와 같은 `order` · `user_id`). 신청서는
                // 언제나 한 주문에 속하고, 그 주문을 볼 수 없는 운영자가 신청서로는 같은 고객의 주문
                // 내용·환불 계좌를 보거나 환불을 실행할 수 있으면 주문 스코프가 우회된다. 판정 대상
                // 모델은 신청서가 아니라 **그 주문**이다 — `{order}` 로 묶인 라우트는 미들웨어가,
                // `{id}` 로 신청서를 받는 라우트는 `ClaimScopeGuard` 가, 목록은 저장소가
                // (`FiltersAdminClaimList::applyAdminClaimScope()`) 같은 판정을 한다. 판정은 클레임 권한
                // 범위와 그 운영자의 주문 조회(`orders.read`) 범위의 교집합이다 — 클레임 권한을 「전체」 로
                // 두어도 주문 조회 범위를 넘지 못한다(`Support\ClaimScopePermissions`).
                [
                    'identifier' => 'claims',
                    'resource_route_key' => 'order',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '취소/반품/교환 관리',
                        'en' => 'Claim Management',
                    ],
                    'description' => [
                        'ko' => '교환/반품/환불 신청서 관리 권한',
                        'en' => 'Exchange, return and refund claim permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '클레임 조회',
                                'en' => 'Read Claims',
                            ],
                            'description' => [
                                'ko' => '교환/반품/환불 신청 목록 및 상세 조회',
                                'en' => 'Read exchange, return and refund claim list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update-status',
                            'name' => [
                                'ko' => '클레임 상태 변경',
                                'en' => 'Update Claim Status',
                            ],
                            'description' => [
                                'ko' => '승인/보류/반려·회수·재발송 등 상태 전이 및 송장 입력',
                                'en' => 'Approve, hold, reject, pickup, reship transitions and tracking input',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            // 관리자 직권 접수(§3-11) — 고객을 대신해 교환·반품·취소·환불을 접수한다.
                            // 상태 전이와 분리한다: 접수는 수량 잠금·고객 알림·결제 요청을 새로 만드는 행위다.
                            'action' => 'create',
                            'name' => [
                                'ko' => '클레임 직권 접수',
                                'en' => 'Create Claims on Behalf',
                            ],
                            'description' => [
                                'ko' => '주문 상세에서 고객을 대신해 교환·반품·취소·환불 접수',
                                'en' => 'Request exchange, return, cancellation or refund on behalf of the customer from the order detail',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update-amounts',
                            'name' => [
                                'ko' => '클레임 금액 수정',
                                'en' => 'Update Claim Amounts',
                            ],
                            'description' => [
                                'ko' => '클레임 배송비·차액·환불 예정액 수정',
                                'en' => 'Update claim shipping fee, price difference and refundable amount',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'execute-refund',
                            'name' => [
                                'ko' => '클레임 환불 실행',
                                'en' => 'Execute Claim Refund',
                            ],
                            'description' => [
                                'ko' => '환불 실행(PG 부분취소/마일리지/계좌)',
                                'en' => 'Execute refund via PG partial cancel, mileage or bank transfer',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 카테고리 관리 권한
                [
                    'identifier' => 'categories',
                    'name' => [
                        'ko' => '카테고리 관리',
                        'en' => 'Category Management',
                    ],
                    'description' => [
                        'ko' => '카테고리 관리 권한',
                        'en' => 'Category management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '카테고리 조회',
                                'en' => 'Read Categories',
                            ],
                            'description' => [
                                'ko' => '카테고리 목록 및 상세 조회',
                                'en' => 'Read category list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '카테고리 생성',
                                'en' => 'Create Category',
                            ],
                            'description' => [
                                'ko' => '카테고리 생성',
                                'en' => 'Create category',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '카테고리 수정',
                                'en' => 'Update Category',
                            ],
                            'description' => [
                                'ko' => '카테고리 수정',
                                'en' => 'Update category',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '카테고리 삭제',
                                'en' => 'Delete Category',
                            ],
                            'description' => [
                                'ko' => '카테고리 삭제',
                                'en' => 'Delete category',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 브랜드 관리 권한
                [
                    'identifier' => 'brands',
                    'resource_route_key' => 'brand',
                    'owner_key' => 'created_by',
                    'name' => [
                        'ko' => '브랜드 관리',
                        'en' => 'Brand Management',
                    ],
                    'description' => [
                        'ko' => '브랜드 관리 권한',
                        'en' => 'Brand management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '브랜드 조회',
                                'en' => 'Read Brands',
                            ],
                            'description' => [
                                'ko' => '브랜드 목록 및 상세 조회',
                                'en' => 'Read brand list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '브랜드 생성',
                                'en' => 'Create Brand',
                            ],
                            'description' => [
                                'ko' => '브랜드 생성',
                                'en' => 'Create brand',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '브랜드 수정',
                                'en' => 'Update Brand',
                            ],
                            'description' => [
                                'ko' => '브랜드 수정',
                                'en' => 'Update brand',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '브랜드 삭제',
                                'en' => 'Delete Brand',
                            ],
                            'description' => [
                                'ko' => '브랜드 삭제',
                                'en' => 'Delete brand',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 상품정보제공고시 관리 권한
                [
                    'identifier' => 'product-notice-templates',
                    'name' => [
                        'ko' => '상품정보제공고시 관리',
                        'en' => 'Product Notice Template Management',
                    ],
                    'description' => [
                        'ko' => '상품정보제공고시 템플릿 관리 권한',
                        'en' => 'Product notice template management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '조회',
                                'en' => 'Read',
                            ],
                            'description' => [
                                'ko' => '상품정보제공고시 조회',
                                'en' => 'Read product notice templates',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '생성',
                                'en' => 'Create',
                            ],
                            'description' => [
                                'ko' => '상품정보제공고시 생성',
                                'en' => 'Create product notice template',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '수정',
                                'en' => 'Update',
                            ],
                            'description' => [
                                'ko' => '상품정보제공고시 수정',
                                'en' => 'Update product notice template',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '삭제',
                                'en' => 'Delete',
                            ],
                            'description' => [
                                'ko' => '상품정보제공고시 삭제',
                                'en' => 'Delete product notice template',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 공통정보 관리 권한
                [
                    'identifier' => 'product-common-infos',
                    'name' => [
                        'ko' => '공통정보 관리',
                        'en' => 'Product Common Info Management',
                    ],
                    'description' => [
                        'ko' => '상품 공통정보 관리 권한',
                        'en' => 'Product common info management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '조회',
                                'en' => 'Read',
                            ],
                            'description' => [
                                'ko' => '공통정보 조회',
                                'en' => 'Read product common info',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '생성',
                                'en' => 'Create',
                            ],
                            'description' => [
                                'ko' => '공통정보 생성',
                                'en' => 'Create product common info',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '수정',
                                'en' => 'Update',
                            ],
                            'description' => [
                                'ko' => '공통정보 수정',
                                'en' => 'Update product common info',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '삭제',
                                'en' => 'Delete',
                            ],
                            'description' => [
                                'ko' => '공통정보 삭제',
                                'en' => 'Delete product common info',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 환경설정 권한
                [
                    'identifier' => 'settings',
                    'name' => [
                        'ko' => '환경설정',
                        'en' => 'Settings',
                    ],
                    'description' => [
                        'ko' => '이커머스 환경설정 권한',
                        'en' => 'Ecommerce settings permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '환경설정 조회',
                                'en' => 'View Settings',
                            ],
                            'description' => [
                                'ko' => '이커머스 환경설정 조회',
                                'en' => 'View ecommerce settings',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '환경설정 수정',
                                'en' => 'Update Settings',
                            ],
                            'description' => [
                                'ko' => '이커머스 환경설정 수정',
                                'en' => 'Update ecommerce settings',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 쿠폰 관리 권한
                [
                    'identifier' => 'promotion-coupon',
                    'resource_route_key' => 'coupon',
                    'owner_key' => 'created_by',
                    'name' => [
                        'ko' => '쿠폰 관리',
                        'en' => 'Coupon Management',
                    ],
                    'description' => [
                        'ko' => '쿠폰 관리 권한',
                        'en' => 'Coupon management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '쿠폰 조회',
                                'en' => 'Read Coupons',
                            ],
                            'description' => [
                                'ko' => '쿠폰 목록 및 상세 조회',
                                'en' => 'Read coupon list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '쿠폰 생성',
                                'en' => 'Create Coupon',
                            ],
                            'description' => [
                                'ko' => '새 쿠폰 등록',
                                'en' => 'Create new coupon',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '쿠폰 수정',
                                'en' => 'Update Coupon',
                            ],
                            'description' => [
                                'ko' => '쿠폰 정보 수정',
                                'en' => 'Update coupon information',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '쿠폰 삭제',
                                'en' => 'Delete Coupon',
                            ],
                            'description' => [
                                'ko' => '쿠폰 삭제',
                                'en' => 'Delete coupon',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 배송정책 관리 권한
                [
                    'identifier' => 'shipping-policies',
                    'resource_route_key' => 'shippingPolicy',
                    'owner_key' => 'created_by',
                    'name' => [
                        'ko' => '배송정책 관리',
                        'en' => 'Shipping Policy Management',
                    ],
                    'description' => [
                        'ko' => '배송정책 관리 권한',
                        'en' => 'Shipping policy management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '배송정책 조회',
                                'en' => 'Read Shipping Policies',
                            ],
                            'description' => [
                                'ko' => '배송정책 목록 및 상세 조회',
                                'en' => 'Read shipping policy list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '배송정책 생성',
                                'en' => 'Create Shipping Policy',
                            ],
                            'description' => [
                                'ko' => '새 배송정책 등록',
                                'en' => 'Create new shipping policy',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '배송정책 수정',
                                'en' => 'Update Shipping Policy',
                            ],
                            'description' => [
                                'ko' => '배송정책 정보 수정',
                                'en' => 'Update shipping policy information',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '배송정책 삭제',
                                'en' => 'Delete Shipping Policy',
                            ],
                            'description' => [
                                'ko' => '배송정책 삭제',
                                'en' => 'Delete shipping policy',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 상품 라벨 관리 권한
                [
                    'identifier' => 'product-labels',
                    'name' => [
                        'ko' => '상품 라벨 관리',
                        'en' => 'Product Label Management',
                    ],
                    'description' => [
                        'ko' => '상품 라벨 관리 권한',
                        'en' => 'Product label management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '상품 라벨 조회',
                                'en' => 'Read Product Labels',
                            ],
                            'description' => [
                                'ko' => '상품 라벨 목록 및 상세 조회',
                                'en' => 'Read product label list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '상품 라벨 생성',
                                'en' => 'Create Product Label',
                            ],
                            'description' => [
                                'ko' => '새 상품 라벨 등록',
                                'en' => 'Create new product label',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '상품 라벨 수정',
                                'en' => 'Update Product Label',
                            ],
                            'description' => [
                                'ko' => '상품 라벨 정보 수정',
                                'en' => 'Update product label information',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '상품 라벨 삭제',
                                'en' => 'Delete Product Label',
                            ],
                            'description' => [
                                'ko' => '상품 라벨 삭제',
                                'en' => 'Delete product label',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],

                // 본인인증 정책 관리 권한
                [
                    'identifier' => 'identity.policies',
                    'name' => [
                        'ko' => '이커머스 본인인증 정책',
                        'en' => 'Ecommerce Identity Policies',
                    ],
                    'description' => [
                        'ko' => '이커머스 컨텍스트의 본인인증 정책 관리 권한',
                        'en' => 'Manage identity verification policies in ecommerce context',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '본인인증 정책 조회',
                                'en' => 'View Identity Policies',
                            ],
                            'description' => [
                                'ko' => '이커머스 본인인증 정책 조회',
                                'en' => 'View ecommerce identity policies',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '본인인증 정책 수정',
                                'en' => 'Update Identity Policies',
                            ],
                            'description' => [
                                'ko' => '이커머스 본인인증 정책 수정/추가/삭제',
                                'en' => 'Update, add, delete ecommerce identity policies',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                // 리뷰 관리 권한
                [
                    'identifier' => 'reviews',
                    'resource_route_key' => 'review',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '리뷰 관리',
                        'en' => 'Review Management',
                    ],
                    'description' => [
                        'ko' => '상품 리뷰 관리 권한',
                        'en' => 'Product review management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '리뷰 조회',
                                'en' => 'Read Reviews',
                            ],
                            'description' => [
                                'ko' => '리뷰 목록 및 상세 조회',
                                'en' => 'Read review list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '리뷰 처리',
                                'en' => 'Manage Review',
                            ],
                            'description' => [
                                'ko' => '리뷰 상태 변경, 답변 등록/수정/삭제, 일괄 처리',
                                'en' => 'Update review status, manage replies, bulk actions',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '리뷰 삭제',
                                'en' => 'Delete Review',
                            ],
                            'description' => [
                                'ko' => '리뷰 삭제',
                                'en' => 'Delete review',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],

                // 문의 관리 권한
                [
                    'identifier' => 'inquiries',
                    'name' => [
                        'ko' => '문의 관리',
                        'en' => 'Inquiry Management',
                    ],
                    'description' => [
                        'ko' => '상품 1:1 문의 관리 권한',
                        'en' => 'Product inquiry management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '문의 조회',
                                'en' => 'Read Inquiries',
                            ],
                            'description' => [
                                'ko' => '전 회원 상품문의 목록 조회',
                                'en' => 'View product inquiries across all members',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '문의 처리',
                                'en' => 'Manage Inquiry',
                            ],
                            'description' => [
                                'ko' => '답변 등록/수정/삭제, 비밀글 내용 열람',
                                'en' => 'Create, update, and delete inquiry replies; view secret inquiry contents',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '문의 삭제',
                                'en' => 'Delete Inquiry',
                            ],
                            'description' => [
                                'ko' => '고객이 작성한 문의 자체를 삭제',
                                'en' => 'Delete inquiries submitted by customers',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],

                // 대시보드 권한
                [
                    'identifier' => 'dashboard',
                    'name' => [
                        'ko' => '대시보드',
                        'en' => 'Dashboard',
                    ],
                    'description' => [
                        'ko' => '관리자 대시보드 이커머스 영역 조회 권한',
                        'en' => 'Admin dashboard commerce area view permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => [
                                'ko' => '대시보드 조회',
                                'en' => 'View Dashboard',
                            ],
                            'description' => [
                                'ko' => '관리자 대시보드의 이커머스 판매 현황/리뷰/문의 위젯 조회',
                                'en' => 'View commerce sales/review/inquiry widgets on the admin dashboard',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],

                // ============================================================
                // 사용자(User) 권한 — 블랙컨슈머 차단용
                // ============================================================

                // 사용자 상품 조회 권한
                [
                    'identifier' => 'user-products',
                    'name' => [
                        'ko' => '사용자 상품',
                        'en' => 'User Products',
                    ],
                    'description' => [
                        'ko' => '사용자 상품 접근 권한 (블랙컨슈머 차단용)',
                        'en' => 'User product access permissions (for blocking malicious consumers)',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'type' => 'user',
                            'name' => [
                                'ko' => '상품 조회',
                                'en' => 'View Products',
                            ],
                            'description' => [
                                'ko' => '상품 목록 및 상세 조회',
                                'en' => 'View product list and details',
                            ],
                            'roles' => ['*'],
                        ],
                    ],
                ],

                // 사용자 주문 권한
                [
                    'identifier' => 'user-orders',
                    'name' => [
                        'ko' => '사용자 주문',
                        'en' => 'User Orders',
                    ],
                    'description' => [
                        'ko' => '사용자 주문 관련 권한 (블랙컨슈머 차단용)',
                        'en' => 'User order permissions (for blocking malicious consumers)',
                    ],
                    'permissions' => [
                        [
                            'action' => 'create',
                            'type' => 'user',
                            'name' => [
                                'ko' => '주문하기',
                                'en' => 'Create Order',
                            ],
                            'description' => [
                                'ko' => '주문 생성',
                                'en' => 'Create a new order',
                            ],
                            'roles' => ['*'],
                        ],
                        [
                            'action' => 'cancel',
                            'type' => 'user',
                            'name' => [
                                'ko' => '주문 취소',
                                'en' => 'Cancel Order',
                            ],
                            'description' => [
                                'ko' => '주문 취소 요청',
                                'en' => 'Request order cancellation',
                            ],
                            'roles' => ['*'],
                        ],
                        [
                            'action' => 'confirm',
                            'type' => 'user',
                            'name' => [
                                'ko' => '구매확정',
                                'en' => 'Confirm Purchase',
                            ],
                            'description' => [
                                'ko' => '주문 상품 구매확정',
                                'en' => 'Confirm purchase of order item',
                            ],
                            'roles' => ['*'],
                            'resource_route_key' => 'id',
                            'owner_key' => 'user_id',
                        ],
                    ],
                ],

                // 사용자 클레임 권한
                [
                    'identifier' => 'user-claims',
                    'name' => [
                        'ko' => '사용자 클레임',
                        'en' => 'User Claims',
                    ],
                    'description' => [
                        'ko' => '교환/반품/환불 신청 관련 권한 (블랙컨슈머 차단용)',
                        'en' => 'Exchange, return and refund request permissions (for blocking malicious consumers)',
                    ],
                    'permissions' => [
                        [
                            'action' => 'request',
                            'type' => 'user',
                            'name' => [
                                'ko' => '클레임 신청',
                                'en' => 'Request Claim',
                            ],
                            'description' => [
                                'ko' => '교환/반품/환불 신청 및 신청 내용 수정',
                                'en' => 'Create and edit exchange, return and refund requests',
                            ],
                            'roles' => ['*'],
                        ],
                        [
                            'action' => 'read',
                            'type' => 'user',
                            'name' => [
                                'ko' => '클레임 조회',
                                'en' => 'View Claims',
                            ],
                            'description' => [
                                'ko' => '본인 신청 내역 목록 및 상세 조회',
                                'en' => 'View own claim list and details',
                            ],
                            'roles' => ['*'],
                        ],
                        [
                            'action' => 'withdraw',
                            'type' => 'user',
                            'name' => [
                                'ko' => '클레임 철회',
                                'en' => 'Withdraw Claim',
                            ],
                            'description' => [
                                'ko' => '승인 전 신청 철회',
                                'en' => 'Withdraw a claim before approval',
                            ],
                            'roles' => ['*'],
                        ],
                        [
                            'action' => 'pay',
                            'type' => 'user',
                            'name' => [
                                'ko' => '클레임 추가결제',
                                'en' => 'Pay Claim Charge',
                            ],
                            'description' => [
                                'ko' => '클레임 배송비·차액 추가결제',
                                'en' => 'Pay claim shipping fee and price difference',
                            ],
                            'roles' => ['*'],
                        ],
                    ],
                ],

                // 사용자 리뷰 권한
                [
                    'identifier' => 'user-reviews',
                    'name' => [
                        'ko' => '사용자 리뷰',
                        'en' => 'User Reviews',
                    ],
                    'description' => [
                        'ko' => '사용자 리뷰 관련 권한 (블랙컨슈머 차단용)',
                        'en' => 'User review permissions (for blocking malicious consumers)',
                    ],
                    'permissions' => [
                        [
                            'action' => 'write',
                            'type' => 'user',
                            'name' => [
                                'ko' => '리뷰 작성',
                                'en' => 'Write Review',
                            ],
                            'description' => [
                                'ko' => '상품 리뷰 작성',
                                'en' => 'Write product review',
                            ],
                            'roles' => ['*'],
                        ],
                    ],
                ],

                // 찜 관리 권한 (#524 §4.1 — 회원 소유 데이터 관리자 표면)
                [
                    'identifier' => 'wishlists',
                    'resource_route_key' => 'wishlist',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '찜 관리',
                        'en' => 'Wishlist Management',
                    ],
                    'description' => [
                        'ko' => '전 회원 찜 조회·삭제 권한',
                        'en' => 'View and delete wishlists across all members',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '찜 조회',
                                'en' => 'View Wishlists',
                            ],
                            'description' => [
                                'ko' => '찜 조회',
                                'en' => 'View Wishlists',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '찜 삭제',
                                'en' => 'Delete Wishlists',
                            ],
                            'description' => [
                                'ko' => '찜 삭제',
                                'en' => 'Delete Wishlists',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 장바구니 관리 권한 (#524 §4.1 — 회원 소유 데이터 관리자 표면)
                [
                    'identifier' => 'carts',
                    'resource_route_key' => 'cart',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '장바구니 관리',
                        'en' => 'Cart Management',
                    ],
                    'description' => [
                        'ko' => '전 회원 장바구니 조회·삭제 권한',
                        'en' => 'View and delete carts across all members',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '장바구니 조회',
                                'en' => 'View Carts',
                            ],
                            'description' => [
                                'ko' => '장바구니 조회',
                                'en' => 'View Carts',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '장바구니 삭제',
                                'en' => 'Delete Carts',
                            ],
                            'description' => [
                                'ko' => '장바구니 삭제',
                                'en' => 'Delete Carts',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 회원 배송지 관리 권한 (#524 §4.1 — 회원 소유 데이터 관리자 표면)
                [
                    'identifier' => 'user-addresses',
                    'resource_route_key' => 'user-address',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '회원 배송지 관리',
                        'en' => 'Member Address Management',
                    ],
                    'description' => [
                        'ko' => '전 회원 배송지 조회·삭제 권한',
                        'en' => 'View and delete member addresses',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '배송지 조회',
                                'en' => 'View Addresses',
                            ],
                            'description' => [
                                'ko' => '배송지 조회',
                                'en' => 'View Addresses',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '배송지 삭제',
                                'en' => 'Delete Addresses',
                            ],
                            'description' => [
                                'ko' => '배송지 삭제',
                                'en' => 'Delete Addresses',
                            ],
                            'roles' => ['admin', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
                // 마일리지 관리 권한
                [
                    'identifier' => 'mileage',
                    'resource_route_key' => 'mileage-transaction',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '마일리지 관리',
                        'en' => 'Mileage Management',
                    ],
                    'description' => [
                        'ko' => '마일리지 내역 조회 및 수동 지급/차감 권한',
                        'en' => 'Mileage history and manual grant/deduct permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '마일리지 내역 조회',
                                'en' => 'Read Mileage',
                            ],
                            'description' => [
                                'ko' => '마일리지 내역 목록 및 상세 조회',
                                'en' => 'Read mileage history list and details',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '마일리지 수동 처리',
                                'en' => 'Manage Mileage',
                            ],
                            'description' => [
                                'ko' => '마일리지 수동 지급/차감 및 일괄 유효기간 연장',
                                'en' => 'Manual grant/deduct and bulk expiry extension',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],

                // 회원 결제 통화 관리 권한 (A3 — 관리자 회원 편집에서 통화 변경)
                [
                    'identifier' => 'user-currency',
                    'name' => [
                        'ko' => '회원 결제 통화 관리',
                        'en' => 'User Currency Management',
                    ],
                    'description' => [
                        'ko' => '관리자가 회원별 결제 통화를 변경하는 권한',
                        'en' => 'Permission for admins to change a user\'s payment currency',
                    ],
                    'permissions' => [
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '회원 결제 통화 변경',
                                'en' => 'Manage User Currency',
                            ],
                            'description' => [
                                'ko' => '회원별 결제 통화 변경',
                                'en' => 'Change a user\'s payment currency',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],

                // 회원 배송국가 관리 권한 (MP08 후속 — 관리자 회원 편집에서 배송국가 변경)
                [
                    'identifier' => 'user-shipping-country',
                    'name' => [
                        'ko' => '회원 배송국가 관리',
                        'en' => 'User Shipping Country Management',
                    ],
                    'description' => [
                        'ko' => '관리자가 회원별 배송국가를 변경하는 권한',
                        'en' => 'Permission for admins to change a user\'s shipping country',
                    ],
                    'permissions' => [
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '회원 배송국가 변경',
                                'en' => 'Manage User Shipping Country',
                            ],
                            'description' => [
                                'ko' => '회원별 배송국가 변경',
                                'en' => 'Change a user\'s shipping country',
                            ],
                            'roles' => ['admin', 'manager', 'sirsoft-ecommerce.manager'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 모듈 설정 파일 목록 반환
     */
    public function getConfig(): array
    {
        return [
            'sirsoft-ecommerce' => $this->getModulePath().'/config/ecommerce.php',
        ];
    }

    /**
     * 완전 공개 자산 스토리지 카테고리 목록
     *
     * 이 카테고리들만 공개 자산 디스크 설정을 따른다. 권한 검사가 걸린 자산
     * (회원 전용/비밀 자료 등)은 직접 URL 이 권한을 우회하므로 포함하지 않는다.
     * 새 공개 자산 카테고리가 생기면 여기에만 추가하면 된다.
     *
     * @var list<string>
     */
    private const PUBLIC_ASSET_CATEGORIES = ['images'];

    /**
     * 카테고리별 스토리지 디스크 이름 반환
     *
     * 완전 공개 자산 카테고리만 공개 자산 디스크(모듈 설정 basic_info.public_asset_disk >
     * 코어 전역 core.storage.public_asset_disk)를 따르고, 설정/캐시 등 나머지
     * 카테고리는 기본 디스크를 유지합니다. 미설정/고아 디스크는 기본 디스크로
     * 폴백해 기존 스트리밍 동작을 보존합니다.
     *
     * 모듈 설정 조회는 공개 자산 카테고리에서만 수행합니다 — 'settings' 카테고리에서
     * 조회하면 설정 로드와 재귀 고리가 생깁니다 (AbstractModule 주석 참조).
     *
     * @param  string  $category  카테고리
     * @return string 디스크 이름
     */
    public function getStorageDiskFor(string $category): string
    {
        if (! in_array($category, self::PUBLIC_ASSET_CATEGORIES, true)) {
            return $this->getStorageDisk();
        }

        $override = module_setting('sirsoft-ecommerce', 'basic_info.public_asset_disk', '');

        return $this->resolvePublicAssetDisk(is_string($override) ? $override : '')
            ?? $this->getStorageDisk();
    }

    /**
     * 모듈 설치 시 실행할 시더 목록 반환
     *
     * 배열 순서대로 실행됩니다.
     * 나머지 시더는 개발/테스트용이므로 설치 시 필수 참조 데이터만 실행합니다.
     * 배송유형은 설정 배송 탭과 배송정책 등록이 고르는 카탈로그라 새 설치에서도 채워야 합니다.
     *
     * @return array<class-string<Seeder>> 시더 클래스명 배열 (FQCN)
     */
    public function getSeeders(): array
    {
        return [
            SequenceSeeder::class,
            ShippingTypeSeeder::class,
            ShippingCarrierSeeder::class,
            ClaimReasonSeeder::class,
        ];
    }

    /**
     * 이 모듈이 등록할 IDV 정책 선언 (DB 동기화).
     *
     * ModuleManager 가 activate/update 시 `identity_policies` 테이블에
     * `source_type='module' / source_identifier='sirsoft-ecommerce'` 로 upsert 하며,
     * 운영자가 S1d 관리자 UI 에서 수정한 필드(enabled/grace/provider/fail_mode) 는
     * `user_overrides` JSON 으로 보존됩니다.
     *
     * 모든 정책은 보수적 기본값 `enabled=false` — 운영자가 S1d 에서 켜야 작동합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIdentityPolicies(): array
    {
        return [
            [
                'key' => 'sirsoft-ecommerce.payment.cancel',
                'scope' => 'hook',
                'target' => 'sirsoft-ecommerce.payment.before_cancel',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => false,
                'applies_to' => 'both',
                'fail_mode' => 'block',
            ],
            [
                'key' => 'sirsoft-ecommerce.payment.approve',
                'scope' => 'hook',
                'target' => 'sirsoft-ecommerce.payment.before_approve',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => false,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
            [
                'key' => 'sirsoft-ecommerce.payment.confirm_deposit',
                'scope' => 'hook',
                'target' => 'sirsoft-ecommerce.payment.before_confirm_deposit',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => false,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
            // 결제 직전 본인 확인 (사용자 측 가드, checkout_verification purpose 사용)
            [
                'key' => 'sirsoft-ecommerce.checkout.before_pay',
                'scope' => 'hook',
                'target' => 'sirsoft-ecommerce.checkout.before_payment',
                'purpose' => 'checkout_verification',
                'grace_minutes' => 30,
                'enabled' => false,
                'applies_to' => 'self',
                'fail_mode' => 'block',
            ],
        ];
    }

    /**
     * 이 모듈이 등록할 IDV 목적(purpose) 선언 (DB 비저장, 런타임 레지스트리).
     *
     * 코어 4종(`signup` / `password_reset` / `self_update` / `sensitive_action`)
     * 외에 이커머스 도메인이 필요로 하는 `checkout_verification` 을 추가합니다.
     * 이 purpose 를 실제 수행하려면 해당 purpose 를 `supportsPurpose()=true` 로
     * 응답하는 Provider (예: 성인/본인확인 플러그인) 가 함께 등록되어야 합니다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getIdentityPurposes(): array
    {
        return [
            'checkout_verification' => [
                // 모듈 i18n 표준 (docs/extension/module-i18n.md) — 백엔드 IdentityVerificationController
                // 의 resolvePurposeText() 가 `vendor-module::file.key` lang 키를 __() 로 해석.
                'label' => 'sirsoft-ecommerce::identity.purposes.checkout_verification.label',
                'description' => 'sirsoft-ecommerce::identity.purposes.checkout_verification.description',
                'default_provider' => null,
                'allowed_channels' => ['email', 'sms', 'ipin'],
            ],
        ];
    }

    /**
     * 이커머스 모듈이 등록할 알림 정의.
     *
     * AbstractModule 계약에 따라 ModuleManager 가 activate/update 시 자동으로
     * NotificationSyncHelper 를 통해 동기화하며, uninstall(deleteData=true) 시 정리합니다.
     * 운영자가 관리자 UI 에서 수정한 필드는 user_overrides JSON 에 보존됩니다.
     *
     * `extension_type`/`extension_identifier` 는 Manager 가 자동 주입.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNotificationDefinitions(): array
    {
        return array_merge([
            $this->orderConfirmedDefinition(),
            $this->orderPendingDepositDefinition(),
            $this->orderShippedDefinition(),
            $this->orderDeliveredDefinition(),
            $this->orderCompletedDefinition(),
            $this->orderCancelledDefinition(),
            $this->newOrderAdminDefinition(),
            $this->orderConfirmedAdminDefinition(),
            $this->orderCompletedAdminDefinition(),
            $this->depositAfterCancelAdminDefinition(),
            $this->inquiryReceivedDefinition(),
            $this->inquiryRepliedDefinition(),
            $this->mileageExpiringSoonDefinition(),
            $this->orderCustomEmailDefinition(),
            $this->checkoutVerificationIdentityDefinition(),
        ], $this->claimNotificationDefinitions());
    }

    /**
     * 주문 상세 「고객에게 메일 보내기」 알림 정의.
     *
     * 관리자가 주문 상세에서 직접 쓰는 메일이다. 7.1.0 전에는 알림 밖에서 `Mail::raw()` 로 보내
     * 「알림 채널 관리」의 메일 토글·발송 로그를 거치지 않았다(#699). 훅으로 발화되지 않고
     * OrderService::sendEmail() 이 직접 보낸다 — hooks 는 비운다. 제목·본문은 관리자 입력을
     * 그대로 싣고({subject}·{message}), 운영자는 앞뒤 문구(꼬리말 등)를 편집할 수 있다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function orderCustomEmailDefinition(): array
    {
        return [
            'type' => 'order_custom_email',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '주문 안내 메일 (관리자 작성)', 'en' => 'Order Message (Written by Admin)'],
            'description' => [
                'ko' => '주문 상세의 「고객에게 메일 보내기」로 관리자가 작성해 보내는 메일',
                'en' => 'Mail an administrator writes and sends from the order detail page',
            ],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [
                ['key' => 'subject', 'description' => '관리자가 입력한 제목'],
                ['key' => 'message', 'description' => '관리자가 입력한 본문 (줄바꿈 유지)'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'customer_name', 'description' => '주문자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '{subject}',
                        'en' => '{subject}',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<div style="color:#333;line-height:1.7">{message}</div>'
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">주문번호 {order_number} 관련 안내입니다. 본 메일은 <a href="{site_url}">{app_name}</a>에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<div style="color:#333;line-height:1.7">{message}</div>'
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This message is about order {order_number}. Sent from <a href="{site_url}">{app_name}</a>.</p>'
                            .'</div>',
                    ],
                ],
            ],
        ];
    }

    /**
     * 결제 시 본인 확인(checkout_verification) 본인인증 알림 정의.
     *
     * 이 모듈이 도입한 `checkout_verification` 목적의 전용 메일 문구다. 7.1.0 부터 본인인증 메시지는
     * 알림 정의(category=identity)이므로 `getIdentityMessages()` 대신 여기서 선언한다(#699).
     * 정의가 없으면 코어 `identity.default` 범용 문구로 나가 결제 맥락이 사라진다.
     * 결제 도메인 정책(payment.cancel/approve/confirm_deposit)은 코어 sensitive_action 문구로 충분해
     * 따로 선언하지 않는다 — 정책별 문구가 필요하면 운영자가 알림 설정 > 본인인증에서 추가한다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function checkoutVerificationIdentityDefinition(): array
    {
        return [
            'type' => IdentityNotificationType::forPurpose('checkout_verification'),
            'category' => IdentityNotificationType::CATEGORY,
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => [
                'ko' => '결제 시 본인 확인',
                'en' => 'Checkout Verification',
            ],
            'description' => [
                'ko' => '결제 진행 전 본인/성인 확인 인증 코드 메일',
                'en' => 'Identity/adult verification code mail before checkout',
            ],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => IdentityNotificationType::commonVariables(),
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 결제 본인 확인 인증 코드',
                        'en' => '[{app_name}] Checkout Verification Code',
                    ],
                    'body' => [
                        'ko' => '<h1>결제 본인 확인</h1>'
                            .'<p>결제를 진행하기 위해 본인 확인이 필요합니다. 아래 인증 코드를 입력해 주세요.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p><strong>본인이 결제를 진행하지 않았다면 이 메일을 무시하고 즉시 비밀번호를 변경해 주세요.</strong></p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Checkout Verification</h1>'
                            .'<p>Please enter the code below to proceed with payment.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p><strong>If you did not initiate this payment, please ignore this email and change your password immediately.</strong></p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ];
    }

    /**
     * 운영자 수신 알림의 수신자 규칙을 반환합니다.
     *
     * **역할 기준이 알림 인프라의 설계다.** 이 모듈은 주문·클레임·문의 권한의 주인을
     * `['admin', 'manager', 'sirsoft-ecommerce.manager']` 로 이미 선언해 두었고(권한 선언부),
     * 수신자는 그 선언을 그대로 따른다.
     *
     * `role:admin` 하나만 적으면 **모듈 설치 시 만들어지는 이커머스 관리자 역할이 빠진다** —
     * 그 사람은 클레임 4종 권한을 다 가졌는데 새 주문도 상품문의도 통보받지 못한다.
     *
     * 같은 사람이 두 역할에 속해도 중복 제거가 전 규칙 적용 뒤에 돌아 한 통만 간다.
     *
     * @return array<int, array<string, mixed>> 수신자 규칙 배열
     */
    private function staffNotificationRecipients(): array
    {
        return [
            ['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true],
            ['type' => 'role', 'value' => 'manager', 'exclude_trigger_user' => true],
            ['type' => 'role', 'value' => 'sirsoft-ecommerce.manager', 'exclude_trigger_user' => true],
        ];
    }

    /**
     * 성능 계측 프로파일 정의 (`g7:bench`).
     *
     * 목록 프로파일의 `columns` 는 해당 목록이 실제로 select 하는 컬럼이어야 합니다. 주문
     * 목록은 Repository 가 상수로 들고 있으므로 그 상수를 그대로 참조합니다 — 여기에 컬럼을
     * 다시 적으면 Repository 변경 시 계측이 조용히 낡습니다. 나머지 목록의 `['*']` 는
     * "응답 계약상 전 컬럼을 노출해 프루닝 불가" 선언이며, 이때 비교축은 select * vs select id
     * 입니다.
     *
     * @return array<string, array<string, mixed>> 프로파일 키 → 정의
     */
    public function getBenchmarkProfiles(): array
    {
        return [
            'orders' => [
                'type' => 'list',
                'label' => '주문 목록',
                'table' => 'ecommerce_orders',
                'columns' => OrderRepository::LIST_COLUMNS,
                'order' => [['ordered_at', 'desc'], ['id', 'desc']],
                // 관리자 주문 목록은 상태 미지정 시 임시 주문 상태를 제외한다
                // (OrderRepository::getListWithFilters). 이 술어를 빼고 재면 옵티마이저가 다른
                // 인덱스를 골라 화면에서 일어나는 일과 다른 것을 잰다. 값은 Enum SSoT 를 참조한다.
                'filters' => ['order_status' => ['not in', OrderStatusEnum::listHiddenValues()]],
                'soft_delete' => true,
            ],
            'products' => [
                'type' => 'list',
                'label' => '상품 목록',
                'table' => 'ecommerce_products',
                'columns' => ['*'],
                'order' => [['created_at', 'desc'], ['id', 'desc']],
                'soft_delete' => true,
            ],
            // 상품 문의는 소프트 삭제 컬럼이 없다 — 실제 스키마 기준 선언
            'product_inquiries' => [
                'type' => 'list',
                'label' => '상품 문의 목록',
                'table' => 'ecommerce_product_inquiries',
                'columns' => ['*'],
                'order' => [['created_at', 'desc'], ['id', 'desc']],
                'soft_delete' => false,
            ],
            'product_reviews' => [
                'type' => 'list',
                'label' => '상품 후기 목록',
                'table' => 'ecommerce_product_reviews',
                'columns' => ['*'],
                'order' => [['created_at', 'desc'], ['id', 'desc']],
                'soft_delete' => true,
            ],
            'coupon_issues' => [
                'type' => 'list',
                'label' => '쿠폰 발급 이력 목록',
                'table' => 'ecommerce_promotion_coupon_issues',
                'columns' => ['*'],
                'order' => [['issued_at', 'desc'], ['id', 'desc']],
                'soft_delete' => false,
            ],
            'extra_fee_templates' => [
                'type' => 'list',
                'label' => '추가 배송비 템플릿 목록',
                'table' => 'ecommerce_shipping_policy_extra_fee_templates',
                'columns' => ['*'],
                'order' => [['zipcode', 'asc'], ['id', 'asc']],
                'soft_delete' => false,
            ],
            'orders_screen' => [
                'type' => 'screen',
                'label' => '관리자 주문 목록 화면',
                'route' => 'api.modules.sirsoft-ecommerce.admin.orders.index',
                'query' => ['per_page' => 20],
                'permissions' => ['sirsoft-ecommerce.orders.read'],
            ],
            'order_create' => [
                'type' => 'write',
                'label' => '주문 생성 (임시 주문 → 주문 전환)',
                'prepare' => [OrderCreationBenchmark::class, 'prepare'],
                'callback' => [OrderCreationBenchmark::class, 'create'],
            ],
        ];
    }

    /**
     * 무통장입금 입금 안내 알림 정의.
     *
     * 무통장입금(dbank) 주문 접수 직후 발송. 입금할 계좌·예금주·입금 기한과 함께
     * 마일리지/예치금 차감 후 실제 입금 필요액(total_due_amount)을 안내한다.
     * 실제 발송 대상 분기(dbank + 입금 필요액 > 0)는 EcommerceNotificationDataListener 가 담당한다.
     */
    private function orderPendingDepositDefinition(): array
    {
        return [
            'type' => 'order_pending_deposit',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '무통장 입금 안내', 'en' => 'Bank Transfer Payment Guide'],
            'description' => ['ko' => '무통장입금 주문 접수 시 입금 계좌·금액을 고객에게 안내', 'en' => 'Sent to customer with deposit account and amount when a bank-transfer order is placed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'deposit_amount', 'description' => '입금 필요 금액 (마일리지/예치금 차감 후)'],
                ['key' => 'bank_name', 'description' => '입금 은행'],
                ['key' => 'account_number', 'description' => '입금 계좌번호'],
                ['key' => 'account_holder', 'description' => '예금주'],
                ['key' => 'depositor_name', 'description' => '입금자명'],
                ['key' => 'deposit_due_at', 'description' => '입금 기한'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 입금 안내 (주문번호: {order_number})',
                        'en' => '[{app_name}] Bank transfer guide (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">입금 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 주문해 주셔서 감사합니다. 아래 계좌로 입금해 주시면 주문이 처리됩니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>입금 금액:</strong> {deposit_amount}</p>'
                            .'<p style="margin:5px 0"><strong>입금 은행:</strong> {bank_name}</p>'
                            .'<p style="margin:5px 0"><strong>계좌번호:</strong> {account_number}</p>'
                            .'<p style="margin:5px 0"><strong>예금주:</strong> {account_holder}</p>'
                            .'<p style="margin:5px 0"><strong>입금자명:</strong> {depositor_name}</p>'
                            .'<p style="margin:5px 0"><strong>입금 기한:</strong> {deposit_due_at}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">입금 기한 내 미입금 시 주문이 자동 취소될 수 있습니다.</p>'
                            .$this->notificationButton('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Bank Transfer Guide</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, thank you for your order. Please transfer to the account below to complete your order.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Amount to Deposit:</strong> {deposit_amount}</p>'
                            .'<p style="margin:5px 0"><strong>Bank:</strong> {bank_name}</p>'
                            .'<p style="margin:5px 0"><strong>Account Number:</strong> {account_number}</p>'
                            .'<p style="margin:5px 0"><strong>Account Holder:</strong> {account_holder}</p>'
                            .'<p style="margin:5px 0"><strong>Depositor Name:</strong> {depositor_name}</p>'
                            .'<p style="margin:5px 0"><strong>Due By:</strong> {deposit_due_at}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Your order may be cancelled automatically if payment is not received by the due date.</p>'
                            .$this->notificationButton('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '입금 안내', 'en' => 'Bank Transfer Guide'],
                    'body' => [
                        'ko' => '{name}님, 주문번호 {order_number}의 입금 금액은 {deposit_amount} 입니다. ({bank_name} {account_number})',
                        'en' => '{name}, the deposit amount for order {order_number} is {deposit_amount}. ({bank_name} {account_number})',
                    ],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 소멸 예정 마일리지 알림 정의.
     */
    private function mileageExpiringSoonDefinition(): array
    {
        return [
            'type' => 'mileage_expiring_soon',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '마일리지 소멸 예정', 'en' => 'Mileage Expiring Soon'],
            'description' => ['ko' => '마일리지 소멸이 임박한 회원에게 발송', 'en' => 'Sent to members whose mileage is about to expire'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.mileage.notify_expiring'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'amount', 'description' => '소멸 예정 마일리지'],
                ['key' => 'currency', 'description' => '통화 코드'],
                ['key' => 'expires_date', 'description' => '소멸 예정일'],
                ['key' => 'balance', 'description' => '현재 사용 가능 잔액'],
                ['key' => 'mileage_url', 'description' => '마일리지 내역 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] {amount}점의 마일리지가 곧 소멸됩니다',
                        'en' => '[{app_name}] {amount} mileage points are expiring soon',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">마일리지 소멸 예정 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 보유하신 마일리지 중 일부가 곧 소멸될 예정입니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>소멸 예정 마일리지:</strong> {amount}점</p>'
                            .'<p style="margin:5px 0"><strong>소멸 예정일:</strong> {expires_date}</p>'
                            .'<p style="margin:5px 0"><strong>현재 잔액:</strong> {balance}점</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">소멸 전에 마일리지를 사용해 보세요.</p>'
                            .$this->notificationButton('마일리지 내역 보기', '{mileage_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Mileage Expiring Soon</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, some of your mileage points are about to expire.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Expiring Mileage:</strong> {amount}</p>'
                            .'<p style="margin:5px 0"><strong>Expiry Date:</strong> {expires_date}</p>'
                            .'<p style="margin:5px 0"><strong>Current Balance:</strong> {balance}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Use your mileage before it expires.</p>'
                            .$this->notificationButton('View Mileage', '{mileage_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '마일리지 소멸 예정 안내', 'en' => 'Mileage Expiring Soon'],
                    'body' => [
                        'ko' => '{name}님, {amount}점의 마일리지가 {expires_date}에 소멸될 예정입니다.',
                        'en' => '{name}, {amount} mileage points will expire on {expires_date}.',
                    ],
                    'click_url' => '{mileage_url}',
                ],
            ],
        ];
    }

    /**
     * 주문 확인 알림 정의.
     */
    private function orderConfirmedDefinition(): array
    {
        return [
            'type' => 'order_confirmed',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '결제 완료', 'en' => 'Payment Completed'],
            'description' => ['ko' => '결제 완료 시 고객에게 발송', 'en' => 'Sent to customer when payment is completed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_confirm'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'total_amount', 'description' => '결제 금액'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
                ['key' => 'shipping_recipient_name', 'description' => '수취인 이름'],
                ['key' => 'shipping_country_name', 'description' => '배송국가명'],
                ['key' => 'shipping_address', 'description' => '배송지 주소'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 결제가 완료되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your payment has been completed (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">결제 완료</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 결제가 완료되었습니다. 주문해 주셔서 감사합니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>결제금액:</strong> {total_amount}</p>'
                            .'<p style="margin:5px 0"><strong>받는분:</strong> {shipping_recipient_name}</p>'
                            .'<p style="margin:5px 0"><strong>배송국가:</strong> {shipping_country_name}</p>'
                            .'<p style="margin:5px 0"><strong>배송지:</strong> {shipping_address}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">주문 상세 내용은 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->notificationButton('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Payment Completed</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your payment has been completed. Thank you for your order.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Total Amount:</strong> {total_amount}</p>'
                            .'<p style="margin:5px 0"><strong>Recipient:</strong> {shipping_recipient_name}</p>'
                            .'<p style="margin:5px 0"><strong>Shipping Country:</strong> {shipping_country_name}</p>'
                            .'<p style="margin:5px 0"><strong>Shipping Address:</strong> {shipping_address}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Click the button below to view your order details.</p>'
                            .$this->notificationButton('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '결제가 완료되었습니다', 'en' => 'Your payment has been completed'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}의 결제가 완료되었습니다.', 'en' => '{name}, payment for your order {order_number} has been completed.'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 배송 시작 알림 정의.
     */
    private function orderShippedDefinition(): array
    {
        return [
            'type' => 'order_shipped',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '배송 시작', 'en' => 'Order Shipped'],
            'description' => ['ko' => '배송 시작 시 고객에게 발송', 'en' => 'Sent to customer when order is shipped'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_ship'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'carrier_name', 'description' => '택배사 이름'],
                ['key' => 'tracking_number', 'description' => '운송장 번호'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
                ['key' => 'shipping_recipient_name', 'description' => '수취인 이름'],
                ['key' => 'shipping_country_name', 'description' => '배송국가명'],
                ['key' => 'shipping_address', 'description' => '배송지 주소'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 주문하신 상품이 발송되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your order has been shipped (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">배송 시작 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 주문하신 상품이 발송되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>택배사:</strong> {carrier_name}</p>'
                            .'<p style="margin:5px 0"><strong>운송장번호:</strong> {tracking_number}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">주문 상세 및 배송 현황은 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->notificationButton('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Order Shipped</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your order has been shipped.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Carrier:</strong> {carrier_name}</p>'
                            .'<p style="margin:5px 0"><strong>Tracking Number:</strong> {tracking_number}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Click the button below to view your order and tracking details.</p>'
                            .$this->notificationButton('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '상품이 발송되었습니다', 'en' => 'Your order has been shipped'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}이 {carrier_name}(송장번호: {tracking_number})으로 발송되었습니다.', 'en' => '{name}, your order {order_number} has been shipped via {carrier_name} (tracking: {tracking_number}).'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 배송 완료 알림 정의.
     */
    private function orderDeliveredDefinition(): array
    {
        return [
            'type' => 'order_delivered',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '배송 완료', 'en' => 'Order Delivered'],
            'description' => ['ko' => '배송 완료 시 고객에게 발송', 'en' => 'Sent to customer when order is delivered'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_deliver'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'carrier_name', 'description' => '택배사 이름'],
                ['key' => 'tracking_number', 'description' => '운송장 번호'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
                ['key' => 'shipping_recipient_name', 'description' => '수취인 이름'],
                ['key' => 'shipping_country_name', 'description' => '배송국가명'],
                ['key' => 'shipping_address', 'description' => '배송지 주소'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 주문하신 상품이 배송 완료되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your order has been delivered (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">배송 완료 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 주문하신 상품의 배송이 완료되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>택배사:</strong> {carrier_name}</p>'
                            .'<p style="margin:5px 0"><strong>운송장번호:</strong> {tracking_number}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">상품을 확인하신 후 주문 상세에서 구매확정을 진행하실 수 있습니다.</p>'
                            .$this->notificationButton('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Order Delivered</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your order has been delivered.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Carrier:</strong> {carrier_name}</p>'
                            .'<p style="margin:5px 0"><strong>Tracking Number:</strong> {tracking_number}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">After receiving your items, you can confirm your purchase from the order details.</p>'
                            .$this->notificationButton('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '상품이 배송 완료되었습니다', 'en' => 'Your order has been delivered'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}의 배송이 완료되었습니다.', 'en' => '{name}, your order {order_number} has been delivered.'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 구매 확정 알림 정의.
     */
    private function orderCompletedDefinition(): array
    {
        return [
            'type' => 'order_completed',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '구매 확정', 'en' => 'Order Completed'],
            'description' => ['ko' => '구매 확정 시 고객에게 발송', 'en' => 'Sent to customer when order is completed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_complete'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'total_amount', 'description' => '결제 금액'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 구매가 확정되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your purchase has been completed (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">구매 확정 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 구매가 확정되었습니다. 이용해 주셔서 감사합니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>결제금액:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">주문 내역은 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->notificationButton('주문 내역 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Purchase Completed</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your purchase has been completed. Thank you for shopping with us.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Total Amount:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Click the button below to view your order history.</p>'
                            .$this->notificationButton('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '구매가 확정되었습니다', 'en' => 'Your purchase has been confirmed'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}의 구매가 확정되었습니다.', 'en' => '{name}, your order {order_number} has been confirmed.'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 주문 취소 알림 정의.
     */
    private function orderCancelledDefinition(): array
    {
        return [
            'type' => 'order_cancelled',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '주문 취소', 'en' => 'Order Cancelled'],
            'description' => ['ko' => '주문 취소 시 고객에게 발송', 'en' => 'Sent to customer when order is cancelled'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_cancel'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'cancel_reason', 'description' => '취소 사유'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 주문이 취소되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your order has been cancelled (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #DC2626;padding-bottom:10px">주문 취소 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 주문이 취소되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>취소 사유:</strong> {cancel_reason}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">환불은 결제 수단에 따라 3~7영업일 이내에 처리됩니다. 주문 상세는 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->notificationButton('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #DC2626;padding-bottom:10px">Order Cancelled</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your order has been cancelled.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Cancel Reason:</strong> {cancel_reason}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Refunds will be processed within 3-7 business days depending on your payment method. Click the button below to view your order details.</p>'
                            .$this->notificationButton('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '주문이 취소되었습니다', 'en' => 'Your order has been cancelled'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}이 취소되었습니다. 사유: {cancel_reason}', 'en' => '{name}, your order {order_number} has been cancelled. Reason: {cancel_reason}'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 취소된 주문에 가상계좌 입금이 들어왔을 때(운영자 수신) 알림 정의. (P-D1 · Q11)
     *
     * 고객이 취소한 뒤에 입금해도 PG 통보는 온다. 주문은 되살리지 않고 자동 환불도 하지 않으므로(Q11), 운영자가
     * 그 돈을 돌려줘야 한다는 사실을 이 알림이 알린다. 결제 플러그인의 입금통보 처리가
     * `DepositAfterCancelRecorder` 로 훅을 낸다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function depositAfterCancelAdminDefinition(): array
    {
        $definition = $this->orderStaffNotificationDefinition(
            type: 'deposit_after_cancel_admin',
            hook: 'sirsoft-ecommerce.order.deposit_after_cancel',
            name: ['ko' => '취소 주문 입금 관리자 알림', 'en' => 'Deposit on a Cancelled Order (Staff)'],
            description: [
                'ko' => '취소된 주문의 가상계좌에 입금이 확인되면 운영자에게 발송(자동 환불되지 않음)',
                'en' => 'Sent to staff when a deposit arrives on the virtual account of a cancelled order (not refunded automatically)',
            ],
            heading: ['ko' => '취소된 주문에 입금이 확인되었습니다', 'en' => 'A deposit arrived on a cancelled order'],
            lead: [
                'ko' => '{name}님, 취소된 주문의 가상계좌에 {deposit_amount}이(가) 입금되었습니다. 자동으로 환불되지 않으니 고객에게 환불해 주세요.',
                'en' => 'Dear {name}, {deposit_amount} was deposited to the virtual account of a cancelled order. It is not refunded automatically — please refund the customer.',
            ],
            summary: [
                'ko' => '취소된 주문({order_number})에 {deposit_amount} 입금이 확인되었습니다. 고객에게 환불하세요.',
                'en' => 'A deposit of {deposit_amount} arrived on cancelled order {order_number}. Please refund the customer.',
            ],
        );
        $definition['variables'][] = ['key' => 'deposit_amount', 'description' => '취소 후 입금된 금액'];

        return $definition;
    }

    /**
     * 주문 결제완료 도달(운영자 수신) 알림 정의.
     *
     * 무통장 주문은 **입금이 확인되는 순간**이 운영자가 움직여야 하는 시점이다. 신규 주문
     * 알림은 접수 시점이라 그 뒤의 입금 확인을 알려 주지 못한다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function orderConfirmedAdminDefinition(): array
    {
        return $this->orderStaffNotificationDefinition(
            type: 'order_confirmed_admin',
            hook: 'sirsoft-ecommerce.order.after_confirm',
            name: ['ko' => '주문 결제완료 관리자 알림', 'en' => 'Order Payment Completed (Staff)'],
            description: [
                'ko' => '주문이 결제완료에 도달하면 운영자에게 발송(무통장 입금 확인 포함)',
                'en' => 'Sent to staff when an order reaches payment completed (including bank deposit confirmation)',
            ],
            heading: ['ko' => '주문 결제가 완료되었습니다', 'en' => 'An order has been paid'],
            lead: [
                'ko' => '{name}님, 주문 결제가 완료되었습니다. 배송 준비를 진행해 주세요.',
                'en' => 'Dear {name}, an order has been paid. Please proceed with fulfillment.',
            ],
            summary: [
                'ko' => '{customer_name}님의 주문({order_number}) 결제가 완료되었습니다. 결제금액 {total_amount}.',
                'en' => 'Payment completed for {customer_name}\'s order ({order_number}), total {total_amount}.',
            ],
        );
    }

    /**
     * 구매확정(운영자 수신) 알림 정의.
     *
     * 구매확정은 정산·후기 요청 같은 후속 작업이 시작되는 지점이다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function orderCompletedAdminDefinition(): array
    {
        return $this->orderStaffNotificationDefinition(
            type: 'order_completed_admin',
            hook: 'sirsoft-ecommerce.order.after_complete',
            name: ['ko' => '구매확정 관리자 알림', 'en' => 'Order Confirmed by Customer (Staff)'],
            description: [
                'ko' => '고객이 구매확정하면 운영자에게 발송',
                'en' => 'Sent to staff when a customer confirms the purchase',
            ],
            heading: ['ko' => '고객이 구매를 확정했습니다', 'en' => 'A customer confirmed their purchase'],
            lead: [
                'ko' => '{name}님, 고객이 구매를 확정했습니다.',
                'en' => 'Dear {name}, a customer has confirmed their purchase.',
            ],
            summary: [
                'ko' => '{customer_name}님이 주문({order_number})을 구매확정했습니다.',
                'en' => '{customer_name} confirmed order {order_number}.',
            ],
        );
    }

    /**
     * 주문 축의 운영자 수신 알림 정의를 만듭니다.
     *
     * 두 정의가 본문 구조·변수·수신자를 공유하므로 문구만 인자로 받는다 — 복제하면
     * 한쪽만 고쳐졌을 때 운영자가 보는 두 메일의 형태가 갈린다.
     *
     * @param  string  $type  알림 유형
     * @param  string  $hook  구독 훅
     * @param  array{ko: string, en: string}  $name  정의 이름
     * @param  array{ko: string, en: string}  $description  정의 설명
     * @param  array{ko: string, en: string}  $heading  메일 제목·본문 머리
     * @param  array{ko: string, en: string}  $lead  메일 본문 도입부
     * @param  array{ko: string, en: string}  $summary  알림센터 본문
     * @return array<string, mixed> 알림 정의
     */
    private function orderStaffNotificationDefinition(
        string $type,
        string $hook,
        array $name,
        array $description,
        array $heading,
        array $lead,
        array $summary,
    ): array {
        $rows = [
            ['ko' => '주문번호', 'en' => 'Order No.', 'var' => 'order_number'],
            ['ko' => '주문자', 'en' => 'Customer', 'var' => 'customer_name'],
            ['ko' => '결제금액', 'en' => 'Total Amount', 'var' => 'total_amount'],
        ];

        return [
            'type' => $type,
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'staff',
            'name' => $name,
            'description' => $description,
            'channels' => ['mail', 'database'],
            'hooks' => [$hook],
            'variables' => [
                ['key' => 'name', 'description' => '수신자(운영자) 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'customer_name', 'description' => '주문자 이름'],
                ['key' => 'total_amount', 'description' => '결제 금액'],
                ['key' => 'order_url', 'description' => '주문 화면 URL (운영자에게는 관리자 주소로 치환)'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => [
                        'ko' => '[{app_name}] '.$heading['ko'].' (주문번호: {order_number})',
                        'en' => '[{app_name}] '.$heading['en'].' (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => $this->claimNotificationMailBody('ko', $heading['ko'], $lead['ko'], $rows, '주문 관리 바로가기', '{order_url}'),
                        'en' => $this->claimNotificationMailBody('en', $heading['en'], $lead['en'], $rows, 'Go to Order Management', '{order_url}'),
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => $heading,
                    'body' => $summary,
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 관리자 신규 주문 알림 정의.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function newOrderAdminDefinition(): array
    {
        return [
            'type' => 'new_order_admin',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'staff',
            'name' => ['ko' => '신규 주문 관리자 알림', 'en' => 'New Order Admin Notification'],
            'description' => ['ko' => '신규 주문 접수 시 관리자에게 발송', 'en' => 'Sent to admin when a new order is placed'],
            'channels' => ['mail', 'database'],
            // 발송 시점은 결제수단별로 다르다(아래 전용 훅으로 위임):
            // - 무통장/전액 비현금(0원) 주문: 주문 생성 시점(입금 전이라도 접수 알림 발송이 정상)
            // - 카드(PG) 주문: 결제완료 시점(주문 생성 시점은 pending_order 라 오발송 — 결제 미완료/이탈 가능)
            // OrderProcessingService 가 결제수단·결제완료 시점을 판단해 이 전용 훅을 1회 발화한다.
            'hooks' => ['sirsoft-ecommerce.order.after_admin_notify'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자(관리자) 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'customer_name', 'description' => '주문자 이름'],
                ['key' => 'total_amount', 'description' => '결제 금액'],
                ['key' => 'order_url', 'description' => '주문 관리 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => [
                        'ko' => '[{app_name}] 신규 주문이 접수되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] New order received (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">신규 주문 접수</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 새로운 주문이 접수되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>주문자:</strong> {customer_name}</p>'
                            .'<p style="margin:5px 0"><strong>결제금액:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">관리자 페이지에서 주문 상세를 확인해 주세요.</p>'
                            .$this->notificationButton('주문 관리 바로가기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">New Order Received</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, a new order has been received.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Customer:</strong> {customer_name}</p>'
                            .'<p style="margin:5px 0"><strong>Total Amount:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Please review the order details in the admin panel.</p>'
                            .$this->notificationButton('Go to Order Management', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => ['ko' => '새로운 주문이 접수되었습니다', 'en' => 'New order received'],
                    'body' => ['ko' => '{customer_name}님이 주문번호 {order_number} (결제금액: {total_amount})을 접수했습니다.', 'en' => '{customer_name} placed order {order_number} (total: {total_amount}).'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 상품 문의 접수 관리자 알림 정의.
     */
    private function inquiryReceivedDefinition(): array
    {
        return [
            'type' => 'inquiry_received',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'staff',
            'name' => ['ko' => '상품 문의 접수', 'en' => 'Inquiry Received'],
            'description' => ['ko' => '상품 문의 접수 시 관리자에게 발송', 'en' => 'Sent to admin when a product inquiry is received'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.product_inquiry.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자(관리자) 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'product_name', 'description' => '상품명'],
                ['key' => 'customer_name', 'description' => '문의자 이름'],
                ['key' => 'inquiry_content', 'description' => '문의 내용 (요약)'],
                ['key' => 'inquiry_url', 'description' => '문의 관리 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => [
                        'ko' => '[{app_name}] 새로운 상품 문의가 접수되었습니다',
                        'en' => '[{app_name}] New product inquiry received',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">새 상품 문의 접수</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 새로운 상품 문의가 접수되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>상품명:</strong> {product_name}</p>'
                            .'<p style="margin:5px 0"><strong>문의자:</strong> {customer_name}</p>'
                            .'<p style="margin:5px 0"><strong>문의 내용:</strong> {inquiry_content}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">관리자 페이지에서 문의 내용을 확인하고 답변해 주세요.</p>'
                            .$this->notificationButton('문의 확인하기', '{inquiry_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">New Product Inquiry</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, a new product inquiry has been received.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Product:</strong> {product_name}</p>'
                            .'<p style="margin:5px 0"><strong>Customer:</strong> {customer_name}</p>'
                            .'<p style="margin:5px 0"><strong>Inquiry:</strong> {inquiry_content}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Please review the inquiry and provide a response in the admin panel.</p>'
                            .$this->notificationButton('View Inquiry', '{inquiry_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => ['ko' => '새로운 상품 문의가 접수되었습니다', 'en' => 'New product inquiry received'],
                    'body' => ['ko' => '{customer_name}님이 "{product_name}" 상품에 문의를 남겼습니다.', 'en' => '{customer_name} left an inquiry on "{product_name}".'],
                    'click_url' => '{inquiry_url}',
                ],
            ],
        ];
    }

    /**
     * 문의 답변 완료 알림 정의.
     */
    private function inquiryRepliedDefinition(): array
    {
        return [
            'type' => 'inquiry_replied',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '문의 답변 완료', 'en' => 'Inquiry Replied'],
            'description' => ['ko' => '문의 답변 시 고객에게 발송', 'en' => 'Sent to customer when inquiry is replied'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.product_inquiry.after_reply'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자(문의자) 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'product_name', 'description' => '상품명'],
                ['key' => 'inquiry_content', 'description' => '원래 문의 내용 (요약)'],
                ['key' => 'inquiry_url', 'description' => '문의 상세 URL (마이페이지)'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'author']],
                    'subject' => [
                        'ko' => '[{app_name}] 상품 문의에 답변이 등록되었습니다',
                        'en' => '[{app_name}] Your product inquiry has been answered',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">문의 답변 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 문의하신 내용에 답변이 등록되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>상품명:</strong> {product_name}</p>'
                            .'<p style="margin:5px 0"><strong>문의 내용:</strong> {inquiry_content}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">아래 버튼을 클릭하여 답변 내용을 확인하세요.</p>'
                            .$this->notificationButton('답변 확인하기', '{inquiry_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Inquiry Answered</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your product inquiry has been answered.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Product:</strong> {product_name}</p>'
                            .'<p style="margin:5px 0"><strong>Your Inquiry:</strong> {inquiry_content}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Click the button below to view the response.</p>'
                            .$this->notificationButton('View Response', '{inquiry_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'author']],
                    'subject' => ['ko' => '문의 답변이 등록되었습니다', 'en' => 'Your inquiry has been answered'],
                    'body' => ['ko' => '{name}님, "{product_name}" 상품 문의에 답변이 등록되었습니다.', 'en' => '{name}, your inquiry on "{product_name}" has been answered.'],
                    'click_url' => '{inquiry_url}',
                ],
            ],
        ];
    }

    // ───────────────────────────────────────────────
    // 클레임 알림 정의 (§4-8 — 고객 가시 상태 전수 29종)
    // ───────────────────────────────────────────────

    /**
     * 클레임 알림 정의 29종을 만듭니다.
     *
     * 유형(교환·반품·환불) × 고객 가시 상태의 곱이며, 목록의 정본은
     * `ClaimStatusNotificationListener::notifiableStatuses()` 다 — 알림 훅을 발화하는
     * 쪽과 정의를 만드는 쪽이 서로 다른 목록을 들면 발화되는데 정의가 없는(=조용히
     * 아무 일도 일어나지 않는) 상태가 생긴다. 여기서 그 목록을 그대로 읽어 쓴다.
     *
     * 29종의 본문 구조는 같고 문구만 다르므로 정의를 한 벌씩 손으로 적지 않는다 —
     * 같은 마크업을 29번 복제하면 메일 서식을 한 번 고칠 때 29곳을 고쳐야 하고,
     * 그중 하나만 빠져도 그 알림만 다른 모양으로 나간다.
     *
     * @return array<int, array<string, mixed>> 알림 정의 목록
     */
    private function claimNotificationDefinitions(): array
    {
        $definitions = [];
        $phrases = $this->claimNotificationPhrases();

        foreach ($this->claimNotificationTypeWords() as $claimType => $words) {
            foreach (ClaimStatusNotificationListener::notifiableStatuses()[$claimType] as $key) {
                $definitions[] = $this->claimNotificationDefinition($claimType, $key, $words, $phrases[$key]);

                // 운영자에게도 알려야 하는 사건은 **별도 정의**다 — 관리자 본문("고객이 교환을
                // 신청했습니다")과 고객 본문("신청이 접수되었습니다")은 한 템플릿에 담기지
                // 않는다. 같은 훅을 공유하므로 한 사건에 고객 1통 + 운영자 1통이 나간다.
                if (in_array($key, self::STAFF_NOTIFIED_CLAIM_KEYS, true)) {
                    $definitions[] = $this->claimStaffNotificationDefinition($claimType, $key, $words);
                }
            }
        }

        $definitions[] = $this->claimWithdrawnDefinition();
        $definitions[] = $this->claimWithdrawnOnBehalfDefinition();
        $definitions[] = $this->claimPartiallyProcessedDefinition();

        foreach ($this->claimTrackingRegisteredDefinitions() as $definition) {
            $definitions[] = $definition;
        }

        $definitions[] = $this->claimCollectionOverdueDefinition();

        return $definitions;
    }

    /**
     * 클레임 유형별 표기 낱말을 반환합니다.
     *
     * 영어는 문장 중간(소문자)과 제목(대문자)이 달라 두 벌을 둔다.
     *
     * @return array<string, array{ko: string, en: string, en_lower: string}> 유형 값 => 낱말
     */
    private function claimNotificationTypeWords(): array
    {
        return [
            ClaimTypeEnum::EXCHANGE->value => ['ko' => '교환', 'en' => 'Exchange', 'en_lower' => 'exchange'],
            ClaimTypeEnum::RETURN->value => ['ko' => '반품', 'en' => 'Return', 'en_lower' => 'return'],
            ClaimTypeEnum::REFUND->value => ['ko' => '환불', 'en' => 'Refund', 'en_lower' => 'refund'],
        ];
    }

    /**
     * 상태별 문구 조각을 반환합니다.
     *
     * `:type` 은 유형 낱말(한국어 / 영어 소문자), `:Type` 은 영어 제목용 대문자로 치환된다.
     * `rows` 는 메일 본문 표에 덧붙는 행이며 `*` 가 기본, 유형 키가 있으면 그 유형만 덮는다
     * (예: 보류 안내 메모는 교환·반품에만 있는 컬럼이라 환불에서는 행 자체를 빼야 빈칸이
     * 남지 않는다).
     *
     * @return array<string, array<string, mixed>> 상태 키 => 문구 조각
     */
    private function claimNotificationPhrases(): array
    {
        // 송장은 상품 행마다 있어 한 신청서에 운송장이 여럿일 수 있다 — 운송장별로 묶은 줄
        // (「택배사 · 운송장 · 상품×수량」)을 한 칸에 싣는다. 값은 발행 시점 스냅샷이다(S10).
        $collectionRows = [
            ['ko' => '회수 운송장', 'en' => 'Collection Tracking', 'var' => 'tracking_lines'],
        ];
        $redeliveryRows = [
            ['ko' => '발송 운송장', 'en' => 'Shipping Tracking', 'var' => 'tracking_lines'],
        ];

        return [
            'requested' => [
                'name' => ['ko' => ':type 신청 접수', 'en' => ':Type Request Received'],
                'description' => ['ko' => ':type 신청이 접수되면 고객에게 발송', 'en' => 'Sent to the customer when a :type request is received'],
                'heading' => ['ko' => ':type 신청이 접수되었습니다', 'en' => ':Type Request Received'],
                // 판매자가 대신 접수했으면 첫 문장 뒤에 그 사실을 덧붙인다(D8 — `request_origin_notice`).
                'lead' => [
                    'ko' => '{name}님, :type 신청이 접수되었습니다. {request_origin_notice} 검토 후 처리 결과를 안내드리겠습니다.',
                    'en' => 'Dear {name}, your :type request has been received. {request_origin_notice} We will let you know once it has been reviewed.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})이 접수되었습니다. {request_origin_notice}',
                    'en' => '{name}, your :type request ({claim_number}) has been received. {request_origin_notice}',
                ],
                'rows' => ['*' => [['ko' => '신청 사유', 'en' => 'Reason', 'var' => 'reason_label']]],
                'extra_variables' => ['request_origin_notice'],
            ],
            'payment_requested' => [
                'name' => ['ko' => ':type 추가결제 요청', 'en' => ':Type Additional Payment Requested'],
                'description' => ['ko' => ':type 추가 청구액이 발생해 결제가 필요할 때 고객에게 발송', 'en' => 'Sent to the customer when an additional payment is required to proceed with the :type'],
                'heading' => ['ko' => ':type 추가금 결제를 요청드립니다', 'en' => 'Additional Payment Required'],
                'lead' => [
                    'ko' => '{name}님, :type 처리를 위한 추가 금액이 발생했습니다. 아래 금액을 결제해 주시면 처리가 이어집니다.',
                    'en' => 'Dear {name}, an additional payment is required to proceed with your :type. Your request will continue once the payment is completed.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})의 미결제 금액은 {outstanding_amount} 입니다.',
                    'en' => '{name}, the outstanding amount for your :type request ({claim_number}) is {outstanding_amount}.',
                ],
                'rows' => ['*' => [
                    ['ko' => '총 청구 금액', 'en' => 'Total Amount Due', 'var' => 'charge_amount'],
                    ['ko' => '미결제 금액', 'en' => 'Outstanding Amount', 'var' => 'outstanding_amount'],
                ]],
            ],
            'payment_completed' => [
                'name' => ['ko' => ':type 추가결제 완료', 'en' => ':Type Additional Payment Completed'],
                'description' => ['ko' => ':type 추가결제가 완료되면 고객에게 발송', 'en' => 'Sent to the customer when the additional payment for the :type is completed'],
                'heading' => ['ko' => ':type 추가금 결제가 완료되었습니다', 'en' => 'Additional Payment Completed'],
                'lead' => [
                    'ko' => '{name}님, :type 추가금 결제가 확인되었습니다. 이어서 처리해 드리겠습니다.',
                    'en' => 'Dear {name}, we have confirmed the additional payment for your :type. We will proceed with your request.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})의 추가 결제({paid_amount})가 완료되었습니다.',
                    'en' => '{name}, the additional payment ({paid_amount}) for your :type request ({claim_number}) has been completed.',
                ],
                'rows' => ['*' => [['ko' => '결제 금액', 'en' => 'Paid Amount', 'var' => 'paid_amount']]],
            ],
            'approved' => [
                'name' => ['ko' => ':type 승인', 'en' => ':Type Approved'],
                'description' => ['ko' => ':type 신청이 승인되면 고객에게 발송', 'en' => 'Sent to the customer when the :type request is approved'],
                'heading' => ['ko' => ':type 신청이 승인되었습니다', 'en' => 'Your :Type Request Has Been Approved'],
                'lead' => [
                    'ko' => '{name}님, :type 신청이 승인되었습니다. 다음 단계가 진행되면 다시 안내드리겠습니다.',
                    'en' => 'Dear {name}, your :type request has been approved. We will notify you as it progresses.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})이 승인되었습니다.',
                    'en' => '{name}, your :type request ({claim_number}) has been approved.',
                ],
                'rows' => ['*' => []],
            ],
            'on_hold' => [
                'name' => ['ko' => ':type 보류', 'en' => ':Type On Hold'],
                'description' => ['ko' => ':type 처리가 보류되면 고객에게 발송', 'en' => 'Sent to the customer when the :type request is put on hold'],
                'heading' => ['ko' => ':type 처리가 보류되었습니다', 'en' => 'Your :Type Request Is On Hold'],
                'lead' => [
                    'ko' => '{name}님, :type 처리가 일시 보류되었습니다. 확인이 끝나는 대로 다시 안내드리겠습니다.',
                    'en' => 'Dear {name}, your :type request has been put on hold. We will contact you once the review is complete.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number}) 처리가 보류되었습니다.',
                    'en' => '{name}, your :type request ({claim_number}) has been put on hold.',
                ],
                // 보류 안내 메모(`admin_memo`)는 교환·반품 신청서에만 있는 컬럼이다.
                'rows' => [
                    '*' => [['ko' => '보류 안내', 'en' => 'Note', 'var' => 'admin_memo']],
                    'refund' => [],
                ],
            ],
            'rejected' => [
                'name' => ['ko' => ':type 반려', 'en' => ':Type Rejected'],
                'description' => ['ko' => ':type 신청이 반려되면 고객에게 발송', 'en' => 'Sent to the customer when the :type request is rejected'],
                'heading' => ['ko' => ':type 신청이 반려되었습니다', 'en' => 'Your :Type Request Has Been Rejected'],
                'lead' => [
                    'ko' => '{name}님, :type 신청이 반려되었습니다. 자세한 사유는 아래를 확인해 주세요.',
                    'en' => 'Dear {name}, your :type request has been rejected. Please see the reason below.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})이 반려되었습니다. 사유: {reject_reason}',
                    'en' => '{name}, your :type request ({claim_number}) has been rejected. Reason: {reject_reason}',
                ],
                'rows' => ['*' => [['ko' => '반려 사유', 'en' => 'Reason for Rejection', 'var' => 'reject_reason']]],
            ],
            'collection_ready' => [
                'name' => ['ko' => ':type 회수 준비', 'en' => ':Type Collection Being Arranged'],
                'description' => ['ko' => ':type 상품 회수 준비가 시작되면 고객에게 발송', 'en' => 'Sent to the customer when collection of the :type items is being arranged'],
                'heading' => ['ko' => '회수를 준비하고 있습니다', 'en' => 'Collection Is Being Arranged'],
                'lead' => [
                    'ko' => '{name}님, :type 상품 회수를 준비하고 있습니다. 회수가 시작되면 다시 안내드리겠습니다.',
                    'en' => 'Dear {name}, we are arranging the collection of your :type items. We will let you know when it starts.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})의 상품 회수를 준비하고 있습니다.',
                    'en' => '{name}, collection for your :type request ({claim_number}) is being arranged.',
                ],
                'rows' => ['*' => []],
            ],
            'collecting' => [
                'name' => ['ko' => ':type 회수중', 'en' => ':Type Collection In Progress'],
                'description' => ['ko' => ':type 상품 회수가 시작되면 고객에게 발송', 'en' => 'Sent to the customer when collection of the :type items has started'],
                'heading' => ['ko' => '회수가 시작되었습니다', 'en' => 'Collection Has Started'],
                'lead' => [
                    'ko' => '{name}님, :type 상품 회수가 시작되었습니다. 아래 운송장으로 진행 상황을 확인하실 수 있습니다.',
                    'en' => 'Dear {name}, collection of your :type items has started. You can track it with the numbers below.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})의 상품 회수가 시작되었습니다.',
                    'en' => '{name}, collection for your :type request ({claim_number}) has started.',
                ],
                'rows' => ['*' => $collectionRows],
            ],
            'collected' => [
                'name' => ['ko' => ':type 회수 완료', 'en' => ':Type Items Collected'],
                'description' => ['ko' => ':type 상품 회수가 완료되면 고객에게 발송', 'en' => 'Sent to the customer when the :type items have been collected'],
                'heading' => ['ko' => '회수가 완료되었습니다', 'en' => 'Items Have Been Collected'],
                'lead' => [
                    'ko' => '{name}님, :type 상품이 회수되었습니다. 검수 후 다음 단계를 진행합니다.',
                    'en' => 'Dear {name}, your :type items have been collected. We will proceed after inspection.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})의 상품이 회수되었습니다.',
                    'en' => '{name}, the items for your :type request ({claim_number}) have been collected.',
                ],
                'rows' => ['*' => []],
            ],
            'redelivery_ready' => [
                'name' => ['ko' => ':type 재발송 준비', 'en' => ':Type Redelivery Being Prepared'],
                'description' => ['ko' => ':type 상품 재발송 준비가 시작되면 고객에게 발송', 'en' => 'Sent to the customer when redelivery of the :type items is being prepared'],
                'heading' => ['ko' => '재발송을 준비하고 있습니다', 'en' => 'Redelivery Is Being Prepared'],
                'lead' => [
                    'ko' => '{name}님, :type 상품 재발송을 준비하고 있습니다. 발송이 시작되면 다시 안내드리겠습니다.',
                    'en' => 'Dear {name}, we are preparing to send your :type items. We will let you know when they ship.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})의 재발송을 준비하고 있습니다.',
                    'en' => '{name}, redelivery for your :type request ({claim_number}) is being prepared.',
                ],
                'rows' => ['*' => []],
            ],
            'redelivering' => [
                'name' => ['ko' => ':type 재발송 시작', 'en' => ':Type Redelivery Shipped'],
                'description' => ['ko' => ':type 상품 재발송이 시작되면 고객에게 발송', 'en' => 'Sent to the customer when the :type items have been shipped back'],
                'heading' => ['ko' => '재발송이 시작되었습니다', 'en' => 'Redelivery Has Shipped'],
                // 선교환이면 헌 상품 반송 기한을 본문 끝에 붙인다(`advance_exchange_notice` — 일반 교환은 빈 문자열).
                'lead' => [
                    'ko' => '{name}님, :type 상품이 발송되었습니다. 아래 운송장으로 배송 상황을 확인하실 수 있습니다. {advance_exchange_notice}',
                    'en' => 'Dear {name}, your :type items have been shipped. You can track the delivery with the numbers below. {advance_exchange_notice}',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})의 상품이 발송되었습니다.',
                    'en' => '{name}, the items for your :type request ({claim_number}) have been shipped.',
                ],
                'rows' => ['*' => $redeliveryRows],
                'extra_variables' => ['advance_exchange_notice'],
            ],
            'redelivered' => [
                'name' => ['ko' => ':type 재발송 도착', 'en' => ':Type Redelivery Delivered'],
                'description' => ['ko' => ':type 재발송 상품이 도착하면 고객에게 발송', 'en' => 'Sent to the customer when the redelivered :type items have arrived'],
                'heading' => ['ko' => '재발송 상품이 도착했습니다', 'en' => 'Your Items Have Arrived'],
                'lead' => [
                    'ko' => '{name}님, :type 상품이 배송 완료되었습니다.',
                    'en' => 'Dear {name}, your :type items have been delivered.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number})의 상품이 배송 완료되었습니다.',
                    'en' => '{name}, the items for your :type request ({claim_number}) have been delivered.',
                ],
                'rows' => ['*' => $redeliveryRows],
            ],
            'completed' => [
                'name' => ['ko' => ':type 완료', 'en' => ':Type Completed'],
                'description' => ['ko' => ':type 처리가 완료되면 고객에게 발송', 'en' => 'Sent to the customer when the :type has been completed'],
                'heading' => ['ko' => ':type 처리가 완료되었습니다', 'en' => 'Your :Type Has Been Completed'],
                'lead' => [
                    'ko' => '{name}님, :type 처리가 완료되었습니다. 이용해 주셔서 감사합니다.',
                    'en' => 'Dear {name}, your :type has been completed. Thank you for shopping with us.',
                ],
                'summary' => [
                    'ko' => '{name}님, :type 신청({claim_number}) 처리가 완료되었습니다.',
                    'en' => '{name}, your :type request ({claim_number}) has been completed.',
                ],
                'rows' => [
                    '*' => [],
                    // 합계 아래에 수단별 금액 줄을 둔다(S7) — 「환불 수단」 한 칸에 현금·마일리지 합계를 묶으면
                    // 마일리지로만 돌려준 환불이 「PG사 환불」 로 알려진다. 낱개 변수는 운영자 편집용으로 함께 연다.
                    'refund' => [
                        ['ko' => '환불 금액', 'en' => 'Refund Amount', 'var' => 'refund_amount'],
                        ['ko' => '환불 내역', 'en' => 'Refund Breakdown', 'var' => 'refund_breakdown'],
                    ],
                ],
                'extra_variables' => ['refund_method_label', 'refund_cash_amount', 'refund_points_amount'],
            ],
        ];
    }

    /**
     * 클레임 알림 치환 변수의 설명 사전을 반환합니다.
     *
     * 운영자가 템플릿 편집기에서 보게 되는 안내문이다.
     *
     * @return array<string, string> 변수 키 => 설명
     */
    private function claimNotificationVariableDescriptions(): array
    {
        return [
            'name' => '수신자 이름',
            'app_name' => '사이트 이름',
            'claim_number' => '클레임 신청번호',
            'claim_type_label' => '클레임 유형 (교환/반품/환불)',
            'order_number' => '주문번호',
            'status_label' => '현재 진행 상태',
            'claim_url' => '신청서 상세 URL (비회원은 비회원 주문 조회 화면)',
            'site_url' => '사이트 URL',
            'reason_label' => '신청 사유',
            'charge_amount' => '총 청구 금액',
            'outstanding_amount' => '미결제 금액',
            'paid_amount' => '결제 완료 금액',
            'reject_reason' => '반려 사유',
            'admin_memo' => '보류 안내 메모',
            'tracking_lines' => '운송장별 상품 줄 (택배사 · 운송장 · 상품×수량, 줄바꿈 구분)',
            'advance_exchange_notice' => '선교환 헌 상품 반송 기한 안내 (일반 교환은 빈 값)',
            'request_origin_notice' => '판매자 대리 접수 안내 (고객 신청은 빈 값)',
            'withdraw_origin_notice' => '판매자 대리 철회 안내 (고객 철회는 빈 값)',
            'collection_due_date' => '선교환 헌 상품 반송 기한',
            'overdue_days' => '반송 기한을 넘긴 일수',
            'unreturned_item_lines' => '아직 회수되지 않은 상품 목록(줄바꿈 구분)',
            'refund_amount' => '환불 금액 (현금 + 마일리지 합계)',
            'refund_method_label' => '실제 환불 수단 (현금 환불이 없으면 마일리지)',
            'refund_cash_amount' => '현금으로 환불된 금액 (없으면 빈 값)',
            'refund_points_amount' => '마일리지로 환불된 금액 (없으면 빈 값)',
            'refund_breakdown' => '수단별 환불 금액 줄 (줄바꿈 구분)',
            'requester_name' => '신청자 이름',
            // 부분 처리 안내용. 여기 없으면 `claimNotificationVariables()` 의 `?? $key` 폴백이
            // 걸려 운영자의 변수 도움말에 영문 키가 그대로 뜬다.
            'processed_count' => '이번에 처리된 상품 수',
            'remaining_count' => '아직 처리되지 않은 상품 수',
            'item_lines' => '이번에 처리된 상품 목록(줄바꿈 구분)',
        ];
    }

    /**
     * 클레임 알림 정의 한 건을 만듭니다.
     *
     * @param  string  $claimType  클레임 유형 값 (exchange|return|refund)
     * @param  string  $key  알림 키 (requested|approved|…)
     * @param  array{ko: string, en: string, en_lower: string}  $words  유형 낱말
     * @param  array<string, mixed>  $phrase  상태별 문구 조각
     * @return array<string, mixed> 알림 정의
     */
    private function claimNotificationDefinition(string $claimType, string $key, array $words, array $phrase): array
    {
        $rows = $phrase['rows'][$claimType] ?? $phrase['rows']['*'] ?? [];
        $baseRows = [
            ['ko' => '신청번호', 'en' => 'Claim No.', 'var' => 'claim_number'],
            ['ko' => '주문번호', 'en' => 'Order No.', 'var' => 'order_number'],
            ['ko' => '진행 상태', 'en' => 'Status', 'var' => 'status_label'],
        ];
        $allRows = array_merge($baseRows, $rows);

        return [
            'type' => $claimType.'_'.$key,
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => $this->claimNotificationText($phrase['name'], $words),
            'description' => $this->claimNotificationText($phrase['description'], $words),
            'channels' => ['mail', 'database'],
            'hooks' => [ClaimStatusNotificationListener::NOTIFY_HOOK_PREFIX.$claimType.'.notify_'.$key],
            'variables' => $this->claimNotificationVariablesWith($allRows, $phrase['extra_variables'] ?? []),
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] '.$this->claimNotificationText($phrase['heading'], $words)['ko'].' (신청번호: {claim_number})',
                        'en' => '[{app_name}] '.$this->claimNotificationText($phrase['heading'], $words)['en'].' (Claim #{claim_number})',
                    ],
                    'body' => [
                        'ko' => $this->claimNotificationMailBody(
                            'ko',
                            $this->claimNotificationText($phrase['heading'], $words)['ko'],
                            $this->claimNotificationText($phrase['lead'], $words)['ko'],
                            $allRows,
                            '신청 내역 보기'
                        ),
                        'en' => $this->claimNotificationMailBody(
                            'en',
                            $this->claimNotificationText($phrase['heading'], $words)['en'],
                            $this->claimNotificationText($phrase['lead'], $words)['en'],
                            $allRows,
                            'View Claim Details'
                        ),
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => $this->claimNotificationText($phrase['heading'], $words),
                    'body' => $this->claimNotificationText($phrase['summary'], $words),
                    'click_url' => '{claim_url}',
                ],
            ],
        ];
    }

    /**
     * 클레임 사건의 **운영자 수신** 알림 정의를 만듭니다.
     *
     * 고객 정의와 같은 훅을 구독한다 — 한 사건에 고객 1통 + 운영자 1통. 본문은 고객용과
     * 다르다("고객이 …했습니다" vs "귀하의 신청이 …되었습니다"). 링크는 고객 링크를 기본값으로
     * 두고, 수신자가 클레임 화면을 열 수 있으면 `personalize_data` 가 관리자 주소로 올린다.
     *
     * @param  string  $claimType  클레임 유형 값
     * @param  string  $key  상태 키 (requested|payment_completed)
     * @param  array{ko: string, en: string, en_lower: string}  $words  유형 낱말
     * @return array<string, mixed> 알림 정의
     */
    private function claimStaffNotificationDefinition(string $claimType, string $key, array $words): array
    {
        $rows = [
            ['ko' => '신청번호', 'en' => 'Claim No.', 'var' => 'claim_number'],
            ['ko' => '주문번호', 'en' => 'Order No.', 'var' => 'order_number'],
            ['ko' => '신청자', 'en' => 'Requester', 'var' => 'requester_name'],
            ['ko' => '진행 상태', 'en' => 'Status', 'var' => 'status_label'],
        ];

        if ($key === 'requested') {
            $rows[] = ['ko' => '신청 사유', 'en' => 'Reason', 'var' => 'reason_label'];
            $headingKo = '고객이 '.$words['ko'].'을(를) 신청했습니다';
            $headingEn = 'A customer requested a '.$words['en_lower'];
            $leadKo = '{name}님, 고객이 '.$words['ko'].' 신청을 냈습니다. 신청 내용을 확인하고 처리해 주세요.';
            $leadEn = 'Dear {name}, a customer has submitted a '.$words['en_lower'].' request. Please review and process it.';
            $summaryKo = '{requester_name}님이 '.$words['ko'].'을(를) 신청했습니다({claim_number}).';
            $summaryEn = '{requester_name} requested a '.$words['en_lower'].' ({claim_number}).';
            $nameKo = $words['ko'].' 신청 접수 관리자 알림';
            $nameEn = $words['en'].' Request Received (Staff)';
            $descKo = '고객이 '.$words['ko'].'을(를) 신청하면 운영자에게 발송';
            $descEn = 'Sent to staff when a customer submits a '.$words['en_lower'].' request';
        } else {
            $rows[] = ['ko' => '결제 금액', 'en' => 'Paid Amount', 'var' => 'paid_amount'];
            $headingKo = $words['ko'].' 추가결제가 완료되었습니다';
            $headingEn = 'Additional payment completed for a '.$words['en_lower'];
            $leadKo = '{name}님, 고객이 '.$words['ko'].' 추가금을 결제했습니다. 다음 처리를 진행해 주세요.';
            $leadEn = 'Dear {name}, the customer has paid the additional amount for their '.$words['en_lower'].'. Please continue processing.';
            $summaryKo = '{requester_name}님의 '.$words['ko'].' 추가결제가 완료되었습니다({claim_number}).';
            $summaryEn = 'Additional payment completed for {requester_name}\'s '.$words['en_lower'].' ({claim_number}).';
            $nameKo = $words['ko'].' 추가결제 완료 관리자 알림';
            $nameEn = $words['en'].' Additional Payment Completed (Staff)';
            $descKo = '고객이 '.$words['ko'].' 추가금을 결제하면 운영자에게 발송';
            $descEn = 'Sent to staff when a customer pays the additional amount for a '.$words['en_lower'];
        }

        return [
            'type' => $claimType.'_'.$key.'_admin',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'staff',
            'name' => ['ko' => $nameKo, 'en' => $nameEn],
            'description' => ['ko' => $descKo, 'en' => $descEn],
            'channels' => ['mail', 'database'],
            'hooks' => [ClaimStatusNotificationListener::NOTIFY_HOOK_PREFIX.$claimType.'.notify_'.$key],
            'variables' => $this->claimNotificationVariables($rows),
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => [
                        'ko' => '[{app_name}] '.$headingKo.' (신청번호: {claim_number})',
                        'en' => '[{app_name}] '.$headingEn.' (Claim #{claim_number})',
                    ],
                    'body' => [
                        'ko' => $this->claimNotificationMailBody('ko', $headingKo, $leadKo, $rows, '클레임 관리 바로가기'),
                        'en' => $this->claimNotificationMailBody('en', $headingEn, $leadEn, $rows, 'Go to Claim Management'),
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => ['ko' => $headingKo, 'en' => $headingEn],
                    'body' => ['ko' => $summaryKo, 'en' => $summaryEn],
                    'click_url' => '{claim_url}',
                ],
            ],
        ];
    }

    /**
     * 부분 처리 안내(고객 수신) 알림 정의를 만듭니다.
     *
     * 운영자가 신청 상품 중 **일부만** 승인·반려했을 때 나간다. 헤더 상태는 그대로여서
     * 기존 상태 알림이 발화하지 않으므로, 이것이 없으면 고객은 자기 신청이 어떻게 갈렸는지
     * 알 방법이 없다.
     *
     * 발화 단위는 **운영자의 [일괄 적용] 1회**다 — 상품마다 보내면 3건 처리에 메일 3통이 된다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function claimPartiallyProcessedDefinition(): array
    {
        $rows = [
            ['ko' => '신청번호', 'en' => 'Claim No.', 'var' => 'claim_number'],
            ['ko' => '클레임 유형', 'en' => 'Claim Type', 'var' => 'claim_type_label'],
            ['ko' => '주문번호', 'en' => 'Order No.', 'var' => 'order_number'],
            ['ko' => '이번에 처리된 상품', 'en' => 'Items Processed', 'var' => 'processed_count'],
            ['ko' => '남은 상품', 'en' => 'Items Remaining', 'var' => 'remaining_count'],
            ['ko' => '처리된 상품 목록', 'en' => 'Processed Items', 'var' => 'item_lines'],
        ];

        $variables = array_merge($this->claimNotificationVariables($rows), [
            ['key' => 'total_count', 'description' => '신청한 상품 수'],
            ['key' => 'approved_count', 'description' => '이번에 승인된 상품 수'],
            ['key' => 'rejected_count', 'description' => '이번에 반려된 상품 수'],
            ['key' => 'decision_label', 'description' => '이번 처리의 결정 표기'],
            // `item_lines` 를 여기 다시 적지 않는다 — 위 $rows 가 이미 싣고 있어
            // 운영자의 변수 목록에 같은 키가 두 줄로 뜬다.
        ]);

        return [
            'type' => 'claim_partially_processed',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '클레임 부분 처리 안내', 'en' => 'Claim Partially Processed'],
            'description' => [
                'ko' => '신청 상품 중 일부만 처리되면 고객에게 발송',
                'en' => 'Sent to the customer when only some of the requested items are processed',
            ],
            'channels' => ['mail', 'database'],
            'hooks' => [ClaimStatusNotificationListener::PARTIALLY_PROCESSED_HOOK],
            'variables' => $variables,
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 신청하신 상품 중 일부가 처리되었습니다 (신청번호: {claim_number})',
                        'en' => '[{app_name}] Some of your requested items have been processed (Claim #{claim_number})',
                    ],
                    'body' => [
                        'ko' => $this->claimNotificationMailBody(
                            'ko',
                            '신청 상품 중 일부가 처리되었습니다',
                            '{name}님, 신청하신 {total_count}개 상품 중 {processed_count}개가 {decision_label} 처리되었습니다. 남은 {remaining_count}개는 계속 검토 중입니다.',
                            $rows,
                            '신청 내역 보기'
                        ),
                        'en' => $this->claimNotificationMailBody(
                            'en',
                            'Some of Your Requested Items Have Been Processed',
                            'Dear {name}, {processed_count} of your {total_count} requested items were {decision_label}. The remaining {remaining_count} are still under review.',
                            $rows,
                            'View Claim Details'
                        ),
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '신청 상품 일부 처리', 'en' => 'Some requested items processed'],
                    'body' => [
                        'ko' => '{name}님, 신청({claim_number}) {total_count}개 중 {processed_count}개가 {decision_label} 처리되었습니다. 남은 {remaining_count}개는 검토 중입니다.'."\n".'{item_lines}',
                        'en' => '{name}, {processed_count} of {total_count} items in claim {claim_number} were {decision_label}. {remaining_count} remain under review.'."\n".'{item_lines}',
                    ],
                    'click_url' => '{claim_url}',
                ],
            ],
        ];
    }

    /**
     * 클레임 철회 관리자 알림 정의를 만듭니다.
     *
     * 신청자가 스스로 철회한 사실을 그 신청자에게 되돌려 보낼 이유가 없다 — 알아야 하는
     * 쪽은 운영자다. 유형 3종이 이 정의 하나를 공유하며 유형은 본문 변수로 실린다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function claimWithdrawnDefinition(): array
    {
        $rows = [
            ['ko' => '신청번호', 'en' => 'Claim No.', 'var' => 'claim_number'],
            ['ko' => '클레임 유형', 'en' => 'Claim Type', 'var' => 'claim_type_label'],
            ['ko' => '주문번호', 'en' => 'Order No.', 'var' => 'order_number'],
            ['ko' => '신청자', 'en' => 'Requester', 'var' => 'requester_name'],
        ];

        return [
            'type' => 'claim_withdrawn',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'staff',
            'name' => ['ko' => '클레임 철회 알림', 'en' => 'Claim Withdrawn Notification'],
            'description' => ['ko' => '고객이 교환·반품·환불 신청을 철회하면 관리자에게 발송', 'en' => 'Sent to admins when a customer withdraws an exchange, return, or refund request'],
            'channels' => ['mail', 'database'],
            'hooks' => [ClaimStatusNotificationListener::WITHDRAWN_HOOK],
            'variables' => $this->claimNotificationVariablesWith($rows, ['withdraw_origin_notice']),
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => [
                        'ko' => '[{app_name}] 클레임 신청이 철회되었습니다 (신청번호: {claim_number})',
                        'en' => '[{app_name}] A claim request has been withdrawn (Claim #{claim_number})',
                    ],
                    'body' => [
                        'ko' => $this->claimNotificationMailBody(
                            'ko',
                            '클레임 신청이 철회되었습니다',
                            '{name}님, {claim_type_label} 신청이 철회되었습니다. {withdraw_origin_notice}',
                            $rows,
                            '클레임 관리 바로가기'
                        ),
                        'en' => $this->claimNotificationMailBody(
                            'en',
                            'A Claim Request Has Been Withdrawn',
                            'Dear {name}, a {claim_type_label} request has been withdrawn. {withdraw_origin_notice}',
                            $rows,
                            'Go to Claim Management'
                        ),
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => ['ko' => '클레임 신청 철회', 'en' => 'Claim Request Withdrawn'],
                    'body' => [
                        'ko' => '{requester_name}님의 {claim_type_label} 신청({claim_number})이 철회되었습니다. {withdraw_origin_notice}',
                        'en' => '{requester_name}\'s {claim_type_label} request ({claim_number}) was withdrawn. {withdraw_origin_notice}',
                    ],
                    'click_url' => '{claim_url}',
                ],
            ],
        ];
    }

    /**
     * 대리 철회 안내(고객 수신) 알림 정의를 만듭니다. (S14)
     *
     * 철회 알림은 관리자 수신 한 종이 원칙이다(`claimWithdrawnDefinition()`) — 고객이 스스로 한 철회를
     * 본인에게 되돌려 보낼 이유가 없어서다. 그런데 운영자가 고객 요청으로 **대신** 철회하면 고객이 한
     * 일이 아니므로, 이 알림이 없으면 고객은 자기 신청이 왜 사라졌는지 알 길이 없다(대리 철회 확인 창도
     * 고객에게 안내된다고 약속한다). 그래서 `withdrawn_by_admin` 이 있을 때만 이 정의가 나간다.
     *
     * 유형 3종이 이 정의 하나를 공유하며 유형은 본문 변수로 실린다(관리자 수신 철회 알림과 같은 방식).
     * 수신자는 다른 고객 알림과 같은 `trigger_user` 규칙이다 — 비회원 신청은 주문서 이메일로 간다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function claimWithdrawnOnBehalfDefinition(): array
    {
        $rows = [
            ['ko' => '신청번호', 'en' => 'Claim No.', 'var' => 'claim_number'],
            ['ko' => '클레임 유형', 'en' => 'Claim Type', 'var' => 'claim_type_label'],
            ['ko' => '주문번호', 'en' => 'Order No.', 'var' => 'order_number'],
        ];

        $leadKo = '{name}님, {claim_type_label} 신청({claim_number})을 고객님 요청으로 판매자가 철회했습니다.';
        $leadEn = 'Dear {name}, the seller withdrew your {claim_type_label} request ({claim_number}) at your request.';

        return [
            'type' => 'claim_withdrawn_on_behalf',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'customer',
            'name' => ['ko' => '고객 요청 대리 철회 안내', 'en' => 'Request Withdrawn on Your Behalf'],
            'description' => [
                'ko' => '운영자가 고객 요청으로 교환·반품·환불 신청을 대신 철회하면 고객에게 발송',
                'en' => 'Sent to the customer when staff withdraw an exchange, return, or refund request at the customer\'s request',
            ],
            'channels' => ['mail', 'database'],
            'hooks' => [ClaimStatusNotificationListener::WITHDRAWN_ON_BEHALF_HOOK],
            'variables' => $this->claimNotificationVariables($rows),
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 고객님 요청으로 신청이 철회되었습니다 (신청번호: {claim_number})',
                        'en' => '[{app_name}] Your request has been withdrawn as you asked (Claim #{claim_number})',
                    ],
                    'body' => [
                        'ko' => $this->claimNotificationMailBody('ko', '신청이 철회되었습니다', $leadKo, $rows, '신청 내역 보기'),
                        'en' => $this->claimNotificationMailBody('en', 'Your Request Has Been Withdrawn', $leadEn, $rows, 'View Claim Details'),
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '신청 철회 안내', 'en' => 'Request Withdrawn'],
                    'body' => [
                        'ko' => $leadKo,
                        'en' => '{name}, the seller withdrew your {claim_type_label} request ({claim_number}) at your request.',
                    ],
                    'click_url' => '{claim_url}',
                ],
            ],
        ];
    }

    /**
     * 송장 등록 알림(고객 수신) 정의 3종을 만듭니다. (교환 회수·교환 재발송·반품 회수)
     *
     * 송장은 상품 행마다 있어, 신청서 상태가 그대로인 채 송장만 새로 적히는 일이 생긴다(「송장만 저장」,
     * 두 번째 상자의 운송장). 그 사실은 상태 알림이 나가지 않으므로 이 알림이 전한다. 같은 요청에서
     * 신청서가 회수중·발송중으로 옮겨지면 상태 알림이 운송장 줄을 싣고 이 알림은 나가지 않는다.
     *
     * 본문 변수 `tracking_lines` 는 이번 요청에서 송장이 새로 적힌 행을 운송장별로 묶은 줄이다.
     *
     * @return array<int, array<string, mixed>> 알림 정의 목록
     */
    private function claimTrackingRegisteredDefinitions(): array
    {
        $specs = [
            [
                'type' => 'exchange_collection_tracking_registered',
                'hook' => ClaimNotificationHooks::forStatus(ClaimTypeEnum::EXCHANGE->value, 'collection_tracking_registered'),
                'name' => ['ko' => '교환 회수 송장 등록', 'en' => 'Exchange Collection Tracking Registered'],
                'description' => ['ko' => '교환 상품의 회수 송장이 새로 등록되면 고객에게 발송', 'en' => 'Sent to the customer when a collection tracking number is registered for exchange items'],
                'heading' => ['ko' => '회수 송장이 등록되었습니다', 'en' => 'Collection Tracking Registered'],
                'lead' => [
                    'ko' => '{name}님, 교환 상품의 회수 송장이 등록되었습니다. 아래 운송장으로 회수 진행을 확인하실 수 있습니다.',
                    'en' => 'Dear {name}, a collection tracking number has been registered for your exchange items. You can track the collection with the numbers below.',
                ],
                'summary' => [
                    'ko' => '{name}님, 교환 신청({claim_number})의 회수 송장이 등록되었습니다.'."\n".'{tracking_lines}',
                    'en' => '{name}, a collection tracking number was registered for your exchange request ({claim_number}).'."\n".'{tracking_lines}',
                ],
                'row' => ['ko' => '회수 운송장', 'en' => 'Collection Tracking', 'var' => 'tracking_lines'],
            ],
            [
                'type' => 'exchange_redelivery_tracking_registered',
                'hook' => ClaimNotificationHooks::forStatus(ClaimTypeEnum::EXCHANGE->value, 'redelivery_tracking_registered'),
                'name' => ['ko' => '교환 상품 발송 송장 등록', 'en' => 'Exchange Redelivery Tracking Registered'],
                'description' => ['ko' => '교환 상품의 발송 송장이 새로 등록되면 고객에게 발송', 'en' => 'Sent to the customer when a redelivery tracking number is registered for exchange items'],
                'heading' => ['ko' => '교환 상품을 보냈습니다', 'en' => 'Your Exchange Items Have Been Sent'],
                'lead' => [
                    'ko' => '{name}님, 교환 상품을 보냈습니다. 아래 운송장으로 배송 상황을 확인하실 수 있습니다. {advance_exchange_notice}',
                    'en' => 'Dear {name}, we have sent your exchange items. You can track the delivery with the numbers below. {advance_exchange_notice}',
                ],
                'summary' => [
                    'ko' => '{name}님, 교환 신청({claim_number})의 교환 상품을 보냈습니다.'."\n".'{tracking_lines}',
                    'en' => '{name}, the items for your exchange request ({claim_number}) have been sent.'."\n".'{tracking_lines}',
                ],
                'row' => ['ko' => '발송 운송장', 'en' => 'Shipping Tracking', 'var' => 'tracking_lines'],
                'extra_variables' => ['advance_exchange_notice'],
            ],
            [
                'type' => 'return_collection_tracking_registered',
                'hook' => ClaimNotificationHooks::forStatus(ClaimTypeEnum::RETURN->value, 'collection_tracking_registered'),
                'name' => ['ko' => '반품 회수 송장 등록', 'en' => 'Return Collection Tracking Registered'],
                'description' => ['ko' => '반품 상품의 회수 송장이 새로 등록되면 고객에게 발송', 'en' => 'Sent to the customer when a collection tracking number is registered for return items'],
                'heading' => ['ko' => '회수 송장이 등록되었습니다', 'en' => 'Collection Tracking Registered'],
                'lead' => [
                    'ko' => '{name}님, 반품 상품의 회수 송장이 등록되었습니다. 아래 운송장으로 회수 진행을 확인하실 수 있습니다.',
                    'en' => 'Dear {name}, a collection tracking number has been registered for your return items. You can track the collection with the numbers below.',
                ],
                'summary' => [
                    'ko' => '{name}님, 반품 신청({claim_number})의 회수 송장이 등록되었습니다.'."\n".'{tracking_lines}',
                    'en' => '{name}, a collection tracking number was registered for your return request ({claim_number}).'."\n".'{tracking_lines}',
                ],
                'row' => ['ko' => '회수 운송장', 'en' => 'Collection Tracking', 'var' => 'tracking_lines'],
            ],
        ];

        $definitions = [];

        foreach ($specs as $spec) {
            $rows = [
                ['ko' => '신청번호', 'en' => 'Claim No.', 'var' => 'claim_number'],
                ['ko' => '주문번호', 'en' => 'Order No.', 'var' => 'order_number'],
                $spec['row'],
            ];

            $variables = $this->claimNotificationVariables($rows);

            foreach ($spec['extra_variables'] ?? [] as $key) {
                $variables[] = ['key' => $key, 'description' => $this->claimNotificationVariableDescriptions()[$key] ?? $key];
            }

            $definitions[] = [
                'type' => $spec['type'],
                'hook_prefix' => 'sirsoft-ecommerce',
                'audience' => 'customer',
                'name' => $spec['name'],
                'description' => $spec['description'],
                'channels' => ['mail', 'database'],
                'hooks' => [$spec['hook']],
                'variables' => $variables,
                'templates' => [
                    [
                        'channel' => 'mail',
                        'recipients' => [['type' => 'trigger_user']],
                        'subject' => [
                            'ko' => '[{app_name}] '.$spec['heading']['ko'].' (신청번호: {claim_number})',
                            'en' => '[{app_name}] '.$spec['heading']['en'].' (Claim #{claim_number})',
                        ],
                        'body' => [
                            'ko' => $this->claimNotificationMailBody('ko', $spec['heading']['ko'], $spec['lead']['ko'], $rows, '신청 내역 보기'),
                            'en' => $this->claimNotificationMailBody('en', $spec['heading']['en'], $spec['lead']['en'], $rows, 'View Claim Details'),
                        ],
                    ],
                    [
                        'channel' => 'database',
                        'recipients' => [['type' => 'trigger_user']],
                        'subject' => $spec['heading'],
                        'body' => $spec['summary'],
                        'click_url' => '{claim_url}',
                    ],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * 선교환 회수 지연 관리자 알림 정의를 만듭니다. (D2 — 교환 전용, 1회)
     *
     * 새 물건을 먼저 보낸 선교환에서 헌 물건이 기한 안에 오지 않으면 운영자에게 한 번 알린다.
     * 돈 청구는 쇼핑몰 밖에서 한다(D2). 배치(`claims:notify-overdue-collections`)가 발화한다.
     *
     * @return array<string, mixed> 알림 정의
     */
    private function claimCollectionOverdueDefinition(): array
    {
        $rows = [
            ['ko' => '신청번호', 'en' => 'Claim No.', 'var' => 'claim_number'],
            ['ko' => '주문번호', 'en' => 'Order No.', 'var' => 'order_number'],
            ['ko' => '고객', 'en' => 'Customer', 'var' => 'requester_name'],
            ['ko' => '반송 기한', 'en' => 'Return Due', 'var' => 'collection_due_date'],
            ['ko' => '지연 일수', 'en' => 'Days Overdue', 'var' => 'overdue_days'],
            ['ko' => '미회수 상품', 'en' => 'Items Not Returned', 'var' => 'unreturned_item_lines'],
        ];

        return [
            'type' => 'exchange_collection_overdue',
            'hook_prefix' => 'sirsoft-ecommerce',
            'audience' => 'staff',
            'name' => ['ko' => '선교환 회수 지연 관리자 알림', 'en' => 'Advance Exchange Collection Overdue (Staff)'],
            'description' => [
                'ko' => '선교환에서 헌 상품이 반송 기한까지 오지 않으면 운영자에게 1회 발송',
                'en' => 'Sent once to staff when the original items of an advance exchange have not been returned by the due date',
            ],
            'channels' => ['mail', 'database'],
            'hooks' => [ClaimNotificationHooks::forStatus(ClaimTypeEnum::EXCHANGE->value, 'collection_overdue')],
            'variables' => $this->claimNotificationVariables($rows),
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => [
                        'ko' => '[{app_name}] 선교환 헌 상품 회수가 늦어지고 있습니다 (신청번호: {claim_number})',
                        'en' => '[{app_name}] Advance exchange items are overdue for return (Claim #{claim_number})',
                    ],
                    'body' => [
                        'ko' => $this->claimNotificationMailBody(
                            'ko',
                            '선교환 회수가 지연되고 있습니다',
                            '{name}님, 교환 상품을 먼저 보낸 선교환의 헌 상품이 반송 기한을 {overdue_days}일 넘겼습니다. 고객에게 연락해 주세요.',
                            $rows,
                            '클레임 관리 바로가기'
                        ),
                        'en' => $this->claimNotificationMailBody(
                            'en',
                            'Advance Exchange Collection Overdue',
                            'Dear {name}, the original items of an advance exchange are {overdue_days} day(s) past the return due date. Please contact the customer.',
                            $rows,
                            'Go to Claim Management'
                        ),
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => $this->staffNotificationRecipients(),
                    'subject' => ['ko' => '선교환 회수 지연', 'en' => 'Advance Exchange Collection Overdue'],
                    'body' => [
                        'ko' => '{requester_name}님의 선교환({claim_number}) 헌 상품이 기한({collection_due_date})을 {overdue_days}일 넘겼습니다.',
                        'en' => 'The original items of {requester_name}\'s advance exchange ({claim_number}) are {overdue_days} day(s) past the due date ({collection_due_date}).',
                    ],
                    'click_url' => '{claim_url}',
                ],
            ],
        ];
    }

    /**
     * 본문 표 변수에 본문 문장에만 쓰이는 변수를 더한 목록을 만듭니다.
     *
     * @param  array<int, array{ko: string, en: string, var: string}>  $rows  본문 표 행
     * @param  array<int, string>  $extraKeys  문장에만 쓰이는 변수 키
     * @return array<int, array{key: string, description: string}> 변수 목록
     */
    private function claimNotificationVariablesWith(array $rows, array $extraKeys): array
    {
        $variables = $this->claimNotificationVariables($rows);
        $descriptions = $this->claimNotificationVariableDescriptions();
        $known = array_column($variables, 'key');

        foreach ($extraKeys as $key) {
            if (! in_array($key, $known, true)) {
                $variables[] = ['key' => $key, 'description' => $descriptions[$key] ?? $key];
            }
        }

        return $variables;
    }

    /**
     * 문구 조각의 유형 낱말을 치환합니다.
     *
     * @param  array{ko: string, en: string}  $text  문구 조각
     * @param  array{ko: string, en: string, en_lower: string}  $words  유형 낱말
     * @return array{ko: string, en: string} 치환된 문구
     */
    private function claimNotificationText(array $text, array $words): array
    {
        return [
            'ko' => strtr($text['ko'], [':type' => $words['ko'], ':Type' => $words['ko']]),
            'en' => strtr($text['en'], [':Type' => $words['en'], ':type' => $words['en_lower']]),
        ];
    }

    /**
     * 본문 표에 쓰인 변수 목록을 만듭니다.
     *
     * 표에 없더라도 모든 클레임 알림이 공통으로 제공하는 변수(수신자·사이트·상세 URL)는
     * 항상 포함한다 — 운영자가 템플릿을 고칠 때 쓸 수 있는 값이기 때문이다.
     *
     * @param  array<int, array{ko: string, en: string, var: string}>  $rows  본문 표 행
     * @return array<int, array{key: string, description: string}> 변수 목록
     */
    private function claimNotificationVariables(array $rows): array
    {
        $descriptions = $this->claimNotificationVariableDescriptions();
        $keys = ['name', 'app_name', 'claim_type_label', 'claim_url', 'site_url'];

        foreach ($rows as $row) {
            $keys[] = $row['var'];
        }

        $variables = [];

        foreach (array_unique($keys) as $key) {
            $variables[] = ['key' => $key, 'description' => $descriptions[$key] ?? $key];
        }

        return $variables;
    }

    /**
     * 클레임 알림 메일 본문 HTML 을 만듭니다.
     *
     * 주문 알림과 같은 서식(제목 밑줄 · 회색 정보 상자 · CTA 버튼 · 발신 안내)을 쓴다 —
     * 같은 상점에서 온 메일이 알림마다 다른 모양이면 수신자가 사칭 메일과 구분하기 어렵다.
     *
     * @param  string  $locale  로케일 (ko|en — 본문 글꼴과 발신 안내 문구가 갈린다)
     * @param  string  $heading  제목
     * @param  string  $lead  머리말
     * @param  array<int, array{ko: string, en: string, var: string}>  $rows  정보 상자 행
     * @param  string  $buttonText  CTA 버튼 문구
     * @return string 메일 본문 HTML
     */
    private function claimNotificationMailBody(
        string $locale,
        string $heading,
        string $lead,
        array $rows,
        string $buttonText,
        string $buttonUrl = '{claim_url}',
    ): string {
        $isKo = $locale === 'ko';
        $fontFamily = $isKo ? '\'Malgun Gothic\',sans-serif' : 'Arial,sans-serif';

        $rowsHtml = '';
        foreach ($rows as $row) {
            $label = $isKo ? $row['ko'] : $row['en'];
            // 값이 여러 줄일 수 있다(`item_lines` 는 상품마다 한 줄) — `pre-line` 이 없으면
            // 줄바꿈이 공백으로 접혀 "A × 2 B × 1" 한 줄로 붙는다.
            $rowsHtml .= '<p style="margin:5px 0;white-space:pre-line"><strong>'.$label.':</strong> {'.$row['var'].'}</p>';
        }

        return '<div style="font-family:'.$fontFamily.';max-width:600px;margin:0 auto;padding:20px">'
            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">'.$heading.'</h2>'
            .'<p style="color:#555;line-height:1.6">'.$lead.'</p>'
            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
            .$rowsHtml
            .'</div>'
            .$this->notificationButton($buttonText, $buttonUrl)
            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
            .'<p style="color:#999;font-size:12px">'
            .($isKo ? '본 메일은 {app_name}에서 발송되었습니다.' : 'This email was sent from {app_name}.')
            .'</p>'
            .'</div>';
    }

    /**
     * 이메일용 CTA 버튼 HTML 을 반환합니다.
     *
     * @param  string  $text  버튼 텍스트
     * @param  string  $url  버튼 링크
     * @return string 인라인 스타일 버튼 HTML
     */
    private function notificationButton(string $text, string $url): string
    {
        return '<div style="text-align:center;margin:25px 0">'
            .'<a href="'.$url.'" style="display:inline-block;padding:12px 30px;'
            .'background-color:#4F46E5;color:#ffffff;text-decoration:none;'
            .'border-radius:6px;font-weight:bold">'.$text.'</a>'
            .'</div>';
    }

    /**
     * 훅 리스너 목록 반환
     */
    public function getHookListeners(): array
    {
        return [
            SyncProductFromOptionListener::class,
            SyncOptionGroupsListener::class,
            ProductActivityLogListener::class,
            OrderActivityLogListener::class,
            CouponActivityLogListener::class,
            ShippingPolicyActivityLogListener::class,
            CategoryActivityLogListener::class,
            ClaimActivityLogListener::class,
            EcommerceAdminActivityLogListener::class,
            EcommerceUserActivityLogListener::class,
            ActivityLogDescriptionResolver::class,
            MergeCartOnLoginListener::class,
            ProductInquiryBoardListener::class,
            SearchProductsListener::class,
            CouponRestoreListener::class,
            CloseUnpaidVbankOnCancelListener::class,
            CouponUseListener::class,
            SeoProductCacheListener::class,
            SeoCategoryCacheListener::class,
            CategoryTreeCacheListener::class,
            SeoSettingsCacheListener::class,
            ShippingPolicyCacheListener::class,
            MileageTransactionListener::class,
            UserMileageInfoListener::class,
            UserEcommerceDataInfoListener::class,
            UserCurrencyInfoListener::class,
            UserShippingCountryInfoListener::class,
            UserPreferenceCoreSaveListener::class,
            AssignDefaultCurrencyOnRegisterListener::class,
            SystemActorContextListener::class,
            AssignDefaultShippingCountryOnRegisterListener::class,
            UserMileageCleanupListener::class,
            EcommerceNotificationDataListener::class,
            ClaimNotificationDataListener::class,
            OrderStatusNotificationListener::class,
            ClaimStatusNotificationListener::class,
            InjectAppConfigDeviceListener::class,
            IssueCashReceiptOnDepositListener::class,
            PurgeCashReceiptIdentifierListener::class,
            Ckeditor5ReferenceSourcesListener::class,
            PrivacyRetentionListener::class,
            // 코어 회원관리 목록의 확장 필터 예제 2종 — 마일리지 잔액·배송지 (#705)
            UserListMileageFilterListener::class,
            UserListAddressFilterListener::class,
            ProvideNecessaryStorageKeysListener::class,
        ];
    }

    /**
     * 스케줄 작업 목록 반환
     *
     * 모듈에서 등록하는 스케줄 작업 목록입니다.
     * 코어에서 이 메서드를 호출하여 모듈 스케줄러를 등록합니다.
     *
     * @return array 스케줄 작업 배열
     *               [
     *               [
     *               'command' => 'artisan:command',
     *               'schedule' => 'daily' | 'hourly' | 'everyMinute' | 'weekly' | cron expression,
     *               'description' => '작업 설명 (선택)',
     *               'enabled_config' => 'config.key' (선택, 설정에 따라 활성화 여부 결정),
     *               ],
     *               ]
     */
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'sirsoft-ecommerce:cancel-pending-orders',
                'schedule' => 'daily',
                'description' => '입금 기한 만료 주문 자동 취소',
                'enabled_config' => 'sirsoft-ecommerce.order_settings.auto_cancel_expired',
            ],
            [
                'command' => 'sirsoft-ecommerce:prune-expired-carts',
                'schedule' => 'daily',
                'description' => '보관기간 만료 장바구니 자동 삭제',
                // cart_expiry_days < 1 시 커맨드 내부 self-guard 로 비활성 (별도 토글 없음)
                'enabled_config' => null,
            ],
            [
                'command' => 'sirsoft-ecommerce:prune-temp-product-images',
                'schedule' => 'daily',
                'description' => '미연결 임시 상품 이미지 자동 삭제',
                // temp_key 가 남아 있으면 끝내 연결되지 않은 폼 세션 부산물이라 오탐 여지가 없다.
                // 운영 데이터가 아니므로 별도 토글 없이 상시 동작한다 (보존기간은 커맨드 옵션).
                'enabled_config' => null,
            ],
            [
                'command' => 'sirsoft-ecommerce:prune-temp-orders',
                'schedule' => 'hourly',
                'description' => '만료 임시 주문 자동 삭제',
                // 만료 판정은 임시 주문 자체의 TTL 이 정하므로 별도 토글 없음
                'enabled_config' => null,
            ],
            [
                'command' => 'sirsoft-ecommerce:earn-mileage',
                'schedule' => 'hourly',
                'description' => '지연 마일리지 적립',
                'enabled_config' => 'sirsoft-ecommerce.mileage.enabled',
            ],
            [
                'command' => 'sirsoft-ecommerce:expire-mileage',
                'schedule' => 'daily',
                'description' => '마일리지 자동 소멸',
                'enabled_config' => 'sirsoft-ecommerce.mileage.expiry_enabled',
            ],
            [
                'command' => 'sirsoft-ecommerce:notify-expiring-mileage',
                'schedule' => 'daily',
                'description' => '소멸 예정 마일리지 알림',
                'enabled_config' => 'sirsoft-ecommerce.mileage.expiry_notification_enabled',
            ],
            [
                'command' => 'sirsoft-ecommerce:reconcile-mileage-balance',
                'schedule' => 'daily',
                'description' => '마일리지 잔액 캐시 정합 교정',
                'enabled_config' => 'sirsoft-ecommerce.mileage.enabled',
            ],
            [
                'command' => 'sirsoft-ecommerce:aggregate-stats',
                'schedule' => 'hourly',
                'description' => '대시보드 판매 현황 집계',
                'enabled_config' => 'sirsoft-ecommerce.dashboard.scheduler_enabled',
            ],
            [
                'command' => 'sirsoft-ecommerce:prune-pending-claim-images',
                'schedule' => 'daily',
                'description' => '신청으로 이어지지 않은 클레임 임시 첨부 자동 삭제',
                // 귀속된 첨부는 유형 값이 바뀌어 대상에서 빠지므로 오탐 여지가 없다.
                // 운영 데이터가 아니므로 별도 토글 없이 상시 동작한다 (보존기간은 커맨드 옵션).
                'enabled_config' => null,
            ],
            [
                'command' => 'sirsoft-ecommerce:cancel-orphan-claim-payments',
                'schedule' => 'daily',
                'description' => '종결된 신청서에 남은 클레임 결제 대기 건 자동 취소',
                // 살아 있는 신청서의 결제 대기 건은 커맨드가 스스로 건너뛴다 (별도 토글 없음).
                'enabled_config' => null,
            ],
            [
                'command' => 'sirsoft-ecommerce:claims:notify-overdue-collections',
                'schedule' => 'daily',
                'description' => '선교환 헌 상품 회수 지연 관리자 알림 (1회)',
                // 선교환 설정을 꺼도 이미 선교환인 건의 지연은 알려야 하므로 별도 토글 없이 돈다 —
                // 대상이 없으면 아무 일도 하지 않는다.
                'enabled_config' => null,
            ],
        ];
    }

    /**
     * 이 모듈이 등록할 HTTP 미들웨어 선언을 반환합니다.
     *
     * 코어 self-gate 게이트가 요청 시점에 targets 패턴 매칭으로 실행합니다.
     *
     * - DetectDevice: 무명 User SSR 셸 catch-all(routes/web.php)에 라우트명이 없어 URI 패턴 '/' 로
     *   타게팅(모든 web 요청 = 기존 web 그룹 append 와 동등). InjectAppConfigDeviceListener 가
     *   appConfig.isIos 로 주입(체크아웃 애플페이 iOS 게이팅).
     * - ResolveShippingCountry: 상품/장바구니/체크아웃/주문생성 라우트에만 부착 (배송국가 해석).
     * - VerifyGuestOrderToken: 비회원 주문 후속 액션 라우트에 부착 (취소·환불예상·배송지수정·구매확정
     *   개별 지정 + 현금영수증 하위 전체 glob). `guest.orders.verify` 는 조회 토큰을 **발급**하는
     *   엔드포인트라 호출 시점에 토큰이 없다 — 부착하면 최초 인증 요청이 404 로 막혀 비회원 조회가
     *   통째로 죽으므로 제외한다(요청 빈도 제한은 throttle 과 GuestOrderAuthService 의 실패 잠금 담당).
     *   부착 대상 개수를 이 주석에 적지 않는다 — 개수를 문서에 박는 것이 다음 누락의 씨앗이다
     *   (실제로 현금영수증 라우트 2건이 추가될 때 targets 갱신이 누락돼 기능이 전면 불능이었다).
     *   정합성은 `GuestOrderTokenMiddlewareRegistrationTest` 가 라우트 테이블 전수로 강제한다.
     *
     * @return array<int, array{class: class-string, groups: array<int, string>, timing?: string, targets: array<int, string>}>
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => DetectDevice::class,
                'groups' => ['web'],
                'targets' => ['/'],
            ],
            [
                'class' => ResolveShippingCountry::class,
                'groups' => ['api'],
                'targets' => [
                    'api.modules.sirsoft-ecommerce.products.*',
                    'api.modules.sirsoft-ecommerce.cart.*',
                    'api.modules.sirsoft-ecommerce.checkout.*',
                    'api.modules.sirsoft-ecommerce.user.orders.store',
                ],
            ],
            [
                'class' => VerifyGuestOrderToken::class,
                'groups' => ['api'],
                'targets' => [
                    'api.modules.sirsoft-ecommerce.guest.orders.cancel',
                    'api.modules.sirsoft-ecommerce.guest.orders.estimate-refund',
                    'api.modules.sirsoft-ecommerce.guest.orders.update-shipping-address',
                    'api.modules.sirsoft-ecommerce.guest.orders.confirm-option',
                    // 현금영수증 하위 전체(show/issue/향후 추가분). glob 은 이 서브트리의 기본값을
                    // '보호' 로 만들어 실패 방향을 안전한 쪽으로 뒤집는다. `cash-receipt` 가 리터럴
                    // 세그먼트라 구조적으로 `verify` 와 매칭될 수 없다.
                    'api.modules.sirsoft-ecommerce.guest.orders.cash-receipt.*',
                    // 클레임(교환/반품/취소·환불) 하위 전체 — 신청·미리보기·목록·상세·수정·철회·첨부·추가결제.
                    // glob 으로 이 서브트리의 기본값을 '보호' 로 둔다(향후 추가 라우트 포함).
                    'api.modules.sirsoft-ecommerce.guest.orders.claimable-options',
                    'api.modules.sirsoft-ecommerce.guest.orders.claims.*',
                    'api.modules.sirsoft-ecommerce.guest.orders.exchanges.*',
                    'api.modules.sirsoft-ecommerce.guest.orders.returns.*',
                    'api.modules.sirsoft-ecommerce.guest.orders.refund-requests.*',
                    'api.modules.sirsoft-ecommerce.guest.orders.claim-images.*',
                    'api.modules.sirsoft-ecommerce.guest.orders.claim-payments.*',
                ],
            ],
        ];
    }

    /**
     * SEO 변수 메타데이터 정의
     *
     * 이커머스 모듈이 SEO 렌더링에 제공하는 변수를 page_type별로 선언합니다.
     *
     * @return array page_type별 변수 정의 배열
     */
    public function seoVariables(): array
    {
        return [
            '_common' => [
                'commerce_name' => [
                    'description' => '쇼핑몰명',
                    'source' => 'setting',
                    'key' => 'basic_info.shop_name',
                ],
            ],
            'product' => [
                'product_name' => [
                    'description' => '상품명',
                    'source' => 'data',
                    'required' => true,
                ],
                'product_description' => [
                    'description' => '상품 설명',
                    'source' => 'data',
                ],
            ],
            'category' => [
                'category_name' => [
                    'description' => '카테고리명',
                    'source' => 'data',
                    'required' => true,
                ],
                'category_description' => [
                    'description' => '카테고리 설명',
                    'source' => 'data',
                ],
            ],
            'search' => [
                'keyword_name' => [
                    'description' => '검색 키워드',
                    'source' => 'query',
                    'key' => 'q',
                ],
            ],
            'shop_index' => [],
        ];
    }

    /**
     * 페이지 타입별 OG 메타태그 기본값 선언
     *
     * 이커머스 도메인 데이터로부터 og:type=product, og:image, image_alt,
     * 그리고 og:product:price:amount/currency 같은 도메인별 OG 부속 태그를 직접 제공.
     * 레이아웃 meta.seo.og 가 같은 키를 선언하면 그쪽이 우선 (override).
     *
     * @param  string  $pageType  페이지 타입 ('product', 'category', 'search', 'shop_index')
     * @param  array  $context  데이터 컨텍스트
     * @param  array  $routeParams  라우트 파라미터
     * @return array OG 데이터
     */
    public function seoOgDefaults(string $pageType, array $context, array $routeParams = []): array
    {
        if ($pageType === 'product') {
            $product = data_get($context, 'product.data', []);
            // 다국어 JSON array (MariaDB 환경 등) 자동 변환
            $name = $this->resolveLocalizedValue($product['name'] ?? '');
            $price = $product['selling_price'] ?? null;

            $extra = [];
            if ($price !== null && $price !== '') {
                $extra[] = ['property' => 'product:price:amount', 'content' => $this->resolveLocalizedValue($price)];
                $extra[] = ['property' => 'product:price:currency', 'content' => 'KRW'];
            }
            if (! empty($product['sku'])) {
                $extra[] = ['property' => 'product:retailer_item_id', 'content' => $this->resolveLocalizedValue($product['sku'])];
            }

            // 절대 URL 변환 — 슬랙/페이스북/쓰레드는 og:image 가 절대 URL 이어야 인식.
            // ProductImage::download_url 은 "/api/..." 상대 경로 형식이므로 url() 통과 필수.
            $imageRaw = $this->resolveLocalizedValue($product['thumbnail_url'] ?? '');
            $image = $imageRaw !== ''
                // 보안: 요청 Host 가 아니라 사이트 주소 — 봇 화면은 캐시되어 모든 크롤러·미리보기에 나간다.
                ? (str_starts_with($imageRaw, 'http') ? $imageRaw : SiteUrl::absolute($imageRaw))
                : '';

            // thumbnail_width/height 는 어떤 Resource 도 방출하지 않는 死키였다 — 값 공급이
            // 불가능한 축이므로 제거하고 크기 메타는 코어 기본값 폴백에 맡긴다 (공개 #22 부수 정리)
            return array_filter([
                'type' => 'product',
                'image' => $image,
                'image_alt' => $name,
                'extra' => $extra,
            ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        }

        if ($pageType === 'category') {
            $category = data_get($context, 'category.data', []);
            $catImageRaw = $this->resolveLocalizedValue($category['thumbnail_url'] ?? '');
            $catImage = $catImageRaw !== ''
                ? (str_starts_with($catImageRaw, 'http') ? $catImageRaw : SiteUrl::absolute($catImageRaw))
                : '';

            return array_filter([
                'type' => 'website',
                'image' => $catImage,
                'image_alt' => $this->resolveLocalizedValue($category['name'] ?? ''),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return [];
    }

    /**
     * 페이지 타입별 JSON-LD 구조화 데이터 선언
     *
     * 이커머스 도메인 스키마(Product/Offer/AggregateRating)를 모듈이 직접 owned.
     * 레이아웃 meta.seo.structured_data 가 비어있을 때 적용.
     *
     * @param  string  $pageType  페이지 타입
     * @param  array  $context  데이터 컨텍스트
     * @param  array  $routeParams  라우트 파라미터
     * @return array Schema.org 형식
     */
    public function seoStructuredData(string $pageType, array $context, array $routeParams = []): array
    {
        if ($pageType !== 'product') {
            return [];
        }

        $product = data_get($context, 'product.data', []);
        if (empty($product)) {
            return [];
        }

        // 다국어 JSON array (MariaDB 환경 등) 자동 변환
        $schema = [
            '@type' => 'Product',
            'name' => $this->resolveLocalizedValue($product['name'] ?? ''),
        ];

        $description = $this->resolveLocalizedValue($product['short_description'] ?? $product['description'] ?? '');
        if ($description !== '') {
            $schema['description'] = $description;
        }

        // 절대 URL 변환 (Schema.org image 도 검색엔진이 절대 URL 권장)
        $imageRaw = $this->resolveLocalizedValue($product['thumbnail_url'] ?? '');
        if ($imageRaw !== '') {
            $schema['image'] = str_starts_with($imageRaw, 'http') ? $imageRaw : SiteUrl::absolute($imageRaw);
        }

        if (! empty($product['sku'])) {
            $schema['sku'] = $this->resolveLocalizedValue($product['sku']);
        }

        $price = $product['selling_price'] ?? null;
        if ($price !== null && $price !== '') {
            // 재고 가능 여부 = 판매 상태(sales_status). ProductResource 가 'in_stock' 키를 만들지
            // 않으므로(실제 키: sales_status enum value + stock_quantity) 종전 in_stock 참조는 항상
            // 빈값이라 모든 상품이 OutOfStock 으로 출력되던 결함을 sales_status 기준으로 수정.
            $salesStatus = (string) $this->resolveLocalizedValue($product['sales_status'] ?? '');
            $inStock = $salesStatus === 'on_sale';
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => $this->resolveLocalizedValue($price),
                'priceCurrency' => 'KRW',
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            ];
        }

        $rating = data_get($context, 'reviews.data.rating_stats.avg');
        $reviewCount = data_get($context, 'reviews.data.reviews.total');
        if ($rating !== null && $rating !== '' && $reviewCount !== null && (int) $reviewCount > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $this->resolveLocalizedValue($rating),
                'reviewCount' => (string) (int) $reviewCount,
                'bestRating' => '5',
                'worstRating' => '1',
            ];
        }

        return $schema;
    }

    /**
     * OG 기본값 키별 데이터 출처(연결 칩) 메타 선언 — 편집기 전용
     *
     * seoOgDefaults() 가 resolve 해 반환하는 평문값이 **어느 상품/카테고리 데이터에서 왔는지**를
     * 편집기 [검색엔진] 탭이 "상품 대표 이미지"·"상품 이름" 같은 연결 칩으로 보여주고 교체할 수
     * 있도록, 키별 데이터 경로(표현식)와 사용자용 라벨을 제공합니다. 데이터 경로가 1:1 로 단순한
     * 키만 선언합니다(파생값 type/extra 는 제외 — 편집기는 평문 폴백).
     *
     * @param  string  $pageType  페이지 타입
     * @return array<string, array{expr: string, label: array<string, string>}> 키별 데이터 경로 메타
     */
    public function seoOgDefaultMeta(string $pageType): array
    {
        // label 은 번역 키 — 번들 언어팩(ja 등)이 같은 키를 번역하면 추가 언어에 자동 대응(편집기가 __() 해석).
        if ($pageType === 'product') {
            return [
                'image' => ['expr' => '{{product.data.thumbnail_url}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.product_image'],
                'image_alt' => ['expr' => '{{product.data.name}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.product_name'],
            ];
        }

        if ($pageType === 'category') {
            return [
                'image' => ['expr' => '{{category.data.thumbnail_url}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.category_image'],
                'image_alt' => ['expr' => '{{category.data.name}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.category_name'],
            ];
        }

        return [];
    }

    /**
     * 구조화 데이터 속성별 데이터 출처(연결 칩) 메타 선언 — 편집기 전용
     *
     * seoStructuredData() 의 중첩 객체를 점 경로 키로 평탄화한 기준으로 선언합니다(예: offers.price).
     * 파생값(offers.availability·aggregateRating.* 등)은 단순 경로가 아니라 제외합니다(평문 폴백).
     *
     * @param  string  $pageType  페이지 타입
     * @return array<string, array{expr: string, label: array<string, string>}> 점 경로 키별 데이터 경로 메타
     */
    public function seoStructuredDataMeta(string $pageType): array
    {
        if ($pageType !== 'product') {
            return [];
        }

        // SEO 제목/설명은 사용자 입력 meta(다국어) 우선, 비어있으면 상품명/설명(다국어)으로 폴백.
        // $localized() 로 현재 로케일 문자열을 추출하여 언어별 SEO 분기를 지원한다.
        return [
            'name' => ['expr' => '{{$localized(product.data.meta_title) ?? $localized(product.data.name)}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.product_name'],
            'description' => ['expr' => '{{$localized(product.data.meta_description) ?? $localized(product.data.short_description)}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.product_description'],
            'image' => ['expr' => '{{product.data.thumbnail_url}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.product_image'],
            'sku' => ['expr' => '{{product.data.sku}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.product_sku'],
            'offers.price' => ['expr' => '{{product.data.selling_price}}', 'label' => 'sirsoft-ecommerce::seo.auto_value.product_price'],
        ];
    }

    /**
     * 관리자 메뉴 정의
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => [
                    'ko' => '이커머스',
                    'en' => 'Ecommerce',
                ],
                'slug' => 'sirsoft-ecommerce',
                'url' => null,
                'icon' => 'fas fa-shopping-cart',
                'order' => 40,
                'children' => [
                    [
                        'name' => [
                            'ko' => '환경설정',
                            'en' => 'Settings',
                        ],
                        'slug' => 'sirsoft-ecommerce-settings',
                        'url' => '/admin/ecommerce/settings',
                        'icon' => 'fas fa-cog',
                        'order' => 1,
                        'permission' => 'sirsoft-ecommerce.settings.read',
                    ],
                    [
                        'name' => [
                            'ko' => '상품 관리',
                            'en' => 'Products',
                        ],
                        'slug' => 'sirsoft-ecommerce-products',
                        'url' => '/admin/ecommerce/products',
                        'icon' => 'fas fa-box',
                        'order' => 2,
                        'permission' => 'sirsoft-ecommerce.products.read',
                    ],
                    [
                        'name' => [
                            'ko' => '카테고리 관리',
                            'en' => 'Categories',
                        ],
                        'slug' => 'sirsoft-ecommerce-categories',
                        'url' => '/admin/ecommerce/categories',
                        'icon' => 'fas fa-folder',
                        'order' => 3,
                        'permission' => 'sirsoft-ecommerce.categories.read',
                    ],
                    [
                        'name' => [
                            'ko' => '브랜드 관리',
                            'en' => 'Brands',
                        ],
                        'slug' => 'sirsoft-ecommerce-brands',
                        'url' => '/admin/ecommerce/brands',
                        'icon' => 'fas fa-tag',
                        'order' => 4,
                        'permission' => 'sirsoft-ecommerce.brands.read',
                    ],
                    [
                        'name' => [
                            'ko' => '상품정보제공고시',
                            'en' => 'Product Notice',
                        ],
                        'slug' => 'sirsoft-ecommerce-product-notices',
                        'url' => '/admin/ecommerce/product-notices',
                        'icon' => 'fas fa-file-alt',
                        'order' => 5,
                        'permission' => 'sirsoft-ecommerce.product-notice-templates.read',
                    ],
                    [
                        'name' => [
                            'ko' => '공통정보 관리',
                            'en' => 'Common Info',
                        ],
                        'slug' => 'sirsoft-ecommerce-common-info',
                        'url' => '/admin/ecommerce/common-info',
                        'icon' => 'fas fa-info-circle',
                        'order' => 6,
                        'permission' => 'sirsoft-ecommerce.product-common-infos.read',
                    ],
                    [
                        'name' => [
                            'ko' => '주문 관리',
                            'en' => 'Orders',
                        ],
                        'slug' => 'sirsoft-ecommerce-orders',
                        'url' => '/admin/ecommerce/orders',
                        'icon' => 'fas fa-receipt',
                        'order' => 7,
                        'permission' => 'sirsoft-ecommerce.orders.read',
                    ],
                    [
                        // 클레임 관리 화면 (D16/D20 계열 — 주문 관리 직후 배치)
                        'name' => [
                            'ko' => '취소/반품/교환 관리',
                            'en' => 'Claims',
                        ],
                        'slug' => 'sirsoft-ecommerce-claims',
                        'url' => '/admin/ecommerce/claims',
                        'icon' => 'fas fa-right-left',
                        'order' => 8,
                        'permission' => 'sirsoft-ecommerce.claims.read',
                    ],
                    [
                        'name' => [
                            'ko' => '쿠폰 관리',
                            'en' => 'Coupons',
                        ],
                        'slug' => 'sirsoft-ecommerce-promotion-coupons',
                        'url' => '/admin/ecommerce/promotion-coupons',
                        'icon' => 'fas fa-ticket-alt',
                        'order' => 9,
                        'permission' => 'sirsoft-ecommerce.promotion-coupon.read',
                    ],
                    [
                        'name' => [
                            'ko' => '배송정책',
                            'en' => 'Shipping Policies',
                        ],
                        'slug' => 'sirsoft-ecommerce-shipping-policies',
                        'url' => '/admin/ecommerce/shipping-policies',
                        'icon' => 'fas fa-truck',
                        'order' => 10,
                        'permission' => 'sirsoft-ecommerce.shipping-policies.read',
                    ],
                    [
                        'name' => [
                            'ko' => '리뷰 관리',
                            'en' => 'Reviews',
                        ],
                        'slug' => 'sirsoft-ecommerce-reviews',
                        'url' => '/admin/ecommerce/reviews',
                        'icon' => 'fas fa-star',
                        'order' => 11,
                        'permission' => 'sirsoft-ecommerce.reviews.read',
                    ],
                    [
                        'name' => [
                            'ko' => '마일리지 내역',
                            'en' => 'Mileage History',
                        ],
                        'slug' => 'sirsoft-ecommerce-mileage-transactions',
                        'url' => '/admin/ecommerce/mileage-transactions',
                        'icon' => 'fas fa-coins',
                        'order' => 12,
                        'permission' => 'sirsoft-ecommerce.mileage.read',
                    ],
                    [
                        'name' => [
                            'ko' => '찜 관리',
                            'en' => 'Wishlist Management',
                        ],
                        'slug' => 'sirsoft-ecommerce-wishlists',
                        'url' => '/admin/ecommerce/wishlists',
                        'icon' => 'fas fa-heart',
                        'order' => 13,
                        'permission' => 'sirsoft-ecommerce.wishlists.read',
                    ],
                    [
                        'name' => [
                            'ko' => '장바구니 관리',
                            'en' => 'Cart Management',
                        ],
                        'slug' => 'sirsoft-ecommerce-carts',
                        'url' => '/admin/ecommerce/carts',
                        'icon' => 'fas fa-cart-shopping',
                        'order' => 14,
                        'permission' => 'sirsoft-ecommerce.carts.read',
                    ],
                    [
                        'name' => [
                            'ko' => '회원 배송지',
                            'en' => 'Member Addresses',
                        ],
                        'slug' => 'sirsoft-ecommerce-user-addresses',
                        'url' => '/admin/ecommerce/user-addresses',
                        'icon' => 'fas fa-map-pin',
                        'order' => 15,
                        'permission' => 'sirsoft-ecommerce.user-addresses.read',
                    ],
                    [
                        'name' => [
                            'ko' => '상품문의 관리',
                            'en' => 'Inquiry Management',
                        ],
                        'slug' => 'sirsoft-ecommerce-inquiries',
                        'url' => '/admin/ecommerce/inquiries',
                        'icon' => 'fas fa-comments',
                        'order' => 16,
                        'permission' => 'sirsoft-ecommerce.inquiries.read',
                    ],
                ],
            ],
        ];
    }
}
