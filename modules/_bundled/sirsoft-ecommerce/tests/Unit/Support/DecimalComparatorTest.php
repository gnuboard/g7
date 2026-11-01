<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Support\DecimalComparator;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * 금액 비교기(소수 4자리) 단위 테스트 — bcmath 없이 같은 판정 (#151)
 *
 * 환불·취소 판정이 `bccomp()` 를 가드 없이 불러 bcmath 가 없는 서버에서 주문 상세가 500 이 됐다.
 * 이 비교기가 종전 `bccomp(number_format(v,4,'.',''), …, 4)` 와 같은 결과를 내는지 표로 잠근다.
 */
class DecimalComparatorTest extends ModuleTestCase
{
    /**
     * 경계 사례 표 — [a, b, 기대값]
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: int}> 사례 이름별 [비교값 a, 비교값 b, 기대 부호]
     */
    public static function cases(): array
    {
        return [
            'null 은 0' => [null, 0, 0],
            'null 과 양수' => [null, '0.0001', -1],
            '문자열 0.00' => ['0.00', 0, 0],
            '부동소수 오차 0.1+0.2 vs 0.3' => [0.1 + 0.2, 0.3, 0],
            '4자리 아래 차이는 같음' => ['10.00001', '10.00002', 0],
            '반올림 경계 0.00005 → 0.0001' => ['0.00005', '0.0001', 0],
            '반올림 경계 0.00004 → 0' => ['0.00004', 0, 0],
            '4자리 차이는 구분' => ['10.0001', '10.0000', 1],
            '대금액' => ['999999999.9999', '999999999.9998', 1],
            '대금액 같음' => ['123456789012.50', 123456789012.5, 0],
            '음수' => ['-5.5', '-5.4', -1],
            '정수 비교' => [30000, 25000, 1],
        ];
    }

    /**
     * 경계 사례마다 소수 4자리 기준 부호를 돌려주는지 확인합니다.
     *
     * @param  mixed  $a  비교값 a
     * @param  mixed  $b  비교값 b
     * @param  int  $expected  기대 부호
     * @return void 반환값 없음
     *
     * @scenario bcmath=present, comparison=table
     *
     * @effects decimal_compare_matches_four_digit_rounding
     */
    #[Test]
    #[DataProvider('cases')]
    public function 소수_4자리_기준으로_비교한다(mixed $a, mixed $b, int $expected): void
    {
        $this->assertSame($expected, DecimalComparator::compare($a, $b));
    }

    /**
     * bcmath 가 있는 환경에서 종전 `bccomp(number_format(…))` 판정과 같은지 교차 확인합니다.
     *
     * @param  mixed  $a  비교값 a
     * @param  mixed  $b  비교값 b
     * @param  int  $expected  기대 부호(사례 표 공용, 여기서는 종전 결과로 대조)
     * @return void 반환값 없음
     *
     * @scenario bcmath=present, comparison=table
     *
     * @effects decimal_compare_equals_legacy_bccomp
     */
    #[Test]
    #[DataProvider('cases')]
    #[RequiresPhpExtension('bcmath')]
    public function 종전_bccomp_결과와_같다(mixed $a, mixed $b, int $expected): void
    {
        $legacy = bccomp(
            number_format((float) ($a ?? 0), 4, '.', ''),
            number_format((float) ($b ?? 0), 4, '.', ''),
            4
        );

        $this->assertSame($legacy, DecimalComparator::compare($a, $b));
    }

    /**
     * 무작위 금액쌍 2만 건에서 종전 판정과 불일치가 없는지 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario bcmath=present, comparison=random
     *
     * @effects decimal_compare_equals_legacy_bccomp
     */
    #[Test]
    #[RequiresPhpExtension('bcmath')]
    public function 무작위_금액쌍에서_종전_bccomp_와_불일치가_없다(): void
    {
        mt_srand(151);
        for ($i = 0; $i < 20000; $i++) {
            $a = mt_rand(0, 100_000_000) / 10 ** mt_rand(0, 6);
            $b = mt_rand(0, 1) ? $a + mt_rand(-5, 5) / 100000 : mt_rand(0, 100_000_000) / 10 ** mt_rand(0, 6);

            $legacy = bccomp(number_format($a, 4, '.', ''), number_format($b, 4, '.', ''), 4);
            $this->assertSame($legacy, DecimalComparator::compare($a, $b), "불일치: {$a} vs {$b}");
        }
    }

    /**
     * bcmath 함수를 막은 별도 PHP 프로세스에서도 비교가 동작하는지 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario bcmath=absent, comparison=table
     *
     * @effects decimal_compare_works_without_bcmath
     */
    #[Test]
    public function 비교는_bcmath_함수가_없는_php_에서도_동작한다(): void
    {
        $autoload = base_path('vendor/autoload.php');
        $class = base_path('modules/_bundled/sirsoft-ecommerce/src/Support/DecimalComparator.php');
        $code = 'require '.var_export($autoload, true).'; require_once '.var_export($class, true).';'
            .' echo json_encode([function_exists("bccomp"),'
            .' \\Modules\\Sirsoft\\Ecommerce\\Support\\DecimalComparator::compare("30000.00", "0.00"),'
            .' \\Modules\\Sirsoft\\Ecommerce\\Support\\DecimalComparator::compare(0.1 + 0.2, "0.3")]);';

        $process = new Process([
            PHP_BINARY,
            '-d', 'disable_functions=bccomp,bcadd,bcsub,bcmul,bcdiv,bcmod,bcpow,bcsqrt,bcscale,bcpowmod',
            '-r', $code,
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame([false, 1, 0], json_decode(trim($process->getOutput()), true));
    }
}
