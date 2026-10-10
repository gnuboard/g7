<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use App\Helpers\TimezoneHelper;
use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;

/**
 * Playwright E2E 용 「사이트 하루 경계」 결제완료 주문 시드 커맨드.
 *
 * 대시보드 「오늘」 배지는 사이트 표준시의 하루에 든 주문 수를 배지 링크와 같은 목록 조건으로 세고, 배지로 연 주문
 * 목록은 `date_basis=site` 일 때 같은 하루로 거른다. 그 일치를 브라우저에서 재려면 **사이트 하루 경계 바로 안쪽과
 * 바로 바깥쪽의 주문**, 그리고 **수량이 여럿인 주문**(배지가 수량이 아니라 주문 수를 세는지 가른다)이 있어야 하는데,
 * 화면·API 로는 주문 시각을 과거로 만들 수 없어 이 커맨드가 고정한다.
 *
 * 만드는 것 (주문번호 접두 `E2E-SDAY-`, 비회원 주문, 결제완료):
 *   - inside       — 사이트 오늘 0시 직후(최대 30분 뒤, 지금을 넘지 않음), 옵션 수량 1 — 오늘 배지·사이트 하루 목록에 든다
 *   - inside_multi — inside 와 같은 시각, 옵션 수량 2 — 배지에 1건으로 든다(수량 2 가 아니다)
 *   - outside      — 사이트 오늘 0시 1분 전(어제 23:59), 옵션 수량 1 — 어느 쪽에도 들지 않는다
 * 주문 옵션은 **이미 있는 상품 옵션**을 가리킨다 — 옵션 팩토리 기본값은 상품을 새로 만들고, 상품은 주문 삭제로
 * 지워지지 않아(restrictOnDelete) 개발 DB 에 시드 상품이 쌓인다. 상품 옵션이 하나도 없으면 실패한다.
 * 만든 뒤 최근 2일 집계 행을 다시 굽는다(판매 추세 그래프의 근거 — 배지는 주문 표를 바로 센다). 팩토리를 쓰므로 개발 의존성(faker)이 설치된 환경에서만
 * 동작한다 — 이 커맨드 자체가 개발 전용이다.
 *
 * 재실행하면 같은 접두의 주문을 먼저 영구 삭제하고 다시 만든다(멱등). `--teardown` 은 전량 삭제하고 집계를 다시 굽는다.
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-site-day-orders --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-site-day-orders --teardown --json
 */
class PlaywrightSeedSiteDayOrders extends Command
{
    /** 시드 주문번호 접두 — 재실행·정리의 모집단 */
    private const ORDER_PREFIX = 'E2E-SDAY-';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-site-day-orders
        {--teardown : 시드한 주문을 전량 삭제하고 집계를 다시 굽는다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 사이트 하루 경계 결제완료 주문 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  OrderPrivacyRetentionService  $retention  주문 영구 삭제에 쓰는 서비스 (정리)
     * @param  EcommerceDashboardService  $dashboard  사이트 오늘·일별 집계 갱신 서비스 (판매 추세 근거)
     * @return int 종료 코드
     */
    public function handle(OrderPrivacyRetentionService $retention, EcommerceDashboardService $dashboard): int
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

        if ($this->option('teardown')) {
            $result = ['deleted_orders' => $this->purge($retention)];
        } else {
            $this->purge($retention);
            $option = ProductOption::query()->whereHas('product')->orderBy('id')->first();
            if (! $option) {
                $this->error('상품 옵션이 하나도 없어 주문 옵션을 붙일 수 없습니다.');

                return self::FAILURE;
            }
            $result = $this->seed($option, $dashboard->siteToday());
        }

        // 판매 추세 그래프는 집계 행을 읽는다 — 시드·정리 뒤 오늘·어제 행을 다시 굽는다(배지는 주문 표를 바로 센다).
        $dashboard->aggregateRecentDays(2);

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
     * 경계 안쪽 주문 두 건(수량 1·수량 2)과 바깥쪽 주문 한 건을 만듭니다.
     *
     * @param  ProductOption  $option  주문 옵션이 가리킬 기존 상품 옵션
     * @param  CarbonImmutable  $siteToday  사이트 표준시 오늘 0시
     * @return array<string, mixed> 주문번호·경계 시각·사이트 시간대
     */
    private function seed(ProductOption $option, CarbonImmutable $siteToday): array
    {
        $now = CarbonImmutable::now($siteToday->getTimezone());
        $inside = $siteToday->addMinutes(30)->min($now);
        $outside = $siteToday->subMinute();

        return [
            'site_timezone' => TimezoneHelper::getSiteTimezone() ?: 'UTC',
            'site_today' => $siteToday->toDateString(),
            'inside' => $this->order('IN', $inside, $option),
            'inside_multi' => $this->order('IN2', $inside, $option, 2),
            'inside_ordered_at_utc' => $inside->utc()->toIso8601String(),
            'outside' => $this->order('OUT', $outside, $option),
            'outside_ordered_at_utc' => $outside->utc()->toIso8601String(),
        ];
    }

    /**
     * 결제완료 주문 한 건을 옵션 한 줄·주소·결제와 함께 만듭니다.
     *
     * @param  string  $tag  주문번호 구분 꼬리표
     * @param  CarbonImmutable  $orderedAt  주문 시각
     * @param  ProductOption  $option  주문 옵션이 가리킬 기존 상품 옵션
     * @param  int  $quantity  옵션 수량
     * @return string 주문번호
     */
    private function order(string $tag, CarbonImmutable $orderedAt, ProductOption $option, int $quantity = 1): string
    {
        $number = self::ORDER_PREFIX.$tag.'-'.now()->format('His');

        // 비회원 주문으로 만든다 — 팩토리 기본값은 회원을 새로 만들어, 정리 뒤에도 개발 DB 에 회원이 쌓인다.
        $order = Order::factory()->forGuest()->create([
            'order_number' => $number,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'ip_address' => '203.0.113.41',
            'ordered_at' => $orderedAt->utc(),
            'paid_at' => $orderedAt->utc(),
        ]);

        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $option->product_id,
            'product_option_id' => $option->id,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => $quantity,
        ]);
        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'orderer_name' => '사이트하루주문자',
            'recipient_name' => '사이트하루수령인',
        ]);
        OrderPayment::factory()->create(['order_id' => $order->id, 'buyer_name' => '사이트하루주문자']);

        return $number;
    }

    /**
     * 같은 접두의 시드 주문을 영구 삭제합니다 (재실행 멱등의 핵심).
     *
     * 보유기간 삭제와 같은 명시 삭제 경로를 쓴다 — 참조 행을 손으로 따로 지우면 목록이 어긋난다.
     *
     * @param  OrderPrivacyRetentionService  $retention  주문 영구 삭제 서비스
     * @return int 삭제한 주문 수
     */
    private function purge(OrderPrivacyRetentionService $retention): int
    {
        $purgedIds = [];

        foreach (Order::withTrashed()->where('order_number', 'like', self::ORDER_PREFIX.'%')->get() as $order) {
            $retention->purge($order);
            $purgedIds[] = $order->id;
        }

        // 삭제 경로가 남기는 주문 삭제 활동 로그도 시드의 흔적이다 — 실행마다 쌓이지 않게 함께 지운다.
        if ($purgedIds !== []) {
            ActivityLog::query()
                ->where('loggable_type', (new Order)->getMorphClass())
                ->whereIn('loggable_id', $purgedIds)
                ->delete();
        }

        return count($purgedIds);
    }
}
