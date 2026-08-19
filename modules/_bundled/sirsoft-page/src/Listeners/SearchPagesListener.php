<?php

namespace Modules\Sirsoft\Page\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\TotalRelation;
use App\Search\SearchCategoryPayload;
use App\Search\SearchHighlighter;
use App\Support\Query\BoundedCount;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Page\Services\PageService;

/**
 * 통합 검색에 페이지 검색 결과를 제공하는 리스너
 *
 * core.search.results Filter Hook을 구독하여 검색 결과에 페이지를 추가합니다.
 * core.search.build_response Filter Hook을 구독하여 응답 구조를 생성합니다.
 * core.search.index_validation_rules Filter Hook을 구독하여 검색 파라미터 규칙을 추가합니다.
 */
class SearchPagesListener implements HookListenerInterface
{
    public function __construct(
        private readonly PageService $pageService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, mixed>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.search.results' => [
                'method' => 'searchPages',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.search.build_response' => [
                'method' => 'buildPagesResponse',
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
     * @return array 페이지 모듈 파라미터가 추가된 rules
     */
    public function addValidationRules(array $rules): array
    {
        $rules['sort'] = ['nullable', 'string', 'in:relevance,latest,oldest'];

        return $rules;
    }

    /**
     * 페이지 검색을 수행하고 결과를 반환합니다.
     *
     * @param  array  $results  기존 검색 결과
     * @param  array  $context  검색 컨텍스트 (q, type, sort, page, per_page, user, request)
     * @return array 페이지가 추가된 검색 결과
     */
    public function searchPages(array $results, array $context): array
    {
        $q = $context['q'] ?? '';
        if (empty($q)) {
            return $results;
        }

        $type = $context['type'] ?? 'all';
        $isRelevantTab = ($type === 'all' || $type === 'pages');

        try {
            if (! $isRelevantTab) {
                // 다른 탭을 보는 중이라도 탭 배지에는 건수가 필요하다. 다만 상한을 건
                // 집계라 대량 매칭에서도 비용이 일정하다.
                // 잘린 값을 정확한 것처럼 내보내지 않도록 정확도를 함께 싣는다.
                //
                // 배지를 그리지 않는 화면은 건수를 요청하지 않는다 — 그 경우 집계를 생략한다.
                if (! ($context['include_inactive_counts'] ?? true)) {
                    $results['pages'] = SearchCategoryPayload::fromCountOnly(
                        new BoundedCount(0, TotalRelation::Exact, null)
                    );

                    return $results;
                }

                $results['pages'] = SearchCategoryPayload::fromCountOnly(
                    $this->pageService->countByKeyword($q)
                );

                return $results;
            }

            $sort = $context['sort'] ?? 'relevance';
            [$orderBy, $direction] = $this->resolveSortOrder($sort);

            // 전체 탭은 미리보기 몇 건, 페이지 탭은 실제 요청 페이지를 그대로 하달한다.
            // 종전에는 PHP_INT_MAX 로 매칭 전량을 읽고 PHP 에서 잘라냈다.
            $isAllTab = ($type === 'all');
            $perPage = $isAllTab
                ? (int) ($context['all_tab_limit'] ?? 5)
                : (int) ($context['per_page'] ?? 10);
            $pageNumber = $isAllTab ? 1 : (int) ($context['page'] ?? 1);

            /** 조회 결과 항목을 화면 형태로 가공한다. */
            $format = fn (iterable $items): array => collect($items)
                ->map(fn ($page) => $this->formatPageResult($page, $q))
                ->toArray();

            // 커서를 받았고 그 정렬을 커서로 처리할 수 있으면 키셋으로 응답한다.
            // 전체 탭은 미리보기 몇 건뿐이라 깊은 페이지가 없어 대상이 아니다.
            // 페이지 번호를 함께 넘겨 커서 없이 깊은 페이지를 지목한 딥링크를 코어가 가려내게
            // 한다 — 넘기지 않으면 기본값 1 이 적용돼 그 링크가 첫 페이지로 되돌아간다.
            $cursorPage = $isAllTab
                ? null
                : $this->pageService->searchByKeywordWithCursor($q, $sort, $perPage, $context['cursor'] ?? null, $pageNumber);

            if ($cursorPage !== null) {
                $results['pages'] = SearchCategoryPayload::fromCursor(
                    $cursorPage,
                    $this->pageService->countByKeyword($q),
                    $format($cursorPage->items())
                );

                return $results;
            }

            $searchPage = $this->pageService->searchByKeyword($q, $orderBy, $direction, $perPage, $pageNumber);

            $results['pages'] = SearchCategoryPayload::fromBounded($searchPage, $format($searchPage->items()));
        } catch (\Exception $e) {
            // 실패를 카테고리 키 미설정으로 삼키면 화면이 "검색 결과 없음" 을 그린다 —
            // failed 페이로드로 표면화하고, 원인 추적을 위해 스택을 함께 남긴다 (#103).
            Log::error('Search pages error', ['message' => $e->getMessage(), 'q' => $q, 'exception' => $e]);
            $results['pages'] = SearchCategoryPayload::failed();
        }

        return $results;
    }

    /**
     * 페이지 검색 결과를 프론트엔드 응답 구조로 변환합니다.
     *
     * @param  array  $response  기존 응답 구조
     * @param  array  $results  검색 결과 (core.search.results에서 반환된 데이터)
     * @param  array  $context  검색 컨텍스트
     * @return array 페이지 응답이 추가된 구조
     */
    public function buildPagesResponse(array $response, array $results, array $context): array
    {
        if (! isset($results['pages'])) {
            return $response;
        }

        $pagesData = $results['pages'];
        $type = $context['type'] ?? 'all';
        $total = $pagesData['total'] ?? 0;
        // 항목은 이미 요청한 페이지 분량만 조회돼 있다 — 여기서 다시 자르지 않는다.
        $items = $pagesData['items'] ?? [];

        $response['pages_count'] = $total;

        if ($type === 'all') {
            $response['pages'] = [
                'total' => $total,
                'total_relation' => $pagesData['total_relation'] ?? null,
                'total_is_exact' => $pagesData['total_is_exact'] ?? true,
                'result_cap' => $pagesData['result_cap'] ?? null,
                'items' => $items,
            ];
        } elseif ($type === 'pages') {
            $response['pages'] = [
                'total' => $total,
                'total_relation' => $pagesData['total_relation'] ?? null,
                'total_is_exact' => $pagesData['total_is_exact'] ?? true,
                'result_cap' => $pagesData['result_cap'] ?? null,
                'items' => $items,
            ];
            $response['current_page'] = (int) ($context['page'] ?? 1);
            $response['per_page'] = (int) ($context['per_page'] ?? 10);
            // 총 건수가 상한에 걸리면 마지막 페이지를 계산할 수 없다 — null 로 알린다.
            $response['last_page'] = $pagesData['last_page'] ?? null;
            $response['has_more_pages'] = $pagesData['has_more_pages'] ?? false;
            // 커서 응답이면 다음/이전 커서를 함께 실어 깊은 페이지를 OFFSET 없이 넘긴다.
            $response['next_cursor'] = $pagesData['next_cursor'] ?? null;
            $response['prev_cursor'] = $pagesData['prev_cursor'] ?? null;
        }

        return $response;
    }

    // ─── 프레젠테이션 유틸리티 ────────────────────────────

    /**
     * sort 파라미터를 정렬 컬럼과 방향으로 변환합니다.
     *
     * @param  string  $sort  정렬 방식 (relevance|latest|oldest)
     * @return array{0: string, 1: string} [orderBy, direction]
     */
    private function resolveSortOrder(string $sort): array
    {
        return match ($sort) {
            'oldest' => ['created_at', 'asc'],
            default => ['created_at', 'desc'],
        };
    }

    /**
     * 페이지를 검색 결과 형식으로 변환합니다.
     *
     * @param  object  $page  페이지 모델
     * @param  string  $keyword  검색어
     * @return array 변환된 페이지 데이터
     */
    private function formatPageResult(object $page, string $keyword): array
    {
        $title = $page->getLocalizedTitle();
        $contentPreview = $this->extractContentPreview($page->content, $keyword);

        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $title,
            'title_highlighted' => $this->highlightKeyword($title, $keyword),
            'content_preview' => $contentPreview,
            'content_preview_highlighted' => $this->highlightKeyword($contentPreview, $keyword),
            'published_at' => $page->published_at?->format('Y-m-d'),
            'url' => "/page/{$page->slug}",
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
     * title은 JSON 배열이며 content도 배열이므로 현재 로케일 값을 추출합니다.
     *
     * @param  array|string|null  $content  본문 내용 (JSON 배열 또는 문자열)
     * @param  string  $keyword  검색어
     * @param  int  $length  추출할 최대 길이
     * @return string 추출된 미리보기 텍스트
     */
    private function extractContentPreview(array|string|null $content, string $keyword, int $length = 150): string
    {
        if (empty($content)) {
            return '';
        }

        // content가 배열(JSON 다국어)인 경우 현재 로케일 값 추출
        if (is_array($content)) {
            $locale = app()->getLocale();
            $text = $content[$locale]
                ?? $content[config('app.fallback_locale')]
                ?? (! empty($content) ? array_values($content)[0] : '');
        } else {
            $text = $content;
        }

        if (empty($text)) {
            return '';
        }

        // HTML 태그 제거 후 공백 정규화 (엔티티 디코드를 태그 제거보다 먼저 수행)
        $plainText = SearchHighlighter::toPlainText((string) $text);
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
