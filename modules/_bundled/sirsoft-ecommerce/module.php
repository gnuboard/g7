<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Extension\AbstractModule;
use App\Seo\Concerns\LocalizesSeoValues;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
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
    use LocalizesSeoValues;

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
     * 이커머스 모듈이 등록할 IDV 메시지 정의/템플릿.
     *
     * 코어 4종 fallback (signup/password_reset/self_update/sensitive_action) 외에
     * 이 모듈이 신규 도입한 `checkout_verification` purpose 의 전용 메일 메시지를 제공합니다.
     * 등록되지 않으면 IdentityMessageDispatcher 가 `provider_default` (가장 일반적인 fallback)
     * 메일을 발송하므로 결제 도메인 컨텍스트가 사라집니다.
     *
     * 결제 도메인 정책 3건(payment.cancel/approve/confirm_deposit)은 purpose=sensitive_action
     * 코어 메일을 그대로 사용해도 충분하므로 별도 등록하지 않습니다.
     * (정책별 차별화가 필요하면 scope_type=SCOPE_POLICY + scope_value=policy_key 로 추가)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIdentityMessages(): array
    {
        return [
            [
                'provider_id' => 'g7:core.mail',
                'scope_type' => \App\Models\IdentityMessageDefinition::SCOPE_PURPOSE,
                'scope_value' => 'checkout_verification',
                'name' => [
                    'ko' => '결제 시 본인 확인',
                    'en' => 'Checkout Verification',
                ],
                'description' => [
                    'ko' => '결제 진행 전 본인/성인 확인 인증 코드 메일',
                    'en' => 'Identity/adult verification code mail before checkout',
                ],
                'channels' => ['mail'],
                'variables' => [
                    ['key' => 'code', 'description' => '인증 코드 (text_code 흐름)'],
                    ['key' => 'expire_minutes', 'description' => '만료까지 남은 분'],
                    ['key' => 'purpose_label', 'description' => '인증 목적 라벨'],
                    ['key' => 'app_name', 'description' => '사이트명'],
                    ['key' => 'site_url', 'description' => '사이트 URL'],
                    ['key' => 'recipient_email', 'description' => '수신자 이메일'],
                ],
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
        return [
            $this->orderConfirmedDefinition(),
            $this->orderShippedDefinition(),
            $this->orderCompletedDefinition(),
            $this->orderCancelledDefinition(),
            $this->newOrderAdminDefinition(),
            $this->inquiryReceivedDefinition(),
            $this->inquiryRepliedDefinition(),
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
            'name' => ['ko' => '주문 확인', 'en' => 'Order Confirmed'],
            'description' => ['ko' => '주문 확인 시 고객에게 발송', 'en' => 'Sent to customer when order is confirmed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_confirm'],
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
                        'ko' => '[{app_name}] 주문이 확인되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your order has been confirmed (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">주문 확인</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 주문해 주셔서 감사합니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>결제금액:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">주문 상세 내용은 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->notificationButton('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Order Confirmed</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, thank you for your order.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Total Amount:</strong> {total_amount}</p>'
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
                    'subject' => ['ko' => '주문이 확인되었습니다', 'en' => 'Your order has been confirmed'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}의 주문이 확인되었습니다.', 'en' => '{name}, your order {order_number} has been confirmed.'],
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
     * 구매 확정 알림 정의.
     */
    private function orderCompletedDefinition(): array
    {
        return [
            'type' => 'order_completed',
            'hook_prefix' => 'sirsoft-ecommerce',
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
     * 관리자 신규 주문 알림 정의.
     */
    private function newOrderAdminDefinition(): array
    {
        return [
            'type' => 'new_order_admin',
            'hook_prefix' => 'sirsoft-ecommerce',
            'name' => ['ko' => '신규 주문 관리자 알림', 'en' => 'New Order Admin Notification'],
            'description' => ['ko' => '신규 주문 접수 시 관리자에게 발송', 'en' => 'Sent to admin when a new order is placed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_create'],
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
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
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
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
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
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
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
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
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
                ? (str_starts_with($imageRaw, 'http') ? $imageRaw : url($imageRaw))
                : '';

            return array_filter([
                'type' => 'product',
                'image' => $image,
                'image_width' => isset($product['thumbnail_width']) && (int) $product['thumbnail_width'] > 0
                    ? (int) $product['thumbnail_width'] : null,
                'image_height' => isset($product['thumbnail_height']) && (int) $product['thumbnail_height'] > 0
                    ? (int) $product['thumbnail_height'] : null,
                'image_alt' => $name,
                'extra' => $extra,
            ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        }

        if ($pageType === 'category') {
            $category = data_get($context, 'category.data', []);
            $catImageRaw = $this->resolveLocalizedValue($category['thumbnail_url'] ?? '');
            $catImage = $catImageRaw !== ''
                ? (str_starts_with($catImageRaw, 'http') ? $catImageRaw : url($catImageRaw))
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
            $schema['image'] = str_starts_with($imageRaw, 'http') ? $imageRaw : url($imageRaw);
        }

        if (! empty($product['sku'])) {
            $schema['sku'] = $this->resolveLocalizedValue($product['sku']);
        }

        $price = $product['selling_price'] ?? null;
        if ($price !== null && $price !== '') {
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => $this->resolveLocalizedValue($price),
                'priceCurrency' => 'KRW',
                'availability' => ! empty($product['in_stock']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
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
