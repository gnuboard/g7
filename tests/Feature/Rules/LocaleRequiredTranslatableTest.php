<?php

namespace Tests\Feature\Rules;

use App\Rules\LocaleRequiredTranslatable;
use Tests\TestCase;

class LocaleRequiredTranslatableTest extends TestCase
{
    /**
     * 각 테스트 시작 전 실행
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모든 테스트에서 한국어 로케일 사용
        app()->setLocale('ko');
    }

    /**
     * 현재 로케일 값이 있을 때 통과
     */
    public function test_passes_when_current_locale_has_value(): void
    {
        $rule = new LocaleRequiredTranslatable(maxLength: 255);
        $passes = true;

        $rule->validate('name', ['ko' => '한국어', 'en' => ''], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, '현재 로케일(ko) 값이 있으면 통과해야 합니다.');
    }

    /**
     * 현재 로케일 값이 없을 때 실패
     */
    public function test_fails_when_current_locale_empty(): void
    {
        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', ['ko' => '', 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
        $this->assertStringContainsString('필수', $errorMessage);
    }

    /**
     * 현재 로케일 값이 null일 때 실패
     */
    public function test_fails_when_current_locale_null(): void
    {
        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', ['ko' => null, 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
    }

    /**
     * 현재 로케일 키가 없을 때 실패
     */
    public function test_fails_when_current_locale_key_missing(): void
    {
        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', ['en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
    }

    /**
     * 다른 로케일 비어있어도 통과
     */
    public function test_passes_when_other_locales_empty(): void
    {
        $rule = new LocaleRequiredTranslatable(maxLength: 255);
        $passes = true;

        $rule->validate('name', ['ko' => '한국어 값', 'en' => null], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, '다른 로케일이 비어있어도 현재 로케일만 있으면 통과해야 합니다.');
    }

    /**
     * 모든 로케일에 값이 있을 때 통과
     */
    public function test_passes_when_all_locales_have_value(): void
    {
        $rule = new LocaleRequiredTranslatable(maxLength: 255);
        $passes = true;

        $rule->validate('name', ['ko' => '한국어', 'en' => 'English'], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, '모든 로케일에 값이 있으면 통과해야 합니다.');
    }

    /**
     * 배열이 아닌 값 테스트
     */
    public function test_fails_when_not_array(): void
    {
        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', 'string value', function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('배열이어야 합니다', $errorMessage);
    }

    /**
     * 지원되지 않는 언어 코드 테스트
     */
    public function test_fails_with_unsupported_language(): void
    {
        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', ['ko' => '한국어', 'fr' => 'Français'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('지원되지 않는 언어 코드', $errorMessage);
        $this->assertStringContainsString('fr', $errorMessage);
    }

    /**
     * 문자열이 아닌 번역 값 테스트
     */
    public function test_fails_when_translation_not_string(): void
    {
        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', ['ko' => 123, 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('문자열이어야 합니다', $errorMessage);
    }

    /**
     * 최대 길이 초과 테스트
     */
    public function test_fails_when_exceeds_max_length(): void
    {
        $rule = new LocaleRequiredTranslatable(maxLength: 10);
        $errorMessage = null;

        $rule->validate('name', ['ko' => '이것은 매우 긴 한국어 텍스트입니다', 'en' => 'Short'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('10', $errorMessage);
        $this->assertStringContainsString('초과할 수 없습니다', $errorMessage);
    }

    /**
     * 최소 길이 미달 테스트
     */
    public function test_fails_when_below_min_length(): void
    {
        $rule = new LocaleRequiredTranslatable(maxLength: 255, minLength: 5);
        $errorMessage = null;

        $rule->validate('name', ['ko' => '짧음', 'en' => ''], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('최소', $errorMessage);
        $this->assertStringContainsString('5', $errorMessage);
    }

    /**
     * 영어 로케일로 변경 시 영어 필수
     */
    public function test_requires_english_when_locale_is_english(): void
    {
        app()->setLocale('en');

        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', ['ko' => '한국어', 'en' => ''], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('en', $errorMessage);
    }

    /**
     * 영어 로케일에서 영어 값 있으면 통과
     */
    public function test_passes_when_english_locale_and_english_value(): void
    {
        app()->setLocale('en');

        $rule = new LocaleRequiredTranslatable(maxLength: 255);
        $passes = true;

        $rule->validate('name', ['ko' => '', 'en' => 'English'], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, '영어 로케일에서 영어 값이 있으면 통과해야 합니다.');
    }

    /**
     * 명시적 필수 로케일 지정 테스트
     */
    public function test_uses_explicit_required_locale(): void
    {
        $rule = new LocaleRequiredTranslatable(maxLength: 255, requiredLocale: 'en');
        $errorMessage = null;

        // 현재 로케일은 ko이지만, 명시적으로 en을 필수로 지정
        $rule->validate('name', ['ko' => '한국어', 'en' => ''], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('en', $errorMessage);
    }

    /**
     * 지원하지 않는 로케일이 현재 로케일일 때 첫 번째 언어로 폴백
     */
    public function test_fallbacks_to_first_language_when_current_locale_not_supported(): void
    {
        app()->setLocale('ja'); // 지원하지 않는 로케일

        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        // 첫 번째 지원 언어(ko)가 필수가 됨
        $rule->validate('name', ['ko' => '', 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
    }

    /**
     * 영어 환경에서 에러 메시지 테스트
     */
    public function test_error_messages_in_english(): void
    {
        app()->setLocale('en');

        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', 'not an array', function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('must be an array', $errorMessage);
    }

    /**
     * 공백만 있는 값은 빈 값으로 처리
     */
    public function test_fails_when_current_locale_has_only_whitespace(): void
    {
        $rule = new LocaleRequiredTranslatable();
        $errorMessage = null;

        $rule->validate('name', ['ko' => '   ', 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
    }
}
