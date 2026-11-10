<?php

namespace Plugins\Sirsoft\CdnCloudfront\Tests\Unit;

use Plugins\Sirsoft\CdnCloudfront\Support\InvalidationPaths;
use Plugins\Sirsoft\CdnCloudfront\Tests\PluginTestCase;

/**
 * 코어 퍼지 항목 → CloudFront 무효화 경로 변환 (순수 계산 — 플러그인 베이스로 부팅만 맞춘다)
 *
 * @scenario-manifest tests/scenarios/cloudfront-purge.yaml
 */
class InvalidationPathsTest extends PluginTestCase
{
    private const HOST = 'cdn.example.test';

    /**
     * 파생본 디렉터리는 와일드카드, 원본 URL 은 그 경로 그대로 들어가고 CDN 호스트가 아닌 URL 은 빠진다.
     *
     * @scenario case=wildcard_and_original
     *
     * @effects wildcard_per_source_and_original_path
     *
     * @return void 반환값 없음
     */
    public function test_wildcard_for_thumbnail_directory_and_exact_original(): void
    {
        $paths = InvalidationPaths::fromItems([
            [
                'public_url_prefix' => 'https://cdn.example.test/thumbnails/cloudfront/modules/a/p1.jpg',
                'original_public_url' => 'https://cdn.example.test/modules/a/p1.jpg',
            ],
            [
                'public_url_prefix' => 'https://other.example.test/thumbnails/public/modules/a/p2.jpg',
                'original_public_url' => 'http://localhost/storage/modules/a/p2.jpg',
            ],
            [
                'public_url_prefix' => null,
                'original_public_url' => null,
            ],
        ], self::HOST);

        $this->assertSame([
            '/thumbnails/cloudfront/modules/a/p1.jpg/*',
            '/modules/a/p1.jpg',
        ], $paths);
    }

    /**
     * 같은 접두가 두 번 오거나 상위 디렉터리가 함께 오면 한 번만, 상위 와일드카드로만 남는다.
     *
     * @scenario case=dedupe_and_cover
     *
     * @effects duplicates_and_covered_paths_removed
     *
     * @return void 반환값 없음
     */
    public function test_duplicates_and_covered_paths_are_removed(): void
    {
        $paths = InvalidationPaths::fromItems([
            ['public_url_prefix' => 'https://cdn.example.test/thumbnails/s3/board/7/a.png', 'original_public_url' => null],
            ['public_url_prefix' => 'https://cdn.example.test/thumbnails/s3/board/7/a.png/', 'original_public_url' => null],
            ['public_url_prefix' => 'https://cdn.example.test/thumbnails/s3/board/7', 'original_public_url' => 'https://cdn.example.test/thumbnails/s3/board/7/x.png'],
        ], self::HOST);

        $this->assertSame(['/thumbnails/s3/board/7/*'], $paths);
    }

    /**
     * 공백·한글 파일명은 브라우저가 요청하는 인코딩 모양으로 맞춘다(이미 인코딩된 것은 두 번 인코딩하지 않는다).
     *
     * @scenario case=encoding
     *
     * @effects paths_url_encoded_once
     *
     * @return void 반환값 없음
     */
    public function test_paths_are_url_encoded_once(): void
    {
        $paths = InvalidationPaths::fromItems([
            ['public_url_prefix' => 'https://CDN.example.test/thumbnails/s3/새 폴더/사진 1.jpg', 'original_public_url' => 'https://cdn.example.test/a%20b.jpg'],
        ], self::HOST);

        $this->assertSame([
            '/thumbnails/s3/%EC%83%88%20%ED%8F%B4%EB%8D%94/%EC%82%AC%EC%A7%84%201.jpg/*',
            '/a%20b.jpg',
        ], $paths);
    }

