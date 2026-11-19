<?php

namespace Tests\Unit\Thumbnails;

use App\Extension\HookManager;
use App\Support\AssetUrl;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 서버 썸네일 URL 유틸 테스트 (og·SEO·확장 공용)
 *
 * `size` 는 같은 출처 + 등록된 서빙 경로에만 붙는다. 외부·정적 `/storage`·`data:`·`blob:`·프로토콜 상대 주소는
 * 그대로다. 쿼리는 원문 그대로 두고(서명 URL 보존) 기존 `size`·`w` 만 바꾼다. `#` 앞에 넣는다.
 * 마지막에 필터 `core.thumbnail.filter_url` 을 지난다(변환 서비스 위임 지점).
 */
class ThumbnailUrlTest extends TestCase
{
    /**
     * 사이트 주소·기능 켬을 고정하고 URL 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.url', 'https://site.test');
        Config::set('core.thumbnail.enabled', true);
        AssetUrl::forceBasePrefix(null);
        HookManager::clearFilter('core.thumbnail.filter_url');
        HookManager::clearFilter('core.thumbnail.filter_url_patterns');
    }

    /**
     * 필터와 접두사 강제를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.thumbnail.filter_url');
        HookManager::clearFilter('core.thumbnail.filter_url_patterns');
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 변환 표 — 원 주소 → 기대 주소.
     *
     * @return array<string, array{0:string,1:string}> 원 주소, 기대 주소
     */
    public static function urlProvider(): array
    {
        return [
            '상대 코어 첨부' => ['/api/attachment/abc123', '/api/attachment/abc123?size=og'],
            '상대 레이아웃 첨부' => ['/api/templates/sirsoft-basic/layout-attachments/7/file', '/api/templates/sirsoft-basic/layout-attachments/7/file?size=og'],
            '사이트 절대 URL' => ['https://site.test/api/attachment/abc123', 'https://site.test/api/attachment/abc123?size=og'],
            '호스트 대소문자' => ['https://SITE.test/api/attachment/abc123', 'https://SITE.test/api/attachment/abc123?size=og'],
            '서명 쿼리 보존' => ['/api/attachment/abc?expires=1700000000&signature=ab%2Fcd', '/api/attachment/abc?expires=1700000000&signature=ab%2Fcd&size=og'],
            '기존 size 교체' => ['/api/attachment/abc?size=sm&v=2', '/api/attachment/abc?v=2&size=og'],
            '기존 w 교체' => ['/api/attachment/abc?w=300', '/api/attachment/abc?size=og'],
            '# 앞에 삽입' => ['/api/attachment/abc?v=1#top', '/api/attachment/abc?v=1&size=og#top'],
            '# 만 있음' => ['/api/attachment/abc#top', '/api/attachment/abc?size=og#top'],
            '외부 출처' => ['https://cdn.example.com/api/attachment/abc', 'https://cdn.example.com/api/attachment/abc'],
            '프로토콜 상대' => ['//site.test/api/attachment/abc', '//site.test/api/attachment/abc'],
            '정적 storage' => ['/storage/attachments/avatars/a.png', '/storage/attachments/avatars/a.png'],
            'data' => ['data:image/png;base64,AAAA', 'data:image/png;base64,AAAA'],
            'blob' => ['blob:https://site.test/1234', 'blob:https://site.test/1234'],
            '등록 안 된 api' => ['/api/attachment/abc/extra', '/api/attachment/abc/extra'],
            '상대 경로(슬래시 없음)' => ['api/attachment/abc', 'api/attachment/abc'],
            '빈 값' => ['', ''],
        ];
    }

    /**
     * 같은 출처·등록 경로만 바꾸고 나머지는 그대로 둔다.
     *
     * @effects url_util_adds_size_only_to_registered_same_origin_paths, url_util_preserves_query_and_fragment
     *
     * @param  string  $url  원 주소
     * @param  string  $expected  기대 주소
     * @return void 반환값 없음
     */
    #[DataProvider('urlProvider')]
    public function test_for_table(string $url, string $expected): void
    {
        $this->assertSame($expected, ThumbnailUrl::for($url, 'og'));
    }

    /**
     * 하위 폴더 설치 — 접두사를 벗겨 패턴을 맞추되 결과 주소는 접두사를 그대로 둔다.
     *
     * @effects url_util_adds_size_only_to_registered_same_origin_paths
     *
     * @return void 반환값 없음
     */
    public function test_subfolder_prefix_is_stripped_for_matching_only(): void
    {
        Config::set('app.url', 'https://site.test/g7');
        AssetUrl::forceBasePrefix('/g7');

        $this->assertSame('/g7/api/attachment/abc?size=sm', ThumbnailUrl::for('/g7/api/attachment/abc', 'sm'));
        $this->assertSame('https://site.test/g7/api/attachment/abc?size=sm', ThumbnailUrl::for('https://site.test/g7/api/attachment/abc', 'sm'));
    }

