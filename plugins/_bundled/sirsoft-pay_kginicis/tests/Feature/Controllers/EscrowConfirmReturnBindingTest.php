<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;
use Tests\Concerns\ForgesRequestHost;

/**
 * 에스크로 구매결정 결과 수신이 확인창을 연 본인 요청과 묶여야만 기록되는지 검증합니다.
 *
 * 결과 수신 주소는 PG 가 사용자 브라우저로 POST 하는 공개 주소다. 종전에는 요청의 tid 만 맞으면
 * 결제 메타에 구매확정·거절을 기록해, tid 를 아는 제3자가 남의 결제에 기록을 남길 수 있었다. 이제
 * 확인창이 발급한 일회용 증표가 경로에 있어야 하고, 증표의 tid 와 요청 tid 가 같아야 한 번만 기록한다.
 * 확인창 닫기 주소와 비로그인 확인창 요청이 오류(500)로 끝나지 않는지도 함께 본다.
 */
class EscrowConfirmReturnBindingTest extends PluginTestCase
{
    use ForgesRequestHost;

    private const BASE = '/plugins/sirsoft-pay_kginicis/payment/escrow-confirm';

    private const TID = 'StdpayESCRWINIpayTest20261003000001';

    /**
     * 사이트 주소를 고정하고 PG API 서비스를 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pinForgedHostSite();

        $api = $this->createMock(KgInicisApiService::class);
        $api->method('isTestMode')->willReturn(true);
        $api->method('getMid')->willReturn('iniescrow0');
        $api->method('getEscrowConfirmMKey')->willReturn('mkey');
        $this->app->instance(KgInicisApiService::class, $api);
    }

    /**
     * 로그인 회원의 에스크로 결제 주문을 만듭니다.
     *
     * @return array{user: User, payment: OrderPayment, order_number: string} 회원·결제·주문번호
     */
    private function makeEscrowOrder(): array
    {
        $user = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $user->id, 'order_number' => 'ORD-ESC-1', 'currency' => 'KRW']);
        $payment = OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'is_escrow' => true,
            'transaction_id' => self::TID,
            'payment_meta' => [],
        ]);

        return ['user' => $user, 'payment' => $payment, 'order_number' => 'ORD-ESC-1'];
    }

    /**
     * 확인창을 열고 결과 수신 주소(증표 포함)를 꺼냅니다.
     *
     * @param  User  $user  로그인 회원
     * @param  string  $field  주소가 담긴 폼 필드 (`returnUrl`·`P_NEXT_URL`)
     * @param  string  $userAgent  요청 User-Agent
     * @return string 결과 수신 주소
     */
    private function openConfirmWindow(User $user, string $field = 'returnUrl', string $userAgent = 'Mozilla/5.0 (Windows NT 10.0)'): string
    {
        $html = (string) $this->actingAs($user)
            ->withHeader('User-Agent', $userAgent)
            ->get('https://attacker.shop.example.test'.self::BASE.'/ORD-ESC-1')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('/name="'.$field.'"\s+value="([^"]+)"/', $html, $m), $field.' 필드가 있어야 합니다.');

        return html_entity_decode($m[1]);
    }

    /**
     * 결제 메타의 구매결정 기록을 읽습니다.
     *
     * @param  OrderPayment  $payment  결제
     * @return array<string, mixed>|null 기록 (없으면 null)
     */
    private function confirmRecord(OrderPayment $payment): ?array
    {
        $meta = json_decode((string) DB::table($payment->getTable())->where('id', $payment->id)->value('payment_meta'), true);

        return is_array($meta) ? ($meta['escrow_confirm'] ?? null) : null;
    }

    /**
     * 확인창이 PG 로 보내는 결과 수신·닫기 주소는 위조 Host 와 무관하게 사이트 주소이고 증표가 경로에 있다.
     *
     * @scenario host_header=forged, sink=escrow_confirm
     *
     * @effects escrow_confirm_urls_are_site_address_with_path_nonce
     *
     * @return void 반환값 없음
     */
    public function test_confirm_window_urls_use_site_address_and_path_nonce(): void
    {
        ['user' => $user] = $this->makeEscrowOrder();

        $returnUrl = $this->openConfirmWindow($user);
        $this->assertMatchesRegularExpression('#^https://shop\.example\.test'.preg_quote(self::BASE, '#').'/pc/return/[A-Za-z0-9]{40}$#', $returnUrl);

        $nextUrl = $this->openConfirmWindow($user, 'P_NEXT_URL', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile');
        $this->assertMatchesRegularExpression('#^https://shop\.example\.test'.preg_quote(self::BASE, '#').'/mobile/return/[A-Za-z0-9]{40}$#', $nextUrl);
    }

    /**
     * 증표가 맞는 수신만 한 번 기록하고, 재사용·다른 tid·증표 없음은 기록하지 않는다.
     *
     * @scenario host_header=canonical, sink=escrow_confirm
     *
     * @effects escrow_confirm_records_only_bound_return_once
     *
     * @return void 반환값 없음
     */
    public function test_only_bound_return_is_recorded_once(): void
    {
        ['user' => $user, 'payment' => $payment] = $this->makeEscrowOrder();

        // 증표 없음 — 종전 주소: 같은 응답, 기록 없음
        $this->post(self::BASE.'/pc/return', ['tid' => self::TID, 'ResultCode' => '00'])->assertOk();
        $this->assertNull($this->confirmRecord($payment));

        // 다른 tid — 증표는 소비되고 기록 없음
        $path = (string) parse_url($this->openConfirmWindow($user), PHP_URL_PATH);
        $this->post($path, ['tid' => 'OTHER-TID', 'ResultCode' => '00'])->assertOk();
        $this->assertNull($this->confirmRecord($payment));
        $this->post($path, ['tid' => self::TID, 'ResultCode' => '00'])->assertOk();
        $this->assertNull($this->confirmRecord($payment), '소비된 증표는 다시 쓸 수 없다');

        // 올바른 증표 — 1회 기록
        $path = (string) parse_url($this->openConfirmWindow($user), PHP_URL_PATH);
        $this->post($path, ['tid' => self::TID, 'ResultCode' => '00'])->assertOk();
        $this->assertSame('confirm', $this->confirmRecord($payment)['type'] ?? null);
    }

    /**
     * 모바일 결과 수신은 사이트 주소의 주문 상세로 돌아가고, 증표가 없으면 기록 없이 주문 목록으로 간다.
     *
     * @scenario host_header=forged, sink=escrow_confirm
     *
     * @effects escrow_confirm_records_only_bound_return_once
     *
     * @return void 반환값 없음
     */
    public function test_mobile_return_redirects_to_site_address(): void
    {
        ['user' => $user, 'payment' => $payment] = $this->makeEscrowOrder();

        $unbound = $this->post('https://attacker.shop.example.test'.self::BASE.'/mobile/return', ['P_ESCROW_TID' => self::TID, 'P_STATUS' => '00']);
        $this->assertSame(self::forgedHostSite().'/mypage/orders', $unbound->headers->get('Location'));
        $this->assertNull($this->confirmRecord($payment));

        $path = (string) parse_url($this->openConfirmWindow($user, 'P_NEXT_URL', 'Mozilla/5.0 (iPhone) Mobile'), PHP_URL_PATH);
        $bound = $this->post('https://shop.example.test:6666'.$path, ['P_ESCROW_TID' => self::TID, 'P_STATUS' => '00']);
        $this->assertSame(self::forgedHostSite().'/mypage/orders/ORD-ESC-1', $bound->headers->get('Location'));
        $this->assertSame('confirm', $this->confirmRecord($payment)['type'] ?? null);
    }

    /**
     * 확인창 닫기 주소는 창 닫기 HTML(200)이고, 비로그인 확인창 요청은 500 이 아니라 401 안내다.
     *
     * 회귀: 주문번호 라우트가 닫기 주소를 가려 `close` 를 주문번호로 받았고, 그 라우트의 인증 미들웨어가
     * 로그인 라우트를 찾다 500 으로 끝났다.
     *
     * @scenario host_header=canonical, sink=escrow_confirm
     *
     * @effects escrow_close_and_guest_window_do_not_error
     *
     * @return void 반환값 없음
     */
    public function test_close_route_and_guest_window(): void
    {
        $close = $this->get(self::BASE.'/close');
        $close->assertOk();
        $this->assertStringContainsString('window.close()', (string) $close->getContent());

        $this->get(self::BASE.'/ORD-ESC-1')->assertStatus(401);

        ['user' => $user] = $this->makeEscrowOrder();
        $this->actingAs($user)->get(self::BASE.'/ORD-NOT-MINE')->assertNotFound();
    }
}
