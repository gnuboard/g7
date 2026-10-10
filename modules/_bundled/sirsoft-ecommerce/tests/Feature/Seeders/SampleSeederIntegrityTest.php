<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seeders;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Database\Seeders\DatabaseSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ClaimSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\OrderSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ProductInquirySeeder;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 이커머스 샘플 시더 정합 시험 (리포트 2.1 보강 시험)
 *
 * 샘플 전체(`--sample`)를 작은 건수로 실제 실행한 뒤 주문·결제·배송·취소·클레임·마일리지·리뷰의
 * 불변식을 확인하고, 샘플 없이 돌리거나 단독으로 돌릴 때 운영 데이터가 남는지 확인한다.
 * 외부 이미지 내려받기는 Http::fake 로 막는다(이미지 없이 상품만 만든다).
 */
class SampleSeederIntegrityTest extends ModuleTestCase
{
    /**
     * 가짜 게시판의 문의 글 저장소 [post_id => ['reply' => bool]]
     *
     * @var array<int, array{reply: bool}>
     */
    private array $fakePosts = [];

    /**
     * 가짜 게시판이 정리 요청으로 받은 글 id 목록
     *
     * @var array<int, int>
     */
    private array $purgedPostIds = [];

    /**
     * 다음 가짜 글 id
     */
    private int $nextPostId = 1;

