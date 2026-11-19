<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Concerns;

use Illuminate\Support\Arr;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 결제 기록에 남기는 간편결제 라벨이 지원 언어 전부로 저장되고, 이미 저장된 라벨은 그대로 쓰이는지 검증합니다.
 *
 * 종전에는 라벨이 ko·en 리터럴이라 일본어 화면의 주문 상세·영수증·주문 목록 결제수단 이름이 한국어로 나갔다.
 * 이후 저장분부터 지원 언어 전부로 남기고, 이미 저장된 결제 기록은 고치지 않는다(데이터 수정 없음).
 */
class ResolvesEasyPayDisplayTest extends PluginTestCase
{
    /**
     * 번들 원본 언어 파일과 일본어 언어팩을 번역기에 싣고 지원 언어를 ko·en·ja 로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-pay_nhnkcp', base_path('plugins/_bundled/sirsoft-pay_nhnkcp/lang'));
        $this->app['translator']->addLines(
            Arr::dot(['payment_methods' => $this->japanesePack()]),
            'ja',
            'sirsoft-pay_nhnkcp',
        );
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);
    }

    /**
     * 새로 결제한 간편결제의 라벨은 지원 언어 전부로 결제 meta 에 남습니다.
     *
     * @scenario contract=payment_label_snapshot
     *
     * @effects easy_pay_payment_label_snapshots_all_locales
     *
     * @return void 반환값 없음
     */
    public function test_new_payment_label_is_snapshotted_in_every_supported_locale(): void
    {
        $meta = $this->subject()->meta(['nhnkcp_easy_pay_method' => 'nhnkcp_kakaopay']);

        $this->assertSame(['ko', 'en', 'ja'], array_keys($meta['label']));
        $this->assertSame('카카오페이', $meta['label']['ko']);
        $this->assertSame('KakaoPay', $meta['label']['en']);
        $this->assertSame($this->japanesePack()['kakaopay']['label'] ?? null, $meta['label']['ja']);
        $this->assertNotSame('카카오페이', $meta['label']['ja']);

        app()->setLocale('ja');
        $this->assertSame($meta['label']['ja'], $this->subject()->label('kakaopay', ['nhnkcp_easy_pay_label' => $meta['label']]));
    }

    /**
     * 이미 저장된 ko·en 라벨은 고치지 않고 그대로 쓴다 — 일본어 화면에서는 대체 언어 값이 나온다.
     *
     * @scenario contract=payment_label_snapshot
     *
     * @effects stored_easy_pay_label_kept_as_is
     *
     * @return void 반환값 없음
     */
    public function test_stored_label_without_japanese_is_used_as_is(): void
    {
        config(['app.site_locale' => 'ko']);
        app()->setLocale('ja');

        $label = $this->subject()->label('kakaopay', [
            'nhnkcp_easy_pay_label' => ['ko' => '카카오페이(저장)', 'en' => 'KakaoPay(saved)'],
        ]);

        $this->assertSame('카카오페이(저장)', $label);
    }

    /**
     * 번들 일본어 언어팩의 결제수단 번역을 읽습니다.
     *
     * @return array<string, mixed> 결제수단 키 => 번역
     */
    private function japanesePack(): array
    {
        return require base_path('lang-packs/_bundled/g7-plugin-sirsoft-pay_nhnkcp-ja/backend/ja/payment_methods.php');
    }

    /**
     * 트레이트의 보호 메서드를 부를 수 있는 시험 대상을 만듭니다.
     *
     * @return object 시험 대상
     */
    private function subject(): object
    {
        return new class
        {
            use ResolvesEasyPayDisplay;

            /**
             * 결제 meta 에 남길 간편결제 값을 돌려줍니다.
             *
             * @param  array<string, mixed>  $payload  PG 페이로드
             * @return array<string, mixed> 간편결제 값
             */
            public function meta(array $payload): array
            {
                return $this->resolveEasyPayMeta($payload);
            }

            /**
             * 간편결제 제공자 표시 라벨을 돌려줍니다.
             *
             * @param  string  $provider  제공자 코드
             * @param  array<string, mixed>  $paymentMeta  결제 meta
             * @return string|null 라벨
             */
            public function label(string $provider, array $paymentMeta): ?string
            {
                return $this->embeddedPgProviderLabel($provider, $paymentMeta);
            }
        };
    }
}
