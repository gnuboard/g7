<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 절사 계산 사본 동일성 테스트
 *
 * `applyRounding()` 과 그 보조 `roundingUnitScale()` 은 두 곳에 복사돼 있다 —
 * `CurrencyConversionService`(주문·클레임·결제 금액)와 `HasMultiCurrencyPrices`
 * (상품·리소스 표시 금액). 한쪽만 고치면 같은 절사 설정에서 두 경로가 다른 값을 내는데,
 * 예외도 오류도 남지 않고 금액만 갈린다.
 *
 * 모집단은 `src/` 를 스캔해 파생한다 — 사본이 하나 더 생기면 그것도 자동으로 대조 대상이
 * 되고, 스캔 결과가 비면(경로가 바뀌었거나 스캔이 깨졌으면) 그 자체로 실패한다.
 */
class CurrencyRoundingParityTest extends ModuleTestCase
{
    /**
     * 동일성을 요구하는 메서드 목록.
     */
    private const METHODS = ['applyRounding', 'roundingUnitScale'];

    /**
     * 사본이 있어야 할 최소 개수 (하한 — 모집단이 비면 실패).
     */
    private const MIN_COPIES = 2;

    public function test_rounding_helper_copies_are_identical(): void
    {
        $definers = $this->filesDefining('applyRounding');

        $this->assertGreaterThanOrEqual(
            self::MIN_COPIES,
            count($definers),
            'applyRounding() 사본을 src/ 에서 찾지 못했습니다 — 스캔 경로가 깨졌거나 사본이 사라졌습니다.'
        );

        foreach (self::METHODS as $method) {
            $bodies = [];

            foreach ($definers as $file) {
                $body = $this->methodBody($file, $method);

                $this->assertNotNull(
                    $body,
                    sprintf('%s 에 %s() 가 없습니다 — 사본 간 구성이 어긋났습니다.', basename($file), $method)
                );

                $bodies[$file] = $body;
            }

            $unique = array_unique($bodies);

            $this->assertCount(
                1,
                $unique,
                sprintf(
                    "%s() 사본이 서로 다릅니다 (%d종). 한쪽만 고치면 같은 절사 설정에서 금액이 갈립니다:\n%s",
                    $method,
                    count($unique),
                    implode("\n", array_keys($bodies))
                )
            );
        }
    }

    /**
     * 지정한 메서드를 정의하는 모듈 소스 파일을 모두 찾습니다.
     *
     * @param  string  $method  메서드명
     * @return array<int, string> 파일 경로 목록 (정렬됨)
     */
    private function filesDefining(string $method): array
    {
        $root = dirname(__DIR__, 3).'/src';
        $found = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $source) === 1) {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    /**
     * 파일에서 메서드 본문을 뽑아 비교 가능한 형태로 정규화합니다.
     *
     * 가시성 키워드(public/protected/private)와 공백·주석은 사본마다 다를 수 있으므로
     * 제거하고, 실제로 값을 만드는 문장만 남긴다.
     *
     * @param  string  $file  파일 경로
     * @param  string  $method  메서드명
     * @return string|null 정규화된 본문 (메서드가 없으면 null)
     */
    private function methodBody(string $file, string $method): ?string
    {
        $source = (string) file_get_contents($file);
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            // 함수명 토큰 찾기
            $nameIndex = $i + 1;
            while ($nameIndex < $count && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_WHITESPACE) {
                $nameIndex++;
            }

            if (! is_array($tokens[$nameIndex]) || $tokens[$nameIndex][1] !== $method) {
                continue;
            }

            return $this->normalize($this->bodyFrom($tokens, $nameIndex, $count));
        }

        return null;
    }

    /**
     * 함수명 위치부터 본문 블록(`{ … }`)의 원문을 수집합니다.
     *
     * @param  array<int, mixed>  $tokens  token_get_all 결과
     * @param  int  $from  함수명 토큰 인덱스
     * @param  int  $count  토큰 총 개수
     * @return string 본문 원문
     */
    private function bodyFrom(array $tokens, int $from, int $count): string
    {
        $depth = 0;
        $started = false;
        $body = '';

        for ($j = $from; $j < $count; $j++) {
            $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($text === '{') {
                $depth++;
                $started = true;
            }

            if ($started) {
                $body .= $text;
            }

            if ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }
        }

        return $body;
    }

    /**
     * 공백·주석 차이를 지워 본문을 비교 가능한 형태로 만듭니다.
     *
     * @param  string  $body  본문 원문
     * @return string 정규화된 본문
     */
    private function normalize(string $body): string
    {
        $body = preg_replace('#/\*.*?\*/#s', '', $body) ?? $body;
        $body = preg_replace('#//[^\n]*#', '', $body) ?? $body;
        $body = preg_replace('/\s+/', ' ', $body) ?? $body;

        return trim($body);
    }
}
