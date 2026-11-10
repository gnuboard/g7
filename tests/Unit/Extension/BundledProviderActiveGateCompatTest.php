<?php

namespace Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 번들 확장 provider 의 활성 게이트 호환 구조 (#860 W4, #730 결정 D10)
 *
 * 비활성 확장의 boot 가 요청 처리에 끼어드는 부수효과(레이트리미터·쿠키 암호화 예외·큐 훅·사이트맵 기여자)를
 * 걸지 않도록 `isExtensionActive()` 게이트 안에 둔다. 게이트 메서드는 7.1.5 코어에 생겼으므로, 옛 코어에서도
 * 지금처럼 동작하도록 호출은 반드시 `method_exists` 가드와 함께 쓴다.
 */
class BundledProviderActiveGateCompatTest extends TestCase
{
    /** 가드 문장 — 이 형태 그대로 쓴다 */
    private const GUARD = "if (! method_exists(\$this, 'isExtensionActive') || \$this->isExtensionActive()) {";

    /**
     * 대상 provider → boot() 안에서 게이트 안에 있어야 하는 부수효과 문장.
     *
     * @return array<string, array<int, string>> provider 경로 → 게이트 안에 있어야 할 문장 목록
     */
    private function targets(): array
    {
        return [
            'modules/_bundled/sirsoft-board/src/Providers/BoardServiceProvider.php' => [
                '$this->registerRateLimiters()',
                'callAfterResolving(SitemapGenerator::class',
            ],
            'modules/_bundled/sirsoft-ecommerce/src/Providers/EcommerceServiceProvider.php' => [
                '$this->registerRateLimiters()',
                '$this->registerQueueJobScopeReset()',
                'callAfterResolving(SitemapGenerator::class',
            ],
            'modules/_bundled/sirsoft-page/src/Providers/PageServiceProvider.php' => [
                'callAfterResolving(SitemapGenerator::class',
            ],
            'plugins/_bundled/sirsoft-gdpr/src/Providers/GdprServiceProvider.php' => [
                'EncryptCookies::except(',
                '$this->registerQueueRelayHooks()',
            ],
            'plugins/_bundled/sirsoft-pay_kginicis/src/Providers/PayKginicisServiceProvider.php' => [
                'EncryptCookies::except(',
            ],
            'plugins/_bundled/sirsoft-pay_nhnkcp/src/Providers/PayNhnkcpServiceProvider.php' => [
                'EncryptCookies::except(',
            ],
            'plugins/_bundled/sirsoft-pay_nicepayments/src/Providers/PayNicepaymentsServiceProvider.php' => [
                'EncryptCookies::except(',
            ],
            'plugins/_bundled/sirsoft-message_bizppurio/src/Providers/MessageBizppurioServiceProvider.php' => [
                '$this->app->resolving(',
            ],
        ];
    }

    /**
     * 메서드 본문(중괄호 안)을 잘라냅니다.
     *
     * @param  string  $source  파일 소스
     * @param  string  $method  메서드 이름
     * @return string 본문
     */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        $this->assertNotFalse($start, "{$method}() 없음");
        $open = strpos($source, '{', $start);

        return substr($source, $open, $this->matchBrace($source, $open) - $open + 1);
    }

    /**
     * 여는 중괄호에 짝이 되는 닫는 중괄호 위치를 찾습니다.
     *
     * @param  string  $source  소스
     * @param  int  $open  여는 중괄호 위치
     * @return int 닫는 중괄호 위치
     */
    private function matchBrace(string $source, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }
        $this->fail('중괄호 짝 없음');
    }

    /**
     * 번들 provider 의 isExtensionActive() 호출은 모두 method_exists 가드와 함께 쓰입니다.
     *
     * @effects bundled_providers_guard_gate_with_method_exists
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 게이트_호출은_모두_method_exists_가드와_함께_쓴다(): void
    {
        $files = array_merge(
            glob(base_path('modules/_bundled/*/src/Providers/*.php')) ?: [],
            glob(base_path('plugins/_bundled/*/src/Providers/*.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        $calls = 0;
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $total = substr_count($source, '$this->isExtensionActive()');
            $guarded = substr_count($source, self::GUARD);
            $calls += $total;
            $this->assertSame($total, $guarded, basename($file).': 가드 없는 isExtensionActive() 호출');
        }
        $this->assertGreaterThan(0, $calls, '게이트를 쓰는 번들 provider 가 없으면 시험이 무의미');
    }

    /**
     * 요청 처리에 끼어드는 boot 부수효과는 게이트 블록 안에 있습니다.
     *
     * @effects bundled_providers_guard_gate_with_method_exists
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 요청_부수효과는_게이트_안에_있다(): void
    {
        foreach ($this->targets() as $relative => $statements) {
            $boot = $this->methodBody((string) file_get_contents(base_path($relative)), 'boot');

            $spans = [];
            $offset = 0;
            while (($at = strpos($boot, self::GUARD, $offset)) !== false) {
                $open = $at + strlen(self::GUARD) - 1;
                $spans[] = [$open, $this->matchBrace($boot, $open)];
                $offset = $open + 1;
            }

            foreach ($statements as $statement) {
                $at = strpos($boot, $statement);
                $this->assertNotFalse($at, "{$relative}: boot() 에 {$statement} 없음");
                $inside = array_filter($spans, fn (array $span): bool => $at > $span[0] && $at < $span[1]);
                $this->assertNotEmpty($inside, "{$relative}: {$statement} 가 게이트 밖에 있음");
            }
        }
    }
}
