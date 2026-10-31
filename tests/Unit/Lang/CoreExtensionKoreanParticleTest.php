<?php

namespace Tests\Unit\Lang;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 코어 한국어 확장·레이아웃 문구의 조사 자리표시 검사 (7.1.0 실측, 결정 2026-10-08 「괄호 조사 74곳 고침」)
 *
 * 모듈·플러그인·템플릿 식별자(영문)처럼 받침을 알 수 없는 자리표시 뒤에 「:module"을(를)」 같은 괄호 조사를 남긴
 * 문구가 74곳 있었다. 조사가 필요 없는 문장(「":module" 모듈을 찾을 수 없습니다.」)으로 바꾸고, 같은 꼴과
 * 자리표시 바로 뒤에 받침을 가정한 조사(「:name을」)가 다시 들어오지 않게 잠근다. 검증 문구는
 * CoreValidationKoreanParticleTest 가 같은 규칙으로 맡는다.
 *
 * @scenario-manifest tests/scenarios/core-extension-korean-particle.yaml
 */
class CoreExtensionKoreanParticleTest extends TestCase
{
    /** 조사를 괄호로 남긴 꼴 */
    private const PLACEHOLDER = '/이\(가\)|을\(를\)|은\(는\)|와\(과\)|\(으\)로/u';

    /** 자리표시(`:name`·`{{name}}`·`{name}`) 바로 뒤(따옴표·낫표 포함)에 붙은 조사. 「파일(:directory)을」처럼 괄호 뒤 조사는 괄호 앞 낱말에 붙으므로 대상이 아니다 */
    private const PARTICLE_AFTER_VARIABLE = '/(?::[a-z_]+|\{\{\s*[A-Za-z_.]+\s*\}\}|\{[a-z_]+\})["\'”」]?(?:이|가|을|를|은|는|과|와|으로|로)(?=[\s.,!?<"\'”]|$)/u';

    /**
     * 검사 대상 코어 한국어 문구 파일.
     *
     * @return array<string, array{0: string}> 파일 이름 목록
     */
    public static function fileProvider(): array
    {
        return [
            'modules' => ['modules'],
            'plugins' => ['plugins'],
            'templates' => ['templates'],
            'extensions' => ['extensions'],
            'module' => ['module'],
            'exceptions' => ['exceptions'],
            'layouts' => ['layouts'],
        ];
    }

    /**
     * 괄호 조사가 남은 문구가 없어야 한다.
     *
     * @scenario particle_form=bracket
     *
     * @effects no_bracket_particle_in_core_extension_messages
     *
     * @param  string  $file  lang/ko 파일 이름(확장자 제외)
     * @return void 반환값 없음
     */
    #[DataProvider('fileProvider')]
    public function test_has_no_bracket_particle(string $file): void
    {
        $offenders = array_filter($this->strings($file), fn (string $text) => preg_match(self::PLACEHOLDER, $text) === 1);

        $this->assertSame([], $offenders, "괄호 조사가 남은 lang/ko/{$file}.php 문구");
    }

    /**
     * 자리표시 바로 뒤에 받침을 가정한 조사가 붙은 문구가 없어야 한다.
     *
     * @scenario particle_form=after_variable
     *
     * @effects no_particle_after_placeholder_in_core_extension_messages
     *
     * @param  string  $file  lang/ko 파일 이름(확장자 제외)
     * @return void 반환값 없음
     */
    #[DataProvider('fileProvider')]
    public function test_has_no_particle_right_after_placeholder(string $file): void
    {
        $offenders = array_filter($this->strings($file), fn (string $text) => preg_match(self::PARTICLE_AFTER_VARIABLE, $text) === 1);

        $this->assertSame([], $offenders, "자리표시 바로 뒤에 조사가 붙은 lang/ko/{$file}.php 문구");
    }

    /**
     * lang/ko 파일의 문자열 값을 점 표기 키와 함께 펼칩니다.
     *
     * @param  string  $file  파일 이름(확장자 제외)
     * @return array<string, string> 점 표기 키 => 문구
     */
    private function strings(string $file): array
    {
        return $this->flatten((array) include dirname(__DIR__, 3)."/lang/ko/{$file}.php");
    }

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
}
