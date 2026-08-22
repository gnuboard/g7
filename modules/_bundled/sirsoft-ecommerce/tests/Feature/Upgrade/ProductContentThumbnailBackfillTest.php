<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_2_0;
use PHPUnit\Framework\Attributes\Test;

/**
 * 1.2.0 상품 설명 썸네일 백필 업그레이드 스텝 테스트 (공개 이슈 #22 동종)
 *
 * 검증 목적:
 * - 1.2.0 이전 등록 상품(캐시 null)이 다국어 설명 JSON 의 첫 내부 이미지로 백필된다
 * - 이미 값이 있는 행·text 모드·외부 이미지만 있는 행은 건드리지 않는다
 * - 재실행해도 결과가 동일하다 (멱등)
 *
 * @group ecommerce
 * @group upgrade
 */
class ProductContentThumbnailBackfillTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'ko']);
    }

    /**
     * 모델 이벤트를 우회해 레거시 상태 상품 행을 만듭니다 (업그레이드 전 등록분과 동일).
     *
     * @param  array  $attributes  덮어쓸 속성
     * @return int 상품 ID
     */
    private function createLegacyRow(array $attributes = []): int
    {
        return DB::table('ecommerce_products')->insertGetId(array_merge([
            'name' => json_encode(['ko' => '레거시 상품'], JSON_UNESCAPED_UNICODE),
            'product_code' => strtoupper(bin2hex(random_bytes(8))),
            'list_price' => 10000,
            'selling_price' => 10000,
            'stock_quantity' => 10,
            'safe_stock_quantity' => 1,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'tax_rate' => 10,
            'description' => json_encode(['ko' => '<p>설명</p>'], JSON_UNESCAPED_UNICODE),
            'description_mode' => 'html',
            'content_thumbnail_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function runBackfill(): void
    {
        $context = new UpgradeContext('1.1.2', '1.2.0', '1.2.0', 'extension-upgrade');

        (new Upgrade_1_2_0)->run($context);
    }

    /**
     * @scenario backfill=legacy_row
     *
     * @effects backfill_fills_legacy_products
     */
    #[Test]
    public function legacy_rows_are_backfilled_from_multilingual_description(): void
    {
        $legacy = $this->createLegacyRow([
            'description' => json_encode([
                'ko' => '<p>글</p><img src="https://evil.example.org/skip.jpg"><img src="/storage/products/legacy.jpg">',
                'en' => '<img src="/storage/products/en.jpg">',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->runBackfill();

        $this->assertSame(
            '/storage/products/legacy.jpg',
            DB::table('ecommerce_products')->where('id', $legacy)->value('content_thumbnail_url'),
            '기본 로케일의 첫 내부 이미지가 백필되어야 합니다 (외부 후보 건너뜀).'
        );
    }

    /**
     * @scenario backfill=text_mode
     *
     * @effects text_mode_description_never_caches
     */
    #[Test]
    public function text_mode_and_filled_and_external_rows_are_untouched(): void
    {
        $textMode = $this->createLegacyRow([
            'description_mode' => 'text',
            'description' => json_encode(['ko' => '텍스트 <img src="/storage/products/literal.jpg">'], JSON_UNESCAPED_UNICODE),
        ]);
        $filled = $this->createLegacyRow([
            'description' => json_encode(['ko' => '<img src="/storage/products/other.jpg">'], JSON_UNESCAPED_UNICODE),
            'content_thumbnail_url' => '/storage/products/kept.jpg',
        ]);
        $external = $this->createLegacyRow([
            'description' => json_encode(['ko' => '<img src="https://evil.example.org/x.jpg">'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->runBackfill();

        $this->assertNull(DB::table('ecommerce_products')->where('id', $textMode)->value('content_thumbnail_url'));
        $this->assertSame('/storage/products/kept.jpg', DB::table('ecommerce_products')->where('id', $filled)->value('content_thumbnail_url'));
        $this->assertNull(DB::table('ecommerce_products')->where('id', $external)->value('content_thumbnail_url'));
    }

    /**
     * @scenario backfill=idempotent
     *
     * @effects backfill_idempotent_second_run
     */
    #[Test]
    public function second_run_is_idempotent(): void
    {
        $this->createLegacyRow([
            'description' => json_encode(['ko' => '<img src="/storage/products/a.jpg">'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->createLegacyRow([
            'description' => json_encode(['ko' => '<p>이미지 없음</p>'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->runBackfill();
        $first = $this->snapshot();

        $this->runBackfill();
        $second = $this->snapshot();

        $this->assertSame($first, $second, '재실행 결과가 동일해야 합니다.');
    }

    /**
     * @return array<int, array{id: int, content_thumbnail_url: string|null}> 스냅샷
     */
    private function snapshot(): array
    {
        return DB::table('ecommerce_products')
            ->orderBy('id')
            ->get(['id', 'content_thumbnail_url'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'content_thumbnail_url' => $row->content_thumbnail_url])
            ->all();
    }
}
