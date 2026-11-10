<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seeders;

use App\Extension\HookManager;
use App\Models\User;
use Database\Seeders\Sample\ThumbnailMeasureKit;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ThumbnailMeasureSeeder;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 이커머스 이미지 썸네일 실측 시더(개발 DB 전용 샘플 시더)의 시작 안내, 정리 대상 선택, 배송정책 경로, 표 모양을 검증한다.
 *
 * - 옵트인(`G7_PLAYWRIGHT_BYPASS=1`) 없이 부르면 시작 안내만 찍고 데이터에 손대기 전에 멈춘다.
 * - 정리 시더는 주문번호 접두만 같은 남의 주문을 지우지 않는다 — 실측 회원의 주문이거나 주문자 이름에 실측 표지가 있어야 한다.
 * - 배송정책은 관리자 화면과 같은 서비스(생성, 삭제 훅 포함)를 지난다.
 * - 실측 데이터 표의 행을 만드는 함수는 DB 없이 부를 수 있고, 그 열 수가 머리글 수(7)와 같다.
 */
class ThumbnailMeasureSeederTest extends ModuleTestCase
{
    /** 실측 시더의 옵트인 환경변수 */
    private const BYPASS = 'G7_PLAYWRIGHT_BYPASS';

    /** @var array{process: string|false, env: string|null, server: string|null} 원래 환경변수 */
    private array $originalBypass;

    /**
     * 옵트인 환경변수를 기억하고 세 통로에서 모두 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBypass = [
            'process' => getenv(self::BYPASS),
            'env' => $_ENV[self::BYPASS] ?? null,
            'server' => $_SERVER[self::BYPASS] ?? null,
        ];
        putenv(self::BYPASS);
        unset($_ENV[self::BYPASS], $_SERVER[self::BYPASS]);
    }

    /**
     * 옵트인 환경변수를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $original = $this->originalBypass;
        putenv($original['process'] === false ? self::BYPASS : self::BYPASS.'='.$original['process']);
        if ($original['env'] !== null) {
            $_ENV[self::BYPASS] = $original['env'];
        }
        if ($original['server'] !== null) {
            $_SERVER[self::BYPASS] = $original['server'];
        }

        parent::tearDown();
    }

    /**
     * 검사할 사이트 언어.
     *
     * @return array<string, array{0: string}> 로케일
     */
    public static function locales(): array
    {
        return ['ko' => ['ko'], 'en' => ['en']];
    }