    /**
     * 와일드카드가 요청당 상한(배포 전체 진행 중 한도 15 보다 낮게 잡은 10)을 넘는 묶음은 공통 상위 디렉터리 와일드카드 하나로 접는다.
     *
     * @scenario case=collapse
     *
     * @effects oversized_batch_collapsed_to_common_directory
     *
     * @return void 반환값 없음
     */
    public function test_batch_over_wildcard_limit_collapses_to_common_directory(): void
    {
        $items = [];
        for ($i = 1; $i <= InvalidationPaths::MAX_WILDCARDS + 1; $i++) {
            $items[] = ['public_url_prefix' => "https://cdn.example.test/thumbnails/s3/modules/shop/images/p{$i}.jpg", 'original_public_url' => null];
        }

        $this->assertSame(['/thumbnails/s3/modules/shop/images/*'], InvalidationPaths::fromItems($items, self::HOST));

        // 한도 안이면 접지 않는다
        $this->assertCount(InvalidationPaths::MAX_WILDCARDS, InvalidationPaths::fromItems(array_slice($items, 0, InvalidationPaths::MAX_WILDCARDS), self::HOST));
    }

    /**
     * 요청당 와일드카드 상한은 배포 전체 진행 중 한도(15)보다 낮다 — 앞선 무효화가 진행 중이어도 다음 요청이 들어갈 자리를 남긴다.
     *
     * @scenario case=collapse
     *
     * @effects oversized_batch_collapsed_to_common_directory
     *
     * @return void 반환값 없음
     */
    public function test_wildcard_limit_per_request_leaves_room_under_distribution_limit(): void
    {
        $this->assertLessThanOrEqual(10, InvalidationPaths::MAX_WILDCARDS);
    }

    /**
     * 접기는 디렉터리 와일드카드만 접는다. 원본 정확 경로까지 공통 상위 계산에 넣으면 파생본 트리(`/thumbnails/…`)와
     * 원본 트리(`/modules/…`)의 공통 상위가 `/` 가 돼 배포 전체(`/*`)를 지운다.
     *
     * @scenario case=collapse
     *
     * @effects collapse_folds_directories_only
     *
     * @return void 반환값 없음
     */
    public function test_collapse_folds_directories_and_keeps_exact_originals(): void
    {
        $items = [];
        $originals = [];
        for ($i = 1; $i <= InvalidationPaths::MAX_WILDCARDS + 1; $i++) {
            $items[] = [
                'public_url_prefix' => "https://cdn.example.test/thumbnails/cloudfront/modules/shop/images/p{$i}.jpg",
                'original_public_url' => "https://cdn.example.test/modules/shop/images/p{$i}.jpg",
            ];
            $originals[] = "/modules/shop/images/p{$i}.jpg";
        }

        $paths = InvalidationPaths::fromItems($items, self::HOST);
        sort($originals);

        $this->assertSame('/thumbnails/cloudfront/modules/shop/images/*', $paths[0]);
        $this->assertSame($originals, array_slice($paths, 1));
        $this->assertNotContains('/*', $paths);
    }

    /**
     * 상품 6개를 디렉터리째 지우면 파생본 트리와 원본 트리 와일드카드가 12개다. 접어도 배포 전체(`/*`)가 나오면 안 된다 —
     * 첫 세그먼트 묶음 안에서만 접어 다른 트리의 캐시를 건드리지 않는다.
     *
     * @scenario case=collapse
     *
     * @effects collapse_stays_inside_first_segment, distribution_root_never_invalidated
     *
     * @return void 반환값 없음
     */
    public function test_six_deleted_product_directories_do_not_collapse_to_distribution_root(): void
    {
        $items = [];
        $thumbnails = [];
        foreach ([11, 12, 13, 14, 15, 16] as $id) {
            $items[] = [
                'public_url_prefix' => "https://cdn.example.test/thumbnails/cloudfront/modules/shop/images/{$id}",
                'original_public_url' => "https://cdn.example.test/modules/shop/images/{$id}",
                'original_is_directory' => true,
            ];
            $thumbnails[] = "/thumbnails/cloudfront/modules/shop/images/{$id}/*";
        }

        $paths = InvalidationPaths::fromItems($items, self::HOST);

        $this->assertNotContains('/*', $paths);
        $this->assertSame(array_merge(['/modules/shop/images/*'], $thumbnails), $paths);
        $this->assertCount(1, InvalidationPaths::requests($paths));
    }

