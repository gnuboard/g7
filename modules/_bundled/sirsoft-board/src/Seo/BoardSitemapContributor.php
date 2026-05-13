<?php

namespace Modules\Sirsoft\Board\Seo;

use App\Seo\Contracts\SitemapContributorInterface;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;

/**
 * Board 모듈 Sitemap 기여자
 *
 * 게시판 및 게시글 URL을 sitemap에 제공합니다.
 */
class BoardSitemapContributor implements SitemapContributorInterface
{
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
     * Sitemap URL 항목 배열을 반환합니다.
     *
     * 게시판 목록, 게시판별 페이지, 각 게시판의 게시글 URL을 생성합니다.
     *
     * @return array<int, array{url: string, lastmod?: string, changefreq?: string, priority?: float}>
     */
    public function getUrls(): array
    {
        $urls = [];

        // 게시판 목록 페이지
        $urls[] = [
            'url' => '/boards',
            'changefreq' => 'weekly',
            'priority' => 0.5,
        ];

        // 게시판별 페이지
        $boards = Board::where('is_active', true)->get(['id', 'slug', 'updated_at']);
        foreach ($boards as $board) {
            $urls[] = [
                'url' => "/board/{$board->slug}",
                'lastmod' => $board->updated_at?->toW3cString(),
                'changefreq' => 'daily',
                'priority' => 0.6,
            ];

            // 각 게시판의 공개된 게시글 (비밀글 제외, 게시 상태만)
            $posts = Post::where('board_id', $board->id)
                ->where('status', PostStatus::Published)
                ->where('is_secret', false)
                ->get(['id', 'updated_at']);
            foreach ($posts as $post) {
                $urls[] = [
                    'url' => "/board/{$board->slug}/{$post->id}",
                    'lastmod' => $post->updated_at?->toW3cString(),
                    'changefreq' => 'monthly',
                    'priority' => 0.5,
                ];
            }
        }

        return $urls;
    }
}
