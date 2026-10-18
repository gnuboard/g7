<?php

namespace Tests\Unit\Lang;

use PHPUnit\Framework\TestCase;

/**
 * 프론트 한국어 언어 파일의 괄호 조사 검사 (7.1.0 실측 O-R2-5)
 *
 * 코어 `lang/partial/ko`, 번들 템플릿 `lang/partial/ko`, 번들 모듈·플러그인 `resources/lang` 의 한국어 문구 29곳이
 * 받침을 알 수 없는 값 뒤에 「{{name}} 은(는)」「{entity}이(가)」「(으)로」 같은 괄호 조사를 남겨 화면에 그대로 보였다.
 * 조사가 필요 없는 문장으로 바꾸고, 같은 꼴이 다시 들어오지 않게 전 파일을 잠근다. 백엔드 문구는
 * CoreValidationKoreanParticleTest·CoreExtensionKoreanParticleTest 가 맡는다.
 *
 * @scenario-manifest tests/scenarios/frontend-korean-particle.yaml
 */
class FrontendKoreanParticleTest extends TestCase
{
    /** 조사를 괄호로 남긴 꼴 */
    private const PLACEHOLDER = '/이\(가\)|을\(를\)|은\(는\)|와\(과\)|\(으\)로/u';

    /**
     * 프론트 한국어 언어 파일 전부에 괄호 조사가 없어야 한다.
     *
     * @scenario particle_form=bracket
     *
     * @effects no_bracket_particle_in_frontend_korean_lang
     *
     * @return void 반환값 없음
     */
    public function test_frontend_korean_lang_has_no_bracket_particle(): void
    {
        $files = $this->koreanLangFiles();
        $this->assertGreaterThan(50, count($files), '검사 대상 한국어 언어 파일을 찾지 못했다');

        $offenders = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            foreach ($this->flatten(is_array($data) ? $data : []) as $key => $text) {
                if (preg_match(self::PLACEHOLDER, $text) === 1) {
                    $offenders[] = $this->relative($file).' :: '.$key.' = '.$text;
                }
            }
        }

        $this->assertSame([], $offenders, '괄호 조사가 남은 프론트 한국어 문구');
    }

    /**
     * 검사 대상 한국어 JSON 언어 파일 목록.
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function koreanLangFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $roots = array_merge(
            [$root.'/lang/partial/ko'],
            glob($root.'/templates/_bundled/*/lang', GLOB_ONLYDIR) ?: [],
            glob($root.'/modules/_bundled/*/resources/lang', GLOB_ONLYDIR) ?: [],
            glob($root.'/plugins/_bundled/*/resources/lang', GLOB_ONLYDIR) ?: [],
        );

        $files = [];
        foreach ($roots as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_ends_with($path, '.json') && (str_contains($path, '/ko/') || str_ends_with($path, '/ko.json'))) {
                    $files[] = $path;
                }
            }
        }
        sort($files);

        return $files;
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
     * 저장소 기준 상대 경로를 돌려줍니다.
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로
     */
    private function relative(string $path): string
    {
        return ltrim(str_replace(str_replace('\\', '/', dirname(__DIR__, 3)), '', $path), '/');
    }
}
