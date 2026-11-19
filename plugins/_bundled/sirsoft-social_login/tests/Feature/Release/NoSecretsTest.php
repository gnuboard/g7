<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Release;

use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 배포물에 실제 자격증명이 섞이지 않는지 — 플러그인 디렉터리와 이 플러그인 언어팩에 실제 앱 비밀값(따옴표 안 8자 넘는 영숫자
 * 연속)이나 카카오 어드민 키(`KakaoAK` 뒤 20자 넘는 영숫자)가 0건이다. 시험 픽스처와 모의 값은 `fx-` `mock-` 처럼 하이픈을 넣어
 * 실제 키 모양을 피한다.
 *
 * @since 1.0.0
 */
class NoSecretsTest extends PluginTestCase
{
    /** 실제 자격증명 모양 */
    private const PATTERN = '/client_secret["\']?\s*[:=]\s*["\'][A-Za-z0-9]{8,}|KakaoAK [A-Za-z0-9]{20,}/i';

    /** 훑지 않는 디렉터리 */
    private const SKIP = ['node_modules', 'dist', 'vendor'];

    /**
     * 플러그인 디렉터리 전부에서 자격증명 모양이 0건인지 확인한다.
     *
     * @scenario check=secrets_plugin
     *
     * @effects no_real_credentials_in_release
     *
     * @return void 반환값 없음
     */
    public function test_plugin_files_carry_no_real_credentials(): void
    {
        [$scanned, $hits] = $this->scan(dirname(__DIR__, 3));

        $this->assertGreaterThan(100, $scanned, '훑은 파일이 너무 적다 — 경로가 틀렸다');
        $this->assertSame([], $hits);
    }

    /**
     * 이 플러그인의 일본어 언어팩에서 자격증명 모양이 0건인지 확인한다.
     *
     * @scenario check=secrets_lang_pack
     *
     * @effects no_real_credentials_in_release
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_files_carry_no_real_credentials(): void
    {
        [$scanned, $hits] = $this->scan(base_path('lang-packs/_bundled/g7-plugin-sirsoft-social_login-ja'));

        $this->assertGreaterThan(5, $scanned, '훑은 파일이 너무 적다 — 경로가 틀렸다');
        $this->assertSame([], $hits);
    }

    /**
     * 디렉터리를 훑어 자격증명 모양이 나온 파일을 모읍니다.
     *
     * @param  string  $root  훑을 디렉터리
     * @return array{0: int, 1: array<int, string>} 훑은 파일 수와 걸린 파일(경로와 건수)
     */
    private function scan(string $root): array
    {
        $this->assertDirectoryExists($root);

        $hits = [];
        $scanned = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (! $file->isFile() || preg_match('#/('.implode('|', self::SKIP).')/#', $path) || $file->getSize() > 2_000_000) {
                continue;
            }
            $scanned++;
            if (preg_match_all(self::PATTERN, (string) file_get_contents($path), $m)) {
                $hits[] = $path.' ('.count($m[0]).')';
            }
        }

        return [$scanned, $hits];
    }
}
