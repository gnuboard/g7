<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\PureTestCase;

/**
 * 모듈 소스가 bcmath 함수를 부르지 않는지 잠근다 (#151)
 *
 * bcmath 는 코어 기준 「선택」 확장이라 없는 서버가 있다. 모듈 composer.json 에 `ext-bcmath` 를
 * 선언하지 않았으므로, 소스에 `bc*()` 호출이 다시 들어오면 그 서버에서 주문 상세·취소가 500 이 된다.
 * 금액 비교는 `Support\DecimalComparator` 를 쓴다.
 */
class NoBcmathUsageTest extends PureTestCase
{
    /**
     * 모듈 src 에 bcmath 함수 호출이 0건인지 검사합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario bcmath=absent, comparison=table
     *
     * @effects module_source_has_no_bcmath_call
     */
    #[Test]
    public function 모듈_src_에_bcmath_호출이_없다(): void
    {
        $src = dirname(__DIR__, 3).'/src';
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $no => $line) {
                $code = preg_replace('#//.*$|^\s*\*.*$#', '', $line);
                if (preg_match('/(?<![\w$>:])\\\\?bc(add|sub|mul|div|mod|pow|powmod|sqrt|comp|scale|floor|ceil|round)\s*\(/', $code)) {
                    $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1)).':'.($no + 1);
                }
            }
        }

        $this->assertSame([], $violations, 'bcmath 함수 호출 발견 — DecimalComparator 를 쓰세요');
    }
}