    /**
     * 생성, 정리 시더는 run() 첫머리에서 그 언어의 시작 안내를 찍고, 옵트인이 없으면 그 뒤에 멈춘다.
     *
     * @scenario seeder=generate
     *
     * @effects start_message_printed_first
     *
     * @param  string  $locale  사이트 언어
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_seeders_print_start_message_first(string $locale): void
    {
        $this->app->setLocale($locale);

        foreach ([
            ThumbnailMeasureSeeder::class => 'sirsoft-ecommerce::messages.seeders.thumbnail_measure.start',
            ThumbnailMeasureCleanupSeeder::class => 'sirsoft-ecommerce::messages.seeders.thumbnail_measure.cleanup_start',
        ] as $seeder => $key) {
            $expected = __($key);
            $this->assertNotSame($key, $expected, $key.' 번역이 없습니다');

            $buffer = new BufferedOutput;
            try {
                (new $seeder)->setCommand($this->bufferedCommand($buffer))->run();
                $this->fail($seeder.' 가 옵트인 없이 실행됐습니다');
            } catch (RuntimeException) {
                // 옵트인 가드 거부 — 그 전에 찍힌 안내만 본다
            }

            $this->assertStringContainsString($expected, $buffer->fetch(), $seeder);
        }
    }

    /**
     * 정리 시더는 주문번호 접두만 같은 남의 주문은 남기고, 실측 회원의 주문과 주문자 이름에 표지가 있는 주문만 지운다.
     *
     * @scenario seeder=cleanup
     *
     * @effects cleanup_keeps_foreign_orders
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_cleanup_keeps_orders_that_only_share_the_number_prefix(): void
    {
        User::factory()->create(['is_super' => true]);
        $author = User::factory()->create(['email' => ThumbnailMeasureKit::AUTHOR_EMAIL]);
        $stranger = User::factory()->create();

        $foreign = $this->order($stranger, ThumbnailMeasureSeeder::ORDER_PREFIX.'FOREIGN-1', 'Operator Kim');
        $owned = $this->order($author, ThumbnailMeasureSeeder::ORDER_PREFIX.'REVIEW-1', 'Operator Lee');
        $marked = $this->order($stranger, ThumbnailMeasureSeeder::ORDER_PREFIX.'CLAIM-1', ThumbnailMeasureKit::MARK.' 주문자');

        $counts = (new ThumbnailMeasureCleanupSeeder)->purge();

        $this->assertTrue(Order::withTrashed()->whereKey($foreign->id)->exists(), '접두만 같은 남의 주문이 지워졌습니다');
        $this->assertFalse(Order::withTrashed()->whereKey($owned->id)->exists(), '실측 회원 주문이 남았습니다');
        $this->assertFalse(Order::withTrashed()->whereKey($marked->id)->exists(), '표지 주문이 남았습니다');
        $this->assertSame(2, $counts['orders']);
    }

    /**
     * 배송정책은 관리자 화면과 같은 서비스로 만들고 지운다 — 생성, 삭제 훅이 한 번씩 돈다.
     *
     * @scenario seeder=cleanup
     *
     * @effects shipping_policy_goes_through_service
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_shipping_policy_is_created_and_deleted_through_service(): void
    {
        $admin = User::factory()->create(['is_super' => true]);
        $created = [];
        $deleted = [];
        HookManager::addAction('sirsoft-ecommerce.shipping_policy.after_create', function ($policy) use (&$created) {
            $created[] = $policy->id;
        });
        HookManager::addAction('sirsoft-ecommerce.shipping_policy.after_delete', function ($id) use (&$deleted) {
            $deleted[] = (int) $id;
        });

        Auth::setUser($admin);
        $method = new ReflectionMethod(ThumbnailMeasureSeeder::class, 'shippingPolicy');
        /** @var ShippingPolicy $policy */
        $policy = $method->invoke(new ThumbnailMeasureSeeder);
        Auth::forgetUser();

        $this->assertSame([$policy->id], $created);
        $this->assertTrue(ThumbnailMeasureKit::hasMark($policy->name));
        $this->assertSame(1, $policy->countrySettings()->count());

        (new ThumbnailMeasureCleanupSeeder)->purge();

        $this->assertSame([$policy->id], $deleted);
        $this->assertFalse(ShippingPolicy::query()->whereKey($policy->id)->exists());
    }

    /**
     * 실측 데이터 표의 이미지 행과 주문, 신청서 행의 열 수가 머리글 수(7)와 같다.
     *
     * @scenario seeder=generate
     *
     * @effects table_rows_match_headers
     *
     * @param  string  $locale  사이트 언어
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_rows_match_header_count(string $locale): void
    {
        $this->app->setLocale($locale);
        $headers = (array) __('sirsoft-ecommerce::messages.seeders.thumbnail_measure.data_headers');
        $image = (object) ['id' => 3, 'hash' => 'abcdefabcdef', 'mime_type' => 'image/jpeg', 'width' => 2000, 'height' => 2000, 'download_url' => '/d'];

        $this->assertCount(7, $headers);
        $this->assertCount(count($headers), ThumbnailMeasureSeeder::imageRow('kind', 1, $image));
        $this->assertCount(count($headers), ThumbnailMeasureSeeder::recordRow('kind', 1, 'THUMB-MEAS-REVIEW-1', '/mypage/orders/THUMB-MEAS-REVIEW-1'));
    }

    /**
     * 주문자 이름을 정한 주문을 만듭니다.
     *
     * @param  User  $user  주문 회원
     * @param  string  $number  주문번호
     * @param  string  $ordererName  주문자 이름
     * @return Order 주문
     */
    private function order(User $user, string $number, string $ordererName): Order
    {
        $order = Order::factory()->forUser($user)->create(['order_number' => $number]);
        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_name' => $ordererName,
        ]);

        return $order;
    }

    /**
     * 출력을 버퍼에 모으는 비대화형 콘솔 명령을 만듭니다.
     *
     * @param  BufferedOutput  $buffer  출력 버퍼
     * @return Command 명령
     */
    private function bufferedCommand(BufferedOutput $buffer): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'test:ecommerce-thumbnail-measure-seed';
        };
        $command->setLaravel($this->app);

        $input = new ArrayInput([], $command->getDefinition());
        $input->setInteractive(false);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        return $command;
    }
}
