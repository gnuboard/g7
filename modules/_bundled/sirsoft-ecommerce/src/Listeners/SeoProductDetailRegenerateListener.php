<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 상품 생성·수정 후 상품 상세 SEO 캐시를 미리 다시 그리는 리스너 (큐)
 *
 * 상세 페이지를 렌더링해 캐시에 넣는 일은 무겁다. 캐시 비우기({@see SeoProductCacheListener})는 저장한 요청
 * 안에서 sync 로 돌고, 다시 그리기만 이 리스너가 큐에서 맡는다(#724). 큐 작업은 커밋 뒤에 돌기 때문에 같은 훅에서
 * 비우기가 먼저 끝난 뒤에 다시 그린다. 다시 그리기가 늦거나 빠져도 다음 봇 방문이 그린다.
 */
class SeoProductDetailRegenerateListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환 (sync 미선언 — 큐로 실행)
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.product.after_create' => [
                'method' => 'onProductCreate',
                'priority' => 20,
            ],
            'sirsoft-ecommerce.product.after_update' => [
                'method' => 'onProductUpdate',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 상품 생성 후 상세 페이지 SEO 캐시를 다시 그립니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Product 모델)
     * @return void 반환값 없음
     */
    public function onProductCreate(...$args): void
    {
        $this->regenerateDetailCache($args);
    }

    /**
     * 상품 수정 후 상세 페이지 SEO 캐시를 다시 그립니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Product 모델)
     * @return void 반환값 없음
     */
    public function onProductUpdate(...$args): void
    {
        $this->regenerateDetailCache($args);
    }

    /**
     * 상품 상세 페이지의 SEO 캐시를 다시 그립니다.
     *
     * URL 구성: {상점 기준 경로}/products/{id}
     * 기준 경로는 상점 주소 설정(route_path / no_route)을 ShopPathResolver 가 해석한 값입니다.
     *
     * @param  array  $args  훅 인자 배열
     * @return void 반환값 없음
     */
    private function regenerateDetailCache(array $args): void
    {
        $product = $args[0] ?? null;

        if (! $product || ! isset($product->id)) {
            return;
        }

        try {
            $regenerator = app(SeoCacheRegenerator::class);
            $url = ShopPathResolver::path("products/{$product->id}");
            $regenerator->renderAndCache($url);

            Log::debug('[SEO] Product detail cache regenerated', [
                'product_id' => $product->id,
                'url' => $url,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Product detail cache regeneration failed', [
                'error' => $e->getMessage(),
                'product_id' => $product->id ?? null,
            ]);
        }
    }
}
