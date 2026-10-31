<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 로그인한 회원이 주문 상세의 [구매결정] 으로 에스크로 구매결정 창을 열 수 있는지 검증한다 (이커머스 전수 실측 S4 — S4-D19)
 *
 * 결함: 구매결정 창(GET /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/{주문번호})은 웹 세션 로그인(Auth::check)으로만
 *   본인을 판정하는데, 사이트 화면의 로그인은 API 토큰이라 새 창으로 여는 링크에는 로그인 정보가 실리지 않는다.
 *   실측: 로그인한 m2 가 에스크로 결제완료 주문 737 의 [구매결정] → 401 「Please log in and start the purchase decision again
 *   from the order details.」. 기존 테스트는 세션 로그인(actingAs)으로 열어 이 경로를 잡지 못했다.
 * 영수증 조회 API(토큰 인증)가 본인 에스크로 결제에 한시 서명 주소를 내려 주고, 구매결정 창은 그 서명을 본인 확인으로 받는다.
 */
class EscrowConfirmSignedLinkTest extends PluginTestCase
{
    private const BASE = '/plugins/sirsoft-pay_kginicis/payment/escrow-confirm';

    /**
     * 구매결정 창이 부르는 KG API 를 시험값으로 고정합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $api = $this->createMock(KgInicisApiService::class);
        $api->method('isTestMode')->willReturn(true);
        $api->method('getMid')->willReturn('iniescrow0');
        $api->method('getEscrowConfirmMKey')->willReturn('mkey');
        $this->app->instance(KgInicisApiService::class, $api);
    }

    /**
     * 회원의 KG 결제완료 주문을 만듭니다.
     *
     * @param  bool  $escrow  에스크로 결제 여부
     * @return array{user: User, order_number: string} 회원과 주문번호
     */
    private function makeOrder(bool $escrow = true): array
    {
        $user = User::factory()->create();
        $orderNumber = 'ORD-ESCL-'.random_int(10000, 99999);
        $order = OrderFactory::new()->create(['user_id' => $user->id, 'order_number' => $orderNumber, 'currency' => 'KRW']);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'kginicis',
            'is_escrow' => $escrow,
            'transaction_id' => 'StdpayVBNKiniescrow0'.random_int(100000, 999999),
            'payment_meta' => [],
        ]);

        return ['user' => $user, 'order_number' => $orderNumber];
    }

    /**
     * 토큰 로그인 회원으로 영수증 조회 API 를 불러 구매결정 주소를 받습니다.
     *
     * @param  User  $user  회원
     * @param  string  $orderNumber  주문번호
     * @return string|null 구매결정 주소
     */
    private function confirmUrlFromReceiptApi(User $user, string $orderNumber): ?string
    {
        // 화면과 같이 API 토큰을 요청 헤더로만 싣는다 — 세션 로그인이 다음 요청(새 창)으로 이어지지 않게.
        $token = $user->createToken('s4-d19')->plainTextToken;
        $url = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/plugins/sirsoft-pay_kginicis/user/orders/'.$orderNumber.'/receipt')
            ->assertOk()
            ->json('data.escrow_confirm_url');
        // 테스트 앱은 요청 사이에 공유된다 — API 요청이 바꾼 기본 가드(sanctum)를 되돌려, 다음 요청을 새 창처럼 비로그인으로 만든다.
        $this->withoutHeader('Authorization');
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();

        return $url;
    }

    /**
     * 본인 에스크로 결제의 영수증 조회 응답에 이 주문의 한시 서명 구매결정 주소가 실린다.
     *
     * @scenario case=receipt_api_link
     *
     * @effects owner_gets_signed_confirm_link
     *
     * @return void 없음
     */
    public function test_receipt_api_gives_owner_a_signed_confirm_link(): void
    {
        ['user' => $user, 'order_number' => $no] = $this->makeOrder();

        $url = $this->confirmUrlFromReceiptApi($user, $no);

        $this->assertIsString($url);
        $this->assertStringStartsWith(self::BASE.'/'.$no.'?', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    /**
     * 세션 로그인이 없는 새 창에서도 서명 주소로 구매결정 창이 열린다.
     *
     * @scenario case=signed_link_opens
     *
     * @effects confirm_window_opens_with_signature
     *
     * @return void 없음
     */
    public function test_signed_link_opens_confirm_window_without_session(): void
    {
        ['user' => $user, 'order_number' => $no] = $this->makeOrder();
        $url = $this->confirmUrlFromReceiptApi($user, $no);

        $html = (string) $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0)')
            ->get($url)
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#/escrow-confirm/pc/return/[A-Za-z0-9]+#', $html);
    }

    /**
     * 구매결정 창(PC·모바일)의 문서 언어·제목·안내 문구는 화면 언어를 따른다 — 한국어 고정이 아니다.
     *
     * @scenario case=window_locale
     *
     * @effects confirm_window_follows_screen_locale
     *
     * @return void 없음
     */
    public function test_confirm_window_follows_screen_locale(): void
    {
        ['user' => $user, 'order_number' => $no] = $this->makeOrder();
        $url = $this->confirmUrlFromReceiptApi($user, $no);

        $pc = (string) $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0)', 'Accept-Language' => 'en'])
            ->get($url)
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('<html lang="en">', $pc);
        $this->assertStringContainsString('<title>'.__('sirsoft-pay_kginicis::messages.escrow.confirm_page_title', [], 'en').'</title>', $pc);
        $this->assertStringContainsString(__('sirsoft-pay_kginicis::messages.escrow.confirm_preparing', [], 'en'), $pc);
        $this->assertStringNotContainsString('에스크로 구매결정', $pc);

        $url = $this->confirmUrlFromReceiptApi($user, $no);
        $mobile = (string) $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile', 'Accept-Language' => 'en'])
            ->get($url)
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('<html lang="en">', $mobile);
        $this->assertStringContainsString(__('sirsoft-pay_kginicis::messages.escrow.confirm_redirecting', [], 'en'), $mobile);
        $this->assertStringNotContainsString('에스크로 구매결정', $mobile);
    }

    /**
     * 서명이 없거나 바뀐 주소, 남의 주문은 열리지 않는다.
     *
     * @scenario case=tampered_or_unsigned
     *
     * @effects confirm_window_refuses_unsigned_or_tampered
     *
     * @return void 없음
     */
    public function test_unsigned_or_tampered_link_is_refused(): void
    {
        ['user' => $user, 'order_number' => $no] = $this->makeOrder();
        ['user' => $other] = $this->makeOrder();
        $url = $this->confirmUrlFromReceiptApi($user, $no);

        $this->get(self::BASE.'/'.$no)->assertStatus(401);
        $this->get(preg_replace('/uid=\d+/', 'uid='.$other->id, $url))->assertStatus(401);
    }

    /**
     * 에스크로가 아닌 결제에는 구매결정 주소를 내려 주지 않는다.
     *
     * @scenario case=non_escrow
     *
     * @effects no_link_for_non_escrow
     *
     * @return void 없음
     */
    public function test_non_escrow_payment_gets_no_confirm_link(): void
    {
        ['user' => $user, 'order_number' => $no] = $this->makeOrder(false);

        $this->assertNull($this->confirmUrlFromReceiptApi($user, $no));
    }
}
