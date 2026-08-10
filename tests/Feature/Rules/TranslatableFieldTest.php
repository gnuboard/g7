<?php

namespace Tests\Feature\Rules;

use App\Models\Role;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslatableFieldTest extends TestCase
{
    use RefreshDatabase;

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
        $rule = new TranslatableField;
        $errorMessage = null;

        $rule->validate('name', 'string value', function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('배열이어야 합니다', $errorMessage);
    }

    /**
     * strictLocales=true 일 때 지원되지 않는 언어 코드는 실패
     */
    public function test_fails_with_unsupported_language_when_strict(): void
    {
        $rule = new TranslatableField(strictLocales: true);
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
     * 기본값(관대)에서는 지원 목록 밖 로케일 키가 있어도 통과해야 한다
     *
     * app.translatable_locales 는 활성 언어팩 기준으로 부팅마다 덮어써지는 가변 런타임 값이다.
     * 언어팩을 비활성화하면 기존 레코드에 남은 그 로케일 키 때문에 수정 자체가 차단되던 결함의 회귀.
     */
    public function test_passes_with_stale_locale_key_by_default(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);

        $rule = new TranslatableField(maxLength: 500);

        $this->assertNull($this->runRule($rule, [
            'ko' => '한국어',
            'en' => 'English',
            'ja' => '日本語',
        ]), '비활성 언어팩의 로케일 키가 남아 있어도 통과해야 함');
    }

    /**
     * LocaleRequiredTranslatable 도 동일하게 stale 로케일 키를 통과시켜야 한다
     */
    public function test_locale_required_rule_passes_with_stale_locale_key_by_default(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);
        app()->setLocale('ko');

        $rule = new LocaleRequiredTranslatable(maxLength: 500);

        $this->assertNull($this->runRule($rule, [
            'ko' => '한국어',
            'en' => 'English',
            'ja' => '日本語',
        ]), '비활성 언어팩의 로케일 키가 남아 있어도 통과해야 함');
    }

    /**
     * 비활성 언어팩의 번역 값은 저장/조회 왕복에서 보존되어야 한다
     *
     * 완화 방침은 "검증에서 제외"이지 "데이터 삭제"가 아니다. 언어팩을 다시 켜면
     * 번역이 그대로 복구되어야 한다.
     */
    public function test_stale_locale_value_survives_model_round_trip(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);

        $name = ['ko' => '한국어', 'en' => 'English', 'ja' => '日本語'];

        $rule = new TranslatableField(maxLength: 500);
        $this->assertNull($this->runRule($rule, $name));

        $role = Role::create([
            'name' => $name,
            'identifier' => 'stale-locale-test',
            'is_admin' => false,
        ]);

        $this->assertSame('日本語', $role->fresh()->getRawOriginal('name')
            ? json_decode($role->fresh()->getRawOriginal('name'), true)['ja']
            : null);
    }

    /**
     * 관대 모드에서도 지원 로케일의 길이 검증은 그대로 동작해야 한다 (완화가 검증을 무력화하지 않음)
     */
    public function test_supported_locale_length_still_validated_in_lenient_mode(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);

        $rule = new TranslatableField(maxLength: 10);

        $this->assertNotNull($this->runRule($rule, [
            'ko' => str_repeat('가', 11),
            'ja' => str_repeat('あ', 999),
        ]), '지원 로케일의 길이 초과는 계속 실패해야 함');
    }

    /**
     * 문자열이 아닌 번역 값 테스트
     */
    public function test_fails_when_translation_not_string(): void
    {
        $rule = new TranslatableField;
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
        $rule = new TranslatableField;
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
        $rule = new TranslatableField;
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

        $rule = new TranslatableField;
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

        $rule = new TranslatableField;
        $errorMessage = null;

        $rule->validate('name', 'not an array', function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        });

        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('배열이어야 합니다', $errorMessage);

        // 원래 로케일로 복구
        app()->setLocale($originalLocale);
    }

    /**
     * 규칙을 실행하고 실패 메시지를 반환합니다.
     *
     * @param  ValidationRule  $rule  검증 규칙
     * @param  mixed  $value  검증 값
     * @return string|null 실패 메시지 (통과 시 null)
     */
    private function runRule($rule, mixed $value): ?string
    {
        $message = null;

        $rule->validate('name', $value, function ($failMessage) use (&$message) {
            $message ??= (string) $failMessage;
        });

        return $message;
    }

    /**
     * 한글 167자는 최대 500자 제한을 통과해야 합니다. (회귀)
     *
     * strlen() 은 UTF-8 한글 1자를 3바이트로 계산하므로 167자가 501바이트가 되어
     * 잘못 거부됩니다. 길이는 문자 단위로 계산되어야 합니다.
     */
    public function test_korean_text_under_max_length_passes(): void
    {
        $rule = new TranslatableField(maxLength: 500);
        $korean = str_repeat('가', 167);

        $this->assertSame(501, strlen($korean), '테스트 전제: 한글 167자는 501바이트');
        $this->assertNull($this->runRule($rule, ['ko' => $korean]));
    }

    /**
     * 한글 500자(경계값)는 통과해야 합니다.
     */
    public function test_korean_text_at_max_length_boundary_passes(): void
    {
        $rule = new TranslatableField(maxLength: 500);

        $this->assertNull($this->runRule($rule, ['ko' => str_repeat('가', 500)]));
    }

    /**
     * 한글 501자는 최대 길이 초과로 실패해야 합니다.
     */
    public function test_korean_text_over_max_length_fails(): void
    {
        $rule = new TranslatableField(maxLength: 500);

        $message = $this->runRule($rule, ['ko' => str_repeat('가', 501)]);

        $this->assertSame(
            __('validation.translatable.max_length', ['lang' => 'ko', 'max' => 500]),
            $message
        );
    }

    /**
     * 영문도 동일하게 문자 단위로 계산되어야 합니다.
     */
    public function test_ascii_text_boundary_matches_character_count(): void
    {
        $rule = new TranslatableField(maxLength: 500);

        $this->assertNull($this->runRule($rule, ['en' => str_repeat('a', 500)]));
        $this->assertNotNull($this->runRule($rule, ['en' => str_repeat('a', 501)]));
    }

    /**
     * 두 자매 규칙이 동일 입력에 동일한 길이 판정을 내려야 합니다. (재발 방지)
     *
     * 한쪽만 strlen() 으로 되돌아가는 회귀를 교차 단언으로 차단합니다.
     *
     * @dataProvider lengthJudgementProvider
     *
     * @param  string  $text  검증 문자열
     * @param  bool  $shouldPass  통과 기대 여부
     */
    public function test_both_translatable_rules_agree_on_length(string $text, bool $shouldPass): void
    {
        $translatable = new TranslatableField(maxLength: 500);
        $localeRequired = new LocaleRequiredTranslatable(maxLength: 500);

        $value = ['ko' => $text];

        $translatableFailed = $this->runRule($translatable, $value) !== null;
        $localeRequiredFailed = $this->runRule($localeRequired, $value) !== null;

        $this->assertSame(
            $translatableFailed,
            $localeRequiredFailed,
            'TranslatableField 와 LocaleRequiredTranslatable 의 길이 판정이 어긋났습니다.'
        );
        $this->assertSame(! $shouldPass, $translatableFailed);
    }

    /**
     * 길이 판정 교차 검증 데이터.
     *
     * @return array<string, array{string, bool}> 검증 문자열과 통과 기대 여부
     */
    public static function lengthJudgementProvider(): array
    {
        return [
            '한글 167자 (501바이트)' => [str_repeat('가', 167), true],
            '한글 500자 경계' => [str_repeat('가', 500), true],
            '한글 501자 초과' => [str_repeat('가', 501), false],
            '영문 500자 경계' => [str_repeat('a', 500), true],
            '영문 501자 초과' => [str_repeat('a', 501), false],
            '이모지 300자' => [str_repeat('😀', 300), true],
        ];
    }
}