    /**
     * 기능을 끄면 바꾸지 않는다.
     *
     * @effects url_util_adds_size_only_to_registered_same_origin_paths
     *
     * @return void 반환값 없음
     */
    public function test_disabled_feature_leaves_urls_untouched(): void
    {
        Config::set('core.thumbnail.enabled', false);

        $this->assertSame('/api/attachment/abc', ThumbnailUrl::for('/api/attachment/abc', 'og'));
        $this->assertFalse(ThumbnailUrl::isThumbnailable('/api/attachment/abc'));
    }

    /**
     * 확장이 서빙 경로를 등록하면 그 경로도 바뀐다. `/api/` 밖 패턴은 받지 않는다.
     *
     * @effects url_patterns_extendable_by_filter
     *
     * @return void 반환값 없음
     */
    public function test_extension_patterns_are_registered_through_the_filter(): void
    {
        HookManager::addFilter('core.thumbnail.filter_url_patterns', fn (array $patterns) => [
            ...$patterns,
            '/api/modules/sirsoft-board/boards/*/attachment/*/preview',
            '/storage/*',
            42,
        ]);

        $patterns = ThumbnailUrl::patterns();

        $this->assertContains('/api/modules/sirsoft-board/boards/*/attachment/*/preview', $patterns);
        $this->assertNotContains('/storage/*', $patterns);
        $this->assertSame(
            '/api/modules/sirsoft-board/boards/free/attachment/a1/preview?size=md',
            ThumbnailUrl::for('/api/modules/sirsoft-board/boards/free/attachment/a1/preview', 'md')
        );
        $this->assertSame('/storage/x.png', ThumbnailUrl::for('/storage/x.png', 'md'));
        $this->assertFalse(ThumbnailUrl::matchesPattern('/api/modules/sirsoft-board/boards/a/b/attachment/c/preview', '/api/modules/sirsoft-board/boards/*/attachment/*/preview'), '* 는 한 조각만');
    }

    /**
     * 변환 서비스 위임 필터는 최종 주소를 바꿀 수 있다 — 원 주소와 규격을 인자로 받는다. 빈 값을 돌려주면 무시한다.
     *
     * @effects url_filter_can_delegate
     *
     * @return void 반환값 없음
     */
    public function test_url_filter_can_delegate_to_a_transform_service(): void
    {
        $seen = [];
        HookManager::addFilter('core.thumbnail.filter_url', function (string $result, string $original, string $preset) use (&$seen) {
            $seen[] = [$result, $original, $preset];

            return str_starts_with($original, 'https://cdn.example.com/')
                ? 'https://img.example.com/'.$preset.'/'.substr($original, strlen('https://cdn.example.com/'))
                : $result;
        });

        $this->assertSame('https://img.example.com/sm/a.png', ThumbnailUrl::for('https://cdn.example.com/a.png', 'sm'));
        $this->assertSame('/api/attachment/abc?size=sm', ThumbnailUrl::for('/api/attachment/abc', 'sm'));
        $this->assertSame(['/api/attachment/abc?size=sm', '/api/attachment/abc', 'sm'], $seen[1]);

        HookManager::clearFilter('core.thumbnail.filter_url');
        HookManager::addFilter('core.thumbnail.filter_url', fn () => '');
        $this->assertSame('/api/attachment/abc?size=sm', ThumbnailUrl::for('/api/attachment/abc', 'sm'));
    }

    /**
     * 규격 이름은 인코딩해 붙인다.
     *
     * @effects url_util_preserves_query_and_fragment
     *
     * @return void 반환값 없음
     */
    public function test_preset_name_is_encoded(): void
    {
        $this->assertSame('/api/attachment/abc?size=sirsoft-board.list', ThumbnailUrl::withSize('/api/attachment/abc', 'sirsoft-board.list'));
        $this->assertSame('/api/attachment/abc?size=a%26b', ThumbnailUrl::withSize('/api/attachment/abc', 'a&b'));
        $this->assertSame('/api/attachment/abc?a=1&size=sm', ThumbnailUrl::withSize('/api/attachment/abc?a=1&size%5B%5D=x', 'sm'), '인코딩한 size 배열 키도 지운다');
    }
}
