<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 카테고리 트리 캐시 무효화 리스너
 *
 * 거의 모든 쇼핑 화면이 카테고리 트리를 읽으므로 결과를 캐시한다
 * ({@see Category::getTree()}). 캐시된 트리에는 카테고리 구조뿐 아니라
 * **상품 수 집계**도 함께 실리므로, 무효화 대상은 카테고리 변경만이 아니라
 * 상품 변경까지 포함한다. 상품 훅을 빠뜨리면 카테고리 배지의 상품 수가
 * 오류 없이 낡은 값으로 남는다.
 */
class CategoryTreeCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        $hooks = [];

        // 카테고리 구조 변경
        foreach ([
            'sirsoft-ecommerce.category.after_create',
            'sirsoft-ecommerce.category.after_update',
            'sirsoft-ecommerce.category.after_delete',
            'sirsoft-ecommerce.category.after_toggle_status',
            'sirsoft-ecommerce.category.after_reorder',
        ] as $hook) {
            $hooks[$hook] = ['method' => 'flush', 'priority' => 10];
        }

        // 상품 변경 — 트리에 실리는 상품 수 집계가 달라진다
        foreach ([
            'sirsoft-ecommerce.product.after_create',
            'sirsoft-ecommerce.product.after_update',
            'sirsoft-ecommerce.product.after_delete',
            'sirsoft-ecommerce.product.after_bulk_update',
        ] as $hook) {
            $hooks[$hook] = ['method' => 'flush', 'priority' => 10];
        }

        return $hooks;
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        $this->flush();
    }

    /**
     * 카테고리 트리 캐시를 비웁니다.
     *
     * @param  mixed  ...$args  훅 인자 (사용하지 않음 — 어떤 변경이든 트리 전체가 대상)
     */
    public function flush(...$args): void
    {
        try {
            Category::flushTreeCache();
        } catch (\Throwable $e) {
            // 캐시 무효화 실패가 본 작업(상품/카테고리 저장)을 되돌리게 두지 않는다.
            // TTL 이 안전망으로 남아 있으므로 낡은 트리는 스스로 만료된다.
            Log::warning('카테고리 트리 캐시 무효화 실패', ['message' => $e->getMessage()]);
        }
    }
}
