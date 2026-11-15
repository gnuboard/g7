<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Services\CouponService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 숨김 상품 노출·쓰기 API 남용 막기 테스트
 *
 * 숨긴 상품은 상세·관련만이 아니라 그 아래 공개 API(후기·문의·받을 수 있는 쿠폰)와 새 찜도 막는다.
 * 회원 쓰기 API(쿠폰 받기·찜·문의 작성·후기 작성)에는 요청 제한을 건다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/hidden-product-and-write-abuse-guards.yaml
 */
class PublicProductHiddenSubresourceTest extends ModuleTestCase
{
    private const BASE = '/api/modules/sirsoft-ecommerce';

    private Product $hidden;

    private Product $visible;

    /**
     * 공개 상품 조회 권한과 숨김·전시 상품을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            ['name' => ['ko' => '상품 조회', 'en' => 'Read Products'], 'type' => PermissionType::User]
        );
        Role::where('identifier', 'user')->first()->permissions()->syncWithoutDetaching([$permission->id]);
        Role::where('identifier', 'guest')->first()?->permissions()->syncWithoutDetaching([$permission->id]);

        $this->hidden = Product::factory()->onSale()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $this->visible = Product::factory()->onSale()->create();
    }

    /**
     * 요청 제한 카운터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        RateLimiter::clear('');
        $this->app['cache']->store()->flush();

        parent::tearDown();
    }

    /**
     * 숨김 상품의 상세·관련·후기·문의 목록·받을 수 있는 쿠폰이 모두 404 이고, 전시 상품은 200 이다.
     *
     * @scenario guard=hidden_product_subresource, actor=guest
     *
     * @effects hidden_product_subresources_return_404, visible_product_subresources_still_open
     *
     * @return void 반환값 없음
     */
    public function test_hidden_product_subresources_return_404(): void
    {
        foreach (['', '/related', '/reviews', '/inquiries', '/downloadable-coupons'] as $suffix) {
            $this->getJson(self::BASE.'/products/'.$this->hidden->product_code.$suffix)
                ->assertStatus(404, '숨김 상품 '.($suffix ?: '상세'));
        }

        foreach (['/reviews', '/downloadable-coupons'] as $suffix) {
            $this->getJson(self::BASE.'/products/'.$this->visible->product_code.$suffix)->assertOk();
        }
    }

    /**
     * 숨김 상품에는 문의를 새로 쓸 수 없다.
     *
     * @scenario guard=hidden_product_subresource, actor=member
     *
     * @effects hidden_product_inquiry_write_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_hidden_product_rejects_new_inquiry(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'sanctum')
            ->postJson(self::BASE.'/products/'.$this->hidden->product_code.'/inquiries', ['title' => '문의', 'content' => '내용'])
            ->assertStatus(404);
    }

    /**
     * 숨김 상품은 새로 찜할 수 없고, 이미 찜한 숨김 상품은 해제할 수 있다.
     *
     * @scenario guard=wishlist_visibility, actor=member
     *
     * @effects hidden_product_new_wishlist_rejected, hidden_product_existing_wishlist_can_be_removed, visible_product_wishlist_still_works
     *
     * @return void 반환값 없음
     */
    public function test_wishlist_toggle_rejects_hidden_product_but_allows_removal(): void
    {
        $user = $this->createUser();
        $url = self::BASE.'/wishlist/toggle';

        $this->actingAs($user, 'sanctum')->postJson($url, ['product_id' => $this->hidden->id])->assertStatus(422);
        $this->assertSame(0, ProductWishlist::where('user_id', $user->id)->count());

        // 숨기기 전에 찜해 둔 상품
        ProductWishlist::create(['user_id' => $user->id, 'product_id' => $this->hidden->id]);
        $this->actingAs($user, 'sanctum')->postJson($url, ['product_id' => $this->hidden->id])
            ->assertOk()->assertJsonPath('data.added', false);
        $this->assertSame(0, ProductWishlist::where('user_id', $user->id)->count());

        $this->actingAs($user, 'sanctum')->postJson($url, ['product_id' => $this->visible->id])
            ->assertOk()->assertJsonPath('data.added', true);

        // 삭제한 상품도 새로 찜할 수 없다
        $deleted = Product::factory()->onSale()->create();
        $deleted->delete();
        $this->actingAs($user, 'sanctum')->postJson($url, ['product_id' => $deleted->id])->assertStatus(422);
    }

