<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Support\AuthorNameMasker;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 작성자 이름 가림 함수 단위 테스트
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/public-review-author-masking-and-feed.yaml
 */
class AuthorNameMaskerTest extends ModuleTestCase
{
    /**
     * 글자 수 경계마다 가림 결과가 규칙과 같다.
     *
     * @scenario surface=masker, name_length=boundary
     *
     * @effects name_masked_by_length_rule
     *
     * @param  string|null  $name  원래 이름
     * @param  string  $expected  기대 결과
     * @return void 반환값 없음
     */
    #[DataProvider('nameProvider')]
    public function test_mask_follows_length_rule(?string $name, string $expected): void
    {
        $this->assertSame($expected, AuthorNameMasker::mask($name));
    }

    /**
     * 경계값 표를 돌려줍니다.
     *
     * @return array<string, array{0: string|null, 1: string}> 이름과 기대 결과
     */
    public static function nameProvider(): array
    {
        return [
            'null' => [null, ''],
            '빈 문자열' => ['', ''],
            '한 글자' => ['김', '김'],
            '두 글자' => ['김철', '김*'],
            '세 글자' => ['홍길동', '홍*동'],
            '네 글자' => ['남궁민수', '남**수'],
            '영문' => ['Alice', 'A***e'],
            '이미 가린 이름' => ['홍*동', '홍*동'],
        ];
    }
}
