<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * Playwright E2E · 브라우저 실측용 회원 이커머스 데이터 시드 커맨드 (#524 WP-E · WP-C2).
 *
 * 관리자 목록 네 갈래(찜 · 장바구니 · 배송지 · 상품문의)와 「숨김 상품은 찜 목록에서 빠진다」를
 * 한 번에 세우려면, 회원 하나에 네 갈래 데이터가 있고 상품이 «정상 · 숨김 · 판매중지» 세 상태로
 * 갈려 있어야 한다. 기존 시드는 상품·주문만 만들어 그 상태를 세울 수 없다.
 *
 * 만드는 것:
 *   - 회원 하나(고정 이메일)
 *   - 상품 셋 — 정상(P1) · 숨김(P2) · 판매중지(P3)
 *   - 찜 3건(세 상품 모두) — 화면에는 P2 가 빠지고 P1·P3 만 남아야 한다
 *   - 장바구니 회원 2건 + 비회원 1건 — 관리자 목록의 `owner` 필터가 가르는 두 쪽
 *   - 배송지 2건(하나는 기본 배송지) — `is_default` 필터의 두 쪽
 *   - 상품문의 1건(미답변) — `is_answered` 필터의 한쪽
 *
 * 재실행하면 같은 계정·상품을 먼저 지우고 다시 만든다(멱등).
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 · ② G7_PLAYWRIGHT_BYPASS=1 · ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-ecommerce-member-data --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-ecommerce-member-data --teardown
 */
class PlaywrightSeedMemberData extends Command
{
    /**
     * 고정 회원 이메일 — 재실행 시 같은 계정을 지우고 다시 만든다.
     */
    private const EMAIL = 'e2e-shop-member@example.test';

    /**
     * 고정 상품 코드 — 상태별로 셋.
     */
    private const CODE_VISIBLE = 'E2E524-VISIBLE';

    private const CODE_HIDDEN = 'E2E524-HIDDEN';

    private const CODE_SUSPENDED = 'E2E524-SUSPENDED';

    /**
     * 비회원 장바구니 식별자.
     */
    private const GUEST_CART_KEY = 'e2e524-guest-cart';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-ecommerce-member-data
        {--teardown : 시드한 데이터를 전량 삭제한다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 실측용 회원 이커머스 데이터 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다.');

            return self::FAILURE;
        }

        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.');

            return self::FAILURE;
        }

        Config::set('app.debug', true);

        $result = $this->option('teardown') ? $this->teardown() : $this->seed();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result as $key => $value) {
                $this->line($key.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * 시드 데이터를 만듭니다.
     *
     * @return array<string, mixed> 만들어진 식별자 모음
     */
    private function seed(): array
    {
        return DB::transaction(function () {
            $this->purge();

            $member = User::create([
                'name' => '쇼핑회원E2E',
                'email' => self::EMAIL,
                'password' => Hash::make('e2e-shop-password'),
                'email_verified_at' => now(),
            ]);

            $visible = Product::factory()->onSale()->create(['product_code' => self::CODE_VISIBLE]);
            $hidden = Product::factory()->hidden()->create(['product_code' => self::CODE_HIDDEN]);
            $suspended = Product::factory()->suspended()->create(['product_code' => self::CODE_SUSPENDED]);

            // 찜 — 셋 다 건다. 화면에서 숨김(P2)만 빠지는 것이 이 시드의 관측점이다.
            foreach ([$visible, $hidden, $suspended] as $product) {
                ProductWishlist::create(['user_id' => $member->id, 'product_id' => $product->id]);
            }

            // 장바구니 — 회원 것과 비회원 것을 함께 둔다(정리 기준이 서로 다르다).
            CartFactory::new()->create([
                'cart_key' => 'e2e524-member-cart-1',
                'user_id' => $member->id,
                'product_id' => $visible->id,
                'quantity' => 2,
            ]);
            CartFactory::new()->create([
                'cart_key' => 'e2e524-member-cart-2',
                'user_id' => $member->id,
                'product_id' => $suspended->id,
                'quantity' => 1,
            ]);
            CartFactory::new()->create([
                'cart_key' => self::GUEST_CART_KEY,
                'user_id' => null,
                'product_id' => $visible->id,
                'quantity' => 3,
            ]);

            // 배송지 — 기본 배송지 하나와 아닌 것 하나.
            UserAddress::create([
                'user_id' => $member->id,
                'name' => '집',
                'recipient_name' => '받는사람A',
                'recipient_phone' => '010-0000-0001',
                'country_code' => 'KR',
                'zipcode' => '06236',
                'address' => '서울특별시 강남구 테헤란로',
                'address_detail' => '101호',
                'is_default' => true,
            ]);
            UserAddress::create([
                'user_id' => $member->id,
                'name' => '회사',
                'recipient_name' => '받는사람B',
                'recipient_phone' => '010-0000-0002',
                'country_code' => 'KR',
                'zipcode' => '13529',
                'address' => '경기도 성남시 분당구 판교역로',
                'address_detail' => '202호',
                'is_default' => false,
            ]);

            $inquiry = ProductInquiry::factory()->create([
                'user_id' => $member->id,
                'product_id' => $visible->id,
                'is_answered' => false,
            ]);

            return [
                'member_uuid' => $member->uuid,
                'member_email' => $member->email,
                'product_visible_id' => $visible->id,
                'product_hidden_id' => $hidden->id,
                'product_suspended_id' => $suspended->id,
                'guest_cart_key' => self::GUEST_CART_KEY,
                'inquiry_id' => $inquiry->id,
            ];
        });
    }

    /**
     * 시드 데이터를 전량 삭제합니다.
     *
     * @return array<string, int> 삭제 건수
     */
    private function teardown(): array
    {
        return DB::transaction(fn () => $this->purge());
    }

    /**
     * 고정 계정·상품에 딸린 데이터를 지웁니다 (재실행 멱등의 핵심).
     *
     * @return array<string, int> 삭제 건수
     */
    private function purge(): array
    {
        $userId = DB::table((new User)->getTable())->where('email', self::EMAIL)->value('id');
        $productIds = DB::table((new Product)->getTable())
            ->whereIn('product_code', [self::CODE_VISIBLE, self::CODE_HIDDEN, self::CODE_SUSPENDED])
            ->pluck('id')
            ->all();

        $counts = [
            'deleted_wishlists' => 0,
            'deleted_carts' => 0,
            'deleted_addresses' => 0,
            'deleted_inquiries' => 0,
            'deleted_products' => 0,
            'deleted_users' => 0,
        ];

        if ($userId !== null) {
            $counts['deleted_wishlists'] = DB::table((new ProductWishlist)->getTable())->where('user_id', $userId)->delete();
            $counts['deleted_carts'] = DB::table((new Cart)->getTable())->where('user_id', $userId)->delete();
            $counts['deleted_addresses'] = DB::table((new UserAddress)->getTable())->where('user_id', $userId)->delete();
            $counts['deleted_inquiries'] = DB::table((new ProductInquiry)->getTable())->where('user_id', $userId)->delete();
        }

        // 비회원 장바구니는 user_id 가 없어 위 조건에 걸리지 않는다 — 키로 따로 지운다.
        $counts['deleted_carts'] += DB::table((new Cart)->getTable())->where('cart_key', self::GUEST_CART_KEY)->delete();

        if ($productIds !== []) {
            DB::table((new ProductWishlist)->getTable())->whereIn('product_id', $productIds)->delete();
            DB::table((new Cart)->getTable())->whereIn('product_id', $productIds)->delete();
            DB::table((new ProductInquiry)->getTable())->whereIn('product_id', $productIds)->delete();
            $counts['deleted_products'] = DB::table((new Product)->getTable())->whereIn('id', $productIds)->delete();
        }

        if ($userId !== null) {
            $counts['deleted_users'] = DB::table((new User)->getTable())->where('id', $userId)->delete();
        }

        return $counts;
    }
}
