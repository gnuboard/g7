<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Benchmark;

use App\Benchmark\Axes\WriteAxisRunner;
use App\Benchmark\BenchmarkProfileRegistry;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Enums\BenchmarkAxis;
use Modules\Sirsoft\Ecommerce\Benchmark\OrderCreationBenchmark;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 계측 프로파일 선언 및 주문 생성 쓰기 축 대상 테스트.
 *
 * 계측 결과는 시간 값이라 **수치를 단언하지 않는다**. 단언 대상은 모듈이 자기 계측 대상을
 * 선언한다는 성질과, 쓰기 축 대상이 실제 서비스 경로로 주문을 만든다는 성질이다.
 */
class OrderCreationBenchmarkTest extends ModuleTestCase
{
    /**
     * 모듈이 자기 계측 대상을 선언합니다.
     *
     * 계측 대상을 코어 커맨드에 하드코딩하지 않고 소유 확장이 선언한다는 설계의 실증입니다.
     *
     * @effects list_profile_filters_match_the_screen_default_predicate
     */
    #[Test]
    public function 모듈이_계측_프로파일을_선언한다(): void
    {
        $declared = (new Module)->getBenchmarkProfiles();

        // 목록 축 — 주문 목록은 Repository 상수를 참조해 컬럼 중복 선언을 없앤다
        $this->assertArrayHasKey('orders', $declared);
        $this->assertSame('list', $declared['orders']['type']);
        $this->assertSame('ecommerce_orders', $declared['orders']['table']);
        $this->assertContains('order_number', $declared['orders']['columns']);
        $this->assertNotContains('memo', $declared['orders']['columns'], '목록에 쓰지 않는 넓은 컬럼은 빠져야 한다.');

        // 관리자 주문 목록은 상태 미지정 시 임시 주문 상태를 NOT IN 으로 제외한다
        // (OrderRepository::getListWithFilters). 이 술어가 빠지면 다른 인덱스를 재게 된다.
        $this->assertSame(
            ['order_status' => ['not in', OrderStatusEnum::listHiddenValues()]],
            $declared['orders']['filters'],
            '주문 목록 프로파일은 화면 기본 필터를 Enum SSoT 기준으로 선언해야 한다.'
        );

        // 화면 축 — 라우트명으로 선언 (URI 프리픽스 조립 금지)
        $this->assertSame('screen', $declared['orders_screen']['type']);
        $this->assertSame(
            'api.modules.sirsoft-ecommerce.admin.orders.index',
            $declared['orders_screen']['route']
        );
        $this->assertSame(['sirsoft-ecommerce.orders.read'], $declared['orders_screen']['permissions']);

        // 쓰기 축 — prepare(계측 제외) / callback(계측) 분리, 클로저 아닌 형식
        $this->assertSame('write', $declared['order_create']['type']);
        $this->assertSame([OrderCreationBenchmark::class, 'prepare'], $declared['order_create']['prepare']);
        $this->assertSame([OrderCreationBenchmark::class, 'create'], $declared['order_create']['callback']);
    }

    /**
     * 선언한 프로파일이 코어 레지스트리에 수집됩니다.
     */
    #[Test]
    public function 선언한_프로파일이_코어_레지스트리에_수집된다(): void
    {
        $profile = app(BenchmarkProfileRegistry::class)->find('sirsoft-ecommerce/order_create');

        $this->assertSame(BenchmarkAxis::Write, $profile->axis);
        $this->assertSame('module', $profile->sourceKind);
        $this->assertSame('sirsoft-ecommerce', $profile->sourceIdentifier);
        $this->assertTrue($profile->mutates(), '쓰기 축은 데이터를 변경하는 축이다.');
    }

    /**
     * `prepare` 가 구매 가능한 상품·옵션과 임시 주문을 세웁니다.
     *
     * 임시 주문을 팩토리로 조립하지 않고 실제 서비스로 만드는 이유는, 주문 생성이 저장된
     * 계산 파라미터로 금액을 재계산해 저장값과 대조하기 때문입니다 (손으로 만든 계산 결과는
     * 어긋나 차단되고, 어긋나지 않게 맞추면 실제 결제 경로가 아닌 다른 것을 재게 됩니다).
     */
    #[Test]
    public function prepare_가_임시_주문을_세운다(): void
    {
        $context = app(OrderCreationBenchmark::class)->prepare(1);

        $this->assertInstanceOf(TempOrder::class, $context['temp_order']);
        $this->assertGreaterThan(0, $context['expected_total_amount'], '결제예정금액이 산출되어야 한다.');
        $this->assertSame(
            $context['user']->id,
            $context['temp_order']->user_id,
            '임시 주문이 준비한 회원에 귀속되어야 한다.'
        );
        $this->assertSame(
            (float) $context['temp_order']->getFinalAmount(),
            $context['expected_total_amount'],
            '결제예정금액은 임시 주문의 최종 금액과 같아야 한다 — 다르면 금액 검증에 막힌다.'
        );
    }

    /**
     * 계측 대상 콜백이 실제 주문을 생성합니다.
     */
    #[Test]
    public function callback_이_실제_주문을_생성한다(): void
    {
        $benchmark = app(OrderCreationBenchmark::class);

        $ordersBefore = Order::count();
        $order = $benchmark->create($benchmark->prepare(1));

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame($ordersBefore + 1, Order::count());
        $this->assertNotEmpty($order->order_number);
    }

    /**
     * 쓰기 축 실행기가 이 대상을 실행하고 결과를 산출합니다. (수치 단언 없음)
     *
     * 계측이 만든 행은 실행기가 감싼 트랜잭션 롤백으로 되돌아가므로 주문 건수가 늘지 않습니다.
     */
    #[Test]
    public function 쓰기_축_실행이_결과를_산출하고_흔적을_남기지_않는다(): void
    {
        $ordersBefore = Order::count();

        $result = app(WriteAxisRunner::class)->run(
            app(BenchmarkProfileRegistry::class)->find('sirsoft-ecommerce/order_create'),
            new BenchmarkRunOptions(runs: 1, allowWrite: true),
        );

        $this->assertFalse($result->skipped, '건너뜀 사유: '.(string) $result->skipReason);
        $this->assertSame(['첫 회(ms)', '중앙값(ms)', '회차', '쿼리(건)', 'DB(ms)'], $result->headers);
        $this->assertGreaterThan(0, $result->metrics['query_count'], '주문 생성은 쿼리를 실행한다.');

        $this->assertSame($ordersBefore, Order::count(), '계측으로 생긴 주문은 롤백되어야 한다.');
    }

    /**
     * `--allow-write` 없이는 이 대상이 실행되지 않습니다.
     */
    #[Test]
    public function allow_write_없이는_실행되지_않는다(): void
    {
        $ordersBefore = Order::count();

        $result = app(WriteAxisRunner::class)->run(
            app(BenchmarkProfileRegistry::class)->find('sirsoft-ecommerce/order_create'),
            new BenchmarkRunOptions(runs: 1),
        );

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('--allow-write', (string) $result->skipReason);
        $this->assertSame($ordersBefore, Order::count());
    }
}