    /**
     * 첫 세그먼트가 상한보다 많으면 더 접을 수 없다 — 배포 전체로 접지 않고 요청을 나눈다.
     *
     * @scenario case=split
     *
     * @effects distribution_root_never_invalidated, requests_split_within_limits
     *
     * @return void 반환값 없음
     */
    public function test_more_first_segments_than_wildcard_limit_are_split_into_requests(): void
    {
        $paths = [];
        for ($i = 1; $i <= InvalidationPaths::MAX_WILDCARDS + 2; $i++) {
            $paths[] = sprintf('/tree%02d/a/*', $i);
            $paths[] = sprintf('/tree%02d/b/*', $i);
        }

        $folded = InvalidationPaths::fold($paths);

        $this->assertNotContains('/*', $folded);
        $this->assertCount(InvalidationPaths::MAX_WILDCARDS + 2, $folded);
        $this->assertSame($folded, InvalidationPaths::fold($folded));

        $requests = InvalidationPaths::requests($folded);

        $this->assertSame([InvalidationPaths::MAX_WILDCARDS, 2], array_map('count', $requests));
        $this->assertSame($folded, array_merge(...$requests));
    }

    /**
     * 정확 경로가 경로 상한을 넘어도 트리별로 접고, 두 트리에 걸쳐 있어도 배포 전체로 번지지 않는다. 루트에 바로 놓인
     * 파일은 접을 디렉터리가 없어 정확 경로로 남는다.
     *
     * @scenario case=collapse
     *
     * @effects collapse_stays_inside_first_segment, distribution_root_never_invalidated
     *
     * @return void 반환값 없음
     */
    public function test_exact_paths_over_path_limit_collapse_inside_first_segment(): void
    {
        $paths = ['/root.jpg', '/thumbnails/one.jpg'];
        for ($i = 1; $i <= InvalidationPaths::MAX_PATHS; $i++) {
            $paths[] = "/modules/shop/p{$i}.jpg";
        }

        $this->assertSame(['/modules/shop/*', '/root.jpg', '/thumbnails/one.jpg'], InvalidationPaths::fold($paths));
    }

    /**
     * 경로 상한으로 나뉜 요청마다 와일드카드는 상한 안이고, 정확 경로는 앞 요청의 남은 자리부터 채운다.
     *
     * @scenario case=split
     *
     * @effects requests_split_within_limits
     *
     * @return void 반환값 없음
     */
    public function test_requests_respect_wildcard_and_path_limits(): void
    {
        $paths = [];
        for ($i = 1; $i <= InvalidationPaths::MAX_WILDCARDS + 1; $i++) {
            $paths[] = "/tree{$i}/*";
        }
        for ($i = 1; $i <= InvalidationPaths::MAX_PATHS; $i++) {
            $paths[] = "/f{$i}.jpg";
        }

        $requests = InvalidationPaths::requests($paths);

        $this->assertSame([InvalidationPaths::MAX_WILDCARDS, InvalidationPaths::MAX_PATHS, 1], array_map('count', $requests));
        $this->assertSame($paths, array_merge(...$requests));

        foreach ($requests as $request) {
            $wildcards = array_filter($request, static fn (string $path) => str_ends_with($path, '/*'));
            $this->assertLessThanOrEqual(InvalidationPaths::MAX_WILDCARDS, count($wildcards));
        }

        $this->assertSame([], InvalidationPaths::requests([]));
    }

    /**
     * 대기열에 어떤 값이 들어 있어도 배포 전체 와일드카드는 보내지 않는다.
     *
     * @scenario case=collapse
     *
     * @effects distribution_root_never_invalidated
     *
     * @return void 반환값 없음
     */
    public function test_fold_drops_distribution_root_wildcard(): void
    {
        $this->assertSame(['/a/*'], InvalidationPaths::fold(['/*', '/a/*']));
    }

