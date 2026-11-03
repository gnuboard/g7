<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Review;

use App\Contracts\Extension\StorageInterface;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductListResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\RecentReviewResource;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원·주문 없이 등록하는 상품 후기(다른 쇼핑몰에서 이관한 후기) 회귀 테스트
 *
 * 쓰기는 `ProductReviewService::createImported()` 하나이고, 그 행이 관리자·공개 화면과 상품 목록 집계에서
 * 회원 후기와 같은 표로 읽히는지 함께 잠근다.
 */
class ImportedProductReviewTest extends ModuleTestCase
{
    private const ADMIN_API = '/api/modules/sirsoft-ecommerce/admin/reviews';

    private Product $product;

    /** @var array<int, array{0: string, 1: callable}> 테스트가 붙인 훅 (tearDown 에서 뗀다) */
    private array $attachedHooks = [];

    /**
     * 이관 후기를 붙일 판매중 상품을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->onSale()->create();
    }

    /**
     * 테스트가 붙인 훅을 떼고 모의 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->attachedHooks as [$hook, $callback]) {
            HookManager::removeAction($hook, $callback);
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * 이관 후기 서비스
     *
     * @return ProductReviewService 서비스
     */
    private function service(): ProductReviewService
    {
        return app(ProductReviewService::class);
    }

    /**
     * 기본 입력에 덮어쓸 값을 합쳐 이관 후기를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return ProductReview 생성·갱신된 후기
     */
    private function import(array $overrides = []): ProductReview
    {
        return $this->service()->createImported($this->product->id, array_merge([
            'rating' => 4,
            'content' => '배송이 빨라서 좋았어요. 다음에도 살게요.',
            'author_name' => '장*맨',
            'created_at' => '2023-05-10 14:22:00',
        ], $overrides));
    }

    /**
     * 훅에 기록용 콜백을 붙이고 호출 횟수를 담을 참조를 돌려줍니다.
     *
     * @param  string  $hook  훅 이름
     * @param  int  $calls  호출 횟수 (참조)
     *
     * @return void 반환값 없음
     */
    private function spyAction(string $hook, int &$calls): void
    {
        $callback = function () use (&$calls) {
            $calls++;
        };
        HookManager::addAction($hook, $callback);
        $this->attachedHooks[] = [$hook, $callback];
    }

    /**
     * 공개 후기 API 를 부를 수 있는 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function storefrontUser(): User
    {
        $user = $this->createUser();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            [
                'name' => ['ko' => '상품 조회', 'en' => 'Read Products'],
                'type' => PermissionType::User,
            ]
        );
        Role::where('identifier', 'user')->first()->permissions()->syncWithoutDetaching([$permission->id]);

        return $user;
    }

    /**
     * 텍스트 후기를 회원·주문 없이 만들고 작성일을 원래 값으로 둡니다.
     *
     * @scenario content_mode=text, existing_row=none
     *
     * @effects imported_review_created_without_member_or_order, imported_review_timestamps_preserved
     *
     * @return void 반환값 없음
     */
    public function test_creates_review_without_member_or_order_and_keeps_original_dates(): void
    {
        $review = $this->import();

        $this->assertTrue($review->wasRecentlyCreated);
        $this->assertNull($review->user_id);
        $this->assertNull($review->order_option_id);
        $this->assertSame('장*맨', $review->author_name);
        $this->assertSame($this->product->id, (int) $review->product_id);
        $this->assertSame(4, $review->rating);
        $this->assertSame('text', $review->content_mode);
        $this->assertSame(ReviewStatus::VISIBLE, $review->status);
        $this->assertNull($review->option_snapshot);

        // 오프셋 없는 문자열은 사이트 기본 시간대의 벽시계로 읽는다
        $expected = Carbon::parse('2023-05-10 14:22:00', TimezoneHelper::getSiteTimezone())
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');

        $fresh = ProductReview::query()->findOrFail($review->id);
        $this->assertSame($expected, $fresh->created_at->format('Y-m-d H:i:s'));
        $this->assertSame($expected, $fresh->updated_at->format('Y-m-d H:i:s'));
    }

