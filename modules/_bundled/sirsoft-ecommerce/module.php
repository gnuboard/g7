<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Extension\AbstractModule;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\EcommerceNotificationDefinitionSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\SequenceSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Listeners\ActivityLogDescriptionResolver;
use Modules\Sirsoft\Ecommerce\Listeners\CategoryActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\CouponActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\CouponRestoreListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceAdminActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceUserActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\MergeCartOnLoginListener;
use Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\OrderConfirmPointListener;
use Modules\Sirsoft\Ecommerce\Listeners\ProductActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\ProductInquiryBoardListener;
use Modules\Sirsoft\Ecommerce\Listeners\SearchProductsListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoCategoryCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoProductCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoSettingsCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\ShippingPolicyActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\StockRestoreListener;
use Modules\Sirsoft\Ecommerce\Listeners\SyncOptionGroupsListener;
use Modules\Sirsoft\Ecommerce\Listeners\SyncProductFromOptionListener;

class Module extends AbstractModule
{
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
     * 모듈 설치 시 실행할 시더 목록 반환
     *
     * 배열 순서대로 실행됩니다.
     * 나머지 시더는 개발/테스트용이므로 설치 시 필수 참조 데이터만 실행합니다.
     *
     * @return array<class-string<Seeder>> 시더 클래스명 배열 (FQCN)
     */
    public function getSeeders(): array
    {
        return [
            SequenceSeeder::class,
            ShippingCarrierSeeder::class,
            EcommerceNotificationDefinitionSeeder::class,
            ClaimReasonSeeder::class,
        ];
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
            EcommerceAdminActivityLogListener::class,
            EcommerceUserActivityLogListener::class,
            ActivityLogDescriptionResolver::class,
            MergeCartOnLoginListener::class,
            ProductInquiryBoardListener::class,
            SearchProductsListener::class,
            StockRestoreListener::class,
            CouponRestoreListener::class,
            SeoProductCacheListener::class,
            SeoCategoryCacheListener::class,
            SeoSettingsCacheListener::class,
            OrderConfirmPointListener::class,
            EcommerceNotificationDataListener::class,
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
                        'name' => [
                            'ko' => '쿠폰 관리',
                            'en' => 'Coupons',
                        ],
                        'slug' => 'sirsoft-ecommerce-promotion-coupons',
                        'url' => '/admin/ecommerce/promotion-coupons',
                        'icon' => 'fas fa-ticket-alt',
                        'order' => 8,
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
                        'order' => 9,
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
                        'order' => 10,
                        'permission' => 'sirsoft-ecommerce.reviews.read',
                    ],
                ],
            ],
        ];
    }
}
