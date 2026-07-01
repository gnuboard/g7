<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 목록 검색 컬럼 한정 (A18①)
 *
 * search_field=name → name 단일컬럼, description → description 단일컬럼,
 * all → name+description, created_by → creator. 선택 필드가 무시되지 않는지 검증.
 */
class CouponSearchFieldTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
        ]);
    }

    private function createCoupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '설명입니다', 'en' => 'Description'],
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'target_scope' => CouponTargetScope::ALL->value,
            'created_by' => $this->adminUser->id,
        ], $attributes));
    }

    /**
     * @return array 응답 data 배열
     */
    private function search(string $field, string $keyword): array
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?'.http_build_query([
                'search_field' => $field,
                'search_keyword' => $keyword,
            ]));

        $response->assertOk();

        return $response->json('data.data') ?? [];
    }

    private function idsOf(array $data): array
    {
        return array_map(fn ($row) => $row['id'], $data);
    }

    /**
     * #1 all: name 단일컬럼 검색에서 제외되는 등록자 키워드도 all 에서는 매칭(union 폭 보존)
     *
     * name/description FULLTEXT 매칭은 MySQL 토큰화(min token size/한글) 특성상 단위 테스트
     * 환경에서 단어 단위로만 검증 가능하므로, all 분기의 핵심인 "creator union 포함"을 검증한다.
     * name/description 컬럼 한정(A18① 결함)은 #2/#3 (LIKE) 가 직접 입증한다.
     */
    public function test_all_includes_creator_union(): void
    {
        $creator = User::factory()->create(['name' => 'AllUnionCreatorMP10']);
        $coupon = $this->createCoupon([
            'name' => ['ko' => '유니온쿠폰', 'en' => 'UnionCoupon'],
            'created_by' => $creator->id,
        ]);

        // all 은 creator 까지 union → 등록자 키워드로 매칭
        $this->assertContains($coupon->id, $this->idsOf($this->search('all', 'AllUnionCreatorMP10')));
        // name 단일컬럼 검색에서는 등록자 키워드로 매칭되지 않음 (컬럼 한정 입증)
        $this->assertNotContains($coupon->id, $this->idsOf($this->search('name', 'AllUnionCreatorMP10')));
    }

    /**
     * #2 name: 이름 단어 매칭, 설명에만 있는 단어는 0건 (핵심)
     */
    public function test_name_field_excludes_description_match(): void
    {
        $coupon = $this->createCoupon([
            'name' => ['ko' => '여름쿠폰바겐', 'en' => 'SummerBargain'],
            'description' => ['ko' => '가을세일설명', 'en' => 'AutumnSale'],
        ]);

        $this->assertContains($coupon->id, $this->idsOf($this->search('name', '여름쿠폰바겐')));
        $this->assertNotContains($coupon->id, $this->idsOf($this->search('name', '가을세일설명')));
    }

    /**
     * #3 description: 설명 단어 매칭, 이름에만 있는 단어는 0건 (핵심)
     */
    public function test_description_field_excludes_name_match(): void
    {
        $coupon = $this->createCoupon([
            'name' => ['ko' => '겨울쿠폰특가', 'en' => 'WinterDeal'],
            'description' => ['ko' => '봄세일설명', 'en' => 'SpringSale'],
        ]);

        $this->assertContains($coupon->id, $this->idsOf($this->search('description', '봄세일설명')));
        $this->assertNotContains($coupon->id, $this->idsOf($this->search('description', '겨울쿠폰특가')));
    }

    /**
     * #4 created_by: creator 매칭 (name/description 무관)
     */
    public function test_created_by_matches_creator(): void
    {
        $creator = User::factory()->create(['name' => 'UniqueCreatorMP10']);
        $coupon = $this->createCoupon([
            'name' => ['ko' => '무관쿠폰', 'en' => 'Irrelevant'],
            'created_by' => $creator->id,
        ]);

        $this->assertContains($coupon->id, $this->idsOf($this->search('created_by', 'UniqueCreatorMP10')));
    }
}
