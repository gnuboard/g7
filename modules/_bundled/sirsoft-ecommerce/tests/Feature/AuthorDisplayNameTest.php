<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameKindRegistry;
use App\DisplayName\DisplayNameResolver;
use App\DisplayName\NicknameRequirement;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\UserAddressFactory;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 이커머스의 회원 표시 이름 (#490 9단계)
 *
 * 상품 후기, 상품 문의와 관리자 화면의 회원 칸이 회원 이름을 `users.name` 에서 직접 읽지 않고 표시 이름 계산기를
 * 거치는지 잠근다. 설정을 바꾸지 않은 사이트의 응답이 수정 전과 같은지도 함께 잠근다.
 *
 * | 자리 | 영역 | 기본값 |
 * | --- | --- | --- |
 * | 사용자 화면의 상품 후기 작성자 | `sirsoft-ecommerce:review` | 사이트 기본을 따름 |
 * | 사용자 화면의 상품 문의 작성자 | `sirsoft-ecommerce:inquiry` | 가린 이름 고정 |
 * | 이커머스 관리자 화면의 회원 칸 | `sirsoft-ecommerce:admin` | 관리자 화면을 따름 |
 */
class AuthorDisplayNameTest extends ModuleTestCase
{
    use CountsQueries;

    private const BASE = '/api/modules/sirsoft-ecommerce';

    /** 관리자 목록을 여는 권한 전부 (회원 조회 권한은 테스트마다 따로 준다) */
    private const ADMIN_PERMISSIONS = [
        'sirsoft-ecommerce.carts.read',
        'sirsoft-ecommerce.wishlists.read',
        'sirsoft-ecommerce.user-addresses.read',
        'sirsoft-ecommerce.inquiries.read',
        'sirsoft-ecommerce.reviews.read',
        'sirsoft-ecommerce.mileage.read',
        'sirsoft-ecommerce.promotion-coupon.read',
        'sirsoft-ecommerce.orders.read',
    ];

    private Product $product;

    /**
     * 표시 이름 설정을 기본값으로 되돌리고 상품과 문의 게시판 훅을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 응답 문구(탈퇴 문구 등)를 한국어로 고정한다. 요청 언어는 로그인 회원의 언어, 없으면 이 헤더로 정해진다.
        $this->withHeader('Accept-Language', 'ko');

        $this->memberSettings([
            'display_name_areas' => null,
            'display_name_selectable_kinds' => ['name'],
            'display_name_member_default_kind' => 'name',
        ]);

        $this->product = Product::factory()->create();

        // 다른 모듈이 붙여 둔 문의 훅이 남아 테스트의 가짜 글과 섞이지 않게 비운다.
        foreach (['sirsoft-ecommerce.inquiry.get_settings', 'sirsoft-ecommerce.inquiry.get_by_ids'] as $hook) {
            HookManager::clearFilter($hook);
        }
    }

    /**
     * 회원 탭의 표시 이름 설정을 바꿉니다.
     *
     * @param  array<string, mixed>  $member  설정 키 => 값
     * @return void 반환값 없음
     */
    private function memberSettings(array $member): void
    {
        foreach ($member as $key => $value) {
            config(["g7_settings.core.member.{$key}" => $value]);
        }
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }

    /**
     * 영역 설정을 바꿉니다.
     *
     * @param  array<string, array<string, mixed>>  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    private function areas(array $areas): void
    {
        $this->memberSettings(['display_name_areas' => $areas]);
    }

    /**
     * 이름과 닉네임을 정한 회원을 만듭니다.
     *
     * @param  string|null  $name  이름
     * @param  string|null  $nickname  닉네임
     * @return User 회원
     */
    private function member(?string $name, ?string $nickname = null): User
    {
        $user = $this->createUser();
        $user->forceFill(['name' => $name, 'nickname' => $nickname])->save();

        return $user->refresh();
    }

    /**
     * 이커머스 관리자 목록을 열 수 있는 관리자를 만듭니다.
     *
     * @param  bool  $canReadMembers  회원 조회 권한(`core.users.read`)을 줄지
     * @return User 관리자
     */
    private function admin(bool $canReadMembers = true): User
    {
        $admin = $this->createAdminUser($canReadMembers ? [...self::ADMIN_PERMISSIONS, 'core.users.read'] : self::ADMIN_PERMISSIONS);
        $admin->forceFill(['name' => '관리자본명', 'nickname' => '운영자', 'language' => 'ko'])->save();

        return $admin->refresh();
    }

    /**
     * 관리자 목록을 조회해 행 배열을 돌려줍니다.
     *
     * @param  User  $admin  관리자
     * @param  string  $path  `admin/` 뒤 경로
     * @param  array<string, mixed>  $query  쿼리 인자
     * @return array<int, array<string, mixed>> 행
     */
    private function adminRows(User $admin, string $path, array $query = []): array
    {
        $response = $this->actingAs($admin)
            ->getJson(self::BASE.'/admin/'.$path.($query === [] ? '' : '?'.http_build_query($query)))
            ->assertOk();

        return $response->json('data.data') ?? [];
    }

    /**
     * 상품 문의 한 건과 그 글을 돌려주는 가짜 게시판 훅을 만듭니다.
     *
     * @param  array<int, array{user: User|null, author_name: string|null}>  $authors  작성자 목록
     * @return array<int, int> 문의 ID 목록 (입력 순서)
     */
    private function inquiries(array $authors): array
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        $posts = [];
        $ids = [];
        foreach ($authors as $index => $author) {
            $pivot = ProductInquiry::create([
                'product_id' => $this->product->id,
                'inquirable_type' => 'board_post',
                'inquirable_id' => 9000 + $index,
                'user_id' => $author['user']?->id,
            ]);
            $ids[] = $pivot->id;
            $posts[] = [
                'id' => $pivot->inquirable_id,
                'user_id' => $author['user']?->id,
                'author_name' => $author['author_name'],
                'title' => '문의 '.$index,
                'is_secret' => false,
            ];
        }

        HookManager::addFilter('sirsoft-ecommerce.inquiry.get_settings', fn ($defaults) => $defaults, priority: 1);
        HookManager::addFilter('sirsoft-ecommerce.inquiry.get_by_ids', fn () => $posts, priority: 1);

        return $ids;
    }

    /**
     * 사용자 화면의 상품 문의 목록에서 문의 ID => 작성자 이름을 뽑습니다.
     *
     * @return array<int, string|null> 문의 ID => 작성자 이름
     */
    private function publicInquiryAuthors(): array
    {
        $response = $this->getJson(self::BASE."/products/{$this->product->id}/inquiries")->assertOk();
        $this->assertStringNotContainsString('real_name', $response->getContent(), '사용자 화면의 문의 응답에 실명 키가 실렸다');

        return collect($response->json('data.items'))->mapWithKeys(fn ($item) => [(int) $item['id'] => $item['author_name']])->all();
    }

    /**
     * 사용자 화면의 상품 후기 목록 행을 돌려줍니다.
     *
     * @return array<int, array<string, mixed>> 후기 ID => 행
     */
    private function publicReviews(): array
    {
        $response = $this->getJson(self::BASE."/products/{$this->product->id}/reviews")->assertOk();
        $this->assertStringNotContainsString('real_name', $response->getContent(), '사용자 화면의 후기 응답에 실명 키가 실렸다');

        return collect($response->json('data.reviews.data'))->keyBy('id')->all();
    }

    /**
     * 회원이 쓴 전시 중 후기를 만듭니다.
     *
     * @param  User|null  $user  작성자 (null 이면 회원 없는 후기)
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     * @return ProductReview 후기
     */
    private function review(?User $user, array $overrides = []): ProductReview
    {
        return ProductReview::factory()->create(array_merge([
            'user_id' => $user?->id,
            'product_id' => $this->product->id,
            'status' => 'visible',
        ], $user === null ? ['order_option_id' => null] : [], $overrides));
    }

    /**
     * 수정 전 문의 작성자 가림 규칙(`ProductInquiryService::maskAuthorName()` 의 종전 구현)입니다.
     *
     * @param  string  $name  이름
     * @return string 가린 이름
     */
    private function legacyMask(string $name): string
    {
        $chars = mb_str_split($name);
        $len = count($chars);
        if ($len === 1) {
            return $name;
        }
        if ($len === 2) {
            return $chars[0].'*';
        }

        return $chars[0].str_repeat('*', $len - 2).$chars[$len - 1];
    }

    /**
     * 요청 하나가 새로 시작한 것처럼(읽어 둔 추가 항목 값을 비우고) 조회의 쿼리를 기록합니다.
     *
     * 표시 이름 계산기는 한 요청 동안 읽은 추가 항목 값을 기억한다. 테스트는 여러 요청이 한 프로세스에서 돌므로
     * 앞 요청이 읽어 둔 값이 남아 있으면 뒤 요청의 쿼리가 실제보다 적게 세진다.
     *
     * @param  \Closure  $measure  측정할 조회
     * @return array<int, string> 실행된 SQL 목록
     */
    private function freshQueries(\Closure $measure): array
    {
        app(DisplayNameResolver::class)->forget();

        return $this->captureQueries($measure);
    }

    /**
     * 행을 늘려도 조회의 쿼리 수가 늘지 않는지 단언합니다 (늘었으면 늘어난 쿼리를 메시지에 적는다).
     *
     * @param  \Closure  $measure  측정할 조회
     * @param  \Closure  $grow  데이터를 늘리는 작업 (측정에 포함되지 않음)
     * @param  string  $label  실패 메시지에 붙일 화면 이름
     * @return void 반환값 없음
     */
    private function assertStableQueries(\Closure $measure, \Closure $grow, string $label): void
    {
        $before = $this->freshQueries($measure);
        $grow();
        $after = $this->freshQueries($measure);

        $this->assertCount(
            1,
            array_filter($after, fn (string $sql) => str_contains($sql, 'field_values')),
            "{$label}: 표시 이름용 추가 항목 값을 한 번에 읽지 않았다",
        );

        $extra = $after;
        foreach ($before as $sql) {
            $index = array_search($sql, $extra, true);
            if ($index !== false) {
                unset($extra[$index]);
            }
        }

        $this->assertLessThanOrEqual(
            count($before),
            count($after),
            "{$label}: 행 수를 늘렸더니 쿼리 수가 ".count($before).' → '.count($after)." 로 늘었다. 늘어난 쿼리:\n".implode("\n", $extra),
        );
    }

    // ==========================================
    // 설정을 바꾸지 않은 사이트 (수정 전과 같은 응답)
    // ==========================================

    /**
     * 표시 이름 설정을 바꾸지 않은 사이트의 상품 문의 작성자는 수정 전과 같은 가림 글자다 (R7).
     *
     * 영역 기본값(가린 이름 고정)이 종전 가림을 대신하므로 두 번 가려지지 않는다.
     *
     * @scenario case=default_inquiry_author_is_masked_as_before
     *
     * @effects default_inquiry_author_equals_legacy_mask, inquiry_author_is_not_masked_twice, user_response_has_no_real_name
     *
     * @return void 반환값 없음
     */
    public function test_r7_default_inquiry_author_equals_legacy_mask_character_by_character(): void
    {
        $names = ['김', '김동', '홍길동', '김민준호', 'John Smith', '남궁민수현'];
        $authors = array_map(fn (string $name) => ['user' => $this->member($name, '별명'), 'author_name' => '글에남은이름'], $names);
        // 회원 행이 없는 글(회원 삭제 뒤 남은 글)은 글에 남은 이름을 종전처럼 가려 보인다
        $authors[] = ['user' => null, 'author_name' => '이몽룡'];
        $ids = $this->inquiries($authors);

        $shown = $this->publicInquiryAuthors();

        foreach ($names as $index => $name) {
            $this->assertSame($this->legacyMask($name), $shown[$ids[$index]], "「{$name}」의 가림 결과가 수정 전과 다르다");
        }
        $this->assertSame('이*룡', $shown[$ids[count($names)]]);
    }

    /**
     * 표시 이름 설정을 바꾸지 않은 사이트의 상품 후기 작성자는 수정 전과 같이 회원 이름이고, 회원 없는 후기는 저장된 이름이다.
     *
     * @scenario case=default_review_author_is_member_name
     *
     * @effects default_review_author_is_member_name, imported_review_author_is_stored_name, user_response_has_no_real_name
     *
     * @return void 반환값 없음
     */
    public function test_default_review_author_is_member_name_and_imported_name_is_kept(): void
    {
        $member = $this->member('홍길동', '길동이');
        $review = $this->review($member);
        $imported = $this->review(null, ['author_name' => '김*수']);

        $rows = $this->publicReviews();

        $this->assertSame('홍길동', $rows[$review->id]['author_name']);
        $this->assertSame('홍길동', $rows[$review->id]['user']['name']);
        $this->assertSame('김*수', $rows[$imported->id]['author_name']);
        $this->assertNull($rows[$imported->id]['user']);
    }

    /**
     * 표시 이름을 닉네임으로 바꿔도 주문의 주문자 이름과 수령인은 주문서에 적힌 그대로이고, 주문서 기본값이 읽는 본인 응답의 이름도 실명이다 (R2).
     *
     * @scenario case=order_names_stay_as_entered
     *
     * @effects order_orderer_and_recipient_stay_as_entered, order_member_block_follows_admin_area, checkout_default_name_source_stays_real_name
     *
     * @return void 반환값 없음
     */
    public function test_r2_order_orderer_and_recipient_stay_real_name_under_nickname_setting(): void
    {
        $member = $this->member('홍길동', '길동이');
        $order = OrderFactory::new()->paid()->create(['user_id' => $member->id]);
        OrderAddressFactory::new()->create([
            'order_id' => $order->id, 'address_type' => 'shipping', 'orderer_name' => '홍길동', 'recipient_name' => '성춘향',
        ]);
        $this->areas([
            'core:site' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
        ]);
        $admin = $this->admin();

        $row = collect($this->adminRows($admin, 'orders'))->firstWhere('id', $order->id);
        $this->assertSame('홍길동', $row['address']['orderer_name']);
        $this->assertSame('성춘향', $row['address']['recipient_name']);
        $this->assertSame(['길동이', '홍길동'], [$row['user']['name'], $row['user']['real_name']]);

        $detail = $this->actingAs($admin)->getJson(self::BASE."/admin/orders/{$order->order_number}")->assertOk()->json('data');
        $this->assertSame('홍길동', $detail['orderer_name']);
        $this->assertSame('성춘향', $detail['recipient_name']);
        $this->assertSame(['길동이', '홍길동'], [$detail['user']['name'], $detail['user']['real_name']]);

        // 주문서의 주문자 기본값은 화면이 본인 응답의 `name` 에서 읽는다 — 그 값은 표시 이름이 아니라 원본 이름이다
        $me = $this->actingAs($member, 'sanctum')->getJson('/api/auth/user')->assertOk()->json('data');
        $this->assertSame('홍길동', $me['name']);
    }

    // ==========================================
    // 영역 설정
    // ==========================================

    /**
     * 후기 영역을 닉네임 고정이나 「회원이 고른 값」으로 바꾸면 후기 작성자가 그 값으로 나가고 실명은 응답 어디에도 없다.
     *
     * @scenario case=review_area_setting_applies
     *
     * @effects review_author_follows_review_area, review_author_follows_member_choice, review_area_does_not_change_inquiry, user_response_has_no_real_name
     *
     * @return void 반환값 없음
     */
    public function test_review_area_setting_changes_review_author(): void
    {
        $member = $this->member('홍길동', '길동이');
        $chooser = $this->member('성춘향', '춘향이');
        $chooser->forceFill(['display_name_kind' => 'nickname'])->save();
        $review = $this->review($member);
        $chosen = $this->review($chooser);
        $inquiryIds = $this->inquiries([['user' => $member, 'author_name' => null]]);

        $this->areas(['sirsoft-ecommerce:review' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $rows = $this->publicReviews();
        $this->assertSame('길동이', $rows[$review->id]['author_name']);
        $this->assertSame('길동이', $rows[$review->id]['user']['name']);
        $response = $this->getJson(self::BASE."/products/{$this->product->id}/reviews")->getContent();
        $this->assertStringNotContainsString('홍길동', $response, '닉네임으로 고정한 후기 응답에 실명이 실렸다');
        // 후기 영역 설정은 문의에 닿지 않는다
        $this->assertSame('홍*동', $this->publicInquiryAuthors()[$inquiryIds[0]]);

        $this->memberSettings([
            'display_name_selectable_kinds' => ['name', 'nickname'],
            'display_name_member_default_kind' => 'name',
            'display_name_areas' => ['sirsoft-ecommerce:review' => ['mode' => 'member']],
        ]);
        $rows = $this->publicReviews();
        $this->assertSame('홍길동', $rows[$review->id]['author_name'], '고르지 않은 회원은 회원 기본 종류로 보인다');
        $this->assertSame('춘향이', $rows[$chosen->id]['author_name']);
    }

    /**
     * 문의 영역을 닉네임 고정이나 「회원이 고른 값」으로 바꾸면 문의 작성자가 그 값 그대로 나간다(다시 가리지 않는다).
     *
     * @scenario case=inquiry_area_setting_applies
     *
     * @effects inquiry_author_follows_inquiry_area, inquiry_author_follows_member_choice, inquiry_author_is_not_masked_twice
     *
     * @return void 반환값 없음
     */
    public function test_inquiry_area_setting_changes_inquiry_author_without_double_masking(): void
    {
        $member = $this->member('홍길동', '길동이');
        $chooser = $this->member('성춘향', '춘향이');
        $chooser->forceFill(['display_name_kind' => 'nickname'])->save();
        $ids = $this->inquiries([
            ['user' => $member, 'author_name' => null],
            ['user' => $chooser, 'author_name' => null],
        ]);

        $this->areas(['sirsoft-ecommerce:inquiry' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $shown = $this->publicInquiryAuthors();
        $this->assertSame('길동이', $shown[$ids[0]]);
        $this->assertSame('춘향이', $shown[$ids[1]]);

        $this->memberSettings([
            'display_name_selectable_kinds' => ['masked_name', 'nickname'],
            'display_name_member_default_kind' => 'masked_name',
            'display_name_areas' => ['sirsoft-ecommerce:inquiry' => ['mode' => 'member']],
        ]);
        $shown = $this->publicInquiryAuthors();
        $this->assertSame('홍*동', $shown[$ids[0]]);
        $this->assertSame('춘향이', $shown[$ids[1]]);
    }

    /**
     * 탈퇴한 회원은 후기와 문의, 관리자 목록에서 종류와 무관하게 탈퇴 문구로 보이고 실명이 실리지 않는다.
     *
     * @scenario case=withdrawn_member_phrase
     *
     * @effects withdrawn_member_shows_phrase_in_reviews_and_inquiries, withdrawn_member_shows_phrase_in_admin_lists, real_name_omitted_for_withdrawn_member
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_member_shows_phrase(): void
    {
        $member = $this->member('홍길동_탈퇴_20261112', '길동이_탈퇴_1');
        $member->forceFill(['status' => UserStatus::Withdrawn->value, 'withdrawn_at' => now()])->save();
        $review = $this->review($member);
        $ids = $this->inquiries([['user' => $member, 'author_name' => null]]);
        CartFactory::new()->create(['user_id' => $member->id, 'product_id' => $this->product->id]);
        $phrase = __('user.withdrawn_user', [], 'ko');

        $this->assertSame($phrase, $this->publicReviews()[$review->id]['author_name']);
        $this->assertSame($phrase, $this->publicInquiryAuthors()[$ids[0]]);

        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $row = $this->adminRows($this->admin(), 'carts')[0];
        $this->assertSame($phrase, $row['member']['name']);
        $this->assertTrue($row['member']['is_withdrawn']);
        $this->assertArrayNotHasKey('real_name', $row['member']);
    }

    // ==========================================
    // 관리자 화면: 표시 이름과 실명 보조
    // ==========================================

    /**
     * 관리자 영역을 닉네임으로 바꾸면 이커머스 관리자 목록의 회원 칸이 닉네임이고, 회원 조회 권한이 있는 관리자에게만 실명 키가 더해진다.
     *
     * @scenario case=admin_lists_show_admin_area_name_with_real_name
     *
     * @effects admin_member_name_follows_admin_area, admin_real_name_added_for_member_readers, admin_real_name_hidden_without_member_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_admin_lists_show_admin_area_name_and_gate_real_name(): void
    {
        $member = $this->member('홍길동', '길동이');
        $granter = $this->admin();
        CartFactory::new()->create(['user_id' => $member->id, 'product_id' => $this->product->id]);
        ProductWishlist::create(['user_id' => $member->id, 'product_id' => $this->product->id]);
        UserAddressFactory::new()->create(['user_id' => $member->id]);
        $this->inquiries([['user' => $member, 'author_name' => null]]);
        $this->review($member);
        MileageTransaction::create([
            'user_id' => $member->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000, 'granted_by' => $granter->id,
        ]);
        $coupon = $this->coupon(['created_by' => $granter->id]);
        CouponIssue::create([
            'coupon_id' => $coupon->id, 'user_id' => $member->id, 'status' => CouponIssueRecordStatus::AVAILABLE->value, 'issued_at' => now(),
        ]);

        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $reader = $this->admin();
        foreach (['carts', 'wishlists', 'user-addresses', 'inquiries'] as $path) {
            $block = $this->adminRows($reader, $path)[0]['member'];
            $this->assertSame(['길동이', '홍길동'], [$block['name'], $block['real_name']], "{$path} 의 회원 칸");
        }

        $reviewRow = $this->adminRows($reader, 'reviews')[0];
        $this->assertSame('길동이', $reviewRow['author_name']);
        $this->assertSame('홍길동', $reviewRow['author_real_name']);
        $this->assertSame(['길동이', '홍길동'], [$reviewRow['user']['name'], $reviewRow['user']['real_name']]);

        $mileage = $this->adminRows($reader, 'mileage-transactions')[0];
        $this->assertSame(['길동이', '홍길동'], [$mileage['user_name'], $mileage['user_real_name']]);
        $this->assertSame(['운영자', '관리자본명'], [$mileage['granted_by_name'], $mileage['granted_by_real_name']]);

        $issue = $this->adminRows($reader, "promotion-coupons/{$coupon->id}/issues")[0];
        $this->assertSame(['길동이', '홍길동'], [$issue['user_name'], $issue['user_real_name']]);

        $couponRow = collect($this->adminRows($reader, 'promotion-coupons'))->firstWhere('id', $coupon->id);
        $this->assertSame('운영자', $couponRow['created_by_name']);
        $this->assertSame('관리자본명', $couponRow['created_by_real_name']);

        // 회원 조회 권한이 없는 관리자: 표시 이름은 같고 실명 키가 없다
        $this->app['auth']->forgetGuards();
        $plain = $this->admin(false);
        foreach (['carts', 'wishlists', 'user-addresses', 'inquiries'] as $path) {
            $block = $this->adminRows($plain, $path)[0]['member'];
            $this->assertSame('길동이', $block['name']);
            $this->assertArrayNotHasKey('real_name', $block, "{$path}: 회원 조회 권한이 없는 관리자에게 실명이 실렸다");
        }
        $reviewRow = $this->adminRows($plain, 'reviews')[0];
        $this->assertArrayNotHasKey('author_real_name', $reviewRow);
        $this->assertArrayNotHasKey('real_name', $reviewRow['user']);
        $mileage = $this->adminRows($plain, 'mileage-transactions')[0];
        $this->assertArrayNotHasKey('user_real_name', $mileage);
        $this->assertArrayNotHasKey('granted_by_real_name', $mileage);
    }

    /**
     * 표시 이름이 실명과 같거나 실명이 비어 있으면 실명 키를 싣지 않는다.
     *
     * @scenario case=real_name_omitted_when_same_or_empty
     *
     * @effects real_name_omitted_when_same_as_display_name, real_name_omitted_when_empty
     *
     * @return void 반환값 없음
     */
    public function test_real_name_is_omitted_when_same_as_display_name_or_empty(): void
    {
        $same = $this->member('홍길동', '길동이');
        $nameless = $this->member(null, '무명씨');
        CartFactory::new()->create(['user_id' => $same->id, 'product_id' => $this->product->id]);
        $admin = $this->admin();

        // 기본 설정: 관리자 영역이 이름 고정이라 표시 이름이 실명과 같다
        $block = $this->adminRows($admin, 'carts')[0]['member'];
        $this->assertSame('홍길동', $block['name']);
        $this->assertArrayNotHasKey('real_name', $block);

        CartFactory::new()->create(['user_id' => $nameless->id, 'product_id' => $this->product->id]);
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $blocks = collect($this->adminRows($admin, 'carts'))->pluck('member')->keyBy('uuid');
        $this->assertSame('무명씨', $blocks[$nameless->uuid]['name']);
        $this->assertArrayNotHasKey('real_name', $blocks[$nameless->uuid], '이름이 빈 회원에게 실명 키가 실렸다');
    }

    /**
     * 이커머스 관리자 영역 설정이 코어 관리자 영역보다 먼저 적용되고, 등록한 세 영역의 부모와 기본값이 선언대로다.
     *
     * @scenario case=ecommerce_areas_are_registered
     *
     * @effects ecommerce_areas_registered_with_declared_defaults, ecommerce_admin_area_overrides_core_admin_area
     *
     * @return void 반환값 없음
     */
    public function test_ecommerce_areas_are_registered_and_admin_area_overrides_core_admin(): void
    {
        $registry = app(DisplayNameAreaRegistry::class);
        $review = $registry->get('sirsoft-ecommerce:review');
        $inquiry = $registry->get('sirsoft-ecommerce:inquiry');
        $adminArea = $registry->get('sirsoft-ecommerce:admin');

        $this->assertNotNull($review, '후기 영역이 등록되지 않았다');
        $this->assertNotNull($inquiry, '문의 영역이 등록되지 않았다');
        $this->assertNotNull($adminArea, '이커머스 관리자 영역이 등록되지 않았다');
        $this->assertSame(['core:site', 'user', 'inherit'], [$review->parent, $review->audience, $review->defaultSetting()['mode']]);
        $this->assertSame(['mode' => 'fixed', 'kind' => 'masked_name'], array_intersect_key($inquiry->defaultSetting(), ['mode' => 1, 'kind' => 1]));
        $this->assertSame(['core:admin', 'admin', 'inherit'], [$adminArea->parent, $adminArea->audience, $adminArea->defaultSetting()['mode']]);
        foreach ([$review, $inquiry, $adminArea] as $area) {
            $this->assertStringNotContainsString('sirsoft-ecommerce::', $area->label(), '영역 이름이 번역되지 않았다');
        }

        $member = $this->member('홍길동', '길동이');
        CartFactory::new()->create(['user_id' => $member->id, 'product_id' => $this->product->id]);
        $this->areas(['sirsoft-ecommerce:admin' => ['mode' => 'fixed', 'kind' => 'name_nickname']]);

        $this->assertSame('홍길동(길동이)', $this->adminRows($this->admin(), 'carts')[0]['member']['name']);
    }

    // ==========================================
    // 검색
    // ==========================================

    /**
     * 관리자 화면의 회원 검색은 이름과 닉네임을 모두 찾는다 (종전에 이메일도 찾던 화면은 이메일도 계속 찾는다).
     *
     * @scenario case=admin_member_search_matches_name_and_nickname
     *
     * @effects admin_member_search_matches_name, admin_member_search_matches_nickname, admin_member_search_keeps_email
     *
     * @return void 반환값 없음
     */
    public function test_admin_member_search_matches_name_and_nickname(): void
    {
        $member = $this->member('홍길동', '바람검객');
        $other = $this->member('성춘향', '봄향기');
        $creator = $this->admin();
        foreach ([$member, $other] as $user) {
            CartFactory::new()->create(['user_id' => $user->id, 'product_id' => $this->product->id]);
            ProductWishlist::create(['user_id' => $user->id, 'product_id' => $this->product->id]);
            UserAddressFactory::new()->create(['user_id' => $user->id, 'recipient_name' => '수령인', 'address' => '어딘가']);
            $this->review($user);
            MileageTransaction::create([
                'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
                'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
            ]);
        }
        $this->inquiries([['user' => $member, 'author_name' => null], ['user' => $other, 'author_name' => null]]);
        $mine = $this->coupon(['created_by' => $creator->id]);
        $otherCreator = $this->admin();
        $otherCreator->forceFill(['name' => '다른관리자', 'nickname' => '부운영자'])->save();
        $this->coupon(['created_by' => $otherCreator->id]);

        $admin = $this->admin();
        $memberBlocks = [
            'carts' => ['search_field' => 'member'],
            'wishlists' => ['search_field' => 'member'],
            'user-addresses' => ['search_field' => 'member'],
            'inquiries' => ['search_field' => 'member'],
        ];
        foreach ($memberBlocks as $path => $query) {
            foreach (['길동' => '이름', '바람검' => '닉네임', $member->email => '이메일'] as $keyword => $label) {
                $rows = $this->adminRows($admin, $path, $query + ['search_keyword' => $keyword]);
                $this->assertSame([$member->uuid], array_column(array_column($rows, 'member'), 'uuid'), "{$path}: {$label} 검색");
            }
        }

        foreach (['길동', '바람검'] as $keyword) {
            $rows = $this->adminRows($admin, 'reviews', ['search_field' => 'reviewer', 'search_keyword' => $keyword]);
            $this->assertSame([$member->uuid], array_column($rows, 'user_id'), "후기 관리: {$keyword} 검색");

            $rows = $this->adminRows($admin, 'mileage-transactions', ['search_field' => 'member', 'search_keyword' => $keyword]);
            $this->assertSame([$member->uuid], array_column($rows, 'user_uuid'), "마일리지 내역: {$keyword} 검색");
        }

        foreach (['관리자본', '운영자'] as $keyword) {
            $rows = $this->adminRows($admin, 'promotion-coupons', ['search_field' => 'created_by', 'search_keyword' => $keyword]);
            $this->assertContains($mine->id, array_column($rows, 'id'), "쿠폰 등록자: {$keyword} 검색");
        }
        $rows = $this->adminRows($admin, 'promotion-coupons', ['search_field' => 'created_by', 'search_keyword' => '부운영']);
        $this->assertNotContains($mine->id, array_column($rows, 'id'));
    }

    // ==========================================
    // 쿼리 수
    // ==========================================

    /**
     * 추가 항목을 표시 이름으로 쓰는 영역에서도 목록의 쿼리 수가 행 수와 무관하다.
     *
     * @scenario case=list_query_count_is_stable_with_field_kind
     *
     * @effects admin_list_reads_field_values_once, public_review_list_reads_field_values_once, public_inquiry_list_reads_field_values_once
     *
     * @return void 반환값 없음
     */
    public function test_list_query_count_does_not_grow_with_rows_when_area_uses_a_field_kind(): void
    {
        $this->seed(CoreFieldDefinitionSeeder::class);
        FieldDefinition::create([
            'field_set' => FieldSet::CORE_USER, 'key' => 'alias', 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '활동명', 'en' => 'Alias'],
            'enabled' => true, 'display_name_usable' => true, 'sort_order' => 900,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        app(DisplayNameKindRegistry::class)->flush();

        $this->areas([
            'core:site' => ['mode' => 'fixed', 'kind' => 'field:alias'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'field:alias'],
            'sirsoft-ecommerce:inquiry' => ['mode' => 'fixed', 'kind' => 'field:alias'],
        ]);

        $authors = [];
        $grow = function (int $count) use (&$authors) {
            for ($i = 0; $i < $count; $i++) {
                $user = $this->member('회원'.count($authors), null);
                app(FieldValueService::class)->write($user, FieldSet::CORE_USER, ['alias' => '활동명'.count($authors)], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
                CartFactory::new()->create(['user_id' => $user->id, 'product_id' => $this->product->id]);
                $this->review($user);
                $authors[] = ['user' => $user, 'author_name' => null];
            }
        };
        $grow(2);
        $admin = $this->admin();

        $cartRows = $this->adminRows($admin, 'carts');
        $this->assertEqualsCanonicalizing(['활동명0', '활동명1'], array_column(array_column($cartRows, 'member'), 'name'));

        $this->assertStableQueries(
            fn () => $this->adminRows($admin, 'carts'),
            fn () => $grow(4),
            '장바구니 관리 목록',
        );
        $this->assertStableQueries(
            fn () => $this->adminRows($admin, 'reviews'),
            fn () => $grow(4),
            '후기 관리 목록',
        );

        $this->app['auth']->forgetGuards();
        $this->assertStableQueries(
            fn () => $this->getJson(self::BASE."/products/{$this->product->id}/reviews?per_page=50")->assertOk(),
            fn () => $grow(4),
            '사용자 화면 후기 목록',
        );

        HookManager::clearFilter('sirsoft-ecommerce.inquiry.get_by_ids');
        HookManager::clearFilter('sirsoft-ecommerce.inquiry.get_settings');
        $ids = $this->inquiries(array_slice($authors, 0, 2));
        $shown = $this->publicInquiryAuthors();
        $this->assertSame(['활동명0', '활동명1'], [$shown[$ids[0]], $shown[$ids[1]]]);
        $before = count($this->freshQueries(fn () => $this->getJson(self::BASE."/products/{$this->product->id}/inquiries?per_page=50")->assertOk()));
        HookManager::clearFilter('sirsoft-ecommerce.inquiry.get_by_ids');
        HookManager::clearFilter('sirsoft-ecommerce.inquiry.get_settings');
        ProductInquiry::query()->delete();
        $this->inquiries(array_slice($authors, 0, 10));
        $after = count($this->freshQueries(fn () => $this->getJson(self::BASE."/products/{$this->product->id}/inquiries?per_page=50")->assertOk()));
        $this->assertLessThanOrEqual($before, $after, "사용자 화면 문의 목록: 행 수를 늘렸더니 쿼리 수가 {$before} → {$after} 로 늘었다");
    }

    // ==========================================
    // 캐시
    // ==========================================

    /**
     * 회원의 표시 이름이 바뀌면(코어 액션) 후기와 문의 작성자가 구워진 상품 상세 SEO 캐시가 비워진다.
     *
     * @scenario case=display_name_change_invalidates_product_detail_cache
     *
     * @effects product_detail_seo_cache_invalidated_on_member_display_name_change
     *
     * @return void 반환값 없음
     */
    public function test_member_display_name_change_invalidates_product_detail_seo_cache(): void
    {
        $layouts = [];
        $seo = Mockery::mock(SeoCacheManagerInterface::class)->shouldIgnoreMissing();
        $seo->shouldReceive('invalidateByLayout')->andReturnUsing(function (string $layout) use (&$layouts) {
            $layouts[] = $layout;

            return 0;
        });
        $this->app->instance(SeoCacheManagerInterface::class, $seo);

        HookManager::doAction('core.user.display_name_changed', $this->member('홍길동', '길동이'), ['nickname']);

        $this->assertContains('shop/show', $layouts, '상품 상세 SEO 캐시를 비우지 않았다');
    }

    /**
     * 쿠폰을 하나 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     * @return Coupon 쿠폰
     */
    private function coupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '표시 이름 쿠폰', 'en' => 'Display name coupon'],
            'target_type' => 'order_amount',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'issue_method' => 'direct',
            'issue_condition' => 'manual',
            'issue_status' => 'issuing',
            'total_quantity' => 10,
            'issued_count' => 0,
            'per_user_limit' => 1,
            'valid_type' => 'period',
            'is_combinable' => false,
        ], $overrides));
    }
}
