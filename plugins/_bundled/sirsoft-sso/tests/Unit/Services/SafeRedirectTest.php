<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Services;

use App\Support\SiteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Sso\Services\SafeRedirect;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 복귀 주소 판정 — 같은 출처 경로만 남기고 열린 리디렉션을 막는다.
 *
 * @since 1.0.0
 */
class SafeRedirectTest extends PluginTestCase
{
    /**
     * 판정 사례.
     *
     * @return array<string, array{0: string|null, 1: string}> 입력 → 기대
     */
    public static function cases(): array
    {
        return [
            'null' => [null, '/'],
            'empty' => ['', '/'],
            'same origin path' => ['/mypage?tab=1#a', '/mypage?tab=1#a'],
            'protocol relative' => ['//evil.test/x', '/'],
            'backslash' => ['/\\evil.test', '/'],
            'absolute other' => ['https://evil.test/login', '/'],
            'javascript scheme' => ['javascript:alert(1)', '/'],
            'scheme in first segment' => ['/javascript:alert(1)', '/'],
            'control char' => ["/a\nb", '/'],
            'relative without slash' => ['mypage', '/'],
        ];
    }

    /**
     * 같은 출처 경로만 남기는지 사례별로 확인한다.
     *
     * @scenario input=redirect_variants
     *
     * @effects safe_path_only
     *
     * @param  string|null  $input  입력 주소
     * @param  string  $expected  기대 경로
     * @return void 반환값 없음
     */
    #[DataProvider('cases')]
    public function test_path_keeps_only_same_origin_paths(?string $input, string $expected): void
    {
        $this->assertSame($expected, SafeRedirect::path($input));
    }

    /**
     * 같은 출처 절대 주소를 경로로 바꾸는지 확인한다.
     *
     * @scenario input=same_origin_absolute
     *
     * @effects converted_to_path
     *
     * @return void 반환값 없음
     */
    public function test_same_origin_absolute_url_becomes_path(): void
    {
        $this->assertSame('/mypage/profile?x=1', SafeRedirect::path(SiteUrl::absolute('/mypage/profile?x=1')));
    }

    /**
     * 거절 때 지정한 대체값을 돌려주고 안전 판정이 맞는지 확인한다.
     *
     * @scenario input=fallback
     *
     * @effects fallback_returned
     *
     * @return void 반환값 없음
     */
    public function test_custom_fallback_and_is_safe(): void
    {
        $this->assertSame('', SafeRedirect::path('//evil.test', ''));
        $this->assertTrue(SafeRedirect::isSafe('/login'));
        $this->assertFalse(SafeRedirect::isSafe('https://evil.test'));
    }
}