    /**
     * 오프셋을 함께 준 작성일은 그 오프셋 그대로 해석합니다.
     *
     * @effects imported_review_timestamps_preserved
     *
     * @return void 반환값 없음
     */
    public function test_created_at_with_explicit_offset_is_respected(): void
    {
        $review = $this->import(['created_at' => '2022-01-02T03:04:05+09:00']);

        $this->assertSame(
            Carbon::parse('2022-01-01 18:04:05', 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            ProductReview::query()->findOrFail($review->id)->created_at->format('Y-m-d H:i:s')
        );
    }

    /**
     * HTML 후기는 상품 설명과 같은 규칙으로 정화합니다(스크립트·이벤트 속성 제거, 허용 태그 유지).
     *
     * @scenario content_mode=html, existing_row=none
     *
     * @effects imported_review_html_sanitized
     *
     * @return void 반환값 없음
     */
    public function test_html_content_is_purified(): void
    {
        $review = $this->import([
            'content_mode' => 'html',
            'content' => '<p onclick="alert(1)">정말 <strong>좋아요</strong></p><script>alert(2)</script><img src="javascript:alert(3)">',
            'reply_content' => '<p>감사합니다<script>x()</script></p>',
            'reply_content_mode' => 'html',
        ]);

        $this->assertSame('html', $review->content_mode);
        $this->assertStringContainsString('<strong>좋아요</strong>', $review->content);
        $this->assertStringNotContainsString('<script', $review->content);
        $this->assertStringNotContainsString('onclick', $review->content);
        $this->assertStringNotContainsString('javascript:', $review->content);
        $this->assertStringNotContainsString('<script', (string) $review->reply_content);
        $this->assertStringContainsString('감사합니다', (string) $review->reply_content);
    }

    /**
     * 텍스트 모드 본문은 손대지 않습니다(화면이 글자로 그리므로 꺾쇠도 그대로 둔다).
     *
     * @effects imported_review_html_sanitized
     *
     * @return void 반환값 없음
     */
    public function test_text_content_is_stored_verbatim(): void
    {
        $review = $this->import(['content' => '1 < 2 그리고 <b>굵게</b> 는 글자 그대로']);

        $this->assertSame('1 < 2 그리고 <b>굵게</b> 는 글자 그대로', $review->content);
    }

    /**
     * 같은 상품·같은 작성자 이름·같은 작성일이면 새로 만들지 않고 그 행을 갱신합니다.
     *
     * @scenario content_mode=text, existing_row=same_author_and_time
     *
     * @effects imported_review_idempotent_on_same_author_and_time
     *
     * @return void 반환값 없음
     */
    public function test_reimport_updates_same_row(): void
    {
        $first = $this->import();
        $second = $this->import(['rating' => 2, 'content' => '다시 이관한 본문입니다. 별점이 바뀌었어요.']);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(2, $second->rating);
        $this->assertSame(1, ProductReview::query()->where('product_id', $this->product->id)->count());

        // 작성자 이름이나 작성일이 다르면 다른 후기다
        $this->import(['author_name' => '김*수']);
        $this->import(['created_at' => '2023-05-10 14:23:00']);
        $this->assertSame(3, ProductReview::query()->where('product_id', $this->product->id)->count());
    }

    /**
     * 재이관이 HTML 로 바뀌어도 정화하고, 관리자가 바꾼 상태·답변은 입력에 그 키가 없으면 보존합니다.
     *
     * @scenario content_mode=html, existing_row=same_author_and_time
     *
     * @effects imported_review_idempotent_on_same_author_and_time, imported_review_html_sanitized
     *
     * @return void 반환값 없음
     */
    public function test_reimport_as_html_keeps_admin_changes_unless_given(): void
    {
        $first = $this->import(['reply_content' => '판매자 답변']);
        $this->service()->updateStatus($first, ReviewStatus::HIDDEN->value);

        $second = $this->import([
            'content_mode' => 'html',
            'content' => '<p>바뀐 본문<script>bad()</script></p>',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('html', $second->content_mode);
        $this->assertStringNotContainsString('<script', $second->content);
        $this->assertSame(ReviewStatus::HIDDEN, $second->status, '입력에 status 가 없으면 관리자가 숨긴 상태를 되돌리지 않습니다.');
        $this->assertSame('판매자 답변', $second->reply_content, '입력에 답변이 없으면 기존 답변을 지우지 않습니다.');

        $third = $this->import(['status' => 'visible']);
        $this->assertSame(ReviewStatus::VISIBLE, $third->status);
    }

    /**
     * 관리자가 지운 후기는 재이관이 되살리지 않습니다.
     *
     * @effects imported_review_idempotent_on_same_author_and_time
     *
     * @return void 반환값 없음
     */
    public function test_reimport_does_not_restore_deleted_review(): void
    {
        $first = $this->import();
        $first->delete();

        $again = $this->import();

        $this->assertSame($first->id, $again->id);
        $this->assertTrue($again->trashed());
        $this->assertSame(0, ProductReview::query()->where('product_id', $this->product->id)->count());
    }

    /**
     * 판매자 답변을 함께 넣으면 답변일이 없을 때 후기 작성일을 답변일로 씁니다(답변 필터가 답변일을 본다).
     *
     * @effects imported_review_reply_stored
     *
     * @return void 반환값 없음
     */
    public function test_reply_is_stored_with_replied_at(): void
    {
        $review = $this->import(['reply_content' => '구매해 주셔서 감사합니다.']);

        $this->assertSame('구매해 주셔서 감사합니다.', $review->reply_content);
        $this->assertSame('text', $review->reply_content_mode);
        $this->assertNull($review->reply_admin_id);
        $this->assertSame(
            $review->created_at->format('Y-m-d H:i:s'),
            $review->replied_at?->format('Y-m-d H:i:s')
        );

        $explicit = $this->import([
            'author_name' => '박*영',
            'reply_content' => '답변',
            'replied_at' => '2023-05-11 09:00:00',
        ]);
        $this->assertSame(
            Carbon::parse('2023-05-11 09:00:00', TimezoneHelper::getSiteTimezone())->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            $explicit->replied_at?->format('Y-m-d H:i:s')
        );
    }

    /**
     * 회원 후기 작성 훅(before/after_create)을 발행하지 않고 이관 전용 훅만 발행합니다.
     *
     * after_create 의 구독자(관리자 활동 로그)가 이관 한 건마다 "후기 작성" 기록을 남기지 않게 한다.
     *
     * @effects imported_review_fires_no_member_create_hooks
     *
     * @return void 반환값 없음
     */
    public function test_member_create_hooks_are_not_fired(): void
    {
        $before = 0;
        $after = 0;
        $imported = 0;
        $this->spyAction('sirsoft-ecommerce.product-review.before_create', $before);
        $this->spyAction('sirsoft-ecommerce.product-review.after_create', $after);
        $this->spyAction('sirsoft-ecommerce.product-review.after_import', $imported);

        $this->import();
        $this->import();

        $this->assertSame(0, $before);
        $this->assertSame(0, $after);
        $this->assertSame(2, $imported, '생성·갱신 모두 after_import 를 한 번씩 발행합니다.');
    }

    /**
     * 필수값이 없거나 범위를 벗어나면 저장하지 않고 거절합니다.
     *
     * @effects imported_review_invalid_input_rejected
     *
     * @return void 반환값 없음
     */
    public function test_invalid_input_is_rejected(): void
    {
        $cases = [
            ['rating' => 0],
            ['rating' => 6],
            ['content' => ''],
            ['author_name' => ''],
            ['author_name' => str_repeat('가', 51)],
            ['created_at' => ''],
            ['created_at' => '날짜 아님'],
            ['content_mode' => 'markdown'],
            ['status' => 'deleted'],
        ];

        foreach ($cases as $overrides) {
            try {
                $this->import($overrides);
                $this->fail('거절되어야 하는 입력이 저장됐습니다: '.json_encode($overrides, JSON_UNESCAPED_UNICODE));
            } catch (\InvalidArgumentException) {
                // 기대한 거절
            }
        }

        $this->assertSame(0, ProductReview::query()->where('product_id', $this->product->id)->count());
    }

    /**
     * 없는 상품에는 붙이지 않습니다.
     *
     * @effects imported_review_invalid_input_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unknown_product_is_rejected(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service()->createImported(999999999, [
            'rating' => 5,
            'content' => '없는 상품',
            'author_name' => '이*나',
            'created_at' => '2023-01-01 00:00:00',
        ]);
    }

    /**
     * 관리자 목록·상세가 회원·주문 없는 후기를 작성자 이름과 이관 표시로 싣고, 작성자 검색이 이름을 찾습니다.
     *
     * @effects imported_review_admin_list_and_reviewer_search, imported_review_resource_shows_author_name
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_and_reviewer_search_include_imported_review(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.reviews.read']);
        $review = $this->import(['content_mode' => 'html', 'content' => '<p>사진처럼 <b>예뻐요</b></p>']);

        $member = $this->createUser();
        ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => OrderOption::factory()->create(['product_id' => $this->product->id])->id,
            'user_id' => $member->id,
        ]);

        $list = $this->actingAs($admin)->getJson(self::ADMIN_API.'?per_page=50')->assertOk();
        $row = collect($list->json('data.data'))->firstWhere('id', $review->id);

        $this->assertNotNull($row, '관리자 목록에 이관 후기가 없습니다.');
        $this->assertSame('장*맨', $row['author_name']);
        $this->assertTrue($row['is_imported']);
        $this->assertNull($row['user']);
        $this->assertNull($row['user_id']);
        $this->assertNull($row['orderOption']);
        $this->assertNull($row['order_option_id']);
        $this->assertSame('사진처럼 예뻐요', $row['content_plain']);
        $this->assertFalse($row['is_owner']);

        $memberRow = collect($list->json('data.data'))->firstWhere('user_id', $member->uuid);
        $this->assertSame($member->name, $memberRow['author_name'], '회원 후기의 작성자 표시는 회원 이름입니다.');
        $this->assertFalse($memberRow['is_imported']);

        $search = $this->actingAs($admin)
            ->getJson(self::ADMIN_API.'?search_field=reviewer&search_keyword='.urlencode('장*'))
            ->assertOk();
        $this->assertSame([$review->id], collect($search->json('data.data'))->pluck('id')->all());

        $this->actingAs($admin)->getJson(self::ADMIN_API.'/'.$review->id)
            ->assertOk()
            ->assertJsonPath('data.author_name', '장*맨')
            ->assertJsonPath('data.is_imported', true)
            ->assertJsonPath('data.orderOption', null);
    }

    /**
     * 상태 변경·답변 저장/삭제·일괄 처리·삭제가 회원·주문 없는 후기에서도 됩니다.
     *
     * @effects imported_review_admin_actions_work
     *
     * @return void 반환값 없음
     */
    public function test_admin_actions_work_on_imported_review(): void
    {
        $admin = $this->createAdminUser([
            'sirsoft-ecommerce.reviews.read',
            'sirsoft-ecommerce.reviews.update',
            'sirsoft-ecommerce.reviews.delete',
        ]);
        $review = $this->import();
        $other = $this->import(['author_name' => '최*호']);

        $this->actingAs($admin)
            ->patchJson(self::ADMIN_API."/{$review->id}/status", ['status' => 'hidden'])
            ->assertOk()
            ->assertJsonPath('data.status', 'hidden');

        $this->actingAs($admin)
            ->postJson(self::ADMIN_API."/{$review->id}/reply", ['reply_content' => '관리자 답변'])
            ->assertOk()
            ->assertJsonPath('data.reply_content', '관리자 답변')
            ->assertJsonPath('data.author_name', '장*맨');

        $this->actingAs($admin)
            ->deleteJson(self::ADMIN_API."/{$review->id}/reply")
            ->assertOk()
            ->assertJsonPath('data.has_reply', false);

        $this->actingAs($admin)
            ->postJson(self::ADMIN_API.'/bulk', ['ids' => [$review->id, $other->id], 'action' => 'change_status', 'status' => 'visible'])
            ->assertOk();
        $this->assertSame(ReviewStatus::VISIBLE, $other->fresh()->status);

        $this->actingAs($admin)
            ->deleteJson(self::ADMIN_API."/{$review->id}")
            ->assertOk();
        $this->assertSoftDeleted('ecommerce_product_reviews', ['id' => $review->id]);
    }

    /**
     * 공개 후기 목록·별점 통계·사진 후기 필터가 이관 후기를 함께 다룹니다.
     *
     * @effects imported_review_public_list_and_rating_stats, imported_review_resource_shows_author_name
     *
     * @return void 반환값 없음
     */
    public function test_public_list_and_rating_stats_include_imported_review(): void
    {
        $user = $this->storefrontUser();
        $this->import(['rating' => 5]);
        $this->import(['author_name' => '한*별', 'rating' => 3]);
        $this->import(['author_name' => '숨*김', 'rating' => 1, 'status' => 'hidden']);

        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews")
            ->assertOk();

        $rows = collect($response->json('data.reviews.data'));
        $this->assertSame(2, $rows->count());
        $this->assertEqualsCanonicalizing(['장*맨', '한*별'], $rows->pluck('author_name')->all());
        $this->assertTrue($rows->every(fn ($row) => $row['is_imported'] === true && $row['user'] === null));
        $this->assertSame(4, (int) round($response->json('data.rating_stats.avg')));
        $this->assertSame(1, $response->json('data.rating_stats.5.count'));
        $this->assertSame(2, $response->json('data.total_count'));

        $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews?photo_only=1")
            ->assertOk()
            ->assertJsonPath('data.reviews.meta.total', 0);

        $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews?sort=rating_asc")
            ->assertOk()
            ->assertJsonPath('data.reviews.data.0.author_name', '한*별');
    }

    /**
     * 상품 목록의 별점 평균·후기 수 집계에 이관 후기가 들어갑니다.
     *
     * @effects imported_review_counted_in_product_list_aggregate
     *
     * @return void 반환값 없음
     */
    public function test_product_list_aggregate_counts_imported_review(): void
    {
        $this->import(['rating' => 5]);
        $this->import(['author_name' => '오*리', 'rating' => 4]);
        $this->import(['author_name' => '숨*김', 'rating' => 1, 'status' => 'hidden']);

        $products = app(ProductService::class)->getProductsByIds([$this->product->id]);
        $payload = ProductListResource::collection($products)->resolve(request());

        $this->assertSame(2, $payload[0]['review_count'] ?? null);
        $this->assertSame(4.5, $payload[0]['rating_avg'] ?? null);
    }

    /**
     * 대시보드 최근 후기도 작성자 이름을 보입니다.
     *
     * @effects imported_review_resource_shows_author_name
     *
     * @return void 반환값 없음
     */
    public function test_recent_review_resource_uses_author_name(): void
    {
        $review = $this->import();

        $payload = (new RecentReviewResource($review->load('product', 'user')))->resolve(request());

        $this->assertSame('장*맨', $payload['author_name']);
    }

    /**
     * 기존 사진 업로드 서비스로 이관 후기에 사진을 붙입니다.
     *
     * @effects imported_review_image_upload_works
     *
     * @return void 반환값 없음
     */
    public function test_image_can_be_attached_to_imported_review(): void
    {
        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true);
        $storage->allows('getDisk')->andReturn('local');
        $this->app->when(ProductReviewImageService::class)
            ->needs(StorageInterface::class)
            ->give(fn () => $storage);

        $review = $this->import();
        $image = app(ProductReviewImageService::class)->upload(UploadedFile::fake()->image('photo.jpg', 400, 300), $review);

        $this->assertSame($review->id, (int) $image->review_id);
        $this->assertSame(1, $review->images()->count());
    }

    /**
     * 주문 옵션 파기 연쇄는 주문이 없는 후기를 건드리지 않습니다.
     *
     * @effects imported_review_untouched_by_order_option_purge
     *
     * @return void 반환값 없음
     */
    public function test_order_option_purge_leaves_imported_review(): void
    {
        $review = $this->import();
        $option = OrderOption::factory()->create(['product_id' => $this->product->id]);
        ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $option->id,
            'user_id' => $this->createUser()->id,
        ]);

        $deleted = $this->service()->purgeForOrderOptions([$option->id]);

        $this->assertSame(1, $deleted);
        $this->assertNotNull(ProductReview::query()->find($review->id));
    }
}
