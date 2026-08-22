<?php

namespace Modules\Sirsoft\Board\Seo;

use App\Enums\SitemapChangeFreq;
use App\Seo\AbstractSitemapContributor;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;

/**
 * Board 모듈 Sitemap 기여자
 *
 * 게시판 및 게시글 URL을 sitemap에 제공합니다.
 * 데이터 접근은 Repository 인터페이스에 위임하며, URL 은 배열로 모으지 않고
 * 한 건씩 지연 yield 하여 대용량 게시글(수백만 건)에서도 메모리를 유계로 유지합니다.
 */
class BoardSitemapContributor extends AbstractSitemapContributor
{
    /**
     * BoardSitemapContributor 생성자
     *
     * @param  BoardRepositoryInterface  $boardRepository  게시판 Repository
     * @param  PostRepositoryInterface  $postRepository  게시글 Repository
     */
    public function __construct(
        private readonly BoardRepositoryInterface $boardRepository,
        private readonly PostRepositoryInterface $postRepository,
    ) {}

    /**
     * 확장 식별자를 반환합니다.
     *
     * @return string 확장 식별자
     */
    public function getIdentifier(): string
    {
        return 'sirsoft-board';
    }

    /**
     * Sitemap URL 항목을 한 건씩 지연 순회합니다.
     *
     * 게시판 목록, 게시판별 페이지, 각 게시판의 게시글 URL을 순서대로 yield 합니다.
     * 기여자당 안전 상한(seo.sitemap_max_urls_per_contributor)을 초과하면 잘라내고
     * 경고를 남깁니다. 상한은 이 기여자가 내보내는 모든 URL 유형에 함께 적용됩니다.
     *
     * @return iterable<int, array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrlsLazy(): iterable
    {
        $maxUrls = (int) g7_core_settings('seo.sitemap_max_urls_per_contributor', 0);
        $emitted = 0;

        foreach ($this->collectUrls() as $entry) {
            if ($maxUrls > 0 && $emitted >= $maxUrls) {
                Log::warning('[SEO] Sitemap 기여자 URL 상한 초과로 잘렸습니다.', [
                    'contributor' => $this->getIdentifier(),
                    'max_urls' => $maxUrls,
                ]);

                return;
            }

            yield $entry;
            $emitted++;
        }
    }

    /**
     * 상한 적용 이전의 URL 후보를 순서대로 지연 생성합니다.
     *
     * @return iterable<int, array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    private function collectUrls(): iterable
    {
        // 'SEO 제공 페이지' 토글 — OFF 시 해당 URL 유형 제외 (기본 노출)
        $seoBoards = (bool) g7_module_settings('sirsoft-board', 'seo.seo_boards', true);
        $seoBoard = (bool) g7_module_settings('sirsoft-board', 'seo.seo_board', true);
        $seoPostDetail = (bool) g7_module_settings('sirsoft-board', 'seo.seo_post_detail', true);

        // 게시판 목록 페이지
        if ($seoBoards) {
            yield [
                'url' => '/boards',
                'changefreq' => SitemapChangeFreq::Weekly->value,
                'priority' => 0.5,
                'resource_type' => 'board_index',
                'resource_id' => null,
            ];
        }

        // 개별 게시판/게시글 URL이 모두 OFF면 게시판 조회 자체를 생략
        if (! $seoBoard && ! $seoPostDetail) {
            return;
        }

        // 게시판별 페이지
        foreach ($this->boardRepository->streamActiveForSitemap() as $board) {
            if ($seoBoard) {
                yield [
                    'url' => "/board/{$board->slug}",
                    'lastmod' => $board->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Daily->value,
                    'priority' => 0.6,
                    'resource_type' => 'board',
                    'resource_id' => (string) $board->id,
                ];
            }

            if (! $seoPostDetail) {
                continue;
            }

            // 각 게시판의 공개된 게시글 (비밀글 제외, 게시 상태만)
            foreach ($this->postRepository->streamPublishedForSitemap($board->id) as $post) {
                yield [
                    'url' => "/board/{$board->slug}/{$post->id}",
                    'lastmod' => $post->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Monthly->value,
                    'priority' => 0.5,
                    'resource_type' => 'board_post',
                    'resource_id' => (string) $post->id,
                ];
            }
        }
    }
}
