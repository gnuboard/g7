<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;

/**
 * 배송정책 시더
 */
class ShippingPolicySeeder extends Seeder
{
    /**
     * 배송정책 시더를 실행합니다.
     */
    public function run(): void
    {
        $this->command->info('배송정책 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingPolicies();

        $policies = $this->getPoliciesData();

        foreach ($policies as $policyData) {
            $countrySettings = $policyData['country_settings'];
            unset($policyData['country_settings']);

            $policy = ShippingPolicy::create($policyData);

            foreach ($countrySettings as $cs) {
                $policy->countrySettings()->create($cs);
            }

            $this->command->line("  - 배송정책 생성: {$policyData['name']['ko']} ({$policyData['name']['en']})");
        }

        $count = ShippingPolicy::count();
        $this->command->info("배송정책 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 배송정책 데이터를 삭제합니다.
     */
    private function deleteExistingPolicies(): void
    {
        $deletedCount = ShippingPolicy::count();

        if ($deletedCount > 0) {
            // 국가별 설정 먼저 명시적 삭제 (DB CASCADE에 의존하지 않음)
            ShippingPolicyCountrySetting::query()->delete();
            ShippingPolicy::query()->delete();
            $this->command->warn("기존 배송정책 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 배송정책 더미 데이터를 반환합니다.
     *
     * @return array 배송정책 데이터 배열
     */
    private function getPoliciesData(): array
    {
        return [
            // 1. 국내 무료배송 (기본 배송정책)
            [
                'name' => ['ko' => '국내 무료배송', 'en' => 'Domestic Free Shipping'],
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 1,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'free',
                        'base_fee' => 0,
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 2. 국내 택배 (고정 배송비 + 도서산간)
            [
                'name' => ['ko' => '국내 택배 (고정)', 'en' => 'Domestic Parcel (Fixed)'],
                'is_active' => true,
                'sort_order' => 2,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'fixed',
                        'base_fee' => 3000,
                        'extra_fee_enabled' => true,
                        'extra_fee_settings' => [
                            ['zipcode' => '63000-63644', 'fee' => 3000, 'region' => '제주도'],
                            ['zipcode' => '52570-52571', 'fee' => 5000, 'region' => '경남 사천 섬지역'],
                        ],
                        'is_active' => true,
                    ],
                ],
            ],

            // 3. 조건부 무료배송 (5만원 이상 + 도서산간)
            [
                'name' => ['ko' => '조건부 무료배송 (5만원 이상)', 'en' => 'Conditional Free (Over 50,000)'],
                'is_active' => true,
                'sort_order' => 3,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'conditional_free',
                        'base_fee' => 2500,
                        'free_threshold' => 50000,
                        'extra_fee_enabled' => true,
                        'extra_fee_settings' => [
                            ['zipcode' => '63000-63644', 'fee' => 3000, 'region' => '제주도'],
                        ],
                        'is_active' => true,
                    ],
                ],
            ],

            // 4. 금액별 구간 배송비 (5구간)
            [
                'name' => ['ko' => '금액별 구간 배송비', 'en' => 'Amount-based Range Shipping'],
                'is_active' => true,
                'sort_order' => 4,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'range_amount',
                        'base_fee' => 0,
                        'ranges' => [
                            'type' => 'amount',
                            'tiers' => [
                                ['min' => 0, 'max' => 10000, 'unit' => '원', 'fee' => 5000],
                                ['min' => 10000, 'max' => 30000, 'unit' => '원', 'fee' => 3000],
                                ['min' => 30000, 'max' => 50000, 'unit' => '원', 'fee' => 2000],
                                ['min' => 50000, 'max' => 100000, 'unit' => '원', 'fee' => 1000],
                                ['min' => 100000, 'max' => null, 'unit' => '원', 'fee' => 0],
                            ],
                        ],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 5. 수량별 구간 배송비 (2구간)
            [
                'name' => ['ko' => '수량별 구간 배송비', 'en' => 'Quantity-based Range Shipping'],
                'is_active' => true,
                'sort_order' => 5,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'range_quantity',
                        'base_fee' => 0,
                        'ranges' => [
                            'type' => 'quantity',
                            'tiers' => [
                                ['min' => 1, 'max' => 5, 'unit' => '개', 'fee' => 3000],
                                ['min' => 6, 'max' => null, 'unit' => '개', 'fee' => 5000],
                            ],
                        ],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 6. 무게별 구간 배송비 (4구간)
            [
                'name' => ['ko' => '무게별 구간 배송비', 'en' => 'Weight-based Range Shipping'],
                'is_active' => true,
                'sort_order' => 6,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'range_weight',
                        'base_fee' => 0,
                        'ranges' => [
                            'type' => 'weight',
                            'tiers' => [
                                ['min' => 0, 'max' => 2, 'unit' => 'kg', 'fee' => 3000],
                                ['min' => 2, 'max' => 5, 'unit' => 'kg', 'fee' => 4000],
                                ['min' => 5, 'max' => 10, 'unit' => 'kg', 'fee' => 6000],
                                ['min' => 10, 'max' => null, 'unit' => 'kg', 'fee' => 8000],
                            ],
                        ],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 7. 부피별 구간 배송비 (3구간)
            [
                'name' => ['ko' => '부피별 구간 배송비', 'en' => 'Volume-based Range Shipping'],
                'is_active' => true,
                'sort_order' => 7,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'direct',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'range_volume',
                        'base_fee' => 0,
                        'ranges' => [
                            'type' => 'volume',
                            'tiers' => [
                                ['min' => 0, 'max' => 50, 'unit' => 'L', 'fee' => 5000],
                                ['min' => 50, 'max' => 100, 'unit' => 'L', 'fee' => 10000],
                                ['min' => 100, 'max' => null, 'unit' => 'L', 'fee' => 20000],
                            ],
                        ],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 8. 부피무게 구간 배송비 (4구간)
            [
                'name' => ['ko' => '부피무게 구간 배송비', 'en' => 'Volume-Weight Range Shipping'],
                'is_active' => true,
                'sort_order' => 8,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'range_volume_weight',
                        'base_fee' => 0,
                        'ranges' => [
                            'type' => 'volume_weight',
                            'tiers' => [
                                ['min' => 0, 'max' => 5, 'unit' => 'kg', 'fee' => 3500],
                                ['min' => 5, 'max' => 10, 'unit' => 'kg', 'fee' => 5000],
                                ['min' => 10, 'max' => 20, 'unit' => 'kg', 'fee' => 8000],
                                ['min' => 20, 'max' => null, 'unit' => 'kg', 'fee' => 12000],
                            ],
                        ],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 9. 해외배송 (API 연동 - 국가별 독립)
            [
                'name' => ['ko' => '해외배송 (DHL)', 'en' => 'International Shipping (DHL)'],
                'is_active' => true,
                'sort_order' => 9,
                'country_settings' => [
                    [
                        'country_code' => 'US',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'USD',
                        'charge_policy' => 'api',
                        'base_fee' => 0,
                        'api_endpoint' => 'https://api.example.com/shipping/calculate',
                        'api_request_fields' => ['order_amount', 'weight', 'zipcode'],
                        'api_response_fee_field' => 'shipping_fee',
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                    [
                        'country_code' => 'CN',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'USD',
                        'charge_policy' => 'api',
                        'base_fee' => 0,
                        'api_endpoint' => 'https://api.example.com/shipping/calculate',
                        'api_request_fields' => ['order_amount', 'weight', 'zipcode'],
                        'api_response_fee_field' => 'shipping_fee',
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                    [
                        'country_code' => 'JP',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'USD',
                        'charge_policy' => 'api',
                        'base_fee' => 0,
                        'api_endpoint' => 'https://api.example.com/shipping/calculate',
                        'api_request_fields' => ['order_amount', 'weight', 'zipcode'],
                        'api_response_fee_field' => 'shipping_fee',
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 10. 퀵서비스 (비활성)
            [
                'name' => ['ko' => '퀵서비스', 'en' => 'Quick Service'],
                'is_active' => false,
                'sort_order' => 10,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'quick',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'fixed',
                        'base_fee' => 5000,
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 11. 수량당 배송비
            [
                'name' => ['ko' => '수량당 배송비 (3개당)', 'en' => 'Per Quantity Shipping (per 3)'],
                'is_active' => true,
                'sort_order' => 11,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'per_quantity',
                        'base_fee' => 3000,
                        'ranges' => ['unit_value' => 3],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 12. 무게당 배송비
            [
                'name' => ['ko' => '무게당 배송비 (1kg당)', 'en' => 'Per Weight Shipping (per 1kg)'],
                'is_active' => true,
                'sort_order' => 12,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'per_weight',
                        'base_fee' => 1000,
                        'ranges' => ['unit_value' => 1],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 13. 부피당 배송비
            [
                'name' => ['ko' => '부피당 배송비 (10L당)', 'en' => 'Per Volume Shipping (per 10L)'],
                'is_active' => true,
                'sort_order' => 13,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'direct',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'per_volume',
                        'base_fee' => 2000,
                        'ranges' => ['unit_value' => 10],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 14. 국내외 복합 배송 (KR + US, 부피무게당)
            [
                'name' => ['ko' => '국내외 복합 배송 (부피무게당)', 'en' => 'Domestic & Intl (Per Volume Weight)'],
                'is_active' => true,
                'sort_order' => 14,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'per_volume_weight',
                        'base_fee' => 3000,
                        'ranges' => ['unit_value' => 5],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                    [
                        'country_code' => 'US',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'USD',
                        'charge_policy' => 'range_weight',
                        'base_fee' => 0,
                        'ranges' => [
                            'type' => 'weight',
                            'tiers' => [
                                ['min' => 0, 'max' => 2, 'unit' => 'kg', 'fee' => 25],
                                ['min' => 2, 'max' => 5, 'unit' => 'kg', 'fee' => 40],
                                ['min' => 5, 'max' => null, 'unit' => 'kg', 'fee' => 60],
                            ],
                        ],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],

            // 15. 금액당 배송비
            [
                'name' => ['ko' => '금액당 배송비 (1만원당)', 'en' => 'Per Amount Shipping (per 10,000)'],
                'is_active' => true,
                'sort_order' => 15,
                'country_settings' => [
                    [
                        'country_code' => 'KR',
                        'shipping_method' => 'parcel',
                        'currency_code' => 'KRW',
                        'charge_policy' => 'per_amount',
                        'base_fee' => 500,
                        'ranges' => ['unit_value' => 10000],
                        'extra_fee_enabled' => false,
                        'is_active' => true,
                    ],
                ],
            ],
        ];
    }
}