    /**
     * 원본을 디렉터리째 지웠으면(코어 `original_is_directory`) 원본 쪽도 그 디렉터리 와일드카드다 — 정확 경로 하나로는
     * 디렉터리 아래 파일들의 캐시가 남는다.
     *
     * @scenario case=original_directory
     *
     * @effects original_directory_invalidated_with_wildcard
     *
     * @return void 반환값 없음
     */
    public function test_original_directory_is_invalidated_with_wildcard(): void
    {
        $paths = InvalidationPaths::fromItems([
            [
                'public_url_prefix' => 'https://cdn.example.test/thumbnails/cloudfront/modules/shop/images/42',
                'original_public_url' => 'https://cdn.example.test/modules/shop/images/42',
                'original_is_directory' => true,
            ],
            [
                'public_url_prefix' => null,
                'original_public_url' => 'https://cdn.example.test/modules/shop/images/7.jpg',
                'original_is_directory' => false,
            ],
        ], self::HOST);

        $this->assertSame([
            '/modules/shop/images/42/*',
            '/thumbnails/cloudfront/modules/shop/images/42/*',
            '/modules/shop/images/7.jpg',
        ], $paths);
    }

    /**
     * 인코딩은 CloudFront 안내(RFC 1738 의 unsafe 문자와 비 ASCII 만)를 따른다 — 괄호·`+`·`!`·`'`·`,`·`$` 를
     * 인코딩하면 브라우저가 요청한 경로와 달라져 무효화가 캐시를 비우지 못한다.
     *
     * @scenario case=encoding
     *
     * @effects only_unsafe_characters_encoded
     *
     * @return void 반환값 없음
     */
    public function test_reserved_characters_are_not_encoded(): void
    {
        $paths = InvalidationPaths::fromItems([
            ['public_url_prefix' => null, 'original_public_url' => "https://cdn.example.test/files/a (1)+b!c'd,e\$f.jpg"],
            ['public_url_prefix' => null, 'original_public_url' => 'https://cdn.example.test/files/%28copy%29%2Bx.jpg'],
            ['public_url_prefix' => null, 'original_public_url' => 'https://cdn.example.test/files/100%25{x}.jpg'],
        ], self::HOST);

        $this->assertSame([
            '/files/(copy)+x.jpg',
            '/files/100%25%7Bx%7D.jpg',
            "/files/a%20(1)+b!c'd,e\$f.jpg",
        ], $paths);
    }

    /**
     * 이미 만든 경로 목록을 다시 접어도(재시도 병합) 같은 결과다 — 같은 대기 건 묶음은 같은 본문이 된다.
     *
     * @scenario case=retry_merge
     *
     * @effects fold_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_fold_is_idempotent_for_built_paths(): void
    {
        $built = InvalidationPaths::fromItems([
            ['public_url_prefix' => 'https://cdn.example.test/thumbnails/cloudfront/a/1.jpg', 'original_public_url' => 'https://cdn.example.test/a/1.jpg'],
            ['public_url_prefix' => 'https://cdn.example.test/thumbnails/cloudfront/a/2.jpg', 'original_public_url' => null],
        ], self::HOST);

        $this->assertSame($built, InvalidationPaths::fold($built));
        $this->assertSame($built, InvalidationPaths::fold(array_merge($built, array_reverse($built))));
    }

    /**
     * CDN 호스트를 가리키는 URL 이 하나도 없으면 빈 목록(무효화를 보내지 않는다)이다. 루트 경로 하나뿐인 URL 도 버린다.
     *
     * @scenario case=nothing_on_host
     *
     * @effects no_paths_when_nothing_on_cdn_host
     *
     * @return void 반환값 없음
     */
    public function test_no_paths_when_nothing_points_at_cdn_host(): void
    {
        $this->assertSame([], InvalidationPaths::fromItems([
            ['public_url_prefix' => 'http://localhost/storage/thumbnails/public/x.jpg', 'original_public_url' => null],
            ['public_url_prefix' => 'https://cdn.example.test/', 'original_public_url' => 'https://cdn.example.test'],
            'not-an-item',
        ], self::HOST));
    }
}
