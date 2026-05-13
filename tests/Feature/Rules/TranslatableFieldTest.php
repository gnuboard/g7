<?php

namespace Tests\Feature\Rules;

use App\Rules\TranslatableField;
use Tests\TestCase;

class TranslatableFieldTest extends TestCase
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
     * 유효한 다국어 배열 테스트
     */
    public function test_passes_with_valid_translatable_array(): void
    {
        $rule = new TranslatableField(maxLength: 255);
        $passes = true;

        $rule->validate('name', ['ko' => '한국어', 'en' => 'English'], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, '유효한 다국어 배열이 통과해야 합니다.');
    }

    /**
     * 배열이 아닌 값 테스트
     */
    public function test_fails_when_not_array(): void
    {
        $rule = new TranslatableField();
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
        $rule = new TranslatableField();
        $errorMessage = null;

        // config('app.translatable_locales')가 ['ko', 'en']이라고 가정
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
        $rule = new TranslatableField();
        $errorMessage = null;

        $rule->validate('name', ['ko' => 123, 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('문자열이어야 합니다', $errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
    }

    /**
     * 최대 길이 초과 테스트
     */
    public function test_fails_when_exceeds_max_length(): void
    {
        $rule = new TranslatableField(maxLength: 10);
        $errorMessage = null;

        $rule->validate('name', ['ko' => '이것은 매우 긴 한국어 텍스트입니다', 'en' => 'Short'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('10', $errorMessage);
        $this->assertStringContainsString('초과할 수 없습니다', $errorMessage);
    }

    /**
     * 필수 필드이고 모든 번역이 비어있을 때 테스트
     */
    public function test_fails_when_required_and_all_empty(): void
    {
        $rule = new TranslatableField(required: true);
        $errorMessage = null;

        $rule->validate('name', ['ko' => '', 'en' => ''], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('최소 하나의 언어', $errorMessage);
    }

    /**
     * 필수가 아니고 비어있어도 통과 테스트
     */
    public function test_passes_when_not_required_and_empty(): void
    {
        $rule = new TranslatableField(required: false);
        $passes = true;

        $rule->validate('name', ['ko' => '', 'en' => ''], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, '필수가 아닌 경우 빈 값도 통과해야 합니다.');
    }

    /**
     * 일부만 입력된 경우 통과 테스트
     */
    public function test_passes_with_partial_translations(): void
    {
        $rule = new TranslatableField();
        $passes = true;

        $rule->validate('name', ['ko' => '한국어', 'en' => ''], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, '일부 언어만 입력해도 통과해야 합니다.');
    }

    /**
     * null 값 허용 테스트
     */
    public function test_passes_with_null_values(): void
    {
        $rule = new TranslatableField();
        $passes = true;

        $rule->validate('name', ['ko' => '한국어', 'en' => null], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, 'null 값도 허용되어야 합니다.');
    }

    /**
     * 영어 환경에서 에러 메시지 테스트
     */
    public function test_error_messages_in_english(): void
    {
        // 현재 로케일 저장
        $originalLocale = app()->getLocale();

        // 영어로 변경
        app()->setLocale('en');

        $rule = new TranslatableField();
        $errorMessage = null;

        $rule->validate('name', 'not an array', function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('must be an array', $errorMessage);

        // 원래 로케일로 복구
        app()->setLocale($originalLocale);
    }

    /**
     * 한국어 환경에서 에러 메시지 테스트
     */
    public function test_error_messages_in_korean(): void
    {
        // 현재 로케일 저장
        $originalLocale = app()->getLocale();

        // 한국어로 변경
        app()->setLocale('ko');

        $rule = new TranslatableField();
        $errorMessage = null;

        $rule->validate('name', 'not an array', function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('배열이어야 합니다', $errorMessage);

        // 원래 로케일로 복구
        app()->setLocale($originalLocale);
    }
}
