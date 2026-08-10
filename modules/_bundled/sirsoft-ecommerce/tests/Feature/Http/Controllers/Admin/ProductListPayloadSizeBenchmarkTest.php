<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 상품목록 응답 크기·쿼리 수 계측 (#518 / 공개 #76)
 *
 * 계획서가 요구한 지표를 같은 데이터셋(상품 100건 × 옵션 20건)에서 잰다. 종전 동작은
 * `?with_options=1` 하위호환 경로가 그대로 재현하므로, 코드를 되돌리지 않고도 전/후를
 * **같은 코드·같은 데이터**에서 비교할 수 있다.
 *
 * 절대 수치(ms)는 머신에 좌우되므로 단언하지 않는다. 단언하는 것은 데이터에서 오는 성질뿐이다 —
 * 응답 바이트가 크게 줄고, 쿼리 수는 늘지 않는다.
 *
 * 실측값은 `--testdox` 로 출력되며 이슈 회신·CHANGELOG 첨부용이다.
 */
class ProductListPayloadSizeBenchmarkTest extends ModuleTestCase
{
    /** 계측 데이터셋 — 계획서가 지정한 규모 */
    private const PRODUCT_COUNT = 100;

    private const OPTIONS_PER_PRODUCT = 20;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
    }

    /**
     * 계측용 데이터셋을 만듭니다.
     *
     * 옵션은 상품당 20건씩 총 2,000행이라 개별 insert 로는 계측보다 시딩이 오래 걸린다.
     * 벌크 insert 로 넣되 모델 캐스팅(JSON 컬럼)은 직접 인코딩한다.
     */
    private function seedDataset(): void
    {
        $products = Product::factory()->count(self::PRODUCT_COUNT)->create([
            'has_options' => true,
            'stock_quantity' => 100,
        ]);

        $rows = [];
        $now = now();

        foreach ($products as $product) {
            for ($i = 0; $i < self::OPTIONS_PER_PRODUCT; $i++) {
                $rows[] = [
                    'product_id' => $product->id,
                    'option_code' => 'OPT-'.$product->id.'-'.$i,
                    'option_values' => json_encode(['색상' => '값'.$i], JSON_UNESCAPED_UNICODE),
                    'option_name' => '옵션'.$i,
                    'price_adjustment' => 0,
                    'stock_quantity' => 10,
                    'is_active' => true,
                    'sort_order' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table((new ProductOption)->getTable())->insert($chunk);
        }
    }

    /**
     * 목록 응답을 한 번 호출해 바이트 수와 쿼리 수를 잽니다.
     *
     * @param  string  $url  호출 URL
     * @return array{bytes: int, queries: int} 계측 결과
     */
    private function measure(string $url): array
    {
        // 워밍 — 권한/설정 캐시가 첫 호출에만 실리므로 계측에서 제외한다
        $this->actingAs($this->adminUser)->getJson($url)->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $response = $this->actingAs($this->adminUser)->getJson($url);
        $response->assertOk();

        return [
            'bytes' => strlen($response->getContent()),
            'queries' => $queries,
        ];
    }

    /**
     * 목록 응답 크기가 옵션 전량 포함 대비 크게 줄고, 쿼리 수는 늘지 않는다.
     *
     * @effects list_payload_bytes_reduced, query_count_not_increased
     */
    public function test_reports_payload_size_and_query_count(): void
    {
        $this->seedDataset();

        $base = '/api/modules/sirsoft-ecommerce/admin/products?per_page=100';

        $after = $this->measure($base);
        $before = $this->measure($base.'&with_options=1');

        // 남은 쿼리가 행 수에 비례하는지도 같은 데이터셋에서 본다. 옵션/이미지 조회는
        // 집계·eager load 로 흡수됐으므로, 여전히 비례하는 몫이 있다면 그것은 다른 원인이다.
        $tenRows = $this->measure('/api/modules/sirsoft-ecommerce/admin/products?per_page=10');

        $reduction = 100 - (int) round($after['bytes'] / $before['bytes'] * 100);

        fwrite(STDERR, sprintf(
            "\n[#518 성능 지표] 상품 %d건 × 옵션 %d건 (per_page=100)\n".
            "  옵션 포함(종전): %s bytes / 쿼리 %d회\n".
            "  옵션 제외(현행): %s bytes / 쿼리 %d회\n".
            "  응답 감소율: %d%%\n".
            "  [참고] 현행 per_page=10: %s bytes / 쿼리 %d회 — 행 수에 비례해 남는 몫 확인용\n",
            self::PRODUCT_COUNT,
            self::OPTIONS_PER_PRODUCT,
            number_format($before['bytes']),
            $before['queries'],
            number_format($after['bytes']),
            $after['queries'],
            $reduction,
            number_format($tenRows['bytes']),
            $tenRows['queries'],
        ));

        // 데이터에서 오는 성질만 단언한다 — 옵션 2,000행이 빠졌으므로 응답은 크게 줄어야 한다
        $this->assertLessThan(
            $before['bytes'] / 2,
            $after['bytes'],
            '옵션을 뺀 응답이 절반 이하로 줄지 않았다'
        );

        // 집계로 대체했으므로 쿼리 수가 늘어서는 안 된다 (집계는 목록 SELECT 안의 서브쿼리)
        $this->assertLessThanOrEqual(
            $before['queries'],
            $after['queries'],
            '옵션을 뺐는데 쿼리 수가 늘었다 — 집계가 행마다 실행되고 있다'
        );
    }
}