    /**
     * 외부 이미지 요청·저장소를 가짜로 바꾸고 회원 30명·관리자 1명을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        Storage::fake('public');
        Http::fake(['*' => Http::response('', 404)]);

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        $adminRole = Role::query()->where('identifier', 'admin')->firstOrFail();

        User::factory()->count(30)->create()
            ->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));
        User::factory()->create()->roles()->attach($adminRole->id, ['assigned_at' => now()]);
    }

    /**
     * 샘플 전체 실행 뒤 주문·결제·배송·취소 문서·시각·금액식 불변식이 성립한다.
     *
     * @scenario run=full_sample
     *
     * @effects every_order_has_payment_and_shipping, pg_rows_use_registered_provider_and_test_marker, tracking_always_has_carrier, cancelled_orders_have_documents, timelines_are_monotonic, order_amount_formula_holds, claim_course_states_present, mileage_never_consumes_future_lot, earn_balance_after_includes_itself, review_snapshot_is_json_object
     *
     * @return void 반환값 없음
     */
    public function test_full_sample_satisfies_order_claim_mileage_invariants(): void
    {
        // 켜진 결제 플러그인 하나를 흉내 낸다 — 카드·가상계좌 샘플이 그 id 로 기록돼야 한다
        HookManager::addFilter('sirsoft-ecommerce.payment.registered_pg_providers', fn ($providers) => [...(array) $providers, ['id' => 'tosspayments']]);

        $this->runSample(orders: 100);

        $orders = Order::count();
        $this->assertGreaterThanOrEqual(50, $orders, '상태별 최소 5건 × 10 상태');

        // E-1 결제 행 없는 주문 0 · E-4 배송 행 없는 주문 0
        $this->assertSame(0, Order::whereDoesntHave('payment')->count(), '모든 주문에 결제 행이 있어야 합니다');
        $this->assertSame(0, Order::whereDoesntHave('shippings')->count(), '모든 주문에 배송 행이 있어야 합니다');

        // E-2 pg_provider ∈ 등록 id ∪ {manual, internal}, 가짜 id·빈 값 0
        $providers = OrderPayment::query()->distinct()->pluck('pg_provider')->all();
        $this->assertEmpty(array_diff($providers, ['tosspayments', 'manual', 'internal']), 'PG id 는 등록 목록 또는 manual 이어야 합니다: '.implode(',', $providers));
        $this->assertContains('tosspayments', $providers, '켜진 결제 플러그인이 있으면 카드·가상계좌 샘플이 그 id 로 기록돼야 합니다');

        // PG 샘플: 거래번호 SAMPLE- 접두 + payment_meta.is_test_mode
        OrderPayment::where('pg_provider', 'tosspayments')->get()->each(function (OrderPayment $p) {
            $this->assertTrue((bool) ($p->payment_meta['is_test_mode'] ?? false), "PG 샘플 결제 {$p->id} 에 테스트 표식이 있어야 합니다");
            if ($p->transaction_id !== null) {
                $this->assertStringStartsWith(OrderSeeder::SAMPLE_TRANSACTION_PREFIX, $p->transaction_id);
            }
        });

        // 결제 대기(입금형)는 입금 대기 + 미래 기한
        OrderPayment::whereHas('order', fn ($q) => $q->where('order_status', OrderStatusEnum::PENDING_PAYMENT->value))
            ->whereIn('payment_method', ['vbank', 'dbank'])->get()
            ->each(function (OrderPayment $p) {
                $this->assertSame('waiting_deposit', $p->payment_status->value);
                $this->assertTrue(($p->vbank_due_at ?? $p->deposit_due_at)?->isFuture() ?? false, '입금기한은 미래여야 합니다');
            });

        // E-5 송장 있고 택배사 없는 배송 0
        $this->assertSame(0, OrderShipping::whereNotNull('tracking_number')->where('tracking_number', '!=', '')->whereNull('carrier_id')->count());

        // E-6 취소 주문은 취소신청서, 결제 후 취소는 환불신청서
        $cancelled = Order::where('order_status', OrderStatusEnum::CANCELLED->value)->get();
        foreach ($cancelled as $order) {
            $this->assertTrue(DB::table('ecommerce_order_cancels')->where('order_id', $order->id)->exists(), "취소 주문 {$order->id} 에 취소신청서가 있어야 합니다");
            if ($order->paid_at !== null) {
                $this->assertTrue(DB::table('ecommerce_order_refunds')->where('order_id', $order->id)->exists(), "결제 후 취소 주문 {$order->id} 에 환불신청서가 있어야 합니다");
            }
        }

        // E-7 시각 단조
        $this->assertSame(0, Order::whereColumn('paid_at', '<', 'ordered_at')->count(), '결제가 주문보다 먼저일 수 없습니다');
        $this->assertSame(0, OrderShipping::whereColumn('delivered_at', '<', 'shipped_at')->count());
        $this->assertSame(0, DB::table('ecommerce_order_options')->whereColumn('confirmed_at', '<', 'delivered_at')->count());
        $this->assertSame(0, Order::where('ordered_at', '>', now()->addMinute())->count(), '미래 주문이 없어야 합니다');

        // 금액식: 결제금액 + 마일리지 = 상품금액 − 할인 + 배송비, 결제 이후(취소 제외) 결제 행 금액 = 받은 돈
        Order::all()->each(function (Order $o) {
            $this->assertEqualsWithDelta(
                (float) $o->subtotal_amount - (float) $o->total_discount_amount + (float) $o->total_shipping_amount,
                (float) $o->total_amount + (float) $o->total_points_used_amount,
                0.01,
                "주문 {$o->id} 금액식",
            );
        });

        // E-8·SE-6 클레임 축 샘플
        $this->assertGreaterThan(0, DB::table('ecommerce_order_exchanges')->count(), '교환 샘플');
        $this->assertGreaterThan(0, DB::table('ecommerce_order_returns')->count(), '반품 샘플');
        $this->assertGreaterThan(0, DB::table('ecommerce_claim_payments')->count(), '추가결제 샘플');
        $this->assertGreaterThan(0, DB::table('ecommerce_order_refunds')->where('refund_method_overridden', true)->count(), '환불수단 변경 샘플');
        $this->assertTrue(MileageTransaction::where('type', MileageTransactionTypeEnum::REFUND_CREDIT->value)->exists(), '현금분 마일리지 지급 샘플');
        $this->assertTrue(MileageTransaction::where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->exists(), '환불 때 사용 마일리지 환급 샘플');
        $this->assertGreaterThan(0, DB::table('ecommerce_shipping_policy_claim_addresses')->count(), '클레임 회수지 (E-A1)');

        // SE-2 사용·차감이 그보다 뒤에 생긴 적립을 소비하지 않는다
        $future = DB::table('ecommerce_mileage_transactions as u')
            ->join('ecommerce_mileage_transactions as s', 's.id', '=', 'u.source_transaction_id')
            ->whereColumn('u.created_at', '<', 's.created_at')
            ->count();
        $this->assertSame(0, $future, '미래 적립을 소비한 거래가 없어야 합니다');

        // 결제액 일치: 결제가 일어난(취소·클레임 코스 제외) 주문은 결제 행 받은 금액 = 주문 받은 돈
        Order::whereNotNull('paid_at')
            ->whereIn('order_status', array_map(fn ($s) => $s->value, OrderStatusEnum::salesEligibleStatuses()))
            ->with('payment')->get()
            ->each(fn (Order $o) => $this->assertEqualsWithDelta((float) $o->total_paid_amount, (float) $o->payment?->paid_amount_local, 0.01, "주문 {$o->id} 결제액"));

        // SE-1 balance_after 연쇄: 시각순으로 직전 balance_after + 이번 금액 = 이번 balance_after.
        // 회원 처리가 끝난 뒤 과거 시각으로 끼워 넣는 거래(클레임 환불 마일리지, 만료 소멸)가 있는 회원은 뺀다.
        $refundUsers = MileageTransaction::where(fn ($q) => $q->where('type', 'like', 'refund_%')->orWhere('type', MileageTransactionTypeEnum::EXPIRED->value))
            ->pluck('user_id')->unique()->all();
        MileageTransaction::whereNotIn('user_id', $refundUsers ?: [0])->orderBy('user_id')->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('user_id')
            ->each(function ($rows) {
                $running = 0.0;
                foreach ($rows as $tx) {
                    $running = round($running + (float) $tx->amount, 2);
                    $this->assertEqualsWithDelta($running, (float) $tx->balance_after, 0.01, "거래 {$tx->id} balance_after 연쇄");
                }
            });

        // SE-1 적립 거래의 balance_after 는 적립액을 포함한다
        MileageTransaction::where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)->get()
            ->each(fn (MileageTransaction $tx) => $this->assertGreaterThanOrEqual((float) $tx->amount - 0.01, (float) $tx->balance_after));

