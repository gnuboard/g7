<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 저장된 `mc_*` 금액의 절사 잔차 정규화(DataMigration) 회귀 테스트
 *
 * 1.3.0 이전에 기록된 행은 `4505 × 0.01 = 45.050000000000004` 같은 어긋난 표기를 들고
 * 있다. 생산 지점 수정은 이후 기록분에만 적용되므로 과거 행은 이 백필이 담당한다.
 *
 * 검증 축: ① 잔차 제거 ② 금액을 바꾸지 않음(허용오차 밖 정밀값 보존) ③ 정수 표기 보존
 * ④ 멱등 ⑤ `_formatted` / `_meta` 불변 ⑥ 대상 테이블이 스키마에서 파생됨
 */
class NormalizeStoredMultiCurrencyRoundingTest extends ModuleTestCase
{
    private object $migration;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.0/migrations/06_NormalizeStoredMultiCurrencyRounding.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\NormalizeStoredMultiCurrencyRounding';

        $this->migration = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.1.1',
            toVersion: '1.3.0',
            currentStep: '1.3.0',
        );
    }

    /**
     * 잔차를 품은 주문 1건을 만들고 그 id 를 돌려줍니다.
     *
     * @param  array<string, mixed>  $usd  USD 통화 맵
     * @return int 주문 id
     */
    private function makeOrder(array $usd): int
    {
        $order = OrderFactory::new()->create();

        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'mc_total_amount' => json_encode([
                'KRW' => ['total_amount' => 5000, 'total_amount_formatted' => '5,000원'],
                'USD' => $usd,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $order->id;
    }

    /**
     * 저장된 mc_total_amount 를 배열로 읽습니다.
     *
     * @param  int  $id  주문 id
     * @return array<mixed> 디코드된 맵
     */
    private function stored(int $id): array
    {
        $raw = DB::table('ecommerce_orders')->where('id', $id)->value('mc_total_amount');

        return json_decode((string) $raw, true) ?: [];
    }

    public function test_removes_float_residue_from_stored_amounts(): void
    {
        $id = $this->makeOrder([
            'total_amount' => 4505 * 0.01,
            'total_amount_formatted' => '$45.05',
            '_meta' => ['is_default' => false, 'exchange_rate' => 0.75, 'snapshot_based' => true],
        ]);

        // 착수 상태 확인 — 저장된 값이 실제로 어긋나 있다
        $this->assertSame(45.050000000000004, $this->stored($id)['USD']['total_amount']);

        $this->migration->run($this->context);

        $this->assertSame(45.05, $this->stored($id)['USD']['total_amount']);
    }

    public function test_preserves_formatted_string_and_meta(): void
    {
        $meta = ['is_default' => false, 'exchange_rate' => 0.75, 'snapshot_based' => true];

        $id = $this->makeOrder([
            'total_amount' => 4505 * 0.01,
            'total_amount_formatted' => '$45.05',
            '_meta' => $meta,
        ]);

        $this->migration->run($this->context);

        $stored = $this->stored($id);

        $this->assertSame('$45.05', $stored['USD']['total_amount_formatted']);
        $this->assertSame($meta, $stored['USD']['_meta']);
    }

    public function test_leaves_integer_amounts_as_integers(): void
    {
        $id = $this->makeOrder([
            'total_amount' => 4505 * 0.01,
            'total_amount_formatted' => '$45.05',
        ]);

        $this->migration->run($this->context);

        // 기본 통화 금액은 정수로 저장된다 — round() 를 태우면 5000 이 5000.0 이 된다
        $this->assertSame(5000, $this->stored($id)['KRW']['total_amount']);
    }

    public function test_does_not_change_genuinely_precise_values(): void
    {
        $id = $this->makeOrder([
            'total_amount' => 45.055,
            'total_amount_formatted' => '$45.055',
        ]);

        $this->migration->run($this->context);

        // 잔차가 아니라 실제로 3자리인 값 — 2자리로 깎지 않는다
        $this->assertSame(45.055, $this->stored($id)['USD']['total_amount']);
    }

    public function test_is_idempotent(): void
    {
        $id = $this->makeOrder([
            'total_amount' => 4505 * 0.01,
            'total_amount_formatted' => '$45.05',
        ]);

        $this->migration->run($this->context);
        $first = DB::table('ecommerce_orders')->where('id', $id)->value('mc_total_amount');

        $this->migration->run($this->context);
        $second = DB::table('ecommerce_orders')->where('id', $id)->value('mc_total_amount');

        $this->assertSame($first, $second);
    }

    public function test_targets_are_derived_from_schema_not_hardcoded(): void
    {
        $method = new \ReflectionMethod($this->migration, 'targets');
        $method->setAccessible(true);

        /** @var array<string, array<int, string>> $targets */
        $targets = $method->invoke($this->migration);

        // 모집단이 비면 그 자체로 실패 (하한)
        $this->assertNotEmpty($targets, 'mc_* 컬럼을 가진 테이블을 스키마에서 찾지 못했습니다.');

        // 출시본에 이미 있던 테이블이 전건 포함돼야 한다
        foreach ([
            'ecommerce_orders',
            'ecommerce_order_options',
            'ecommerce_order_payments',
            'ecommerce_order_shippings',
            'ecommerce_order_refunds',
        ] as $table) {
            $this->assertArrayHasKey($table, $targets, $table.' 이 정규화 대상에서 빠졌습니다.');
            $this->assertNotEmpty($targets[$table]);
        }

        // 다른 확장/코어 테이블은 건드리지 않는다
        foreach (array_keys($targets) as $table) {
            $this->assertStringStartsWith('ecommerce_', $table);
        }
    }
}
