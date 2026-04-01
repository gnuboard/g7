<?php

namespace Tests\Unit\Requests\Ecommerce;

use Illuminate\Support\Facades\App;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Tests\TestCase;

/**
 * StoreEcommerceSettingsRequest 커스텀 검증 테스트
 *
 * withValidator 메서드의 커스텀 검증 로직을 테스트합니다.
 * - 통화 코드 중복 검증
 * - 통화명 필수 검증 (최소 하나의 로케일)
 */
class StoreEcommerceSettingsRequestCustomValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 한국어 로케일 설정
        App::setLocale('ko');
    }

    /**
     * FormRequest를 통한 검증 수행 (커스텀 로직 포함)
     *
     * @param array $data 검증할 데이터
     *
     * @return \Illuminate\Validation\Validator
     */
    protected function validateWithCustomRules(array $data): \Illuminate\Validation\Validator
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge($data);

        $validator = \Illuminate\Support\Facades\Validator::make($data, $request->rules());

        // withValidator 메서드 실행
        $request->withValidator($validator);

        return $validator;
    }

    /**
     * 통화 코드 중복 - 중복된 코드가 있으면 실패
     */
    public function test_duplicate_currency_codes_fail_validation(): void
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
                    ],
                    [
                        'code' => 'KRW', // 중복
                        'name' => ['ko' => '원 (중복)'],
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.1.code', $validator->errors()->toArray());
    }

    /**
     * 통화 코드 중복 - 대소문자 무시하여 중복 검출
     */
    public function test_duplicate_currency_codes_case_insensitive(): void
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
                    ],
                    [
                        'code' => 'krw', // 소문자로 중복
                        'name' => ['ko' => '원 (소문자)'],
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.1.code', $validator->errors()->toArray());
    }

    /**
     * 통화 코드 중복 - 고유한 코드들은 통과
     */
    public function test_unique_currency_codes_pass_validation(): void
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
                    ],
                    [
                        'code' => 'USD',
                        'name' => ['ko' => '달러'],
                    ],
                    [
                        'code' => 'JPY',
                        'name' => ['ko' => '엔'],
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * 통화명 필수 - 빈 배열이면 실패
     */
    public function test_empty_currency_name_array_fails_validation(): void
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
                        'name' => [], // 빈 배열
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.name', $validator->errors()->toArray());
    }

    /**
     * 통화명 필수 - name이 없으면 실패
     */
    public function test_missing_currency_name_fails_validation(): void
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
                        // name 필드 누락
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.name', $validator->errors()->toArray());
    }

    /**
     * 통화명 필수 - 모든 로케일이 빈 문자열이면 실패
     */
    public function test_all_empty_locale_names_fail_validation(): void
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
                        'name' => [
                            'ko' => '',
                            'en' => '',
                        ],
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.name', $validator->errors()->toArray());
    }

    /**
     * 통화명 필수 - 모든 로케일이 공백만 있으면 실패
     */
    public function test_whitespace_only_locale_names_fail_validation(): void
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
                        'name' => [
                            'ko' => '   ',
                            'en' => "\t\n",
                        ],
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('language_currency.currencies.0.name', $validator->errors()->toArray());
    }

    /**
     * 통화명 필수 - 하나의 로케일에만 이름이 있으면 통과
     */
    public function test_single_locale_name_passes_validation(): void
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
                        'name' => [
                            'ko' => '원',
                            // en은 없음
                        ],
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * 통화명 필수 - 일부 로케일만 비어있어도 다른 로케일에 값이 있으면 통과
     */
    public function test_partial_locale_names_pass_validation(): void
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
                        'name' => [
                            'ko' => '원',
                            'en' => '', // 빈 문자열이지만 ko에 값이 있음
                        ],
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * 통화명 필수 - 여러 통화 중 일부만 문제가 있는 경우
     */
    public function test_mixed_valid_invalid_currencies(): void
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
                        'name' => ['ko' => '원'], // 유효
                    ],
                    [
                        'code' => 'USD',
                        'name' => [], // 무효 - 빈 배열
                    ],
                    [
                        'code' => 'JPY',
                        'name' => ['ko' => '엔'], // 유효
                    ],
                    [
                        'code' => 'EUR',
                        'name' => ['ko' => '', 'en' => ''], // 무효 - 모두 빈 문자열
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();

        // 인덱스 1과 3에서 오류 발생
        $this->assertArrayHasKey('language_currency.currencies.1.name', $errors);
        $this->assertArrayHasKey('language_currency.currencies.3.name', $errors);

        // 인덱스 0과 2는 오류 없음
        $this->assertArrayNotHasKey('language_currency.currencies.0.name', $errors);
        $this->assertArrayNotHasKey('language_currency.currencies.2.name', $errors);
    }

    /**
     * 통화 목록이 비어있으면 커스텀 검증 건너뜀
     */
    public function test_empty_currencies_array_skips_custom_validation(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'language_currency' => [
                'currencies' => [],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * 통화 설정이 없으면 커스텀 검증 건너뜀
     */
    public function test_missing_currencies_skips_custom_validation(): void
    {
        $data = [
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
            'language_currency' => [
                'default_language' => 'ko',
            ],
        ];

        $validator = $this->validateWithCustomRules($data);

        $this->assertFalse($validator->fails());
    }

    /**
     * 오류 메시지가 다국어로 표시되는지 확인
     *
     * 빈 배열이 아닌 모든 로케일이 빈 문자열인 경우 테스트
     * (빈 배열은 기본 required_with 검증에서 먼저 잡힘)
     */
    public function test_error_messages_are_localized(): void
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
                        'name' => ['ko' => '', 'en' => ''], // 모든 로케일이 빈 문자열
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);
        $errors = $validator->errors()->toArray();

        $this->assertStringContainsString(
            '최소 하나의 언어로 입력',
            $errors['language_currency.currencies.0.name'][0]
        );
    }

    /**
     * 중복 코드 오류 메시지가 다국어로 표시되는지 확인
     */
    public function test_duplicate_code_error_message_is_localized(): void
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
                    ],
                    [
                        'code' => 'KRW',
                        'name' => ['ko' => '원 중복'],
                    ],
                ],
            ],
        ];

        $validator = $this->validateWithCustomRules($data);
        $errors = $validator->errors()->toArray();

        $this->assertStringContainsString(
            '중복된 통화 코드',
            $errors['language_currency.currencies.1.code'][0]
        );
    }
}