        // E-11 리뷰 옵션 스냅샷은 JSON 객체(문자열로 감싸지 않음), E-12 답변은 리뷰 뒤
        $this->assertSame(0, DB::table('ecommerce_product_reviews')->whereNotNull('option_snapshot')->whereRaw("JSON_TYPE(option_snapshot) = 'STRING'")->count());
        $this->assertSame(0, DB::table('ecommerce_product_reviews')->whereColumn('replied_at', '<', 'created_at')->count());
    }

    /**
     * 전체 재시드를 두 번 해도 게시판 문의 글이 늘지 않고, 정리 요청에는 지운 상품의 문의 글만 실린다.
     *
     * @scenario run=reseed_twice
     *
     * @effects inquiry_board_posts_do_not_accumulate
     *
     * @return void 반환값 없음
     */
    public function test_reseed_twice_does_not_accumulate_inquiry_posts(): void
    {
        $this->fakeInquiryBoard();

        $this->runSample();
        $firstInquiries = ProductInquiry::count();
        $firstPostIds = array_keys($this->fakePosts);
        $this->assertSame('product-inquiry', app(EcommerceSettingsService::class)->getSetting('inquiry.board_slug'), '비어 있던 문의 게시판 설정에 product-inquiry 가 연결돼야 합니다');

        // 게시판에 이커머스와 무관한 글 하나가 있다고 치자 — 정리 요청에 실리면 안 된다
        $this->fakePosts[999999] = ['reply' => true];

        $this->runSample();

        $this->assertEqualsCanonicalizing($firstPostIds, $this->purgedPostIds, '정리 요청에는 이전 문의 글 id 만 실려야 합니다');
        $this->assertArrayHasKey(999999, $this->fakePosts, '문의와 무관한 게시판 글은 남아야 합니다');
        $this->assertCount(ProductInquiry::count() + 1, $this->fakePosts, '재시드 뒤 게시판 문의 글 수 = 새 문의 수 (누적 없음)');
        $this->assertGreaterThan(0, $firstInquiries);
    }

    /**
     * 문의 시더만 따로 돌렸는데 문의가 이미 있으면 지우지도 만들지도 않는다.
     *
     * @scenario run=inquiry_standalone
     *
     * @effects existing_inquiries_survive_standalone_run
     *
     * @return void 반환값 없음
     */
    public function test_standalone_inquiry_seeder_keeps_existing_inquiries(): void
    {
        $this->fakeInquiryBoard();
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'product-inquiry');

        $product = Product::factory()->create();
        $existing = ProductInquiry::create([
            'product_id' => $product->id,
            'inquirable_type' => 'fake_post',
            'inquirable_id' => 4242,
            'user_id' => null,
            'is_answered' => true,
            'answered_at' => now(),
            'product_name_snapshot' => ['ko' => '실제 문의 상품'],
        ]);
        $this->fakePosts[4242] = ['reply' => true];

        (new ProductInquirySeeder)->setCommand($this->command())->run();

        $this->assertSame(1, ProductInquiry::count(), '기존 문의는 그대로, 새 문의는 만들지 않아야 합니다');
        $this->assertTrue(ProductInquiry::whereKey($existing->id)->exists());
        $this->assertSame([], $this->purgedPostIds, '게시판에 정리 요청을 보내지 않아야 합니다');
        $this->assertArrayHasKey(4242, $this->fakePosts);
    }

    /**
     * 클레임 시더만 따로 돌리면 기존(운영) 주문에 클레임을 붙이지 않고 건너뛴다.
     *
     * @scenario run=claim_standalone
     *
     * @effects claim_seeder_standalone_leaves_orders_untouched
     *
     * @return void 반환값 없음
     */
    public function test_claim_seeder_standalone_leaves_existing_orders(): void
    {
        $member = User::whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))->first();
        $orders = Order::factory()->count(3)->delivered()->forUser($member)->create();
        foreach ($orders as $order) {
            OrderOption::factory()->forOrder($order)->create([
                'option_status' => OrderStatusEnum::DELIVERED,
                'quantity' => 2,
            ]);
        }

        (new ClaimSeeder)->setCommand($this->command())->run();

        $this->assertSame(0, DB::table('ecommerce_order_exchanges')->count(), '단독 실행은 교환을 만들지 않아야 합니다');
        $this->assertSame(0, DB::table('ecommerce_order_returns')->count(), '단독 실행은 반품을 만들지 않아야 합니다');
        $this->assertSame(0, DB::table('ecommerce_order_refunds')->count(), '단독 실행은 환불을 만들지 않아야 합니다');
        $this->assertSame(3, Order::where('order_status', OrderStatusEnum::DELIVERED->value)->count(), '기존 주문 상태는 그대로여야 합니다');
    }

    /**
     * 샘플 없이 돌리면 회원 배송지·실제 활동 로그를 건드리지 않고, 대화형이 아닌 샘플 실행은 --force 없이 멈춘다.
     *
     * @scenario run=non_interactive
     *
     * @effects user_addresses_untouched_without_sample, real_activity_logs_preserved, non_interactive_without_force_aborts
     *
     * @return void 반환값 없음
     */
    public function test_operational_data_is_protected(): void
    {
        $user = User::first();
        UserAddress::factory()->forUser($user)->count(2)->create();
        $realLog = ActivityLog::create([
            'log_type' => 'admin',
            'user_id' => $user->id,
            'action' => 'product.update',
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_update',
            'description_params' => ['product_name' => '실제 상품'],
            'properties' => ['product_id' => 1],
        ]);

        // E-16 샘플 없이 시드 — 배송지 그대로
        $this->runSeeder(sample: false);
        $this->assertSame(2, UserAddress::count(), '샘플 없이 시드하면 회원 배송지를 지우지 않아야 합니다');

        // SE-7 대화형이 아니고 --force 가 없으면 샘플을 만들지 않는다
        $product = Product::factory()->create();
        $this->runSeeder(sample: true, force: false);
        $this->assertTrue(Product::whereKey($product->id)->exists(), '확인 없이 상품을 지우면 안 됩니다');

        // E-15 샘플 재시드(강제)도 실제 활동 로그는 남긴다
        $this->runSeeder(sample: true, force: true, counts: ['products' => 4, 'orders' => 50]);
        $this->assertTrue(ActivityLog::whereKey($realLog->id)->exists(), '표식 없는 실제 활동 로그는 보존돼야 합니다');
        $this->assertGreaterThanOrEqual(2, UserAddress::where('user_id', $user->id)->count(), '기존 배송지는 그대로 남아야 합니다');
    }

    /**
     * 설치 시더(getSeeders)만으로 배송유형 카탈로그가 채워진다.
     *
     * @scenario run=install_only
     *
     * @effects install_seeders_fill_shipping_types
     *
     * @return void 반환값 없음
     */
    public function test_install_seeders_fill_shipping_types(): void
    {
        ShippingType::query()->delete();

        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        foreach ($module->getSeeders() as $seederClass) {
            $this->seed($seederClass);
        }

        $this->assertSame(11, ShippingType::count(), '배송유형 기본 11종이 새 설치에 있어야 합니다');
    }

    /**
     * 샘플 전체를 작은 건수로 실행합니다.
     *
     * @param  int  $orders  주문 수
     * @return void 반환값 없음
     */
    private function runSample(int $orders = 60): void
    {
        $this->runSeeder(sample: true, force: true, counts: ['products' => 6, 'orders' => $orders]);
    }

    /**
     * 이커머스 DatabaseSeeder 를 실행합니다.
     *
     * @param  bool  $sample  샘플 포함 여부 (--sample)
     * @param  bool  $force  강제 진행 여부 (--force, 대화형이 아닌 입력)
     * @param  array<string, int>  $counts  건수 옵션 (--count)
     * @return void 반환값 없음
     */
    private function runSeeder(bool $sample, bool $force = true, array $counts = []): void
    {
        $seeder = new DatabaseSeeder;
        $seeder->setContainer($this->app);
        $seeder->setCommand($this->command($force));
        $seeder->setIncludeSample($sample);
        $seeder->setSeederCounts($counts);
        $seeder->run();
    }

    /**
     * 출력을 버리는 비대화형 콘솔 명령을 만듭니다.
     *
     * @param  bool  $force  --force 옵션 값
     * @return Command 명령
     */
    private function command(bool $force = true): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'test:ecommerce-sample-seed {--force}';
        };
        $command->setLaravel($this->app);

        $input = new ArrayInput($force ? ['--force' => true] : [], $command->getDefinition());
        $input->setInteractive(false);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new NullOutput));

        return $command;
    }

    /**
     * 게시판 모듈 대신 문의 훅 세 개에 답하는 가짜 게시판을 붙입니다 (product-inquiry 게시판).
     *
     * @return void 반환값 없음
     */
    private function fakeInquiryBoard(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.inquiry.get_settings', fn ($carry, $slug = null) => $slug === 'product-inquiry' ? ['slug' => $slug] : $carry);

        HookManager::addFilter('sirsoft-ecommerce.inquiry.seed_sample_post', function ($carry, $slug = null, $question = [], $reply = null) {
            $id = $this->nextPostId++;
            $this->fakePosts[$id] = ['reply' => $reply !== null];

            return ['post_id' => $id, 'inquirable_type' => 'fake_post'];
        });

        HookManager::addFilter('sirsoft-ecommerce.inquiry.purge_sample_posts', function ($carry, $slug = null, $inquirables = []) {
            foreach ((array) $inquirables as $ids) {
                foreach ((array) $ids as $id) {
                    $this->purgedPostIds[] = (int) $id;
                    unset($this->fakePosts[(int) $id]);
                }
            }

            return $carry;
        });
    }
}
