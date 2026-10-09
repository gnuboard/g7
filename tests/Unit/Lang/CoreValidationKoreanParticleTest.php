<?php

namespace Tests\Unit\Lang;

use PHPUnit\Framework\TestCase;

/**
 * 코어 한국어 검증 문구의 조사 자리표시 검사 (7.1.0 실측 O-K4F-2)
 *
 * 「:attribute이(가)」처럼 받침을 모르는 자리에 조사를 괄호로 남긴 문구와 「:max를」처럼 자리표시
 * 바로 뒤에 받침을 가정한 조사를 붙인 문구는 값에 따라 틀리거나 어색한 문장이 된다. 코어 검증 문구는
 * 확장 밖 모든 요청에 쓰이므로 이런 꼴이 다시 들어오지 않게 잠근다(게시판 모듈의 같은 검사와 같은 규칙).
 */
class CoreValidationKoreanParticleTest extends TestCase
{
    /** 조사를 괄호로 남긴 꼴 */
    private const PLACEHOLDER = '/이\(가\)|을\(를\)|은\(는\)|와\(과\)|\(으\)로/u';

    /** 자리표시(`:name`·`{{name}}`·`{name}`) 바로 뒤(따옴표·낫표 포함)에 붙은 조사 */
    private const PARTICLE_AFTER_VARIABLE = '/(?::[a-z_]+|\{\{\s*[A-Za-z_.]+\s*\}\}|\{[a-z_]+\})["\'”」]?(?:이|가|을|를|은|는|과|와|으로|로)(?=[\s.,!?<"\'”]|$)/u';

    /**
     * 배열의 문자열 값을 점 표기 키와 함께 펼칩니다.
     *
     * @param  array<mixed>  $values  언어 배열
     * @param  string  $prefix  키 접두
     * @return array<string, string> 점 표기 키 => 문구
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } elseif (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * 코어 한국어 검증 문구를 펼쳐 돌려줍니다.
     *
     * @return array<string, string> 키 => 문구
     */
    private function validationStrings(): array
    {
        return $this->flatten((array) include dirname(__DIR__, 3).'/lang/ko/validation.php');
    }

    /**
     * 괄호 조사가 남은 문구가 없어야 한다.
     *
     * @scenario particle_form=bracket
     *
     * @effects no_bracket_particle_in_core_validation
     *
     * @return void 반환값 없음
     */
    public function test_core_validation_has_no_bracket_particle(): void
    {
        $offenders = array_filter($this->validationStrings(), fn (string $text) => preg_match(self::PLACEHOLDER, $text) === 1);

        $this->assertSame([], $offenders, '괄호 조사가 남은 코어 검증 문구');
    }

    /**
     * 자리표시 바로 뒤에 받침을 가정한 조사가 붙은 문구가 없어야 한다.
     *
     * @scenario particle_form=after_variable
     *
     * @effects no_particle_after_placeholder_in_core_validation
     *
     * @return void 반환값 없음
     */
    public function test_core_validation_has_no_particle_right_after_placeholder(): void
    {
        $offenders = array_filter($this->validationStrings(), fn (string $text) => preg_match(self::PARTICLE_AFTER_VARIABLE, $text) === 1);

        $this->assertSame([], $offenders, '자리표시 바로 뒤에 조사가 붙은 코어 검증 문구');
    }
}
