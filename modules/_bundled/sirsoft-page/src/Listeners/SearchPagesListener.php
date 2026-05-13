<?php

namespace Modules\Sirsoft\Page\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Page\Services\PageService;

/**
 * 통합 검색에 페이지 검색 결과를 제공하는 리스너
 *
 * core.search.results Filter Hook을 구독하여 검색 결과에 페이지를 추가합니다.
 * core.search.build_response Filter Hook을 구독하여 응답 구조를 생성합니다.
 * core.search.validation_rules Filter Hook을 구독하여 검색 파라미터 규칙을 추가합니다.
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
            'core.search.validation_rules' => [
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
                $results['pages'] = [
                    'total' => $this->pageService->countByKeyword($q),
                    'items' => [],
                ];

                return $results;
            }

            $sort = $context['sort'] ?? 'relevance';
            [$orderBy, $direction] = $this->resolveSortOrder($sort);

            $limit = ($type === 'all')
                ? ($context['all_tab_limit'] ?? 5)
                : PHP_INT_MAX;

            $searchResult = $this->pageService->searchByKeyword($q, $orderBy, $direction, $limit);

            $results['pages'] = [
                'total' => $searchResult['total'],
                'items' => $searchResult['items']->map(
                    fn ($page) => $this->formatPageResult($page, $q)
                )->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error('Search pages error', ['message' => $e->getMessage(), 'q' => $q]);
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
        $items = $pagesData['items'] ?? [];

        $response['pages_count'] = $total;

        if ($type === 'all') {
            $allTabLimit = $context['all_tab_limit'] ?? 5;
            $response['pages'] = [
                'total' => $total,
                'items' => array_slice($items, 0, $allTabLimit),
            ];
        } elseif ($type === 'pages') {
            $page = $context['page'] ?? 1;
            $perPage = $context['per_page'] ?? 10;
            $offset = ($page - 1) * $perPage;

            $response['pages'] = [
                'total' => $total,
                'items' => array_slice($items, $offset, $perPage),
            ];
            $response['current_page'] = $page;
            $response['per_page'] = $perPage;
            $response['last_page'] = max(1, (int) ceil($total / $perPage));
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
            default  => ['created_at', 'desc'],
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
        if (empty($text) || empty($keyword)) {
            return $text ?? '';
        }

        $escapedKeyword = preg_quote($keyword, '/');

        return preg_replace('/('.$escapedKeyword.')/iu', '<mark>$1</mark>', $text);
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

        // HTML 태그 제거 후 공백 정규화
        $plainText = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $text))));
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
