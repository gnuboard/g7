<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\Concerns;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 상품 문의 스레드 정리 절차 (상품 재생성 전)
 *
 * 문의 피벗은 product_id FK 가 onDelete cascade 라, 상품을 지우면 DB 가 피벗만 조용히 지우고 게시판의
 * 문의 글은 고아로 남는다(재시드마다 게시판에 샘플 글이 쌓인다). 그래서 상품을 지우기 전에 그 상품들의
 * 문의 글 id 를 게시판에 넘겨 **그 글과 그 글의 답변만** 지우게 하고, 피벗은 명시적으로 지운다.
 * 게시판의 다른 글·다른 답변은 건드리지 않는다(게시판 리스너 `purgeSamplePosts` 의 계약).
 */
trait PurgesProductInquiries
{
    /**
     * 모든 상품 문의 피벗과 그 게시판 글(+답변)을 정리하고 정리한 문의 수를 돌려줍니다.
     *
     * @return int 정리한 문의 수
     */
    protected function purgeProductInquiryThreads(): int
    {
        $count = ProductInquiry::withTrashed()->count();
        if ($count === 0) {
            return 0;
        }

        $inquirables = ProductInquiry::withTrashed()
            ->get(['inquirable_type', 'inquirable_id'])
            ->groupBy('inquirable_type')
            ->map(fn ($rows) => $rows->pluck('inquirable_id')->map(fn ($id) => (int) $id)->values()->all())
            ->all();

        $boardSlug = $this->inquiryBoardSlug();
        if ($boardSlug !== null) {
            HookManager::applyFilters('sirsoft-ecommerce.inquiry.purge_sample_posts', 0, $boardSlug, $inquirables);
        }

        ProductInquiry::withTrashed()->forceDelete();

        return $count;
    }

    /**
     * 이커머스 설정의 상품 문의 게시판 슬러그를 돌려줍니다 (미설정이면 null).
     *
     * @return string|null 게시판 슬러그
     */
    protected function inquiryBoardSlug(): ?string
    {
        try {
            $slug = app(EcommerceSettingsService::class)->getSetting('inquiry.board_slug');
        } catch (\Throwable) {
            return null;
        }

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
