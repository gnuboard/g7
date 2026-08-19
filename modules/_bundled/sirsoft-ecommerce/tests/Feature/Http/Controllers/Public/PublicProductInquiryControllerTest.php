<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 상품 1:1 문의 API Feature 테스트
 *
 * GET  /api/modules/sirsoft-ecommerce/products/{productId}/inquiries - 문의 목록 조회 (비회원 포함)
 * POST /api/modules/sirsoft-ecommerce/products/{productId}/inquiries - 문의 작성 (회원 전용)
 */
class PublicProductInquiryControllerTest extends ModuleTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create();

        // 다른 모듈(sirsoft-board 등) 의 ServiceProvider 가 등록한 inquiry.* 필터가
        // ModuleTestCase snapshot 에 의해 잔존하여 test mock 과 충돌하는 cross-module
        // contamination 을 차단.
        foreach ([
            'sirsoft-ecommerce.inquiry.delete',
            'sirsoft-ecommerce.inquiry.update_reply',
            'sirsoft-ecommerce.inquiry.delete_reply',
            'sirsoft-ecommerce.inquiry.create',
            'sirsoft-ecommerce.inquiry.get_settings',
            'sirsoft-ecommerce.inquiry.get_by_ids',
            'sirsoft-ecommerce.inquiry.store_validation_rules',
            'sirsoft-ecommerce.inquiry.update_validation_rules',
        ] as $hook) {
            HookManager::clearFilter($hook);
        }
    }

    // ========================================
    // index() — 문의 목록 조회
    // ========================================

    #[Test]
    public function 비회원도_문의_목록을_조회할_수_있다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_settings',
            fn ($defaults) => $defaults,
            priority: 1
        );
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            fn () => [],
            priority: 1
        );

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        );

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'items',
                    'meta' => ['inquiry_available', 'board_settings', 'total', 'current_page', 'per_page', 'last_page'],
                ],
            ]);
    }

    /**
     * 작성자 이름은 회원 계정에서 배치로 모아 온 뒤 마스킹되어 내려간다.
     *
     * 이름은 행마다 조회하면 N+1 이 되므로 표시할 ID 만 모아 한 번에 읽는다
     * (`UserRepositoryInterface::getNamesByIds`). 이 경로가 비면 목록의 작성자
     * 칸이 게시글 스냅샷 이름으로 조용히 대체되어, 개명 후에도 옛 이름이 남는다.
     */
    #[Test]
    public function 문의_목록의_작성자_이름은_회원_계정에서_읽어_마스킹된다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        $user = $this->createUser();
        $user->forceFill(['name' => '홍길동'])->save();

        $pivot = ProductInquiry::create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'board_post',
            'inquirable_id' => 777,
            'user_id' => $user->id,
        ]);

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_settings',
            fn ($defaults) => $defaults,
            priority: 1
        );
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            fn () => [[
                'id' => $pivot->inquirable_id,
                'user_id' => $user->id,
                // 게시글 스냅샷에는 다른 이름이 들어 있다 — 회원 계정 이름이 이겨야 한다.
                'author_name' => '스냅샷이름',
                'title' => '문의 제목',
                'is_secret' => false,
            ]],
            priority: 1
        );

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        );

        $response->assertOk();

        // 존재 확정 후 값 비교 — 항목이 비면 이름 비교는 아무것도 증명하지 못한다.
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('홍*동', $items[0]['author_name']);
    }

    /**
     * 게시판 훅이 신원 판정(can_view_secret=false)만 하고 원문 필드 null 처리를 누락하더라도
     * 서비스가 그 권위 플래그로 payload 를 재확정한다 (KVE-2026-1914 A-2 이중 방어).
     *
     * 훅(get_by_ids)이 마스킹을 누락한 채 content/reply/attachments 를 실어 보내는 회귀를
     * 모사한다 — 서비스가 can_view_secret=false 를 신뢰해 content/reply/attachments 를
     * 다시 마스킹해야 원문이 새지 않는다. 자기 권한을 재계산하지 않으므로(플래그만 신뢰)
     * 게이트 강도가 훅과 갈리지 않는다.
     *
     * @scenario layer=service, viewer=non_viewer
     *
     * @effects service_remasks_when_hook_omits_masking, service_remasks_title_with_shared_placeholder
     */
    #[Test]
    public function 훅이_마스킹을_누락해도_서비스가_can_view_secret_플래그로_원문을_재차단한다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        $owner = $this->createUser(); // 조회자(비회원)와 다른 작성자
        $pivot = ProductInquiry::create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'board_post',
            'inquirable_id' => 555,
            'user_id' => $owner->id,
        ]);

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_settings',
            fn ($defaults) => $defaults,
            priority: 1
        );
        // 훅이 신원 판정만 하고 필드 마스킹을 누락한 상태(회귀)를 모사 — content/reply/attachments 가 원문 그대로 실려온다.
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            fn () => [[
                'id' => $pivot->inquirable_id,
                'user_id' => $owner->id,
                'author_name' => '작성자',
                'title' => '비밀 문의 제목',
                'is_secret' => true,
                'can_view_secret' => false,
                'content' => '유출되면 안 되는 비밀 내용',
                'reply' => '유출되면 안 되는 답변',
                'attachments' => [['id' => 1, 'original_filename' => 'secret.pdf']],
            ]],
            priority: 1
        );

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        );

        $response->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['is_secret']);
        $this->assertNull($items[0]['content'], '훅 누락 시에도 서비스가 content 를 재마스킹해야 합니다');
        $this->assertNull($items[0]['reply'], '훅 누락 시에도 서비스가 reply 를 재마스킹해야 합니다');
        $this->assertSame([], $items[0]['attachments'], '훅 누락 시에도 서비스가 attachments 를 비워야 합니다');
        // A2b: title 도 재마스킹 대상 — 훅이 실어 보낸 원문 제목이 유출되면 안 된다.
        $this->assertNotSame('비밀 문의 제목', $items[0]['title'], '훅 누락 시에도 서비스가 title 원문을 유출하면 안 됩니다');
        $this->assertSame(
            __('sirsoft-board::messages.post.secret_post_title'),
            $items[0]['title'],
            '훅 누락 시에도 서비스가 title 을 게시판 비밀글 플레이스홀더로 재마스킹해야 합니다'
        );
    }

    /**
     * 훅 payload 에 can_view_secret 키가 **아예 없으면** 서비스는 마스킹 쪽으로 닫힌다.
     *
     * `ProductInquiryService` 의 판정은 `($post['can_view_secret'] ?? false) === false` 라
     * 플래그 부재 시 fail-closed 다. 그런데 이 분기를 밟는 테스트가 없으면 `?? false` 를
     * `?? true`(fail-open)로 바꿔도 전 스위트가 green 이다 — 게시판 훅을 대체 구현한
     * 확장이 플래그를 실어 보내지 않는 순간 비밀 문의 원문이 그대로 나간다.
     *
     * 플래그를 명시 전달하는 위 테스트와 달리, 여기서는 키 자체를 생략한다.
     *
     * @scenario layer=service, viewer=non_viewer
     *
     * @effects service_fails_closed_when_flag_absent
     */
    #[Test]
    public function can_view_secret_키가_없으면_서비스가_마스킹_쪽으로_닫힌다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        $owner = $this->createUser();
        $pivot = ProductInquiry::create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'board_post',
            'inquirable_id' => 556,
            'user_id' => $owner->id,
        ]);

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_settings',
            fn ($defaults) => $defaults,
            priority: 1
        );
        // can_view_secret 키를 의도적으로 생략 — 권위 플래그를 실어 보내지 않는 훅 구현을 모사한다.
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            fn () => [[
                'id' => $pivot->inquirable_id,
                'user_id' => $owner->id,
                'author_name' => '작성자',
                'title' => '비밀 문의 제목',
                'is_secret' => true,
                'content' => '유출되면 안 되는 비밀 내용',
                'reply' => '유출되면 안 되는 답변',
                'attachments' => [['id' => 1, 'original_filename' => 'secret.pdf']],
            ]],
            priority: 1
        );

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        );

        $response->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['is_secret']);
        $this->assertNull($items[0]['content'], '플래그 부재 시 content 는 마스킹되어야 합니다(fail-closed)');
        $this->assertNull($items[0]['reply'], '플래그 부재 시 reply 는 마스킹되어야 합니다(fail-closed)');
        $this->assertSame([], $items[0]['attachments'], '플래그 부재 시 attachments 는 비워져야 합니다');
        $this->assertSame(
            __('sirsoft-board::messages.post.secret_post_title'),
            $items[0]['title'],
            '플래그 부재 시 title 도 플레이스홀더로 마스킹되어야 합니다'
        );
    }

    /**
     * 열람 권한이 있으면(can_view_secret=true) 비밀글 원문이 그대로 노출된다 (과잉 차단 회귀 방지).
     *
     * @scenario layer=service, viewer=owner
     *
     * @effects service_exposes_original_when_flag_true
     */
    #[Test]
    public function can_view_secret_true면_비밀글_원문이_노출된다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        $owner = $this->createUser();
        $pivot = ProductInquiry::create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'board_post',
            'inquirable_id' => 556,
            'user_id' => $owner->id,
        ]);

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_settings',
            fn ($defaults) => $defaults,
            priority: 1
        );
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            fn () => [[
                'id' => $pivot->inquirable_id,
                'user_id' => $owner->id,
                'author_name' => '작성자',
                'title' => '비밀 문의 제목',
                'is_secret' => true,
                'can_view_secret' => true,
                'content' => '열람 가능한 비밀 내용',
                'reply' => '열람 가능한 답변',
                'attachments' => [['id' => 1, 'original_filename' => 'ok.pdf']],
            ]],
            priority: 1
        );

        $items = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        )->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('열람 가능한 비밀 내용', $items[0]['content']);
        $this->assertSame('열람 가능한 답변', $items[0]['reply']);
        $this->assertCount(1, $items[0]['attachments']);
        // 과잉 차단 회귀 방지: 열람 권한이 있으면 title 도 원문 그대로여야 한다(A2b 재마스킹이 과하지 않음).
        $this->assertSame('비밀 문의 제목', $items[0]['title']);
    }

    /**
     * 화면 목록은 저장소 쿼리 레벨 페이지네이션을 써야 한다 (#102 동형 결함 해소).
     *
     * 종전에는 전량 get() 후 PHP forPage() 잘라내기라, 문의가 늘수록 한 페이지를 여는
     * 것만으로 전 행이 적재됐다. 페이지 경계 정확성과 LIMIT 사용을 함께 고정한다.
     */
    #[Test]
    public function 문의_목록은_쿼리_레벨_페이지네이션을_사용하고_페이지_경계가_정확하다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        for ($i = 1; $i <= 15; $i++) {
            ProductInquiry::create([
                'product_id' => $this->product->id,
                'inquirable_type' => 'board_post',
                'inquirable_id' => 1000 + $i,
                'user_id' => null,
            ]);
        }

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_settings',
            fn ($defaults) => $defaults,
            priority: 1
        );
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            fn ($_, $context) => array_map(
                fn ($id) => ['id' => $id, 'title' => '문의 '.$id, 'is_secret' => false],
                $context['ids'] ?? []
            ),
            priority: 1
        );

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries?page=2&per_page=10"
        );

        $response->assertOk();

        // 페이지 경계: 15건 중 두 번째 묶음(5건)
        $items = $response->json('data.items');
        $this->assertCount(5, $items);
        $this->assertSame(15, $response->json('data.meta.total'));
        $this->assertSame(2, $response->json('data.meta.current_page'));
        $this->assertSame(2, $response->json('data.meta.last_page'));
        // id 내림차순 전순서 — 2페이지 첫 항목은 1005 (1015..1006 이 1페이지)
        $this->assertSame(1005, $items[0]['post_id']);

        // 쿼리 레벨 페이지네이션 — 피벗 조회 SELECT 에 LIMIT 이 있어야 한다
        $limitedPivotSelects = array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'product_inquiries')
                && stripos(ltrim($sql), 'select') === 0
                && stripos($sql, 'limit') !== false
        );
        $this->assertNotEmpty(
            $limitedPivotSelects,
            '문의 피벗 조회에 LIMIT 이 없습니다 — 전량 적재 후 PHP 잘라내기(#102 동형)입니다.'
        );
    }

    #[Test]
    public function board_slug_미설정_시_빈_목록과_inquiry_available_false를_반환한다(): void
    {
        // board_slug를 null로 초기화
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', null);

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        );

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'items' => [],
                    'meta' => [
                        'inquiry_available' => false,
                        'total' => 0,
                    ],
                ],
            ]);
    }

    /**
     * 존재하지 않는 상품은 404 를 반환한다.
     *
     * 상품 파라미터는 라우트 모델 바인딩(product_code 또는 id)으로 해석되므로, 없는 상품은
     * 컨트롤러에 진입하기 전에 404 가 된다 (#450 상품코드 라우팅 전환). 문의 게시판 설정 여부와
     * 무관하다 — 설정 미비로 빈 목록을 주는 경우는 **존재하는 상품**에 한하며 그 계약은
     * `board_slug_미설정_시_빈_목록과_inquiry_available_false를_반환한다` 가 담당한다.
     */
    #[Test]
    public function 존재하지_않는_상품_조회_시_404를_반환한다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', null);

        $this->getJson('/api/modules/sirsoft-ecommerce/products/99999/inquiries')
            ->assertNotFound();
    }

    // ========================================
    // store() — 문의 작성
    // ========================================

    #[Test]
    public function 비인증_사용자는_문의를_작성할_수_없다(): void
    {
        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries",
            ['content' => '문의 내용입니다 자세하게']
        );

        $response->assertUnauthorized();
    }

    /**
     * 서비스가 클라이언트 IP 를 게시판 훅 payload 에 주입한다 (문의 생성 경로).
     *
     * 게시판 Listener 는 `request()->ip()` 를 참조하지 않고 payload 의 ip_address 만
     * 쓰도록 경계가 잡혀 있다(Listener 측 회귀 테스트 별도 존재). 그 경계가 성립하려면
     * **요청 경계인 이 서비스가 IP 를 실어 보내야** 하는데, 주입 측을 단언하는 테스트가
     * 없으면 주입 코드를 지워도 스위트가 green 이고 문의 IP 가 조용히 0.0.0.0 이 된다.
     *
     * @effects service_injects_client_ip_into_hook_payload
     */
    #[Test]
    public function 서비스가_문의_생성_훅_payload_에_클라이언트_ip_를_주입한다(): void
    {
        $user = $this->createUser();

        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        $capturedIp = null;
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.create',
            function ($result, $slug, $data) use (&$capturedIp) {
                $capturedIp = $data['ip_address'] ?? null;

                return ['post_id' => 999, 'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post'];
            },
            priority: 1
        );

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->postJson(
                "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries",
                ['content' => '문의 내용입니다 자세하게']
            )
            ->assertStatus(201);

        $this->assertSame(
            '203.0.113.55',
            $capturedIp,
            '서비스가 요청 IP 를 게시판 훅 payload 로 주입해야 합니다'
        );
    }

    #[Test]
    public function 로그인_사용자는_문의를_작성할_수_있다(): void
    {
        $user = $this->createUser();

        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.create',
            fn () => ['post_id' => 999, 'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post'],
            priority: 1
        );

        $response = $this->actingAs($user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries",
                ['content' => '문의 내용입니다 자세하게']
            );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id'],
            ]);

        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'product_id' => $this->product->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function 필수_필드_content_없이_요청_시_422를_반환한다(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries",
                [] // content 누락
            );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }
}
