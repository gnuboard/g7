<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\PermissionHelper;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Services\ProductService;

/**
 * 통합 검색에 상품 검색 결과를 제공하는 리스너
 *
 * core.search.results Filter Hook을 구독하여 검색 결과에 상품을 추가합니다.
 * core.search.build_response Filter Hook을 구독하여 응답 구조를 생성합니다.
 * core.search.validation_rules Filter Hook을 구독하여 검색 파라미터 규칙을 추가합니다.
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
            'core.search.validation_rules' => [
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
     * @param mixed ...$args 훅에서 전달된 인수들
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
     * @param array $rules 기존 validation rules
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
     * @param array $results 기존 검색 결과
     * @param array $context 검색 컨텍스트 (q, type, sort, page, per_page, user, request)
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
            // 다른 탭인 경우 count만 반환
            if (!$isRelevantTab) {
                $results['products'] = [
                    'total' => $this->productService->countByKeyword($q, $categoryId),
                    'items' => [],
                ];

                return $results;
            }

            $offset = ($page - 1) * $perPage;
            $searchResult = $this->productService->searchByKeyword($q, $sort, $categoryId, $offset, $perPage);

            $items = $searchResult['items']->map(
                fn ($product) => $this->formatProductResult($product, $q)
            )->toArray();

            $results['products'] = [
                'total' => $searchResult['total'],
                'items' => $items,
            ];
        } catch (\Exception $e) {
            Log::error('Search products error', [
                'message' => $e->getMessage(),
                'q' => $q,
            ]);
        }

        return $results;
    }

    /**
     * 상품 검색 결과를 프론트엔드 응답 구조로 변환합니다.
     *
     * @param array $response 기존 응답 구조
     * @param array $results 검색 결과 (core.search.results에서 반환된 데이터)
     * @param array $context 검색 컨텍스트
     * @return array 상품 응답이 추가된 구조
     */
    public function buildProductsResponse(array $response, array $results, array $context): array
    {
        if (!isset($results['products'])) {
            return $response;
        }

        $productsData = $results['products'];
        $type = $context['type'] ?? 'all';
        $page = $context['page'] ?? 1;
        $perPage = $context['per_page'] ?? 10;

        $items = $productsData['items'] ?? [];
        $total = $productsData['total'] ?? 0;

        if ($type === 'all') {
            $allTabLimit = $context['all_tab_limit'] ?? 5;
            $response['products'] = array_slice($items, 0, $allTabLimit);
        } elseif ($type === 'products') {
            $response['products'] = $items;
            $response['current_page'] = $page;
            $response['per_page'] = $perPage;
            $response['last_page'] = max(1, (int) ceil($total / $perPage));
        }

        $response['products_count'] = $total;

        return $response;
    }

    // ─── 프레젠테이션 유틸리티 ────────────────────────────

    /**
     * 상품을 검색 결과 형식으로 변환합니다.
     *
     * @param object $product 상품 모델
     * @param string $keyword 검색어
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
            'selling_price_formatted' => $this->formatCurrencyPrice($product->selling_price, 'KRW'),
            'list_price' => $product->list_price,
            'list_price_formatted' => $this->formatCurrencyPrice($product->list_price, 'KRW'),
            'discount_rate' => $product->getDiscountRate(),
            'multi_currency_selling_price' => $this->buildMultiCurrencyPrices($product->selling_price),
            'multi_currency_list_price' => $this->buildMultiCurrencyPrices($product->list_price),
            'labels' => $labels,
            'url' => '/shop/'.$product->id,
            'review_count' => (int) ($product->review_count ?? 0),
            'rating_avg' => $product->rating_avg !== null ? round((float) $product->rating_avg, 1) : 0.0,
        ];
    }

    /**
     * 텍스트에서 검색어를 하이라이트 처리합니다.
     *
     * @param string|null $text 원본 텍스트
     * @param string $keyword 검색어
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
     * @param string|null $content 본문 내용
     * @param string $keyword 검색어
     * @param int $length 추출할 최대 길이
     * @return string 추출된 미리보기 텍스트
     */
    private function extractContentPreview(?string $content, string $keyword, int $length = 150): string
    {
        if (empty($content)) {
            return '';
        }

        $plainText = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($content))));
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