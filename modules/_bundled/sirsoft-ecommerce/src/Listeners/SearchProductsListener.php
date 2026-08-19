<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\TotalRelation;
use App\Helpers\PermissionHelper;
use App\Search\SearchCategoryPayload;
use App\Search\SearchHighlighter;
use App\Support\Query\BoundedCount;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 통합 검색에 상품 검색 결과를 제공하는 리스너
 *
 * core.search.results Filter Hook을 구독하여 검색 결과에 상품을 추가합니다.
 * core.search.build_response Filter Hook을 구독하여 응답 구조를 생성합니다.
 * core.search.index_validation_rules Filter Hook을 구독하여 검색 파라미터 규칙을 추가합니다.
 */
class SearchProductsListener implements HookListenerInterface
{
    use HasMultiCurrencyPrices;

    public function __construct(
        private readonly ProductService $productService,
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
                'method' => 'searchProducts',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.search.build_response' => [
                'method' => 'buildProductsResponse',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.search.index_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     * Filter Hook은 getSubscribedHooks에서 지정한 메서드를 직접 호출하므로 이 메서드는 사용되지 않습니다.
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
     * @return array 이커머스 모듈 파라미터가 추가된 rules
     */
    public function addValidationRules(array $rules): array
    {
        $sortValues = 'relevance,latest,oldest,views,popular,price_asc,price_desc';
        $rules['sort'] = ['nullable', 'string', "in:{$sortValues}"];
        $rules['category_id'] = ['nullable', 'integer'];

        return $rules;
    }

    /**
     * 상품 검색을 수행하고 결과를 반환합니다.
     *
     * @param  array  $results  기존 검색 결과
     * @param  array  $context  검색 컨텍스트 (q, type, sort, page, per_page, user, request)
     * @return array 상품이 추가된 검색 결과
     */
    public function searchProducts(array $results, array $context): array
    {
        $q = $context['q'] ?? '';
        if (empty($q)) {
            return $results;
        }

        // 상품 조회 권한 체크 — 권한 없으면 검색 결과에 상품 미포함
        $user = $context['user'] ?? null;
        if (! PermissionHelper::check('sirsoft-ecommerce.user-products.read', $user)) {
            return $results;
        }

        $type = $context['type'] ?? 'all';
        $isRelevantTab = ($type === 'all' || $type === 'products');

        $request = $context['request'] ?? null;
        $categoryId = $request?->input('category_id') ? (int) $request->input('category_id') : null;
        $sort = $context['sort'] ?? 'relevance';
        $page = $context['page'] ?? 1;
        $perPage = $context['per_page'] ?? 10;

        try {
            // 다른 탭인 경우 count만 반환.
            // 배지에도 정확도를 함께 싣는다 — 상한에 걸려 잘린 값을 정확한 것처럼
            // 내보내면 화면에는 그냥 틀린 숫자로만 보인다.
            if (! $isRelevantTab) {
                // 배지를 그리지 않는 화면은 건수를 요청하지 않는다 — 그 경우 집계를 생략한다.
                if (! ($context['include_inactive_counts'] ?? true)) {
                    $results['products'] = SearchCategoryPayload::fromCountOnly(
                        new BoundedCount(0, TotalRelation::Exact, null)
                    );

                    return $results;
                }

                $results['products'] = SearchCategoryPayload::fromCountOnly(
                    $this->productService->countByKeyword($q, $categoryId)
                );

                return $results;
            }

            // 전체 탭은 미리보기 몇 건만, 상품 탭은 실제 요청 페이지를 그대로 조회한다.
            // 종전에는 전체 탭에서도 페이지 분량을 받아 PHP 에서 다시 잘랐다.
            $isAllTab = ($type === 'all');
            $fetchPerPage = $isAllTab ? (int) ($context['all_tab_limit'] ?? 5) : (int) $perPage;
            $offset = $isAllTab ? 0 : (((int) $page - 1) * (int) $perPage);

            /** 조회 결과 항목을 화면 형태로 가공한다. */
            $format = fn (iterable $items): array => collect($items)
                ->map(fn ($product) => $this->formatProductResult($product, $q))
                ->toArray();

            // 그 정렬을 커서로 처리할 수 있으면 키셋으로 응답한다. 페이지 번호를 함께 넘기는
            // 이유는 커서 없이 깊은 페이지를 직접 지목한 딥링크를 코어가 가려내야 하기
            // 때문이다 — 넘기지 않으면 기본값 1 이 적용돼 그 링크가 첫 페이지로 되돌아간다.
            // 전체 탭은 미리보기 몇 건뿐이라 깊은 페이지가 없어 대상이 아니다.
            $cursorPage = $isAllTab
                ? null
                : $this->productService->searchByKeywordWithCursor(
                    $q,
                    $sort,
                    $categoryId,
                    $fetchPerPage,
                    $context['cursor'] ?? null,
                    (int) $page
                );

            if ($cursorPage !== null) {
                $results['products'] = SearchCategoryPayload::fromCursor(
                    $cursorPage,
                    $this->productService->countByKeyword($q, $categoryId),
                    $format($cursorPage->items())
                );

                return $results;
            }

            $searchPage = $this->productService->searchByKeyword($q, $sort, $categoryId, $offset, $fetchPerPage);

            $results['products'] = SearchCategoryPayload::fromBounded($searchPage, $format($searchPage->items()));
        } catch (\Exception $e) {
            // 실패를 카테고리 키 미설정으로 삼키면 화면이 "검색 결과 없음" 을 그린다 —
            // failed 페이로드로 표면화하고, 원인 추적을 위해 스택을 함께 남긴다 (#103).
            Log::error('Search products error', [
                'message' => $e->getMessage(),
                'q' => $q,
                'exception' => $e,
            ]);
            $results['products'] = SearchCategoryPayload::failed();
        }

        return $results;
    }

    /**
     * 상품 검색 결과를 프론트엔드 응답 구조로 변환합니다.
     *
     * @param  array  $response  기존 응답 구조
     * @param  array  $results  검색 결과 (core.search.results에서 반환된 데이터)
     * @param  array  $context  검색 컨텍스트
     * @return array 상품 응답이 추가된 구조
     */
    public function buildProductsResponse(array $response, array $results, array $context): array
    {
        if (! isset($results['products'])) {
            return $response;
        }

        $productsData = $results['products'];
        $type = $context['type'] ?? 'all';
        $page = $context['page'] ?? 1;
        $perPage = $context['per_page'] ?? 10;

        // 항목은 이미 요청한 분량만 조회돼 있다 — 여기서 다시 자르지 않는다.
        $items = $productsData['items'] ?? [];
        $total = $productsData['total'] ?? 0;

        if ($type === 'all') {
            $response['products'] = $items;
        } elseif ($type === 'products') {
            $response['products'] = $items;
            $response['current_page'] = $page;
            $response['per_page'] = $perPage;
            // 총 건수가 상한에 걸리면 마지막 페이지를 계산할 수 없다 — null 로 알린다.
            $response['last_page'] = $productsData['last_page'] ?? null;
            $response['has_more_pages'] = $productsData['has_more_pages'] ?? false;
            // 커서 응답이면 다음/이전 커서를 함께 실어 깊은 페이지를 OFFSET 없이 넘긴다.
            $response['next_cursor'] = $productsData['next_cursor'] ?? null;
            $response['prev_cursor'] = $productsData['prev_cursor'] ?? null;
        }

        $response['products_count'] = $total;
        $response['products_total_is_exact'] = $productsData['total_is_exact'] ?? true;
        $response['products_total_relation'] = $productsData['total_relation'] ?? null;

        return $response;
    }

    // ─── 프레젠테이션 유틸리티 ────────────────────────────

    /**
     * 상품을 검색 결과 형식으로 변환합니다.
     *
     * @param  object  $product  상품 모델
     * @param  string  $keyword  검색어
     * @return array 변환된 상품 데이터
     */
    private function formatProductResult(object $product, string $keyword): array
    {
        $name = $product->getLocalizedName();
        $description = $product->getLocalizedDescription() ?? '';
        $shortDescription = $this->extractContentPreview($description, $keyword, 100);
        $primaryCategory = $product->primaryCategory->first();

        $labels = $product->activeLabelAssignments->map(function ($assignment) {
            $label = $assignment->label;

            return [
                'name' => $label->getLocalizedName(),
                'color' => $label->color,
            ];
        })->toArray();

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $name,
            'name_highlighted' => $this->highlightKeyword($name, $keyword),
            'short_description' => $shortDescription,
            'description_highlighted' => $this->highlightKeyword($shortDescription, $keyword),
            'thumbnail_url' => $product->getThumbnailUrl(),
            'category_name' => $primaryCategory?->getLocalizedName() ?? '',
            'brand_name' => $product->brand?->getLocalizedName() ?? '',
            'sales_status' => $product->sales_status?->value,
            'sales_status_label' => $product->sales_status?->label(),
            'selling_price' => $product->selling_price,
            // 통화 코드를 못 박지 않는다 — 이 문자열은 화면이 표시 통화 환산값을 찾지 못했을 때
            // 쓰는 폴백이라, 기준통화 금액에 다른 통화 기호가 붙으면 그대로 노출된다.
            'selling_price_formatted' => $this->formatBaseCurrency($product->selling_price),
            'list_price' => $product->list_price,
            'list_price_formatted' => $this->formatBaseCurrency($product->list_price),
            'discount_rate' => $product->getDiscountRate(),
            'multi_currency_selling_price' => $this->buildMultiCurrencyPrices($product->selling_price),
            'multi_currency_list_price' => $this->buildMultiCurrencyPrices($product->list_price),
            'labels' => $labels,
            // 상점 주소는 운영자 설정 — 기본값 리터럴은 주소를 바꾼 상점에서 죽은 링크가 된다 (공개 #85)
            'url' => ShopPathResolver::path('products/'.$product->product_code),
            'review_count' => (int) ($product->review_count ?? 0),
            'rating_avg' => $product->rating_avg !== null ? round((float) $product->rating_avg, 1) : 0.0,
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
     * @param  string|null  $content  본문 내용
     * @param  string  $keyword  검색어
     * @param  int  $length  추출할 최대 길이
     * @return string 추출된 미리보기 텍스트
     */
    private function extractContentPreview(?string $content, string $keyword, int $length = 150): string
    {
        if (empty($content)) {
            return '';
        }

        $plainText = SearchHighlighter::toPlainText($content);
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
