<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 구매확정 경고 문구 정확성 테스트 (S2-D2 회귀 · #599 통합실측 세션2)
 *
 * 마이페이지 주문상세의 [구매확정] 모달은
 * `구매확정 후에는 취소/교환/환불이 불가능합니다.` 라고 경고했다.
 * **셋 중 둘이 사실이 아니다.**
 *
 * `UserClaimService::claimableOptions()` 는 교환·반품 가능 상태를
 * `claim.exchange_requestable_statuses` · `claim.return_requestable_statuses`
 * 에서 읽고, 기본값은 둘 다 `[delivered, confirmed]` 다. 즉 구매확정한 상품도
 * **교환·반품은 그대로 신청할 수 있다**. 닫히는 것은 취소·환불뿐이다
 * (`branchForStatus(confirmed)` 가 null 이라 refund 가 유형 목록에서 빠진다).
 *
 * 실측(주문 107 · 사과 옵션 231)에서 구매확정 직후
 * `claimable-options` 가 `claimable_types: ["exchange","return"]` 을 그대로
 * 돌려주는 것을 확인했다.
 *
 * 사실과 반대인 경고는 **정당한 반품을 단념시킨다** — 배송완료 상품을
 * 구매확정하면 반품 길이 막힌다고 읽히기 때문이다.
 *
 * 이 테스트는 두 가지를 잠근다.
 *   ① 설정 기본값이 confirmed 를 교환·반품 가능 상태로 유지하는가(계약)
 *   ② 경고 문구가 그 계약과 어긋나지 않는가(문구)
 */
class ConfirmPurchaseWarningAccuracyTest extends ModuleTestCase
{
    /**
     * 경고 문구가 사는 템플릿 언어 파셜.
     */
    private const WARNING_PATH = 'mypage.order_detail.confirm_modal.warning';

    /**
     * 저장소 루트.
     *
     * **단계 수를 고정하지 않는다.** 이 테스트는 `_bundled` 원본과 활성 사본 **양쪽**에
     * 같은 내용으로 복제되는데 두 경로의 깊이가 한 단계 다르다 —
     * `modules/_bundled/sirsoft-ecommerce/tests/Unit/Lang` 는 6단계,
     * `modules/sirsoft-ecommerce/tests/Unit/Lang` 는 5단계다. 6단계로 고정하면 활성
     * 사본에서 저장소 **밖**(`htdocs/`)을 가리켜, 언어 파일이 멀쩡한데도
     * `assertFileExists` 가 「파일이 없다」로 터진다 — 가드가 스스로를 무력화한다
     * (#599 세션2 계획서준수검증 2차 결함3).
     *
     * 그래서 `artisan` 과 `composer.json` 이 **함께** 있는 디렉터리를 만날 때까지
     * 올라간다. 둘 중 하나만 보면 다른 PHP 프로젝트에서도 멈출 수 있어 둘 다 요구한다.
     *
     * @return string 저장소 루트 절대 경로
     */
    private function repoRoot(): string
    {
        $dir = __DIR__;

        while (! is_file($dir.'/artisan') || ! is_file($dir.'/composer.json')) {
            $parent = dirname($dir);

            // dirname() 이 더는 줄지 않으면 드라이브 루트다. 마커 없이 여기까지 왔으면
            // 경로 가정이 깨진 것이므로 조용히 넘기지 않고 red 로 세운다.
            if ($parent === $dir) {
                $this->fail('저장소 루트를 찾지 못했습니다(artisan·composer.json 마커 부재) — 시작: '.__DIR__);
            }

            $dir = $parent;
        }

        return $dir;
    }

    /**
     * 경고 문구가 실릴 수 있는 **모든 사본**의 경로를 돌려줍니다.
     *
     * 템플릿 언어 파셜(ko·en)과 **언어팩**(ja) 은 서로 다른 트리에 산다. 앞의 것만 보면
     * 번역이 딴소리를 해도 검사가 닿지 않는다 — S2-D2 를 고치고도 ja 가 「交換・返金が
     * できません」으로 남아 있었던 것이 그 구멍이다(#599 세션2 계획서준수검증 결함1).
     *
     * @return array<string, string> 사본 이름 => 절대 경로
     */
    private function warningFilePaths(): array
    {
        $root = $this->repoRoot();

        return [
            // 템플릿 원본 (ko·en)
            'template:ko' => "{$root}/templates/_bundled/sirsoft-basic/lang/partial/ko/mypage.json",
            'template:en' => "{$root}/templates/_bundled/sirsoft-basic/lang/partial/en/mypage.json",
            // 언어팩 (ja) — 번들 원본과 활성 사본 **둘 다**. 한쪽만 고치면 다른 쪽이 화면에 뜬다.
            'langpack:ja(_bundled)' => "{$root}/lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/mypage.json",
            'langpack:ja(active)' => "{$root}/lang-packs/g7-template-sirsoft-basic-ja/frontend/partial/mypage.json",
        ];
    }

    private function readWarningAt(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        // WARNING_PATH 의 'mypage.' 접두는 파일명이므로 떼고 탐색한다.
        $segments = explode('.', self::WARNING_PATH);
        array_shift($segments);

        $node = $data;
        foreach ($segments as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }

    private function readWarning(string $locale): ?string
    {
        return $this->readWarningAt(
            $this->repoRoot()."/templates/_bundled/sirsoft-basic/lang/partial/{$locale}/mypage.json"
        );
    }

    /**
     * 구매확정 상태는 교환·반품 신청이 열려 있어야 한다 (문구가 기대는 계약).
     *
     * @scenario surface=claim_contract
     *
     * @effects confirmed_status_keeps_exchange_and_return_open
     */
    public function test_confirmed_status_still_allows_exchange_and_return(): void
    {
        $exchange = config(
            'g7_settings.claim.exchange_requestable_statuses',
            [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]
        );
        $return = config(
            'g7_settings.claim.return_requestable_statuses',
            [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]
        );

        $this->assertContains(
            OrderStatusEnum::CONFIRMED->value,
            $exchange,
            '구매확정 상태가 교환 신청 가능 목록에서 빠졌습니다. '
                .'경고 문구(취소/교환/환불 불가)의 전제가 바뀌었다면 문구도 함께 고쳐야 합니다.'
        );

        $this->assertContains(
            OrderStatusEnum::CONFIRMED->value,
            $return,
            '구매확정 상태가 반품 신청 가능 목록에서 빠졌습니다. '
                .'경고 문구(취소/교환/환불 불가)의 전제가 바뀌었다면 문구도 함께 고쳐야 합니다.'
        );
    }

    /**
     * 경고 문구는 교환·반품까지 막힌다고 말하면 안 된다.
     *
     * @scenario surface=warning_copy
     *
     * @effects warning_does_not_claim_exchange_or_return_blocked
     */
    public function test_confirm_warning_does_not_claim_exchange_or_return_are_blocked(): void
    {
        $ko = $this->readWarning('ko');

        $this->assertNotNull($ko, '구매확정 경고 문구(ko)를 찾지 못했습니다: '.self::WARNING_PATH);

        // 「취소/교환/환불이 불가능」 처럼 교환·반품까지 한 묶음으로 막힌다고
        // 선언하는 형태를 금지한다. 교환·반품을 **가능하다고** 말하는 문장은 허용된다.
        foreach (['교환', '반품'] as $word) {
            $this->assertDoesNotMatchRegularExpression(
                "/{$word}[^.]{0,20}(불가능|불가|할 수 없)/u",
                $ko,
                "구매확정 경고가 '{$word}' 이(가) 막힌다고 말합니다. 실제로는 구매확정 후에도 "
                    ."{$word}을(를) 신청할 수 있습니다(claim.*_requestable_statuses 에 confirmed 포함). 실측: {$ko}"
            );
        }

        $en = $this->readWarning('en');

        if ($en !== null) {
            $this->assertDoesNotMatchRegularExpression(
                '/(exchange|return)[^.]{0,30}(is not available|are not available|cannot)/i',
                $en,
                "구매확정 경고(en)가 교환·반품이 막힌다고 말합니다. 실측: {$en}"
            );
        }
    }

    /**
     * **모든 로케일 사본**이 교환·반품까지 막힌다고 말하지 않는다.
     *
     * 위의 ko·en 단언은 `templates/…/lang/partial/` 만 본다. 번역은 거기 살지 않는다 —
     * ja 는 `lang-packs/` 에 있고, 그래서 S2-D2 를 고치고도 일본어 화면은 **여전히**
     * 「交換・返金ができません」이라고 경고했다(#599 세션2 계획서준수검증 결함1).
     * 일본어 사용자는 정당한 교환·반품을 단념한다.
     *
     * 그 구멍은 두 겹이었다:
     *   ① 언어팩 트리를 **탐색조차 하지 않았다**.
     *   ② 파일이 없으면 `null` 을 돌려주고 호출부가 `if (… !== null)` 로 감싸
     *      **단언을 통째로 건너뛰었다** — 검사가 초록이어도 아무것도 증명하지 않는다.
     *
     * 그래서 이 테스트는 사본이 **없으면 실패**한다. 경로가 바뀌었는데 조용히 통과하면
     * 같은 사고가 그대로 되풀이된다.
     *
     * @scenario surface=warning_copy
     *
     * @effects warning_does_not_claim_exchange_or_return_blocked
     */
    public function test_every_locale_copy_of_the_warning_keeps_exchange_and_return_open(): void
    {
        // 「교환·반품이 막힌다」고 읽히는 형태만 잡는다. 가능하다고 **말하는** 문장은 통과해야 한다.
        // ko/en 은 부정어가 뒤에 붙고(교환…불가), ja 는 조사 뒤 `できません` 이 온다.
        $blockedPatterns = [
            'ko' => '/(교환|반품)[^.。]{0,20}(불가능|불가|할 수 없|안 됩니다)/u',
            'en' => '/(exchange|return)[^.]{0,30}(is not available|are not available|cannot|not allowed)/i',
            'ja' => '/(交換|返品)[^.。]{0,20}(できません|不可|できなくなります)/u',
        ];

        foreach ($this->warningFilePaths() as $label => $path) {
            $this->assertFileExists(
                $path,
                "구매확정 경고 사본({$label})이 없습니다. 경로가 바뀌었다면 이 테스트의 "
                    .'warningFilePaths() 도 함께 고쳐야 합니다 — 여기서 조용히 넘어가면 '
                    .'그 로케일은 사실과 반대인 경고를 그대로 띄웁니다.'
            );

            $warning = $this->readWarningAt($path);

            $this->assertNotNull(
                $warning,
                "구매확정 경고 문구({$label})를 찾지 못했습니다: ".self::WARNING_PATH
                    .' — 키가 사라졌거나 구조가 바뀌었습니다.'
            );

            foreach ($blockedPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $warning,
                    "구매확정 경고({$label})가 교환·반품이 막힌다고 말합니다. 실제로는 구매확정 "
                        .'후에도 교환·반품을 신청할 수 있습니다(claim.*_requestable_statuses 에 '
                        ."confirmed 포함). 실측: {$warning}"
                );
            }
        }
    }
}
