<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 옵션 배치 조회 엔드포인트 테스트
 *
 * `GET admin/products/options` 는 목록에서 펼친 행들의 옵션을 한 번에 공급한다.
 * 단건 라우트만 두면 한 페이지를 전부 펼쳤을 때 100요청이 되므로 배치가 기본형이다.
 *
 * 계약의 핵심은 **부분 성공**이다. 존재하지 않거나 권한 스코프 밖인 ID 는 404 를 만들지 않고
 * 응답의 `product_ids` 에서 빠진다. 호출자는 요청 배열과 대조해 제외된 ID 를 확정한다.
 */
class ProductOptionsBatchFetchTest extends ModuleTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-ecommerce/admin/products/options';

    protected User $adminUser;

    /** 수집된 실행 쿼리 SQL 목록 */
    private array $queries = [];

    /** DB::listen 리스너 등록 여부 (재등록하면 같은 쿼리가 중복 기록된다) */
    private bool $listening = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
    }

    /**
     * 옵션을 가진 상품을 만듭니다.
     *
     * @param  array<int, array{stock?: int, active?: bool, sort?: int}>  $options  옵션 정의
     * @param  int|null  $createdBy  등록자 ID
     * @return Product 생성된 상품
     */
    private function makeProductWithOptions(array $options, ?int $createdBy = null): Product
    {
        $product = Product::factory()->create([
            'has_options' => $options !== [],
            'created_by' => $createdBy,
        ]);

        foreach ($options as $index => $spec) {
            ProductOption::create([
                'product_id' => $product->id,
                'option_code' => 'OPT-'.$product->id.'-'.$index,
                'option_values' => ['색상' => '값'.$index],
                'option_name' => '옵션'.$index,
                'price_adjustment' => 0,
                'stock_quantity' => $spec['stock'] ?? 10,
                'is_active' => $spec['active'] ?? true,
                'sort_order' => $spec['sort'] ?? $index,
            ]);
        }

        return $product;
    }

    /**
     * 쿼리 로그 수집을 (재)시작합니다.
     *
     * 리스너는 테스트당 1회만 등록한다. 매번 등록하면 리스너가 누적되어 같은 쿼리가 등록 수만큼
     * 중복 기록되고, 쿼리 수 단언이 구조와 무관하게 실패한다.
     */
    private function startCapture(): void
    {
        $this->queries = [];

        if ($this->listening) {
            return;
        }

        $this->listening = true;

        DB::listen(function ($query) {
            $this->queries[] = $query->sql;
        });
    }

    /**
     * 상품 ID 목록으로 배치 조회를 호출합니다.
     *
     * @param  array<int, mixed>  $productIds  요청할 상품 ID
     * @return TestResponse 응답
     */
    private function fetchOptions(array $productIds, ?User $as = null)
    {
        $query = http_build_query(['product_ids' => $productIds]);

        return $this->actingAs($as ?? $this->adminUser)->getJson(self::ENDPOINT.'?'.$query);
    }

    // ==================== 기본 계약 ====================

    /**
     * @scenario row_state=expanded,option_profile=active_only
     */
    public function test_returns_options_keyed_by_product_id(): void
    {
        $first = $this->makeProductWithOptions([['stock' => 5], ['stock' => 6]]);
        $second = $this->makeProductWithOptions([['stock' => 7]]);

        $response = $this->fetchOptions([$first->id, $second->id]);

        $response->assertOk();

        $this->assertSame([$first->id, $second->id], $response->json('data.product_ids'));
        $this->assertCount(2, $response->json('data.options.'.$first->id));
        $this->assertCount(1, $response->json('data.options.'.$second->id));
    }

    /**
     * @scenario row_state=expanded,option_profile=mixed
     */
    public function test_includes_inactive_options(): void
    {
        $product = $this->makeProductWithOptions([
            ['active' => true],
            ['active' => false],
        ]);

        $response = $this->fetchOptions([$product->id]);

        $response->assertOk();

        $options = $response->json('data.options.'.$product->id);

        $this->assertCount(2, $options, '인라인 편집기가 is_active 를 다루므로 비활성 옵션도 내려와야 한다');
        $this->assertSame(
            [true, false],
            array_column($options, 'is_active')
        );
    }

    public function test_options_are_ordered_by_sort_order(): void
    {
        $product = $this->makeProductWithOptions([
            ['sort' => 30],
            ['sort' => 10],
            ['sort' => 20],
        ]);

        $response = $this->fetchOptions([$product->id]);

        $response->assertOk();

        $this->assertSame(
            [10, 20, 30],
            array_column($response->json('data.options.'.$product->id), 'sort_order')
        );
    }

    /**
     * @scenario row_state=expanded,option_profile=none
     */
    public function test_product_without_options_returns_empty_array(): void
    {
        $product = $this->makeProductWithOptions([]);

        $response = $this->fetchOptions([$product->id]);

        $response->assertOk();
        $this->assertSame([$product->id], $response->json('data.product_ids'));
        $this->assertSame([], $response->json('data.options.'.$product->id));
    }

    /**
     * 부분 성공 — 존재하지 않는 ID 는 404 가 아니라 맵/목록 양쪽에서 빠집니다.
     *
     * @effects missing_ids_resolved_as_empty
     */
    public function test_missing_product_id_is_omitted_not_404(): void
    {
        $existing = $this->makeProductWithOptions([['stock' => 3]]);
        $missingId = $existing->id + 999999;

        $response = $this->fetchOptions([$existing->id, $missingId]);

        $response->assertOk();
        $this->assertSame([$existing->id], $response->json('data.product_ids'));
        $this->assertNull($response->json('data.options.'.$missingId));
    }

    // ==================== 권한 ====================

    public function test_requires_products_read_permission(): void
    {
        $product = $this->makeProductWithOptions([['stock' => 1]]);
        $withoutPermission = $this->createAdminUser([]);

        $this->fetchOptions([$product->id], $withoutPermission)->assertStatus(403);
    }

    /**
     * scope=self 사용자가 타인 상품 ID 를 실어 보내도 옵션이 새지 않아야 합니다.
     *
     * 옵션 테이블을 바로 조회하면 상품 소유권 검사가 통째로 우회된다. 허용 ID 를 상품 쿼리에서
     * 먼저 확정하는 이유다.
     *
     * @effects scope_filtered_ids_absent
     */
    public function test_scope_self_user_cannot_read_other_users_product_options(): void
    {
        $owner = User::factory()->create();
        $scopedUser = $this->createScopeSelfAdmin();

        $mine = $this->makeProductWithOptions([['stock' => 1]], createdBy: $scopedUser->id);
        $theirs = $this->makeProductWithOptions([['stock' => 2]], createdBy: $owner->id);

        $response = $this->fetchOptions([$mine->id, $theirs->id], $scopedUser);

        $response->assertOk();
        $this->assertSame([$mine->id], $response->json('data.product_ids'), '타인 상품 ID 는 목록에서 빠져야 한다');
        $this->assertNull($response->json('data.options.'.$theirs->id), '타인 상품 옵션은 맵에도 없어야 한다');
    }

    // ==================== 검증 ====================

    public function test_product_ids_is_required(): void
    {
        $this->actingAs($this->adminUser)
            ->getJson(self::ENDPOINT)
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_ids');
    }

    public function test_empty_product_ids_is_rejected(): void
    {
        $this->fetchOptions([])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_ids');
    }

    public function test_more_than_one_hundred_product_ids_is_rejected(): void
    {
        $this->fetchOptions(range(1, 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_ids');

        // 상한(100)은 목록 per_page 상한과 같다 — 한 페이지를 전부 펼친 경우가 최대치
        $this->fetchOptions(range(1, 100))->assertOk();
    }

    public function test_non_integer_product_id_is_rejected(): void
    {
        $this->fetchOptions(['banana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_ids.0');
    }

    // ==================== 쿼리 수 ====================

    /**
     * 요청 상품 수가 늘어도 쿼리 수는 상수여야 합니다 (허용 ID 확정 1 + 옵션 조회 1).
     *
     * @effects query_count_constant_across_rows
     */
    public function test_query_count_is_constant_regardless_of_product_count(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->makeProductWithOptions([['stock' => 1], ['stock' => 2]])->id;
        }

        // 권한 캐시 워밍 (측정 대상 제외)
        $this->fetchOptions([$ids[0]])->assertOk();

        $this->startCapture();
        $this->fetchOptions([$ids[0]])->assertOk();
        $singleCount = $this->countDomainQueries();

        $this->startCapture();
        $this->fetchOptions($ids)->assertOk();
        $batchCount = $this->countDomainQueries();
        $batchSql = $this->domainQueries();

        $this->assertSame(
            $singleCount,
            $batchCount,
            "상품 수에 비례해 쿼리가 늘었다 (1건: {$singleCount}, 10건: {$batchCount})\n".implode("\n", $batchSql)
        );
    }

    /**
     * 상품/옵션 테이블을 건드린 쿼리 수를 셉니다.
     *
     * @return int 도메인 쿼리 수
     */
    private function countDomainQueries(): int
    {
        return count($this->domainQueries());
    }

    /**
     * 상품/옵션 테이블을 건드린 쿼리 목록을 돌려줍니다.
     *
     * @return array<int, string> 도메인 쿼리 SQL
     */
    private function domainQueries(): array
    {
        return array_values(array_filter(
            $this->queries,
            fn (string $sql) => str_contains($sql, 'ecommerce_products') || str_contains($sql, 'ecommerce_product_options')
        ));
    }

    /**
     * `sirsoft-ecommerce.products.read` 를 scope=self 로 가진 관리자를 만듭니다.
     *
     * @return User 생성된 사용자
     */
    private function createScopeSelfAdmin(): User
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        $permission = Permission::where('identifier', 'sirsoft-ecommerce.products.read')->firstOrFail();
        $permission->update(['owner_key' => 'created_by', 'resource_route_key' => 'product']);

        /** @var Role $role */
        $role = $user->roles()->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            $permission->id => ['scope_type' => ScopeType::Self],
        ]);

        $this->clearPermissionCache();

        return $user;
    }

    /**
     * PermissionHelper 의 static 권한 캐시를 비웁니다.
     *
     * 권한/스코프를 테스트 중에 바꾸면 앞서 캐시된 판정이 그대로 재사용되어 변경이 반영되지 않습니다.
     */
    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
