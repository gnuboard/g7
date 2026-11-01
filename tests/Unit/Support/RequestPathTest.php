<?php

namespace Tests\Unit\Support;

use App\Support\RequestPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 요청 경로 정규화 헬퍼 회귀 (공개#156).
 *
 * catch-all 판정·SEO 렌더·SEO 캐시 키·방문 통계가 같은 정규형을 쓰도록 규칙을 한 곳에 고정한다.
 * 클라이언트 `Router.normalizePathname()` 과 같은 규칙이어야 한다.
 */
class RequestPathTest extends TestCase
{
    /**
     * 정규화 입력 변종과 기대값.
     *
     * @return array<string, array{0: string, 1: string}> 변종별 입력과 기대 정규형
     */
    public static function pathProvider(): array
    {
        return [
            '빈 값은 루트' => ['', '/'],
            '루트는 그대로' => ['/', '/'],
            '연속 슬래시 루트' => ['//', '/'],
            '정규형은 그대로' => ['/admin', '/admin'],
            '끝 슬래시 제거' => ['/admin/', '/admin'],
            '연속·끝 슬래시 함께' => ['/admin//users/', '/admin/users'],
            '앞 슬래시 보장' => ['admin/', '/admin'],
            '프로토콜 상대 시도는 같은 사이트 경로' => ['//evil.com/', '/evil.com'],
            '깊은 경로 끝 연속 슬래시' => ['/a/b/c//', '/a/b/c'],
            '인코딩은 건드리지 않음' => ['/%2F/', '/%2F'],
        ];
    }

    /**
     * 끝·연속 슬래시와 루트·인코딩 변종을 정규형으로 바꾼다.
     *
     * @scenario path_shape=trailing_slash
     *
     * @effects request_path_normalizer_collapses_slashes
     *
     * @param  string  $input  입력 경로
     * @param  string  $expected  기대 정규형
     * @return void 반환값 없음
     */
    #[DataProvider('pathProvider')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, RequestPath::normalize($input));
    }
}
