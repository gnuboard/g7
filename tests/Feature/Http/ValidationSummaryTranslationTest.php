<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 검증 실패 요약 문구의 「나머지 오류 수」 꼬리 번역
 *
 * Laravel 은 422 응답의 `message` 를 첫 오류 + 「(and N more error(s))」로 만든다(`ValidationException::summarize`).
 * 이 꼬리는 JSON 번역 키로만 번역되는데 코어에는 그 번역이 없어, 오류가 두 개 이상인 폼마다 한국어 화면의 안내가
 * 영어 꼬리로 끝났다(관리자 레이아웃은 422 토스트에 `error.message` 를 그대로 보인다).
 */
class ValidationSummaryTranslationTest extends TestCase
{
    /**
     * 필수 필드를 지정한 수만큼 비운 검증 실패 예외를 만든다.
     *
     * @param  int  $fieldCount  비울 필수 필드 수
     * @return ValidationException 요약 문구가 담긴 예외
     */
    private function failingValidation(int $fieldCount): ValidationException
    {
        $rules = [];
        for ($i = 1; $i <= $fieldCount; $i++) {
            $rules['field_'.$i] = 'required';
        }

        return new ValidationException(Validator::make([], $rules));
    }

    /**
     * 한국어에서 나머지 오류 수 꼬리가 한국어로 나온다.
     *
     * @scenario locale=ko, extra_errors=many
     *
     * @effects summary_tail_is_translated
     *
     * @return void 반환값 없음
     */
    public function test_korean_summary_tail_is_translated(): void
    {
        app()->setLocale('ko');

        $message = $this->failingValidation(3)->getMessage();

        $this->assertStringNotContainsString('more error', $message);
        $this->assertStringEndsWith('(외 2개 오류)', $message);
    }

    /**
     * 오류가 하나 더 있을 때도 한국어 꼬리로 나온다.
     *
     * @scenario locale=ko, extra_errors=one
     *
     * @effects summary_tail_is_translated
     *
     * @return void 반환값 없음
     */
    public function test_korean_summary_tail_for_one_extra_error(): void
    {
        app()->setLocale('ko');

        $this->assertStringEndsWith('(외 1개 오류)', $this->failingValidation(2)->getMessage());
    }

    /**
     * 영어는 오류가 하나 더 있으면 Laravel 원문과 같은 단수 꼬리를 쓴다.
     *
     * @scenario locale=en, extra_errors=one
     *
     * @effects english_summary_tail_keeps_plural_rule
     *
     * @return void 반환값 없음
     */
    public function test_english_summary_tail_keeps_singular(): void
    {
        app()->setLocale('en');

        $this->assertStringEndsWith('(and 1 more error)', $this->failingValidation(2)->getMessage());
    }

    /**
     * 영어는 오류가 둘 이상 더 있으면 Laravel 원문과 같은 복수 꼬리를 쓴다.
     *
     * @scenario locale=en, extra_errors=many
     *
     * @effects english_summary_tail_keeps_plural_rule
     *
     * @return void 반환값 없음
     */
    public function test_english_summary_tail_keeps_plural(): void
    {
        app()->setLocale('en');

        $this->assertStringEndsWith('(and 2 more errors)', $this->failingValidation(3)->getMessage());
    }

    /**
     * 오류가 하나뿐이면 꼬리 없이 그 오류 문장만 나온다.
     *
     * @scenario locale=ko, extra_errors=none
     *
     * @effects single_error_has_no_tail
     *
     * @return void 반환값 없음
     */
    public function test_single_error_has_no_tail(): void
    {
        app()->setLocale('ko');

        $message = $this->failingValidation(1)->getMessage();

        $this->assertStringNotContainsString('(외', $message);
        $this->assertStringNotContainsString('more error', $message);
    }

    /**
     * 꼬리 문구가 아닌 없는 키는 종전처럼 키 그대로 돌려준다.
     *
     * @scenario locale=ko, extra_errors=none
     *
     * @effects unrelated_missing_keys_are_untouched
     *
     * @return void 반환값 없음
     */
    public function test_unrelated_missing_keys_are_untouched(): void
    {
        app()->setLocale('ko');

        $this->assertSame('Some untranslated sentence.', __('Some untranslated sentence.'));
        $this->assertSame('validation.no_such_key_for_test', __('validation.no_such_key_for_test'));
    }
}
