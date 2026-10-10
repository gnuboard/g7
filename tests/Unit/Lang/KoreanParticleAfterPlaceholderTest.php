<?php

namespace Tests\Unit\Lang;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 코어 한국어 언어 파일에서 값이 바뀌는 자리표시자 바로 뒤에 받침에 따라 갈리는 조사를 한 가지로 붙이지 않는다.
 *
 * 숫자·날짜·이름은 받침 유무가 값마다 달라 「1000를」(천 → 을)·「2026-10-07와」처럼 틀린 조사가 나온다
 * (2026-10-06 실측 O-H4: 「1회 실행당 발송 상한 필드는 1000를 초과하면 안 됩니다.」). 조사를 피하는 말로 쓰거나
 * 「이(가)」「을(를)」처럼 두 꼴을 함께 적는다.
 */
class KoreanParticleAfterPlaceholderTest extends TestCase
{
    /**
     * 코어 ko 언어 파일의 모든 문자열에 받침 의존 조사가 자리표시자에 한 꼴로만 붙은 곳이 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=core_lang
     *
     * @effects placeholder_is_not_followed_by_a_single_form_particle
     */
    #[Test]
    public function core_korean_strings_do_not_attach_a_single_form_particle_to_a_placeholder(): void
    {
        $offenders = [];
        foreach (glob(base_path('lang/ko/*.php')) as $file) {
            $strings = [];
            $lines = require $file;
            array_walk_recursive($lines, function ($value, $key) use (&$strings) {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            });

            foreach ($strings as $value) {
                // `px` 는 「픽셀」(을)·「피엑스」(를) 두 가지로 읽혀 어느 한 꼴도 맞지 않는다.
                if (preg_match('/(:[a-z_]+|\dpx)(을|를|이|가|은|는|와|과|으로|로)(?![가-힣(])/u', $value)) {
                    $offenders[] = basename($file).': '.$value;
                }
            }
        }

        $this->assertSame([], $offenders, "자리표시자 뒤 조사를 한 꼴로 붙인 문구:\n".implode("\n", $offenders));
    }

    /**
     * 숫자 상한 검증 문구가 값에 상관없이 맞는 말이 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=core_lang
     *
     * @effects numeric_max_message_reads_correctly_for_any_number
     */
    #[Test]
    public function numeric_max_message_reads_correctly_for_any_number(): void
    {
        app()->setLocale('ko');

        $message = __('validation.max.numeric', ['attribute' => '발송 상한', 'max' => 1000]);

        $this->assertStringNotContainsString('1000를', $message);
        $this->assertStringContainsString('1000', $message);
    }
}
