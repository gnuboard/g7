<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Lang;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 한국어 문구의 조사 자리표시 검사 (7.1.0 전수 실측 O-K4-2)
 *
 * 「{{name}}이(가) 추가되었습니다」「:status(으)로 변경했습니다」처럼 받침을 모르는 자리에
 * 조사를 괄호로 남긴 문구와, 「":keyword"가」처럼 자리표시 바로 뒤에 받침을 가정한 조사를 붙인
 * 문구는 값에 따라 틀린 문장이 된다. 게시판 모듈이 내보내는 한국어 문구(백엔드 언어 파일,
 * 프론트엔드 언어 파셜, 모듈 기본 알림 템플릿)에 이런 꼴이 다시 들어오지 않게 잠근다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/validation-message-particle.yaml 참조.
 */
class KoreanParticlePlaceholderTest extends ModuleTestCase
{
    /** 조사를 괄호로 남긴 꼴 */
    private const PLACEHOLDER = '/이\(가\)|을\(를\)|은\(는\)|와\(과\)|\(으\)로/u';

    /** 자리표시(`:name`·`{{name}}`·`{name}`) 바로 뒤(따옴표·낫표 포함)에 붙은 조사 */
    private const PARTICLE_AFTER_VARIABLE = '/(?::[a-z_]+|\{\{\s*[A-Za-z_.]+\s*\}\}|\{[a-z_]+\})["\'”」]?(?:이|가|을|를|은|는|과|와|으로|로)(?=[\s.,!?<"\'”]|$)/u';

    /**
     * 모듈 루트 경로를 돌려줍니다.
     *
     * @return string 모듈 루트
     */
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
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

    /**
     * 게시판 한국어 문구 전부를 「출처 => 문구」로 모읍니다.
     *
     * @return array<string, string> 출처 => 문구
     */
    private function koreanStrings(): array
    {
        $root = $this->moduleRoot();
        $strings = [];

        foreach (glob("{$root}/src/lang/ko/*.php") as $file) {
            foreach ($this->flatten((array) include $file) as $key => $value) {
                $strings[basename($file).':'.$key] = $value;
            }
        }

        $jsonFiles = array_merge(
            [$root.'/resources/lang/ko.json'],
            glob("{$root}/resources/lang/partial/ko/*.json"),
            glob("{$root}/resources/lang/partial/ko/*/*.json")
        );
        foreach ($jsonFiles as $file) {
            if (! is_file($file)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            foreach ($this->flatten(is_array($decoded) ? $decoded : []) as $key => $value) {
                $strings[substr($file, strlen($root) + 1).':'.$key] = $value;
            }
        }

        // 모듈 기본 알림 템플릿 등 module.php 의 한국어 줄 (메일 본문은 여러 줄로 이어 붙인다)
        foreach (file("{$root}/module.php") as $index => $line) {
            if (preg_match('/\p{Hangul}/u', $line) === 1) {
                $strings['module.php:'.($index + 1)] = $line;
            }
        }

        return $strings;
    }

    /**
     * 게시판 한국어 문구에 조사를 괄호로 남긴 꼴이 없다.
     *
     * @scenario case=module_strings_scan
     *
     * @effects no_particle_placeholder_in_board_strings
     *
     * @return void 반환값 없음
     */
    public function test_board_korean_strings_have_no_particle_placeholder(): void
    {
        $found = array_filter($this->koreanStrings(), fn (string $value) => preg_match(self::PLACEHOLDER, $value) === 1);

        $this->assertSame([], $found, '조사 자리표시가 남은 문구: '.implode(' / ', array_keys($found)));
    }

    /**
     * 게시판 한국어 문구에 자리표시 바로 뒤에 붙은 조사가 없다(값의 받침에 따라 틀린 문장이 된다).
     *
     * @scenario case=module_strings_scan
     *
     * @effects no_particle_after_variable_in_board_strings
     *
     * @return void 반환값 없음
     */
    public function test_board_korean_strings_have_no_particle_glued_to_variable(): void
    {
        $found = array_filter($this->koreanStrings(), fn (string $value) => preg_match(self::PARTICLE_AFTER_VARIABLE, $value) === 1);

        $this->assertSame([], $found, '자리표시 뒤에 조사가 붙은 문구: '.implode(' / ', array_keys($found)));
    }
}
