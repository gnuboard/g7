<?php

namespace Tests\Feature\Rules;

use App\Models\Role;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleRequiredTranslatableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 각 테스트 시작 전 실행
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모든 테스트에서 사이트 기본 언어·요청 언어 모두 한국어로 시작한다
        config(['app.site_locale' => 'ko']);
        app()->setLocale('ko');
    }

    /**
     * 현재 로케일 값이 있을 때 통과
     *
     * @scenario case=site_locale_value
     *
     * @effects required_locale_value_must_be_present
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=site_locale_value
     *
     * @effects required_locale_value_must_be_present
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_current_locale_empty(): void
    {
        $rule = new LocaleRequiredTranslatable;
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
     *
     * @scenario case=site_locale_value
     *
     * @effects required_locale_value_must_be_present
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_current_locale_null(): void
    {
        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', ['ko' => null, 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
    }

    /**
     * 현재 로케일 키가 없을 때 실패
     *
     * @scenario case=site_locale_value
     *
     * @effects required_locale_value_must_be_present
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_current_locale_key_missing(): void
    {
        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', ['en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
    }

    /**
     * 다른 로케일 비어있어도 통과
     *
     * @scenario case=site_locale_value
     *
     * @effects required_locale_value_must_be_present
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=site_locale_value
     *
     * @effects required_locale_value_must_be_present
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=value_shape
     *
     * @effects translatable_value_shape_validated
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_not_array(): void
    {
        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', 'string value', function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('배열이어야 합니다', $errorMessage);
    }

    /**
     * strictLocales=true 일 때 지원되지 않는 언어 코드는 실패
     *
     * @scenario case=value_shape
     *
     * @effects translatable_value_shape_validated
     *
     * @return void 반환값 없음
     */
    public function test_fails_with_unsupported_language_when_strict(): void
    {
        $rule = new LocaleRequiredTranslatable(strictLocales: true);
        $errorMessage = null;

        $rule->validate('name', ['ko' => '한국어', 'fr' => 'Français'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('지원되지 않는 언어 코드', $errorMessage);
        $this->assertStringContainsString('fr', $errorMessage);
    }

    /**
     * 기본값(관대)에서는 지원 목록 밖 로케일 키가 있어도 통과해야 한다
     *
     * app.translatable_locales 는 활성 언어팩 기준으로 부팅마다 덮어써지는 가변 런타임 값이다.
     * 언어팩을 비활성화하면 기존 레코드에 남은 그 로케일 키 때문에 수정이 차단되던 결함의 회귀.
     *
     * @scenario case=value_shape
     *
     * @effects translatable_value_shape_validated
     *
     * @return void 반환값 없음
     */
    public function test_passes_with_stale_locale_key_by_default(): void
    {
        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', ['ko' => '한국어', 'fr' => 'Français'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNull($errorMessage, '비활성 언어팩의 로케일 키가 남아 있어도 통과해야 함');
    }

    /**
     * 비활성 언어팩의 번역 값은 저장/조회 왕복에서 보존되어야 한다
     *
     * 완화 방침은 "검증에서 제외"이지 "데이터 삭제"가 아니다. 통과만 확인하면 검증 통과 후
     * 저장 경로에서 값이 유실되는 경우를 놓친다 — 언어팩을 다시 켰을 때 번역이 그대로
     * 복구되는지까지 확인한다. (TranslatableField 쪽 동일 단언과 짝)
     *
     * @scenario case=value_shape
     *
     * @effects translatable_value_shape_validated
     *
     * @return void 반환값 없음
     */
    public function test_stale_locale_value_survives_model_round_trip(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);

        $name = ['ko' => '한국어', 'en' => 'English', 'ja' => '日本語'];

        $rule = new LocaleRequiredTranslatable(maxLength: 255);
        $errorMessage = null;
        $rule->validate('name', $name, function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });
        $this->assertNull($errorMessage, '지원 목록 밖 로케일이 있어도 검증은 통과해야 함');

        $role = Role::create([
            'name' => $name,
            'identifier' => 'locale-required-stale-round-trip',
            'is_admin' => false,
        ]);

        $stored = json_decode($role->fresh()->getRawOriginal('name'), true);

        $this->assertSame('日本語', $stored['ja'] ?? null, '비활성 로케일 번역이 저장에서 유실되면 안 됨');
        $this->assertSame('한국어', $stored['ko'] ?? null);
        $this->assertSame('English', $stored['en'] ?? null);
    }

    /**
     * 문자열이 아닌 번역 값 테스트
     *
     * @scenario case=value_shape
     *
     * @effects translatable_value_shape_validated
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_translation_not_string(): void
    {
        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', ['ko' => 123, 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('문자열이어야 합니다', $errorMessage);
    }

    /**
     * 최대 길이 초과 테스트
     *
     * @scenario case=value_shape
     *
     * @effects translatable_value_shape_validated
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=value_shape
     *
     * @effects translatable_value_shape_validated
     *
     * @return void 반환값 없음
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
     * 요청 언어가 영어여도 필수는 사이트 기본 언어(ko)다
     *
     * 영어 관리자가 만든 상품을 한국어 관리자가 이어 고칠 때 「ko 언어의 값은 필수」로 막히던 결함
     * (이커머스 전수 실측 S5, 제품 결정 2026-10-08) — 필수 언어가 보는 사람마다 달라지지 않게 한다.
     *
     * @scenario case=request_locale_differs
     *
     * @effects required_locale_is_site_locale_not_request_locale
     *
     * @return void 반환값 없음
     */
    public function test_requires_site_locale_even_when_request_locale_is_english(): void
    {
        app()->setLocale('en');

        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', ['ko' => '', 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage, '요청 언어가 en 이어도 사이트 기본 언어(ko) 값이 비면 실패해야 합니다.');
        $this->assertStringContainsString('ko', $errorMessage);
    }

    /**
     * 요청 언어가 영어여도 사이트 기본 언어 값만 있으면 통과
     *
     * @scenario case=request_locale_differs
     *
     * @effects required_locale_is_site_locale_not_request_locale
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_request_locale_english_and_site_locale_value_present(): void
    {
        app()->setLocale('en');

        $rule = new LocaleRequiredTranslatable(maxLength: 255);
        $passes = true;

        $rule->validate('name', ['ko' => '한국어', 'en' => ''], function () use (&$passes) {
            $passes = false;
        });

        $this->assertTrue($passes, '사이트 기본 언어(ko) 값이 있으면 요청 언어(en) 값이 비어도 통과해야 합니다.');
    }

    /**
     * 사이트 기본 언어가 영어면 요청 언어와 무관하게 영어가 필수
     *
     * @scenario case=request_locale_differs
     *
     * @effects required_locale_is_site_locale_not_request_locale
     *
     * @return void 반환값 없음
     */
    public function test_requires_english_when_site_locale_is_english(): void
    {
        config(['app.site_locale' => 'en']);
        app()->setLocale('ko');

        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', ['ko' => '한국어', 'en' => ''], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('en', $errorMessage);
    }

    /**
     * 명시적 필수 로케일 지정 테스트
     *
     * @scenario case=request_locale_differs
     *
     * @effects required_locale_is_site_locale_not_request_locale
     *
     * @return void 반환값 없음
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
     * 사이트 기본 언어가 지원 목록에 없으면 첫 번째 언어로 폴백
     *
     * @scenario case=request_locale_differs
     *
     * @effects required_locale_is_site_locale_not_request_locale
     *
     * @return void 반환값 없음
     */
    public function test_fallbacks_to_first_language_when_current_locale_not_supported(): void
    {
        config(['app.site_locale' => 'fr']); // 지원하지 않는 사이트 기본 언어
        app()->setLocale('en');

        $rule = new LocaleRequiredTranslatable;
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
     *
     * @scenario case=value_shape
     *
     * @effects translatable_value_shape_validated
     *
     * @return void 반환값 없음
     */
    public function test_error_messages_in_english(): void
    {
        app()->setLocale('en');

        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', 'not an array', function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('must be an array', $errorMessage);
    }

    /**
     * 공백만 있는 값은 빈 값으로 처리
     *
     * @scenario case=site_locale_value
     *
     * @effects required_locale_value_must_be_present
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_current_locale_has_only_whitespace(): void
    {
        $rule = new LocaleRequiredTranslatable;
        $errorMessage = null;

        $rule->validate('name', ['ko' => '   ', 'en' => 'English'], function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('ko', $errorMessage);
    }
}
