<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Extension\ModuleManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;

/**
 * #162 브라우저 실측 이커머스 데이터 정리 시더 (CLI + G7_PLAYWRIGHT_BYPASS=1 전용)
 *
 * 표지 상품(`Issue162MeasureSeeder::PRODUCT_CODE`)과 그 상품이 든 주문만 지운다. 주문을 지우면 주문 옵션·결제·주소·배송
 * 행이 외래키로 함께 지워지고, 상품을 지우면 옵션·카테고리 연결·장바구니가 함께 지워진다. 그 상품의 본문 미디어(외래키가
 * 없다)는 행과 모듈 저장소 파일을 따로 지운다. 삭제 건수를 출력한다.
 */
class Issue162MeasureCleanupSeeder extends Seeder
{
    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        if (php_sapi_name() !== 'cli' || env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->command?->error(__('sirsoft-ecommerce::messages.seeders.issue162_measure.guard'));

            return;
        }

        $productIds = Product::withTrashed()
            ->where('product_code', Issue162MeasureSeeder::PRODUCT_CODE)
            ->pluck('id');

        $orders = 0;
        $products = 0;
        $media = 0;

        // 본문 미디어 서비스가 주입받는 것과 같은 모듈 저장소(모듈 인스턴스의 기본 디스크). 본문 미디어는 소유자 외래키가
        // 없어 상품을 지워도 남으므로 행과 파일을 따로 지운다.
        $storage = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorage();
        $mediaRows = ContentMedia::withTrashed()
            ->where('attachmentable_type', ContentMediaOwnerType::Product->morphType())
            ->whereIn('attachmentable_id', $productIds)
            ->get();

        DB::transaction(function () use ($productIds, $mediaRows, &$orders, &$products, &$media) {
            $orderIds = OrderOption::query()->whereIn('product_id', $productIds)->distinct()->pluck('order_id');
            $orders = Order::withTrashed()->whereIn('id', $orderIds)->forceDelete();
            $products = Product::withTrashed()->whereIn('id', $productIds)->forceDelete();
            foreach ($mediaRows as $row) {
                $row->forceDelete();
                $media++;
            }
        });

        foreach ($mediaRows as $row) {
            if ($storage->exists(ContentMediaService::CATEGORY, $row->storagePath())) {
                $storage->delete(ContentMediaService::CATEGORY, $row->storagePath());
            }
        }

        $this->command?->info(__('sirsoft-ecommerce::messages.seeders.issue162_measure.cleanup_done', [
            'orders' => $orders,
            'products' => $products,
            'media' => $media,
        ]));
    }
}