    /**
     * 회원 쓰기 API 네 종류가 분당 한도를 넘으면 429 를 준다 (쿠폰 받기는 단건·일괄이 한도를 나눠 쓴다).
     *
     * @scenario guard=write_rate_limit, actor=member
     *
     * @effects coupon_download_is_rate_limited_and_shared, wishlist_write_is_rate_limited, inquiry_write_is_rate_limited, review_write_is_rate_limited
     *
     * @return void 반환값 없음
     */
    public function test_member_write_apis_are_rate_limited(): void
    {
        config([
            'sirsoft-ecommerce.rate_limits.coupon_download' => 2,
            'sirsoft-ecommerce.rate_limits.wishlist_write' => 1,
            'sirsoft-ecommerce.rate_limits.inquiry_write' => 1,
            'sirsoft-ecommerce.rate_limits.review_write' => 1,
        ]);
        $token = $this->createUser()->createToken('abuse')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        // 쿠폰 받기 — 단건 1회 + 일괄 1회로 한도 2를 채우면 세 번째는 429
        $this->postJson(self::BASE.'/user/coupons/999999/download', [], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/user/coupons/bulk-download', ['coupon_ids' => [999999]], $headers)->assertOk();
        $this->postJson(self::BASE.'/user/coupons/bulk-download', ['coupon_ids' => [999999]], $headers)->assertStatus(429);

        // 찜 — 토글과 삭제가 한도를 나눠 쓴다
        $this->postJson(self::BASE.'/wishlist/toggle', ['product_id' => $this->visible->id], $headers)->assertOk();
        $this->deleteJson(self::BASE.'/wishlist/999999', [], $headers)->assertStatus(429);

        // 문의 작성 · 후기 작성 — 두 번째 요청은 본문 검증보다 먼저 429
        $inquiryUrl = self::BASE.'/products/'.$this->visible->product_code.'/inquiries';
        $this->assertNotSame(429, $this->postJson($inquiryUrl, [], $headers)->status());
        $this->postJson($inquiryUrl, [], $headers)->assertStatus(429);

        $this->assertNotSame(429, $this->postJson(self::BASE.'/user/reviews', [], $headers)->status());
        $this->postJson(self::BASE.'/user/reviews', [], $headers)->assertStatus(429);
    }

    /**
     * 관리자 직접 발급의 건너뜀 사유가 코드(reason)와 문구(message)로 나뉘어 온다.
     *
     * @scenario guard=direct_issue_reason_code, actor=admin
     *
     * @effects direct_issue_skip_carries_reason_code_and_message
     *
     * @return void 반환값 없음
     */
    public function test_direct_issue_skip_reports_reason_code_and_message(): void
    {
        $coupon = Coupon::create([
            'name' => ['ko' => '직접 발급 쿠폰', 'en' => 'Direct Coupon'],
            'description' => ['ko' => '설명', 'en' => 'Desc'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'issue_method' => CouponIssueMethod::DIRECT,
            'issue_condition' => CouponIssueCondition::MANUAL,
            'issue_status' => CouponIssueStatus::ISSUING,
            'issue_from' => Carbon::now()->subDay(),
            'issue_to' => Carbon::now()->addMonth(),
            'per_user_limit' => 1,
            'total_quantity' => 100,
            'issued_count' => 0,
            'valid_type' => 'period',
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
        ]);
        $user = $this->createUser();
        $service = app(CouponService::class);

        $first = $service->issueDirectly($coupon->id, [$user->id]);
        $this->assertSame(1, $first['issued']);

        $second = $service->issueDirectly($coupon->id, [$user->id]);
        $this->assertSame(0, $second['issued']);
        $this->assertSame($user->id, $second['skipped'][0]['user_id']);
        $this->assertSame('download_limit_exceeded', $second['skipped'][0]['reason']);
        $this->assertSame(
            __('sirsoft-ecommerce::messages.coupon.download_limit_exceeded'),
            $second['skipped'][0]['message']
        );
    }
}
