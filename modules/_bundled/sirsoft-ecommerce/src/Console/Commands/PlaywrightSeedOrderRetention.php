<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;

/**
 * Playwright E2E · 브라우저 실측용 종결 주문 개인정보 보유기간 시드 커맨드 (이슈 #636 S5).
 *
 * 보유기간 처리는 「종결 후 N년(최소 5년)이 지난 주문」만 건드리므로, 실측하려면 **오래전에 끝난
 * 주문**이 필요하다. 화면·API 로는 종결 시각을 과거로 만들 수 없어 이 커맨드가 그 상태를 고정한다.
 *
 * 만드는 것 (주문번호 접두 `E2E-RET-`):
 *   - 6년 전 구매확정 주문 1건 · 6년 전 취소 주문 1건 — 대상
 *   - 1년 전 구매확정 주문 1건 — 보유기간 안이라 대상 아님
 *   - 6년 전 주문이지만 배송완료(구매확정 전) 1건 — 미종결이라 어떤 경우에도 대상 아님
 * 각 주문에 주소·결제를 붙인다. 주문 옵션은 붙이지 않는다 — 옵션 팩토리가 상품을 새로 만들고, 상품은
 * 주문 삭제로 지워지지 않아(restrictOnDelete) 개발 DB 에 시드 상품이 쌓인다. 팩토리를 쓰므로
 * 개발 의존성(faker)이 설치된 환경에서만 동작한다 — 이 커맨드 자체가 개발 전용이다.
 *
 * 재실행하면 같은 접두의 주문을 먼저 영구 삭제하고 다시 만든다(멱등). `--teardown` 은 전량 삭제한다.
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-order-retention --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-order-retention --teardown
 */
class PlaywrightSeedOrderRetention extends Command
{
    /** 시드 주문번호 접두 — 재실행·정리의 모집단 */
    private const ORDER_PREFIX = 'E2E-RET-';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-order-retention
        {--teardown : 시드한 주문을 전량 삭제한다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 실측용 종결 주문 개인정보 보유기간 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  OrderPrivacyRetentionService  $service  주문 영구 삭제에 쓰는 서비스 (정리)
     * @return int 종료 코드
     */
    public function handle(OrderPrivacyRetentionService $service): int
    {
        // ① CLI 한정
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다.');

            return self::FAILURE;
        }

        // ② 명시 옵트인
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제
        Config::set('app.debug', true);

        $result = $this->option('teardown') ? ['deleted_orders' => $this->purge($service)] : $this->seed($service);

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
     * 시드 주문을 만듭니다.
     *
     * @param  OrderPrivacyRetentionService  $service  주문 영구 삭제 서비스 (재실행 정리)
     * @return array<string, mixed> 만들어진 주문번호 모음
     */
    private function seed(OrderPrivacyRetentionService $service): array
    {
        $this->purge($service);

        $sixYearsAgo = now()->subYears(6);

        return [
            'confirmed_old' => $this->order('CONF', OrderStatusEnum::CONFIRMED, ['confirmed_at' => $sixYearsAgo], $sixYearsAgo),
            'cancelled_old' => $this->order('CANC', OrderStatusEnum::CANCELLED, ['cancelled_at' => $sixYearsAgo], $sixYearsAgo),
            'confirmed_recent' => $this->order('RCNT', OrderStatusEnum::CONFIRMED, ['confirmed_at' => now()->subYear()], now()->subYear()),
            'delivered_old' => $this->order('DLVR', OrderStatusEnum::DELIVERED, ['confirmed_at' => null], $sixYearsAgo),
            'orderer_name' => '보유기간주문자',
        ];
    }

    /**
     * 주문 한 건을 주소·결제와 함께 만듭니다.
     *
     * @param  string  $tag  주문번호 구분 꼬리표
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  array<string, mixed>  $timestamps  종결 시각 컬럼
     * @param  \DateTimeInterface  $updatedAt  마지막 수정 시각 (레거시 종결 판정 대용)
     * @return string 주문번호
     */
    private function order(string $tag, OrderStatusEnum $status, array $timestamps, \DateTimeInterface $updatedAt): string
    {
        $number = self::ORDER_PREFIX.$tag.'-'.now()->format('His');

        // 비회원 주문으로 만든다 — 팩토리 기본값은 회원을 새로 만들어, 정리 뒤에도 개발 DB 에 회원이 쌓인다.
        $order = Order::factory()->forGuest()->create(array_merge([
            'order_number' => $number,
            'order_status' => $status,
            'ip_address' => '203.0.113.40',
            'ordered_at' => $updatedAt,
        ], $timestamps));

        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'orderer_name' => '보유기간주문자',
            'orderer_phone' => '010-2020-3030',
            'recipient_name' => '보유기간수령인',
            'recipient_phone' => '010-4040-5050',
        ]);
        OrderPayment::factory()->create(['order_id' => $order->id, 'buyer_name' => '보유기간주문자']);

        DB::table($order->getTable())->where('id', $order->id)->update(['updated_at' => $updatedAt]);

        return $number;
    }

    /**
     * 같은 접두의 시드 주문을 영구 삭제합니다 (재실행 멱등의 핵심).
     *
     * 보유기간 삭제와 같은 명시 삭제 경로를 쓴다 — 참조 행을 손으로 따로 지우면 목록이 어긋난다.
     *
     * @param  OrderPrivacyRetentionService  $service  주문 영구 삭제 서비스
     * @return int 삭제한 주문 수
     */
    private function purge(OrderPrivacyRetentionService $service): int
    {
        $deleted = 0;

        foreach (Order::withTrashed()->where('order_number', 'like', self::ORDER_PREFIX.'%')->get() as $order) {
            $service->purge($order);
            $deleted++;
        }

        return $deleted;
    }
}
