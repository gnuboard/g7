<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Security;

use App\Enums\PermissionType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\ExtraFeeTemplateService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 서비스 계층 스코프 게이트 재적용 (KVE-2026-1919 형제)
 *
 * `PermissionMiddleware` 는 라우트 파라미터가 Model 로 resolve 될 때만 스코프를 검사하고,
 * 아니면 "목록 엔드포인트" 로 보아 건너뛴다. 이 모듈에는 두 형태가 모두 있었다:
 *
 * 1. 정적 경로 — `bulk-*` 처럼 리소스 파라미터 자체가 없음
 * 2. 파라미터명 불일치 — 권한은 `shippingPolicy`/`coupon` 을 가리키는데 라우트는 `{id}`
 *    라 바인딩이 안 일어남. 이쪽은 **상세 경로까지** 무가드였다.
 *
 * 조사 시점 이 모듈 전체의 `checkScopeAccess`/`filterByScope` 호출은 **0건**이었다 —
 * 미들웨어가 스킵되면 어떤 2차 방어도 없었다는 뜻이다.
 *
 * 대표 경로(상세 1 + 일괄 1)를 고정한다. 나머지 서비스도 같은 trait 을 쓴다.
 */
class ServiceScopeReapplyTest extends ModuleTestCase
{
    /**
     * 지정한 스코프로 권한을 보유한 관리자를 만듭니다.
     *
     * @param  string  $identifier  권한 식별자
     * @param  string|null  $scope  스코프 유형 (self / role / null=전체)
     * @param  User|null  $user  권한을 줄 기존 회원 (null 이면 새로 만든다)
     * @return User 권한을 받은 관리자
     */
    private function adminWithScope(string $identifier, ?string $scope, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $role = Role::create([
            'identifier' => 'scoped-'.$user->id.'-'.uniqid(),
            'name' => ['ko' => '스코프 관리자', 'en' => 'Scoped Admin'],
        ]);
        $user->roles()->attach($role->id);

        foreach (['admin.access', $identifier] as $key) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $key],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => PermissionType::Admin]
            );

            if ($key === $identifier) {
                // 스코프 대상 권한임을 명시 — module.php 카테고리 단위 선언과 같은 메타데이터
                $permission->update(self::scopeMetaFor($identifier));
            }

            $role->permissions()->syncWithoutDetaching([
                $permission->id => ['scope_type' => $key === $identifier ? $scope : null],
            ]);
        }

        // PermissionHelper 정적 캐시 초기화 (다른 액터의 판정이 남지 않도록)
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        return $user->fresh();
    }

    /**
     * self 스코프 관리자는 남이 만든 배송정책을 수정할 수 없다(파라미터명 불일치 경로).
     *
     * @scenario route_shape=param_name_mismatch
     *
     * @effects detail_route_reapplies_scope_gate_when_binding_absent
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_admin_cannot_update_others_shipping_policy(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', 'self');
        $owner = User::factory()->create();
        $this->actingAs($actor);

        $policy = $this->makePolicy($owner->id);

        $this->expectException(AccessDeniedHttpException::class);

        app(ShippingPolicyService::class)->update($policy, ['name' => ['ko' => '변경', 'en' => 'Changed']]);
    }

    /**
     * (과차단 회귀) 자기 소유 대상은 그대로 수정할 수 있어야 한다.
     *
     * @scenario route_shape=param_name_mismatch
     *
     * @effects detail_route_allows_in_scope_target
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_admin_can_update_own_shipping_policy(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', 'self');
        $this->actingAs($actor);

        $policy = $this->makePolicy($actor->id);

        $updated = app(ShippingPolicyService::class)->update($policy, [
            'name' => ['ko' => '변경', 'en' => 'Changed'],
        ]);

        $this->assertNotNull($updated, '자기 소유 정책은 수정할 수 있어야 합니다');
    }

    /**
     * self 스코프 관리자가 남의 추가배송비 템플릿이 섞인 일괄 토글을 보내면 전량 거부된다(정적 일괄 경로).
     *
     * @scenario route_shape=static_bulk
     *
     * @effects static_bulk_route_reapplies_scope_gate
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_admin_cannot_bulk_toggle_others_extra_fee_templates(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', 'self');
        $owner = User::factory()->create();
        $this->actingAs($actor);

        $mine = $this->makeTemplate($actor->id, '63001');
        $theirs = $this->makeTemplate($owner->id, '63002');

        try {
            app(ExtraFeeTemplateService::class)->bulkToggleActive([$mine->id, $theirs->id], false);
            $this->fail('스코프 밖 대상이 섞이면 거부되어야 합니다');
        } catch (AccessDeniedHttpException) {
            // 기대 경로
        }

        // 전량 거부 — 내 것도 바뀌지 않아야 한다.
        $this->assertTrue((bool) $mine->fresh()->is_active, '거부된 일괄 요청은 아무것도 바꾸지 않아야 합니다');
        $this->assertTrue((bool) $theirs->fresh()->is_active);
    }

    /**
     * (과차단 회귀) 글로벌 스코프 액터의 일괄 작업은 종전대로 동작해야 한다.
     *
     * @scenario route_shape=static_bulk
     *
     * @effects static_bulk_route_allows_global_scoped_actor
     *
     * @return void 반환값 없음
     */
    public function test_global_scoped_admin_can_bulk_toggle_any_extra_fee_template(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', null);
        $owner = User::factory()->create();
        $this->actingAs($actor);

        $theirs = $this->makeTemplate($owner->id, '63003');

        $count = app(ExtraFeeTemplateService::class)->bulkToggleActive([$theirs->id], false);

        $this->assertSame(1, $count);
        $this->assertFalse((bool) $theirs->fresh()->is_active);
    }

    /**
     * (계약 표면) 스코프 거부는 HTTP 403 으로 표면화되어야 한다.
     *
     * 서비스가 던지는 AccessDeniedHttpException 이 컨트롤러 generic catch 에 삼켜지면
     * 권한 거부가 500 서버 장애로 위장된다 — 서비스 층 단언만으로는 이 회귀를 못 본다.
     *
     * @scenario route_shape=static_bulk
     *
     * @effects scope_denial_surfaces_as_403_over_http
     *
     * @return void 반환값 없음
     */
    public function test_scope_denied_bulk_toggle_surfaces_as_403_over_http(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', 'self');
        $owner = User::factory()->create();

        $mine = $this->makeTemplate($actor->id, '63004');
        $theirs = $this->makeTemplate($owner->id, '63005');

        $response = $this->actingAs($actor)->patchJson(
            '/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk-toggle-active',
            ['ids' => [$mine->id, $theirs->id], 'is_active' => false]
        );

        $response->assertStatus(403);

        // 전량 거부 — 상태 불변까지 단언한다.
        $this->assertTrue((bool) $mine->fresh()->is_active);
        $this->assertTrue((bool) $theirs->fresh()->is_active);
    }

    /**
     * (계약 복원) 존재하지 않는 대상은 스코프 거부가 아니라 not_found 400 으로 응답한다.
     *
     * 스코프 게이트 도입이 "리소스 부재 = 스코프 거부(→ 500 위장)" 로 기존 400 계약을
     * 바꾸면 안 된다.
     *
     * @scenario route_shape=param_name_mismatch
     *
     * @effects missing_target_keeps_not_found_contract
     *
     * @return void 반환값 없음
     */
    public function test_missing_brand_returns_not_found_not_scope_denied(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.brands.delete', null);

        $response = $this->actingAs($actor)->deleteJson(
            '/api/modules/sirsoft-ecommerce/admin/brands/999999'
        );

        $response->assertStatus(400);
    }

    /**
     * 권한 식별자 접두 → module.php 카테고리 단위 스코프 메타.
     *
     * 테스트 DB 는 module.php 권한을 동기화하지 않으므로 같은 값을 직접 넣는다.
     */
    private const SCOPE_META = [
        'sirsoft-ecommerce.products.' => ['resource_route_key' => 'product', 'owner_key' => 'created_by'],
        'sirsoft-ecommerce.brands.' => ['resource_route_key' => 'brand', 'owner_key' => 'created_by'],
        'sirsoft-ecommerce.promotion-coupon.' => ['resource_route_key' => 'coupon', 'owner_key' => 'created_by'],
        'sirsoft-ecommerce.shipping-policies.' => ['resource_route_key' => 'shippingPolicy', 'owner_key' => 'created_by'],
        'sirsoft-ecommerce.reviews.' => ['resource_route_key' => 'review', 'owner_key' => 'user_id'],
        'sirsoft-ecommerce.wishlists.' => ['resource_route_key' => 'wishlist', 'owner_key' => 'user_id'],
        'sirsoft-ecommerce.carts.' => ['resource_route_key' => 'cart', 'owner_key' => 'user_id'],
        'sirsoft-ecommerce.user-addresses.' => ['resource_route_key' => 'user-address', 'owner_key' => 'user_id'],
        'sirsoft-ecommerce.mileage.' => ['resource_route_key' => 'mileage-transaction', 'owner_key' => 'user_id'],
    ];

    /**
     * 권한 식별자에 맞는 스코프 메타를 돌려줍니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return array{resource_route_key: string, owner_key: string} 스코프 메타
     */
    private static function scopeMetaFor(string $identifier): array
    {
        foreach (self::SCOPE_META as $prefix => $meta) {
            if (str_starts_with($identifier, $prefix)) {
                return $meta;
            }
        }

        throw new \LogicException("스코프 메타 미정의 권한: {$identifier}");
    }

    /**
     * (E1~E9) 상세·단건·일괄 경로 — 라우트가 `{id}`·정적 경로라 미들웨어가 건너뛰는 곳.
     *
     * @return array<string, array{0: string}> 사례 이름 → 사례 키
     */
    public static function deniedTargetCases(): array
    {
        return [
            'E1 상품 상세' => ['e1_product_show'],
            'E1 상품 코드 상세' => ['e1_product_show_by_code'],
            'E2 이미지 업로드(상품 있음)' => ['e2_image_upload'],
            'E2 이미지 삭제' => ['e2_image_delete'],
            'E2 이미지 순서' => ['e2_image_reorder'],
            'E2 대표 이미지' => ['e2_image_thumbnail'],
            'E3 옵션 일괄 가격(상품 ID)' => ['e3_option_bulk_price'],
            'E3 옵션 일괄 가격(옵션 ID 상품 위조)' => ['e3_option_bulk_price_spoofed'],
            'E3 옵션 일괄 재고' => ['e3_option_bulk_stock'],
            'E3 옵션 통합 일괄' => ['e3_option_bulk_update'],
            'E4 브랜드 상세' => ['e4_brand_show'],
            'E5 쿠폰 상세' => ['e5_coupon_show'],
            'E5 쿠폰 발급 내역' => ['e5_coupon_issues'],
            'E5 쿠폰 발급 취소' => ['e5_coupon_cancel_issue'],
            'E6 배송정책 상세' => ['e6_policy_show'],
            'E6 배송정책 클레임 설정' => ['e6_policy_claim_settings'],
            'E6 추가배송비 템플릿 상세' => ['e6_extra_fee_show'],
            'E8 찜 단건 삭제' => ['e8_wishlist_destroy'],
            'E8 찜 일괄 삭제' => ['e8_wishlist_bulk'],
            'E8 장바구니 단건 삭제' => ['e8_cart_destroy'],
            'E8 장바구니 일괄 삭제' => ['e8_cart_bulk'],
            'E8 배송지 단건 삭제' => ['e8_address_destroy'],
            'E8 배송지 일괄 삭제' => ['e8_address_bulk'],
            'E9 마일리지 적립건 편집' => ['e9_mileage_update'],
            'E9 마일리지 연결 거래' => ['e9_mileage_linked'],
            'E9 마일리지 수동 지급' => ['e9_mileage_store'],
            'E9 마일리지 유효기간 연장' => ['e9_mileage_extend'],
        ];
    }

    /**
     * (E1~E9) self 스코프 관리자는 남의 대상을 읽거나 바꿀 수 없다 — 403, 상태 불변.
     *
     * @scenario gap=E1|E2|E3|E4|E5|E6|E8|E9, scope=self, target=others, surface=detail_or_bulk
     *
     * @effects unbound_route_reapplies_scope_in_service, scope_denial_surfaces_as_403_not_500, denied_request_changes_nothing
     *
     * @param  string  $case  사례 키
     * @return void 반환값 없음
     */
    #[DataProvider('deniedTargetCases')]
    public function test_self_scoped_admin_is_denied_on_others_target(string $case): void
    {
        $owner = User::factory()->create();
        $scenario = $this->targetScenario($case, $owner);
        $actor = $this->adminWithScope($scenario['permission'], 'self');

        $response = ($scenario['send'])($this->actingAs($actor));

        $response->assertStatus(403);
        if (isset($scenario['unchanged'])) {
            ($scenario['unchanged'])();
        }
    }

    /**
     * (과차단 회귀) self 스코프 관리자는 자기 대상에는 그대로 접근한다.
     *
     * @scenario gap=E1|E2|E3|E4|E5|E6|E8|E9, scope=self, target=own, surface=detail_or_bulk
     *
     * @effects in_scope_target_still_allowed
     *
     * @param  string  $case  사례 키
     * @return void 반환값 없음
     */
    #[DataProvider('deniedTargetCases')]
    public function test_self_scoped_admin_keeps_access_to_own_target(string $case): void
    {
        $actor = User::factory()->create();
        $scenario = $this->targetScenario($case, $actor);
        $this->adminWithScope($scenario['permission'], 'self', $actor);

        $response = ($scenario['send'])($this->actingAs($actor->fresh()));

        $this->assertTrue(
            $response->status() >= 200 && $response->status() < 300,
            "자기 대상은 허용되어야 합니다 ({$case}): {$response->status()} ".$response->getContent()
        );
    }

    /**
     * (E7·E8·E9) 관리자 목록 — 스코프 밖 행은 걸러진다.
     *
     * @return array<string, array{0: string}> 사례 이름 → 사례 키
     */
    public static function scopedListCases(): array
    {
        return [
            'E7 관리자 리뷰 목록' => ['e7_review_list'],
            'E8 찜 목록' => ['e8_wishlist_list'],
            'E8 장바구니 목록' => ['e8_cart_list'],
            'E8 배송지 목록' => ['e8_address_list'],
            'E9 마일리지 내역 목록' => ['e9_mileage_list'],
            'N1 추가배송비 템플릿 목록(원장 감사 발견)' => ['n1_extra_fee_list'],
        ];
    }

    /**
     * (E7·E8·E9) self 스코프 관리자의 목록에는 자기 행만 남는다.
     *
     * @scenario gap=E7|E8|E9|N1, scope=self, target=others, surface=list
     *
     * @effects admin_list_filters_out_of_scope_rows, admin_list_keeps_in_scope_rows
     *
     * @param  string  $case  사례 키
     * @return void 반환값 없음
     */
    #[DataProvider('scopedListCases')]
    public function test_self_scoped_admin_list_hides_others_rows(string $case): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $scenario = $this->listScenario($case, $actor, $other);
        $this->adminWithScope($scenario['permission'], 'self', $actor);

        $response = $this->actingAs($actor->fresh())->getJson($scenario['uri']);

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($scenario['mine'], $ids, '자기 행은 남아야 합니다');
        $this->assertNotContains($scenario['theirs'], $ids, '스코프 밖 행은 걸러져야 합니다');
    }

    /**
     * (과차단 회귀) 전체 스코프 관리자의 목록은 종전대로 모든 행을 본다.
     *
     * @scenario gap=E7|E8|E9|N1, scope=all, target=others, surface=list
     *
     * @effects admin_list_unscoped_actor_sees_all_rows
     *
     * @param  string  $case  사례 키
     * @return void 반환값 없음
     */
    #[DataProvider('scopedListCases')]
    public function test_global_scoped_admin_list_sees_all_rows(string $case): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $scenario = $this->listScenario($case, $actor, $other);
        $this->adminWithScope($scenario['permission'], null, $actor);

        $ids = collect($this->actingAs($actor->fresh())->getJson($scenario['uri'])->assertOk()->json('data.data'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($scenario['mine'], $ids);
        $this->assertContains($scenario['theirs'], $ids);
    }

    /**
     * 사례 키에 맞는 대상과 요청을 준비합니다.
     *
     * @param  string  $case  사례 키
     * @param  User  $owner  대상 소유자
     * @return array{permission: string, send: \Closure, unchanged?: \Closure} 권한·요청·상태 불변 단언
     */
    private function targetScenario(string $case, User $owner): array
    {
        $api = '/api/modules/sirsoft-ecommerce/admin';

        switch ($case) {
            case 'e1_product_show':
                $product = Product::factory()->create(['created_by' => $owner->id]);

                return ['permission' => 'sirsoft-ecommerce.products.read',
                    'send' => fn ($t) => $t->getJson("{$api}/products/{$product->id}")];

            case 'e1_product_show_by_code':
                $product = Product::factory()->create(['created_by' => $owner->id]);

                return ['permission' => 'sirsoft-ecommerce.products.read',
                    'send' => fn ($t) => $t->getJson("{$api}/products/by-code/{$product->product_code}")];

            case 'e2_image_upload':
                Storage::fake('local');
                Storage::fake('public');
                $product = Product::factory()->create(['created_by' => $owner->id]);

                return ['permission' => 'sirsoft-ecommerce.products.update',
                    'send' => fn ($t) => $t->post("{$api}/products/{$product->id}/images", [
                        'file' => UploadedFile::fake()->image('scope.jpg', 10, 10),
                        'collection' => 'main',
                    ], ['Accept' => 'application/json']),
                    'unchanged' => fn () => $this->assertSame(0, ProductImage::where('product_id', $product->id)->count())];

            case 'e2_image_delete':
                $image = $this->makeImage(Product::factory()->create(['created_by' => $owner->id]), true);

                return ['permission' => 'sirsoft-ecommerce.products.update',
                    'send' => fn ($t) => $t->deleteJson("{$api}/products/images/{$image->id}"),
                    'unchanged' => fn () => $this->assertNotNull($image->fresh())];

            case 'e2_image_reorder':
                $image = $this->makeImage(Product::factory()->create(['created_by' => $owner->id]), true);

                return ['permission' => 'sirsoft-ecommerce.products.update',
                    'send' => fn ($t) => $t->patchJson("{$api}/products/images/reorder", ['order' => [['id' => $image->id, 'order' => 7]]]),
                    'unchanged' => fn () => $this->assertSame(0, (int) $image->fresh()->sort_order)];

            case 'e2_image_thumbnail':
                $product = Product::factory()->create(['created_by' => $owner->id]);
                $this->makeImage($product, true);
                $target = $this->makeImage($product, false);

                return ['permission' => 'sirsoft-ecommerce.products.update',
                    'send' => fn ($t) => $t->patchJson("{$api}/products/{$product->id}/images/{$target->id}/thumbnail"),
                    'unchanged' => fn () => $this->assertFalse((bool) $target->fresh()->is_thumbnail)];

            case 'e3_option_bulk_price':
                $option = ProductOption::factory()->create([
                    'product_id' => Product::factory()->create(['created_by' => $owner->id])->id,
                    'price_adjustment' => 0,
                ]);

                return ['permission' => 'sirsoft-ecommerce.products.update',
                    'send' => fn ($t) => $t->patchJson("{$api}/options/bulk-price", [
                        'product_ids' => [$option->product_id], 'method' => 'increase', 'value' => 100, 'unit' => 'won',
                    ]),
                    'unchanged' => fn () => $this->assertEquals(0, (float) $option->fresh()->price_adjustment)];

            case 'e3_option_bulk_price_spoofed':
                // 「상품ID-옵션ID」 의 상품 자리는 요청자가 쓴 값이다 — 판정은 옵션의 실제 상품으로 해야 한다.
                $option = ProductOption::factory()->create([
                    'product_id' => Product::factory()->create(['created_by' => $owner->id])->id,
                    'price_adjustment' => 0,
                ]);

                return ['permission' => 'sirsoft-ecommerce.products.update',
                    'send' => function ($t) use ($api, $option) {
                        $mine = Product::factory()->create(['created_by' => auth()->id()]);

                        return $t->patchJson("{$api}/options/bulk-price", [
                            'option_ids' => ["{$mine->id}-{$option->id}"], 'method' => 'increase', 'value' => 100, 'unit' => 'won',
                        ]);
                    },
                    'unchanged' => fn () => $this->assertEquals(0, (float) $option->fresh()->price_adjustment)];

            case 'e3_option_bulk_stock':
                $option = ProductOption::factory()->create([
                    'product_id' => Product::factory()->create(['created_by' => $owner->id])->id,
                    'stock_quantity' => 5,
                ]);

                return ['permission' => 'sirsoft-ecommerce.products.update',
                    'send' => fn ($t) => $t->patchJson("{$api}/options/bulk-stock", [
                        'product_ids' => [$option->product_id], 'method' => 'set', 'value' => 99,
                    ]),
                    'unchanged' => fn () => $this->assertSame(5, (int) $option->fresh()->stock_quantity)];

            case 'e3_option_bulk_update':
                $option = ProductOption::factory()->create([
                    'product_id' => Product::factory()->create(['created_by' => $owner->id])->id,
                    'stock_quantity' => 5,
                ]);

                return ['permission' => 'sirsoft-ecommerce.products.update',
                    'send' => fn ($t) => $t->patchJson("{$api}/options/bulk-update", [
                        'ids' => ["{$option->product_id}-{$option->id}"],
                        'bulk_changes' => ['stock_quantity' => ['method' => 'set', 'value' => 99]],
                    ]),
                    'unchanged' => fn () => $this->assertSame(5, (int) $option->fresh()->stock_quantity)];

            case 'e4_brand_show':
                $brand = Brand::create([
                    'name' => ['ko' => '브랜드', 'en' => 'Brand'], 'slug' => 'scope-'.uniqid(),
                    'is_active' => true, 'sort_order' => 1, 'created_by' => $owner->id,
                ]);

                return ['permission' => 'sirsoft-ecommerce.brands.read',
                    'send' => fn ($t) => $t->getJson("{$api}/brands/{$brand->id}")];

            case 'e5_coupon_show':
                $coupon = $this->makeCoupon($owner->id);

                return ['permission' => 'sirsoft-ecommerce.promotion-coupon.read',
                    'send' => fn ($t) => $t->getJson("{$api}/promotion-coupons/{$coupon->id}")];

            case 'e5_coupon_issues':
                $coupon = $this->makeCoupon($owner->id);

                return ['permission' => 'sirsoft-ecommerce.promotion-coupon.read',
                    'send' => fn ($t) => $t->getJson("{$api}/promotion-coupons/{$coupon->id}/issues")];

            case 'e5_coupon_cancel_issue':
                $coupon = $this->makeCoupon($owner->id, 1);
                $issue = CouponIssue::create([
                    'coupon_id' => $coupon->id, 'user_id' => User::factory()->create()->id,
                    'coupon_code' => 'SCOPE-'.uniqid(), 'status' => CouponIssueRecordStatus::AVAILABLE->value,
                    'issued_at' => now(),
                ]);

                return ['permission' => 'sirsoft-ecommerce.promotion-coupon.update',
                    'send' => fn ($t) => $t->deleteJson("{$api}/promotion-coupons/{$coupon->id}/issues/{$issue->id}"),
                    'unchanged' => fn () => $this->assertSame(CouponIssueRecordStatus::AVAILABLE, $issue->fresh()->status)];

            case 'e6_policy_show':
                $policy = $this->makePolicy($owner->id);

                return ['permission' => 'sirsoft-ecommerce.shipping-policies.read',
                    'send' => fn ($t) => $t->getJson("{$api}/shipping-policies/{$policy->id}")];

            case 'e6_policy_claim_settings':
                $policy = $this->makePolicy($owner->id);

                return ['permission' => 'sirsoft-ecommerce.shipping-policies.read',
                    'send' => fn ($t) => $t->getJson("{$api}/shipping-policies/{$policy->id}/claim-settings")];

            case 'e6_extra_fee_show':
                $template = $this->makeTemplate($owner->id, (string) random_int(10000, 99999));

                return ['permission' => 'sirsoft-ecommerce.shipping-policies.read',
                    'send' => fn ($t) => $t->getJson("{$api}/extra-fee-templates/{$template->id}")];

            case 'e8_wishlist_destroy':
            case 'e8_wishlist_bulk':
                $id = $this->makeWishlist($owner);

                return ['permission' => 'sirsoft-ecommerce.wishlists.manage',
                    'send' => fn ($t) => $case === 'e8_wishlist_bulk'
                        ? $t->deleteJson("{$api}/wishlists/bulk", ['ids' => [$id]])
                        : $t->deleteJson("{$api}/wishlists/{$id}"),
                    'unchanged' => fn () => $this->assertTrue(DB::table('ecommerce_product_wishlists')->where('id', $id)->exists())];

            case 'e8_cart_destroy':
            case 'e8_cart_bulk':
                $cart = CartFactory::new()->forUser($owner)->create();

                return ['permission' => 'sirsoft-ecommerce.carts.manage',
                    'send' => fn ($t) => $case === 'e8_cart_bulk'
                        ? $t->deleteJson("{$api}/carts/bulk", ['ids' => [$cart->id]])
                        : $t->deleteJson("{$api}/carts/{$cart->id}"),
                    'unchanged' => fn () => $this->assertNotNull(Cart::find($cart->id))];

            case 'e8_address_destroy':
            case 'e8_address_bulk':
                $address = UserAddress::factory()->create(['user_id' => $owner->id]);

                return ['permission' => 'sirsoft-ecommerce.user-addresses.manage',
                    'send' => fn ($t) => $case === 'e8_address_bulk'
                        ? $t->deleteJson("{$api}/user-addresses/bulk", ['ids' => [$address->id]])
                        : $t->deleteJson("{$api}/user-addresses/{$address->id}"),
                    'unchanged' => fn () => $this->assertNotNull(UserAddress::find($address->id))];

            case 'e9_mileage_update':
                $tx = $this->makeMileageEarn($owner);

                return ['permission' => 'sirsoft-ecommerce.mileage.manage',
                    'send' => fn ($t) => $t->patchJson("{$api}/mileage-transactions/{$tx->id}", ['memo' => '범위 밖 편집']),
                    'unchanged' => fn () => $this->assertNull($tx->fresh()->memo)];

            case 'e9_mileage_linked':
                $tx = $this->makeMileageEarn($owner);

                return ['permission' => 'sirsoft-ecommerce.mileage.read',
                    'send' => fn ($t) => $t->getJson("{$api}/mileage-transactions/{$tx->id}/linked")];

            case 'e9_mileage_store':
                return ['permission' => 'sirsoft-ecommerce.mileage.manage',
                    'send' => fn ($t) => $t->postJson("{$api}/mileage-transactions", [
                        'user_id' => $owner->uuid, 'action' => 'earn', 'amount' => 100, 'currency' => 'KRW',
                    ]),
                    'unchanged' => fn () => $this->assertSame(0, MileageTransaction::where('user_id', $owner->id)->count())];

            case 'e9_mileage_extend':
                $tx = $this->makeMileageEarn($owner, now()->addDays(10));
                $before = $tx->expires_at->toDateTimeString();

                return ['permission' => 'sirsoft-ecommerce.mileage.manage',
                    'send' => fn ($t) => $t->postJson("{$api}/mileage-transactions/extend-expiry", [
                        'lot_ids' => [$tx->id], 'days' => 30,
                    ]),
                    'unchanged' => fn () => $this->assertSame($before, $tx->fresh()->expires_at->toDateTimeString())];
        }

        throw new \LogicException("알 수 없는 사례: {$case}");
    }

    /**
     * 목록 사례의 자기 행·남의 행과 요청 주소를 준비합니다.
     *
     * @param  string  $case  사례 키
     * @param  User  $actor  요청 관리자 (자기 행 소유자)
     * @param  User  $other  남의 행 소유자
     * @return array{permission: string, uri: string, mine: int, theirs: int} 권한·주소·행 ID
     */
    private function listScenario(string $case, User $actor, User $other): array
    {
        $api = '/api/modules/sirsoft-ecommerce/admin';

        return match ($case) {
            'e7_review_list' => [
                'permission' => 'sirsoft-ecommerce.reviews.read', 'uri' => "{$api}/reviews",
                'mine' => (int) ProductReview::factory()->create(['user_id' => $actor->id])->id,
                'theirs' => (int) ProductReview::factory()->create(['user_id' => $other->id])->id,
            ],
            'e8_wishlist_list' => [
                'permission' => 'sirsoft-ecommerce.wishlists.read', 'uri' => "{$api}/wishlists",
                'mine' => $this->makeWishlist($actor), 'theirs' => $this->makeWishlist($other),
            ],
            'e8_cart_list' => [
                'permission' => 'sirsoft-ecommerce.carts.read', 'uri' => "{$api}/carts",
                'mine' => (int) CartFactory::new()->forUser($actor)->create()->id,
                'theirs' => (int) CartFactory::new()->forUser($other)->create()->id,
            ],
            'e8_address_list' => [
                'permission' => 'sirsoft-ecommerce.user-addresses.read', 'uri' => "{$api}/user-addresses",
                'mine' => (int) UserAddress::factory()->create(['user_id' => $actor->id])->id,
                'theirs' => (int) UserAddress::factory()->create(['user_id' => $other->id])->id,
            ],
            'e9_mileage_list' => [
                'permission' => 'sirsoft-ecommerce.mileage.read', 'uri' => "{$api}/mileage-transactions",
                'mine' => (int) $this->makeMileageEarn($actor)->id, 'theirs' => (int) $this->makeMileageEarn($other)->id,
            ],
            'n1_extra_fee_list' => [
                'permission' => 'sirsoft-ecommerce.shipping-policies.read', 'uri' => "{$api}/extra-fee-templates",
                'mine' => (int) $this->makeTemplate($actor->id, (string) random_int(10000, 49999))->id,
                'theirs' => (int) $this->makeTemplate($other->id, (string) random_int(50000, 99999))->id,
            ],
            default => throw new \LogicException("알 수 없는 사례: {$case}"),
        };
    }

    /**
     * 상품 이미지를 만듭니다 (팩토리 부재 — ProductImageScopeTest 와 같은 직접 생성).
     *
     * @param  Product  $product  소속 상품
     * @param  bool  $isThumbnail  대표 이미지 여부
     * @return ProductImage 생성된 이미지
     */
    private function makeImage(Product $product, bool $isThumbnail): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            'original_filename' => 'scope.jpg',
            'stored_filename' => 'scope-'.uniqid().'.jpg',
            'disk' => 'public',
            'path' => 'ecommerce/products/scope-'.uniqid().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'is_thumbnail' => $isThumbnail,
            'sort_order' => 0,
        ]);
    }

    /**
     * 소유자를 지정해 쿠폰을 만듭니다.
     *
     * @param  int  $ownerId  생성자 ID
     * @param  int  $issuedCount  발급 수량
     * @return Coupon 생성된 쿠폰
     */
    private function makeCoupon(int $ownerId, int $issuedCount = 0): Coupon
    {
        return Coupon::create([
            'name' => ['ko' => '스코프 쿠폰', 'en' => 'Scope coupon'],
            'target_type' => 'order_amount',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'issue_method' => 'direct',
            'issue_condition' => 'manual',
            'issue_status' => 'issuing',
            'total_quantity' => 10,
            'issued_count' => $issuedCount,
            'per_user_limit' => 1,
            'valid_type' => 'period',
            'is_combinable' => false,
            'created_by' => $ownerId,
        ]);
    }

    /**
     * 회원의 찜 행을 만듭니다.
     *
     * @param  User  $user  찜한 회원
     * @return int 찜 행 ID
     */
    private function makeWishlist(User $user): int
    {
        return (int) DB::table('ecommerce_product_wishlists')->insertGetId([
            'user_id' => $user->id,
            'product_id' => Product::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 회원의 관리자 적립 lot 을 만듭니다.
     *
     * @param  User  $user  적립 대상 회원
     * @param  Carbon|null  $expiresAt  만료 시각
     * @return MileageTransaction 생성된 적립 거래
     */
    private function makeMileageEarn(User $user, $expiresAt = null): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000, 'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 소유자를 지정해 배송정책을 만듭니다 (팩토리 부재 — 기존 테스트와 같은 직접 생성).
     *
     * @param  int  $ownerId  소유자 ID
     * @return ShippingPolicy 생성된 배송정책
     */
    private function makePolicy(int $ownerId): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '정책', 'en' => 'Policy'],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
            'created_by' => $ownerId,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
        ]);

        return $policy;
    }

    /**
     * 소유자를 지정해 추가배송비 템플릿을 만듭니다.
     *
     * @param  int  $ownerId  소유자 ID
     * @param  string  $zipcode  우편번호 (유니크 충돌 회피용)
     * @return ExtraFeeTemplate 생성된 템플릿
     */
    private function makeTemplate(int $ownerId, string $zipcode): ExtraFeeTemplate
    {
        return ExtraFeeTemplate::create([
            'zipcode' => $zipcode,
            'fee' => 3000.00,
            'region' => '제주도',
            'description' => '제주도 추가배송비',
            'is_active' => true,
            'created_by' => $ownerId,
        ]);
    }
}
