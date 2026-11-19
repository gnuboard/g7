<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Exports\Formats\CsvExportWriter;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 창의 항목 이름과 파일 머리글이 서로 구별되고, 「첫 행에만 표시」 설명이 그 유형의 상위 단위를 가리킨다
 *
 * - 한 행 단위에서 고를 수 있는 항목의 이름은 서로 달라야 한다 — 같으면 창에서 둘을 구별할 수 없고 파일 머리글도 겹친다
 *   (상품 「옵션 1개당 1행」의 상품 정가·옵션 정가가 둘 다 「정가」였다).
 * - 하위 단위 행에서 첫 행에만 쓰는 상위 값의 설명은 유형의 상위 단위를 쓴다 — 상품 파일에 「주문 단위」가 붙었다.
 */
class ExportCatalogLabelsTest extends ExportTestCase
{
    protected User $admin;

    /**
     * 세 유형을 모두 볼 수 있는(개인정보 포함) 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateExportJob::class]);

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.exports.create',
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.exports.personal_data',
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.mileage.read',
        ]);
    }

    /**
     * 유형의 창 카탈로그를 받습니다.
     *
     * @param  string  $type  유형
     * @param  string  $locale  화면 언어
     * @return array<string, mixed> 카탈로그 data
     */
    private function catalog(string $type, string $locale = 'ko'): array
    {
        app()->setLocale($locale);

        return (array) $this->actingAs($this->admin)
            ->withHeaders(['Accept-Language' => $locale])
            ->getJson($this->exportsUrl('/catalog?type='.$type))
            ->assertOk()
            ->json('data');
    }

    /**
     * 모든 유형·행 단위에서 고를 수 있는 항목의 이름이 서로 다르다(한국어·영어 모두).
     *
     * @return void 반환값 없음
     *
     * @scenario type=products, row_unit=order_option
     *
     * @effects field_labels_unique_per_row_unit
     */
    public function test_field_labels_are_unique_within_each_row_unit(): void
    {
        $duplicates = [];
        foreach (['ko', 'en'] as $locale) {
            foreach (['orders', 'products', 'mileage'] as $type) {
                foreach ((array) $this->catalog($type, $locale)['fields'] as $unit => $fields) {
                    $labels = array_column((array) $fields, 'label');
                    foreach (array_count_values($labels) as $label => $count) {
                        if ($count > 1) {
                            $duplicates[] = "{$locale} {$type}/{$unit}: {$label} ×{$count}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $duplicates);
    }

    /**
     * 상품 「옵션 1개당 1행」의 상위 값 설명은 「상품 단위」다 — 창 배지와 파일 머리글 모두.
     *
     * @return void 반환값 없음
     *
     * @scenario type=products, row_unit=order_option
     *
     * @effects parent_level_label_names_type_unit
     */
    public function test_parent_level_label_names_the_product_unit(): void
    {
        $this->assertSame('상품 단위', $this->catalog('products')['parent_level_label'] ?? null);
        $this->assertSame('주문 단위', $this->catalog('orders')['parent_level_label'] ?? null);

        $product = ProductFactory::new()->create();
        ProductOptionFactory::new()->count(2)->create(['product_id' => $product->id]);

        app()->setLocale('ko');
        $id = (int) $this->actingAs($this->admin)->withHeaders(['Accept-Language' => 'ko'])->postJson($this->exportsUrl(), [
            'type' => 'products',
            'format' => 'csv',
            'column_mode' => 'custom',
            'fields' => ['product.name', 'product.stock_quantity', 'option.stock_quantity'],
            'row_unit' => 'option',
            'scope' => 'selected',
            'selected_ids' => [$product->id],
            'locale_mode' => 'locale',
            'include_totals' => false,
        ], ['Idempotency-Key' => 'labels-1'])->assertStatus(202)->json('data.id');
        app(ExportService::class)->generate($id);

        $content = Storage::disk('modules')->get($this->storedPath(Export::findOrFail($id)->file_path));
        $header = str_getcsv(explode("\r\n", substr($content, strlen(CsvExportWriter::BOM)))[0]);

        $this->assertContains('재고 수량 (상품 단위·첫 행에만 표시)', $header);
        $this->assertContains('옵션 재고 수량', $header);
        $this->assertSame([], array_values(array_filter($header, fn ($h) => str_contains($h, '주문 단위'))));
    }
}
