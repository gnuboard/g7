<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\PermissionHelper;
use App\Search\SearchCategoryPayload;
use App\Search\SearchHighlighter;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 통합 검색에 게시글 검색 결과를 제공하는 리스너
 *
 * core.search.results Filter Hook을 구독하여 검색 결과에 게시글을 추가합니다.
 * core.search.build_response Filter Hook을 구독하여 응답 구조를 생성합니다.
 * core.search.index_validation_rules Filter Hook을 구독하여 검색 파라미터 규칙을 추가합니다.
 */
class SearchPostsListener implements HookListenerInterface
{
    use FormatsBoardDate;

    public function __construct(
        private readonly PostService $postService,
        private readonly BoardService $boardService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.search.results' => [
                'method' => 'searchPosts',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.search.build_response' => [
                'method' => 'buildPostsResponse',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.search.index_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void
    {
        // Filter Hook은 getSubscribedHooks에서 지정한 메서드를 직접 호출하므로
        // 이 메서드는 인터페이스 요구사항 충족을 위해서만 존재합니다.
    }

    /**
     * 검색 파라미터 validation rules 추가
     *
     * @param  array  $rules  기존 validation rules
     * @return array 게시판 모듈 파라미터가 추가된 rules
     */
    public function addValidationRules(array $rules): array
    {
        $rules['sort'] = ['nullable', 'string', 'in:relevance,latest,oldest,views,popular'];
        $rules['board_slug'] = ['nullable', 'string', 'max:100'];

        return $rules;
    }

    /**
     * 게시글 검색을 수행하고 결과를 반환합니다.
     *
     * @param  array  $results  기존 검색 결과
     * @param  array  $context  검색 컨텍스트 (q, type, sort, page, per_page, user, request)
     * @return array 게시글이 추가된 검색 결과
     */
    public function searchPosts(array $results, array $context): array
    {
        $q = $context['q'] ?? '';
        if (empty($q)) {
            return $results;
        }

        $type = $context['type'] ?? 'all';
        $isRelevantTab = ($type === 'all' || $type === 'posts');

        try {
            $boardSlug = ($context['request'] ?? null)?->input('board_slug', '') ?? '';
            $boards = $this->boardService->getActiveBoardsForSearch(
                ! empty($boardSlug) ? $boardSlug : null
            );

            // 게시판별 읽기 권한 필터링 — 권한 없는 게시판 제외
            $user = $context['user'] ?? null;
            $boards = $boards->filter(function ($board) use ($user) {
                return PermissionHelper::check("sirsoft-board.{$board->slug}.posts.read", $user);
            });

            if ($boards->isEmpty()) {
                return $isRelevantTab ? $results : $this->withEmptyPostsResult($results);
            }

            if (! $isRelevantTab) {
                // 배지를 그리지 않는 화면은 건수 자체를 요청하지 않는다 — 그 경우 집계를 생략한다.
                return ($context['include_inactive_counts'] ?? true)
                    ? $this->withCountOnlyResult($results, $boards, $q)
                    : $this->withEmptyPostsResult($results);
            }

            $results['posts'] = $this->buildSearchResult($boards, $q, $context);
        } catch (\Exception $e) {
            // 실패를 카테고리 키 미설정으로 삼키면 화면이 "검색 결과 없음" 을 그린다 —
            // failed 페이로드로 표면화하고, 원인 추적을 위해 스택을 함께 남긴다 (#103).
            Log::error('Search posts error', ['message' => $e->getMessage(), 'q' => $q, 'exception' => $e]);
            $results['posts'] = SearchCategoryPayload::failed(['available_boards' => []]);
        }

        return $results;
    }

    /**
     * 게시글 검색 결과를 프론트엔드 응답 구조로 변환합니다.
     *
     * @param  array  $response  기존 응답 구조
     * @param  array  $results  검색 결과 (core.search.results에서 반환된 데이터)
     * @param  array  $context  검색 컨텍스트
     * @return array 게시글 응답이 추가된 구조
     */
    public function buildPostsResponse(array $response, array $results, array $context): array
    {
        if (! isset($results['posts'])) {
            return $response;
        }

        $postsData = $results['posts'];
        $type = $context['type'] ?? 'all';

        if ($type === 'all') {
            $response['posts'] = $this->buildAllTabResponse($postsData, $context);
        } elseif ($type === 'posts') {
            $response = $this->buildPostsTabResponse($response, $postsData, $context);
        }

        if (isset($postsData['available_boards'])) {
            $response['posts']['available_boards'] = $postsData['available_boards'];
        }

        $response['posts_count'] = $postsData['total'] ?? 0;

        return $response;
    }

    // ─── searchPosts 헬퍼 ─────────────────────────────────

    /**
     * 빈 게시글 결과를 설정합니다.
     *
     * @param  array  $results  기존 검색 결과
     * @return array 빈 posts가 추가된 결과
     */
    private function withEmptyPostsResult(array $results): array
    {
        $results['posts'] = ['total' => 0, 'items' => [], 'available_boards' => []];

        return $results;
    }

    /**
     * 다른 탭에서 게시글 건수만 조회합니다 (단일 쿼리).
     *
     * @param  array  $results  기존 검색 결과
     * @param  iterable  $boards  게시판 목록
     * @param  string  $keyword  검색어
     * @return array count만 포함된 결과
     */
    private function withCountOnlyResult(array $results, iterable $boards, string $keyword): array
    {
        // 다른 탭을 보는 중이라도 탭 배지에는 건수가 필요하다. 상한을 건 집계라
        // 대량 매칭에서도 비용이 일정하다.
        // 잘린 값을 정확한 것처럼 내보내지 않도록 정확도를 함께 싣는다.
        $boardIds = collect($boards)->pluck('id')->all();
        $count = $this->postService->countAcrossBoards($boardIds, $keyword);

        $results['posts'] = SearchCategoryPayload::fromCountOnly($count, ['available_boards' => []]);

        return $results;
    }

    /**
     * 전체 게시판을 단일 쿼리로 검색하고 결과 구조를 생성합니다.
     *
     * @param  iterable  $boards  게시판 목록
     * @param  string  $keyword  검색어
     * @param  array  $context  검색 컨텍스트
     * @return array posts 결과 구조
     */
    private function buildSearchResult(iterable $boards, string $keyword, array $context): array
    {
        $sort = $context['sort'] ?? 'relevance';
        $page = $context['page'] ?? 1;
        $perPage = $context['per_page'] ?? 10;
        $type = $context['type'] ?? 'all';

        $boardIds = collect($boards)->pluck('id')->all();
        // available_boards(필터 드롭다운)는 검색 결과 필터와 동일하게 읽기 권한을 통과한
        // 게시판만 담는다 — 권한 없는 게시판이 필터 목록으로 노출되지 않도록 컨텍스트 사용자를 전달.
        $extra = ['available_boards' => $this->boardService->getActiveBoardsListForFilter($context['user'] ?? null)];

        /** 조회 결과 항목을 화면 형태로 가공한다. */
        $format = fn (iterable $items): array => collect($items)
            ->map(fn ($post) => $this->formatPostResult($post, $keyword))
            ->all();

        // 전체 탭은 최신 N개만 보여 주므로 깊은 페이지 자체가 없다.
        if ($type === 'all') {
            $allTabLimit = (int) ($context['all_tab_limit'] ?? 5);
            $searchPage = $this->postService->searchAcrossBoards($boardIds, $keyword, $sort, $allTabLimit, 1);

            return SearchCategoryPayload::fromBounded($searchPage, $format($searchPage->items()), $extra);
        }

        // 커서로 처리할 수 있는 정렬이면 키셋으로 응답한다. 첫 페이지는 커서가 없는 것이
        // 정상이며 그때 다음 커서가 발급된다. 커서 없이 깊은 페이지를 직접 지목한 요청
        // (딥링크)만 offset 을 유지한다 — 판정은 코어가 하고 이 리스너는 결과 유무만 본다.
        $cursorPage = $this->postService->searchAcrossBoardsByCursor(
            $boardIds,
            $keyword,
            $sort,
            (int) $perPage,
            $context['cursor'] ?? null,
            (int) $page
        );

        if ($cursorPage !== null) {
            return SearchCategoryPayload::fromCursor(
                $cursorPage,
                $this->postService->countAcrossBoards($boardIds, $keyword),
                $format($cursorPage->items()),
                $extra
            );
        }

        $searchPage = $this->postService->searchAcrossBoards($boardIds, $keyword, $sort, (int) $perPage, (int) $page);

        return SearchCategoryPayload::fromBounded($searchPage, $format($searchPage->items()), $extra);
    }

    // ─── buildPostsResponse 헬퍼 ─────────────────────────

    /**
     * 전체 탭 응답 구조를 생성합니다.
     *
     * @param  array  $postsData  게시글 데이터
     * @param  array  $context  검색 컨텍스트
     * @return array 전체 탭용 응답
     */
    private function buildAllTabResponse(array $postsData, array $context): array
    {
        return [
            'total' => $postsData['total'] ?? 0,
            'total_relation' => $postsData['total_relation'] ?? null,
            'total_is_exact' => $postsData['total_is_exact'] ?? true,
            'result_cap' => $postsData['result_cap'] ?? null,
            'items' => $postsData['items'] ?? [],
        ];
    }

    /**
     * posts 탭 응답 구조를 생성합니다 (DB 페이지네이션).
     *
     * @param  array  $response  기존 응답
     * @param  array  $postsData  게시글 데이터
     * @param  array  $context  검색 컨텍스트
     * @return array 페이지네이션이 추가된 응답
     */
    private function buildPostsTabResponse(array $response, array $postsData, array $context): array
    {
        $page = (int) ($context['page'] ?? 1);
        $perPage = (int) ($context['per_page'] ?? 10);
        $totalItems = $postsData['total'] ?? 0;

        $response['posts'] = [
            'total' => $totalItems,
            'total_relation' => $postsData['total_relation'] ?? null,
            'total_is_exact' => $postsData['total_is_exact'] ?? true,
            'result_cap' => $postsData['result_cap'] ?? null,
            'items' => $postsData['items'] ?? [],
        ];
        $response['current_page'] = $page;
        $response['per_page'] = $perPage;
        // 총 건수가 상한에 걸리면 마지막 페이지를 계산할 수 없다 — null 로 알린다.
        // 화면은 이 값이 null 이면 마지막 페이지 점프만 감추고, "다음" 은 계속 노출한다.
        $response['last_page'] = $postsData['last_page'] ?? null;
        $response['has_more_pages'] = $postsData['has_more_pages'] ?? false;
        // 커서 응답이면 다음/이전 커서를 함께 실어 화면이 깊은 페이지를 OFFSET 없이 넘긴다.
        // offset 응답에서는 두 값이 null 이라 화면이 분기 없이 같은 키를 읽는다.
        $response['next_cursor'] = $postsData['next_cursor'] ?? null;
        $response['prev_cursor'] = $postsData['prev_cursor'] ?? null;

        return $response;
    }

    // ─── 프레젠테이션 유틸리티 ────────────────────────────

    /**
     * 게시글을 검색 결과 형식으로 변환합니다.
     *
     * @param  object  $post  게시글 (board relation 로드 필수)
     * @param  string  $keyword  검색어
     * @return array 변환된 게시글 데이터
     */
    private function formatPostResult(object $post, string $keyword): array
    {
        $contentMode = $post->content_mode ?? 'text';
        $contentPreview = $this->extractContentPreview($post->content, $keyword, 150, $contentMode);
        $boardSlug = $post->board?->slug ?? '';

        // 하이라이트 필드는 소비 측이 HTML 로 렌더하므로, 공유 헬퍼가 원문을 이스케이프한 뒤
        // 검색어만 <mark> 로 감싼다. 텍스트 모드의 리터럴 태그 문자열도 여기서 이스케이프된다.
        $contentPreviewHighlighted = $this->highlightKeyword($contentPreview, $keyword);

        return [
            'id' => $post->id,
            'title' => $post->title,
            'title_highlighted' => $this->highlightKeyword($post->title, $keyword),
            'content_preview' => $contentPreview,
            'content_preview_highlighted' => $contentPreviewHighlighted,
            'content_mode' => $contentMode,
            'board' => [
                'slug' => $boardSlug,
                'name' => $post->board?->getLocalizedName() ?? '',
            ],
            'board_name' => $post->board?->getLocalizedName() ?? '',
            'board_slug' => $boardSlug,
            // 모듈 번역 키는 네임스페이스를 붙여야 해석된다. 붙이지 않으면 해석에 실패해도
            // 예외 없이 키 문자열이 그대로 화면에 나간다 (실측: 작성자에 "board.anonymous").
            'author_name' => $post->author_name ?? $post->user?->name ?? __('sirsoft-board::messages.common.guest'),
            'created_at' => $this->formatCreatedAt($post->created_at),
            'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
            'view_count' => $post->view_count ?? 0,
            'comment_count' => $post->comments_count ?? 0,
            'url' => "/board/{$boardSlug}/{$post->id}",
        ];
    }

    /**
     * 텍스트에서 검색어를 하이라이트 처리합니다.
     *
     * @param  string|null  $text  원본 텍스트
     * @param  string  $keyword  검색어
     * @return string 하이라이트 처리된 텍스트
     */
    private function highlightKeyword(?string $text, string $keyword): string
    {
        return SearchHighlighter::highlight($text, $keyword);
    }

    /**
     * 본문에서 키워드 주변 텍스트를 추출합니다.
     *
     * HTML 모드: strip_tags로 태그 제거 후 평문 추출
     * 텍스트 모드: 태그 문자열을 그대로 보존 (실제 게시글 표시와 동일하게)
     *
     * @param  string|null  $content  본문 내용
     * @param  string  $keyword  검색어
     * @param  int  $length  추출할 최대 길이
     * @param  string  $contentMode  콘텐츠 모드 (text|html)
     * @return string 추출된 미리보기 텍스트
     */
    private function extractContentPreview(?string $content, string $keyword, int $length = 150, string $contentMode = 'text'): string
    {
        if (empty($content)) {
            return '';
        }

        // HTML 모드: 태그 제거 후 평문 추출(엔티티 디코드를 태그 제거보다 먼저 수행),
        // 텍스트 모드: 태그 문자열 그대로 보존(하이라이트 시점에 이스케이프됨)
        if ($contentMode === 'html') {
            $plainText = SearchHighlighter::toPlainText($content);
        } else {
            $plainText = trim(preg_replace('/\s+/', ' ', $content));
        }
        $position = mb_stripos($plainText, $keyword);

        if ($position !== false) {
            $start = max(0, $position - 50);
            $preview = mb_substr($plainText, $start, $length);

            return ($start > 0 ? '...' : '')
                .$preview
                .(mb_strlen($plainText) > $start + $length ? '...' : '');
        }

        $preview = mb_substr($plainText, 0, $length);

        return $preview.(mb_strlen($plainText) > $length ? '...' : '');
    }
}
