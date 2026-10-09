<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Tosspayments\Listeners\ValidateRedirectUrlSettingsListener;
use Plugins\Sirsoft\Tosspayments\Plugin;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 결제 후 이동 주소(redirect_success_url·redirect_fail_url) 설정 검증.
 *
 * 설정 화면에서 이 칸에 「not a url」 같은 글자를 넣어도 그대로 저장돼, 결제를 마친 구매자가 상대 경로
 * 「not a url」 로 보내지는 깨진 화면을 만났다(이커머스 전수 실측 S5, 2026-10-07 — 나이스페이먼츠에서 발견,
 * 네 결제 플러그인 모두 스키마가 type string 뿐). 이동 주소는 `{shopBase}`·`/`·`http(s)://` 로 시작하고
 * 공백이 없어야 하며, 다른 도메인으로 새는 `//host` 와 `javascript:` 는 받지 않는다. 비우면 기본값을 쓴다.
 */
class ValidateRedirectUrlSettingsListenerTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * 플러그인 번역(lang) 이름공간을 등록해 오류 문구를 실제 문장으로 읽게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace(self::IDENTIFIER, dirname(__DIR__, 3).'/lang');
    }

    /**
     * 리스너가 더한 규칙으로 입력을 검증해 실패한 칸 목록을 돌려줍니다.
     *
     * @param  array<string, mixed>  $input  설정 입력
     * @return array<int, string> 실패한 칸 이름
     */
    private function failedFields(array $input): array
    {
        $rules = (new ValidateRedirectUrlSettingsListener)->rules([], self::IDENTIFIER, $input);

        return array_keys(Validator::make($input, $rules)->errors()->toArray());
    }

    /**
     * 거절돼야 하는 이동 주소 묶음입니다 — 아무 글자·공백·다른 도메인 상대 프로토콜·javascript·슬래시 없는 경로.
     *
     * @return array<string, array{0: string}> 사례 이름 → [이동 주소]
     */
    public static function invalidUrls(): array
    {
        return [
            '아무 글자' => ['not a url'],
            '공백 포함' => ['{shopBase}/orders/{orderId} /complete'],
            '다른 도메인 상대 프로토콜' => ['//evil.example.com/steal'],
            // 상점이 경로 없이(no_route) 서면 {shopBase} 가 빈 값이라 결과가 //evil… 이 되어 다른 사이트로 간다
            '상점 기준 뒤 이중 슬래시' => ['{shopBase}//evil.example.com/steal'],
            '역슬래시 우회' => ['/\\evil.example.com'],
            '상점 기준 뒤 역슬래시' => ['{shopBase}/\\evil.example.com'],
            'javascript 주소' => ['javascript:alert(1)'],
            '슬래시 없는 경로' => ['orders/complete'],
        ];
    }

    /**
     * 통과해야 하는 이동 주소 묶음입니다 — 기본값·상점 기준 주소·상대 경로·전체 주소·빈 값.
     *
     * @return array<string, array{0: ?string}> 사례 이름 → [이동 주소 또는 null]
     */
    public static function validUrls(): array
    {
        return [
            '기본 성공 주소' => ['{shopBase}/orders/{orderId}/complete'],
            '기본 실패 주소' => ['{shopBase}/checkout'],
            '상점 기준 주소만' => ['{shopBase}'],
            '상대 경로' => ['/shop/orders/{orderId}/complete'],
            '전체 주소' => ['https://shop.example.com/orders/{orderId}/complete'],
            '빈 값(기본값 사용)' => [null],
        ];
    }

    /**
     * 잘못된 이동 주소는 성공·실패 두 칸 모두 거절됩니다.
     *
     * @scenario case=invalid
     *
     * @effects invalid_redirect_url_rejected
     *
     * @param  string  $value  거절돼야 하는 이동 주소
     * @return void 반환값 없음
     */
    #[DataProvider('invalidUrls')]
    public function test_invalid_redirect_urls_are_rejected(string $value): void
    {
        $this->assertSame(
            ['redirect_success_url', 'redirect_fail_url'],
            $this->failedFields(['redirect_success_url' => $value, 'redirect_fail_url' => $value])
        );
    }

    /**
     * 형식에 맞는 이동 주소와 빈 값은 통과합니다.
     *
     * @scenario case=valid
     *
     * @effects valid_redirect_url_accepted
     *
     * @param  string|null  $value  통과해야 하는 이동 주소(빈 값이면 기본값 사용)
     * @return void 반환값 없음
     */
    #[DataProvider('validUrls')]
    public function test_valid_redirect_urls_pass(?string $value): void
    {
        $this->assertSame([], $this->failedFields(['redirect_success_url' => $value, 'redirect_fail_url' => $value]));
    }

    /**
     * 거절 문구가 번역 키가 아닌 실제 문장으로 나옵니다.
     *
     * @scenario case=message
     *
     * @effects error_message_translated
     *
     * @return void 반환값 없음
     */
    public function test_error_message_is_translated(): void
    {
        $rules = (new ValidateRedirectUrlSettingsListener)->rules([], self::IDENTIFIER, ['redirect_fail_url' => 'bad']);
        $message = Validator::make(['redirect_fail_url' => 'bad'], $rules)->errors()->first('redirect_fail_url');

        $this->assertStringNotContainsString('messages.settings', $message);
        $this->assertNotSame('', trim($message));
    }

    /**
     * 다른 플러그인의 설정 검증 규칙에는 손대지 않습니다.
     *
     * @scenario case=other_plugin
     *
     * @effects other_plugin_rules_untouched
     *
     * @return void 반환값 없음
     */
    public function test_other_plugins_rules_are_left_untouched(): void
    {
        $rules = ['other' => ['required']];

        $this->assertSame($rules, (new ValidateRedirectUrlSettingsListener)->rules($rules, 'sirsoft-other', ['redirect_fail_url' => 'bad']));
    }

    /**
     * 리스너가 플러그인 설정 검증 필터 훅에 구독돼 있고 플러그인이 그것을 등록합니다.
     *
     * @scenario case=registration
     *
     * @effects listener_registered
     *
     * @return void 반환값 없음
     */
    public function test_listener_is_registered_on_the_settings_validation_filter(): void
    {
        $this->assertArrayHasKey('core.plugin_settings.update_validation_rules', ValidateRedirectUrlSettingsListener::getSubscribedHooks());
        $this->assertContains(ValidateRedirectUrlSettingsListener::class, (new Plugin)->getHookListeners());
    }
}
