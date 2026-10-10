<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 API 문서 실측 샘플 정리 테스트
 *
 * 2026-10-09 실측(O-R2-6): 개발 DB 에 `api:docgen --seed` 의 이커머스 시더가 만든 샘플(카테고리 `apidoc-sample-category`,
 * 브랜드 `apidoc-sample-brand`, 쿠폰 「API 문서 샘플 쿠폰」, 추가배송비 템플릿 우편번호 00000)이 남아 있었다. 코어 정리가
 * 확장 시더의 생성분도 기록했다가 실측 뒤 지우므로, 이커머스 범위 실행이 끝나면 샘플과 시드 중 만든 행이 남지 않는지 고정한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/apidoc-sample-cleanup-ecommerce.yaml
 */
class ApiDocSampleCleanupEcommerceTest extends ModuleTestCase
{
    /**
     * 실행 전후로 비교할 테이블(접두 제외).
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'users', 'roles', 'menus', 'ecommerce_products', 'ecommerce_categories', 'ecommerce_brands', 'ecommerce_orders',
        'ecommerce_promotion_coupons', 'ecommerce_shipping_policies', 'ecommerce_shipping_carriers', 'ecommerce_claim_reasons',
        'ecommerce_product_labels', 'ecommerce_product_common_infos', 'ecommerce_product_notice_templates',
        'ecommerce_shipping_policy_extra_fee_templates', 'ecommerce_user_addresses',
    ];

    /**
     * 이커머스 범위 api:docgen --seed 가 끝나면 이커머스 샘플과 시드 중 만든 행이 남지 않는다.
     *
     * @scenario scope=module_ecommerce
     *
     * @effects ecommerce_apidoc_samples_removed_after_docgen_run
     *
     * @return void 반환값 없음
     */
    public function test_ecommerce_scope_docgen_seed_leaves_no_samples(): void
    {
        // 실측 요청은 외부로 나가지 않게, 시드가 일으킬 수 있는 메일·알림도 막는다
        Http::fake();
        Mail::fake();
        Notification::fake();
        $before = $this->tableCounts();

        Artisan::call('api:docgen', ['--scope' => 'module:sirsoft-ecommerce', '--check' => true, '--seed' => true]);
        $output = Artisan::output();

        // 이커머스 시더가 실제로 샘플을 만들었고(빈 실행이 아님) 정리가 그 행들을 지웠다
        $this->assertMatchesRegularExpression('/완전 샘플 시드: [1-9]\d*개 도메인 \(module:sirsoft-ecommerce\)/u', $output);
        preg_match('/완전 샘플 정리: (\d+)행/u', $output, $m);
        $this->assertGreaterThan(20, (int) ($m[1] ?? 0), '정리한 행 수가 너무 적다: '.$output);

        $this->assertSame(0, DB::table('ecommerce_categories')->where('slug', 'apidoc-sample-category')->count(), '샘플 카테고리가 남았다');
        $this->assertSame(0, DB::table('ecommerce_brands')->where('slug', 'apidoc-sample-brand')->count(), '샘플 브랜드가 남았다');
        $this->assertSame(0, DB::table('ecommerce_products')->where('product_code', 'APIDOCSAMPLE01')->count(), '샘플 상품이 남았다');
        $this->assertSame(0, DB::table('ecommerce_orders')->where('order_number', 'like', 'APIDOC-%')->count(), '샘플 주문이 남았다');
        $this->assertSame($before, $this->tableCounts(), '시드 중 만든 행이 남았다');
    }

    /**
     * 비교 대상 테이블의 행 수.
     *
     * @return array<string, int> 테이블 => 행 수
     */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
