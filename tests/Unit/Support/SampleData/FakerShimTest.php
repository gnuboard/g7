<?php

namespace Tests\Unit\Support\SampleData;

use App\Support\SampleData\FakerShim;
use App\Support\SampleData\OptionalShim;
use App\Support\SampleData\UniqueShim;
use BadMethodCallException;
use DateTime;
use OverflowException;
use PHPUnit\Framework\TestCase;

/**
 * FakerShim 단위 테스트
 *
 * fakerphp/faker 미설치 환경에서 샘플 시더/팩토리가 사용하는 Faker 호환 API 를 검증합니다.
 * Laravel 부트스트랩 없이 순수 단위 테스트로 실행됩니다.
 */
class FakerShimTest extends TestCase
{
    private FakerShim $shim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shim = new FakerShim('ko_KR');
    }

    // ---------------------------------------------------------------
    //  순수 랜덤
    // ---------------------------------------------------------------

    public function test_random_element_returns_array_member(): void
    {
        $pool = ['a', 'b', 'c', 'd'];
        for ($i = 0; $i < 50; $i++) {
            $this->assertContains($this->shim->randomElement($pool), $pool);
        }
    }

    public function test_random_element_returns_null_for_empty_array(): void
    {
        $this->assertNull($this->shim->randomElement([]));
    }

    public function test_random_elements_without_duplicates(): void
    {
        $pool = ['a', 'b', 'c', 'd', 'e'];
        $result = $this->shim->randomElements($pool, 3, false);
        $this->assertCount(3, $result);
        $this->assertCount(3, array_unique($result));
    }

    public function test_random_elements_with_duplicates(): void
    {
        $result = $this->shim->randomElements(['x'], 5, true);
        $this->assertCount(5, $result);
        $this->assertSame(['x', 'x', 'x', 'x', 'x'], $result);
    }

    public function test_number_between_respects_bounds(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $n = $this->shim->numberBetween(10, 20);
            $this->assertGreaterThanOrEqual(10, $n);
            $this->assertLessThanOrEqual(20, $n);
        }
    }

    public function test_number_between_handles_reversed_bounds(): void
    {
        $n = $this->shim->numberBetween(50, 10);
        $this->assertGreaterThanOrEqual(10, $n);
        $this->assertLessThanOrEqual(50, $n);
    }

    public function test_random_float_respects_bounds_and_decimals(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $f = $this->shim->randomFloat(2, 0, 10);
            $this->assertGreaterThanOrEqual(0, $f);
            $this->assertLessThanOrEqual(10, $f);
            $this->assertMatchesRegularExpression('/^\d+(\.\d{1,2})?$/', (string) $f);
        }
    }

    public function test_boolean_with_100_always_true(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($this->shim->boolean(100));
        }
    }

    public function test_boolean_with_0_always_false(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse($this->shim->boolean(0));
        }
    }

    public function test_numerify_replaces_hash_with_digits(): void
    {
        $result = $this->shim->numerify('AB-####-CD');
        $this->assertMatchesRegularExpression('/^AB-\d{4}-CD$/', $result);
    }

    public function test_lexify_replaces_question_with_letters(): void
    {
        $result = $this->shim->lexify('??-?');
        $this->assertMatchesRegularExpression('/^[a-z]{2}-[a-z]$/', $result);
    }

    public function test_bothify_combines_digits_and_letters(): void
    {
        $result = $this->shim->bothify('##-??');
        $this->assertMatchesRegularExpression('/^\d{2}-[a-z]{2}$/', $result);
    }

    // ---------------------------------------------------------------
    //  인터넷
    // ---------------------------------------------------------------

    public function test_ipv4_valid_format(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $ip = $this->shim->ipv4();
            $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
        }
    }

    public function test_ipv6_valid_format(): void
    {
        $this->assertNotFalse(filter_var($this->shim->ipv6(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6));
    }

    public function test_mac_address_valid_format(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $this->shim->macAddress());
    }

    public function test_user_agent_is_nonempty_string(): void
    {
        $ua = $this->shim->userAgent();
        $this->assertIsString($ua);
        $this->assertNotEmpty($ua);
    }

    public function test_email_valid_format(): void
    {
        $this->assertNotFalse(filter_var($this->shim->email(), FILTER_VALIDATE_EMAIL));
    }

    public function test_url_valid_format(): void
    {
        $this->assertNotFalse(filter_var($this->shim->url(), FILTER_VALIDATE_URL));
    }

    // ---------------------------------------------------------------
    //  식별자
    // ---------------------------------------------------------------

    public function test_uuid_valid_v4(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $this->shim->uuid()
        );
    }

    public function test_semver_format(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->shim->semver());
    }

    public function test_slug_lowercase_hyphenated(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)*$/', $this->shim->slug(5, false));
    }

    // ---------------------------------------------------------------
    //  날짜/시간
    // ---------------------------------------------------------------

    public function test_date_time_returns_datetime(): void
    {
        $dt = $this->shim->dateTime();
        $this->assertInstanceOf(DateTime::class, $dt);
    }

    public function test_date_time_between_respects_range(): void
    {
        $start = strtotime('2020-01-01');
        $end = strtotime('2020-12-31');
        $dt = $this->shim->dateTimeBetween($start, $end);
        $this->assertGreaterThanOrEqual($start, $dt->getTimestamp());
        $this->assertLessThanOrEqual($end, $dt->getTimestamp());
    }

    public function test_date_format(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $this->shim->date());
    }

    // ---------------------------------------------------------------
    //  한국어 데이터
    // ---------------------------------------------------------------

    public function test_name_is_korean(): void
    {
        $name = $this->shim->name();
        $this->assertMatchesRegularExpression('/^[가-힣]{2,6}$/u', $name);
    }

    public function test_company_is_korean(): void
    {
        $company = $this->shim->company();
        $this->assertNotEmpty($company);
        $this->assertMatchesRegularExpression('/[가-힣]/u', $company);
    }

    public function test_address_is_korean_with_number(): void
    {
        $address = $this->shim->address();
        $this->assertMatchesRegularExpression('/[가-힣].*[가-힣].*\d+$/u', $address);
    }

    public function test_city_is_korean(): void
    {
        $this->assertMatchesRegularExpression('/[가-힣]/u', $this->shim->city());
    }

    public function test_postcode_5_digits(): void
    {
        $this->assertMatchesRegularExpression('/^\d{5}$/', $this->shim->postcode());
    }

    public function test_phone_number_korean_mobile_format(): void
    {
        $this->assertMatchesRegularExpression('/^010-\d{4}-\d{4}$/', $this->shim->phoneNumber());
    }

    public function test_country_code_in_expected_set(): void
    {
        $this->assertContains($this->shim->countryCode(), ['KR', 'US', 'JP', 'CN', 'GB', 'DE', 'FR', 'IT', 'SG', 'TW']);
    }

    // ---------------------------------------------------------------
    //  텍스트
    // ---------------------------------------------------------------

    public function test_word_is_korean(): void
    {
        $this->assertMatchesRegularExpression('/[가-힣]/u', $this->shim->word());
    }

    public function test_words_returns_array_by_default(): void
    {
        $this->assertIsArray($this->shim->words(3));
        $this->assertCount(3, $this->shim->words(3));
    }

    public function test_words_as_text_returns_string(): void
    {
        $this->assertIsString($this->shim->words(3, true));
    }

    public function test_sentence_ends_with_period(): void
    {
        $this->assertStringEndsWith('.', $this->shim->sentence());
    }

    public function test_paragraph_contains_multiple_sentences(): void
    {
        $paragraph = $this->shim->paragraph(5, false);
        // At least 2 sentences expected
        $this->assertGreaterThanOrEqual(2, substr_count($paragraph, '.'));
    }

    public function test_paragraphs_as_text_joined_by_newlines(): void
    {
        $text = $this->shim->paragraphs(3, true);
        $this->assertStringContainsString("\n\n", $text);
    }

    public function test_text_respects_max_length(): void
    {
        $this->assertLessThanOrEqual(100, mb_strlen($this->shim->text(100)));
    }

    // ---------------------------------------------------------------
    //  수식자 (unique / optional)
    // ---------------------------------------------------------------

    public function test_optional_with_full_weight_returns_value(): void
    {
        $result = $this->shim->optional(1)->name();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_optional_with_zero_weight_returns_default(): void
    {
        $this->assertNull($this->shim->optional(0)->name());
        $this->assertSame('X', $this->shim->optional(0, 'X')->name());
    }

    public function test_optional_returns_shim_instance(): void
    {
        $this->assertInstanceOf(OptionalShim::class, $this->shim->optional());
    }

    public function test_unique_returns_distinct_values(): void
    {
        $pool = ['a', 'b', 'c', 'd', 'e'];
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->shim->unique()->randomElement($pool);
        }
        $this->assertCount(5, array_unique($results));
    }

    public function test_unique_throws_when_pool_exhausted(): void
    {
        $this->expectException(OverflowException::class);
        for ($i = 0; $i < 3; $i++) {
            $this->shim->unique()->randomElement(['only']);
        }
    }

    public function test_unique_reset_clears_seen(): void
    {
        $this->shim->unique()->randomElement(['a']);
        // After reset, same value becomes available again
        $result = $this->shim->unique(true)->randomElement(['a']);
        $this->assertSame('a', $result);
    }

    public function test_unique_returns_shim_instance(): void
    {
        $this->assertInstanceOf(UniqueShim::class, $this->shim->unique());
    }

    // ---------------------------------------------------------------
    //  예외 처리
    // ---------------------------------------------------------------

    public function test_unknown_method_throws_bad_method_call(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/nonexistentMethod/');
        $this->shim->nonexistentMethod();
    }

    public function test_locale_accessible_as_property(): void
    {
        $shim = new FakerShim('en_US');
        $this->assertSame('en_US', $shim->locale);
    }
}
