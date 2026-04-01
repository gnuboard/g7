<?php

namespace Tests\Unit\Requests\Ecommerce;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * StoreEcommerceSettingsRequest 검증 테스트
 *
 * 이커머스 설정 저장 요청의 검증 로직을 테스트합니다.
 */
class StoreEcommerceSettingsRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 한국어 로케일 설정
        App::setLocale('ko');
    }

    /**
     * FormRequest의 규칙으로 검증 수행
     *
     * @param array $data 검증할 데이터
     *
     * @return \Illuminate\Validation\Validator
     */
    protected function createValidator(array $data): \Illuminate\Validation\Validator
    {
        $request = new StoreEcommerceSettingsRequest();

        return Validator::make($data, $request->rules());
    }

    /**
     * 기본 정보 - 쇼핑몰명 필수 검증
     */
    public function test_shop_name_is_required(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '',
                'route_path' => 'shop',
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('basic_info.shop_name', $validator->errors()->toArray());
    }

    /**
     * 기본 정보 - 쇼핑몰명 정상 입력
     */
    public function test_shop_name_passes_with_valid_data(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * 기본 정보 - 라우트 경로 필수 (no_route가 false일 때)
     */
    public function test_route_path_required_when_no_route_is_false(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => '',
                'no_route' => false,
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('basic_info.route_path', $validator->errors()->toArray());
    }

    /**
     * 기본 정보 - 라우트 경로 불필요 (no_route가 true일 때)
     */
    public function test_route_path_not_required_when_no_route_is_true(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => '',
                'no_route' => true,
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * 통화 설정 - 통화 코드 필수
     */
    public function test_currency_code_is_required(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'language_currency' => [
                'currencies' => [
                    [
                        'code' => '',
                        'name' => ['ko' => '원'],
                        'exchange_rate' => 1,
                    ],
                ],
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.code', $validator->errors()->toArray());
    }

    /**
     * 통화 설정 - 통화명 배열 필수
     */
    public function test_currency_name_must_be_array(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'language_currency' => [
                'currencies' => [
                    [
                        'code' => 'KRW',
                        'name' => '원', // 배열이 아닌 문자열
                        'exchange_rate' => 1,
                    ],
                ],
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.name', $validator->errors()->toArray());
    }

    /**
     * 통화 설정 - 반올림 방식 유효성 검증
     */
    public function test_rounding_method_must_be_valid(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'language_currency' => [
                'currencies' => [
                    [
                        'code' => 'KRW',
                        'name' => ['ko' => '원'],
                        'rounding_method' => 'invalid', // 잘못된 값
                    ],
                ],
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.rounding_method', $validator->errors()->toArray());
    }

    /**
     * 통화 설정 - 유효한 반올림 방식 통과
     */
    #[DataProvider('validRoundingMethodsProvider')]
    public function test_valid_rounding_methods_pass(string $method): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'language_currency' => [
                'currencies' => [
                    [
                        'code' => 'KRW',
                        'name' => ['ko' => '원'],
                        'rounding_method' => $method,
                    ],
                ],
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * 유효한 반올림 방식 데이터 프로바이더
     *
     * @return array<string, array<string>>
     */
    public static function validRoundingMethodsProvider(): array
    {
        return [
            'floor' => ['floor'],
            'round' => ['round'],
            'ceil' => ['ceil'],
        ];
    }

    /**
     * 통화 설정 - 환율은 숫자여야 함
     */
    public function test_exchange_rate_must_be_numeric(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'language_currency' => [
                'currencies' => [
                    [
                        'code' => 'USD',
                        'name' => ['ko' => '달러'],
                        'exchange_rate' => 'not-a-number',
                    ],
                ],
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.exchange_rate', $validator->errors()->toArray());
    }

    /**
     * 통화 설정 - 환율은 0 이상이어야 함
     */
    public function test_exchange_rate_must_be_positive(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'language_currency' => [
                'currencies' => [
                    [
                        'code' => 'USD',
                        'name' => ['ko' => '달러'],
                        'exchange_rate' => -1,
                    ],
                ],
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.exchange_rate', $validator->errors()->toArray());
    }

    /**
     * 개인정보 책임자 이메일 형식 검증
     */
    public function test_privacy_officer_email_must_be_valid_email(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
                'privacy_officer_email' => 'invalid-email',
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('basic_info.privacy_officer_email', $validator->errors()->toArray());
    }

    /**
     * SEO 설정 - 유효한 데이터 통과
     */
    public function test_seo_settings_with_valid_data(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'seo' => [
                'meta_main_title' => '{site_name} - {commerce_name}',
                'meta_main_description' => '최고의 쇼핑몰입니다.',
                'seo_site_main' => true,
                'seo_user_agents' => ['Googlebot', 'Bingbot'],
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * SEO 설정 - seo_user_agents 규칙은 코어로 이관되어 검증하지 않음
     */
    public function test_seo_user_agents_is_no_longer_validated(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'seo' => [
                'seo_user_agents' => 'Googlebot', // 코어로 이관됨 — 이커머스에서 검증 안 함
            ],
        ];

        $validator = $this->createValidator($data);

        // seo_user_agents 규칙이 제거되었으므로 검증 통과해야 함
        $this->assertFalse($validator->fails());
    }

    /**
     * 전체 유효한 설정 데이터 통과
     */
    public function test_complete_valid_settings_pass(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
                'no_route' => false,
                'company_name' => '테스트 회사',
                'business_number_1' => '123',
                'business_number_2' => '45',
                'business_number_3' => '67890',
                'ceo_name' => '홍길동',
                'business_type' => '소매업',
                'business_category' => '전자상거래',
                'zipcode' => '12345',
                'base_address' => '서울시 강남구',
                'detail_address' => '테스트 빌딩 101호',
                'phone_1' => '02',
                'phone_2' => '1234',
                'phone_3' => '5678',
                'email_id' => 'test',
                'email_domain' => 'example.com',
                'privacy_officer' => '김철수',
                'privacy_officer_email' => 'privacy@example.com',
            ],
            'language_currency' => [
                'default_language' => 'ko',
                'default_currency' => 'KRW',
                'currencies' => [
                    [
                        'code' => 'KRW',
                        'name' => ['ko' => '원', 'en' => 'Won'],
                        'exchange_rate' => null,
                        'rounding_unit' => '1',
                        'rounding_method' => 'floor',
                        'is_default' => true,
                    ],
                    [
                        'code' => 'USD',
                        'name' => ['ko' => '달러', 'en' => 'Dollar'],
                        'exchange_rate' => 0.00085,
                        'rounding_unit' => '0.01',
                        'rounding_method' => 'round',
                        'is_default' => false,
                    ],
                ],
            ],
            'seo' => [
                'meta_main_title' => '{site_name} - {commerce_name}',
                'meta_main_description' => '최고의 쇼핑몰',
                'seo_site_main' => true,
                'seo_category' => true,
                'seo_search_result' => true,
                'seo_product_detail' => true,
                'seo_user_agents' => ['Googlebot', 'Bingbot'],
            ],
        ];

        $validator = $this->createValidator($data);

        $this->assertFalse($validator->fails());
    }
}
