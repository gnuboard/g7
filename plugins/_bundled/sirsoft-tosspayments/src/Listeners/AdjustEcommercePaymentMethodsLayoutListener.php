<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Tosspayments\Concerns\MapsTossPaymentMethods;

/**
 * 이커머스 결제수단 설정 화면에서 토스 주문서형 결제수단을 "PG 선택 불필요" 항목으로 표시한다.
 *
 * admin_ecommerce_settings 레이아웃의 여러 표현식에 등장하는 no-PG 결제수단 리스트 리터럴
 * (`['point','deposit','free','dbank', ...]`) 에 활성 토글된 toss_* id 를 추가한다.
 *
 * KG 플러그인이 같은 리스트를 상수 치환 방식으로 이미 재작성하므로, 두 플러그인이 동시
 * 활성일 때 서로의 결과를 덮어쓰지 않도록 "현재 리스트에 없는 id 만 append" 하는 멱등
 * 구현으로 작성한다 (KG 의 통짜 상수 치환을 복제하면 KG id 가 소실된다).
 */
class AdjustEcommercePaymentMethodsLayoutListener implements HookListenerInterface
{
    use MapsTossPaymentMethods;

    private const TARGET_LAYOUT = 'admin_ecommerce_settings';

    /**
     * no-PG 결제수단 리스트 리터럴의 앵커 — 코어가 항상 이 4종을 이 순서로 연다.
     * KG 가 먼저 실행되면 뒤에 kginicis_* 가 append 되어 있을 수 있으므로, 여는 대괄호부터
     * 닫는 대괄호까지 통째로 캡처해 그 안에 없는 toss_* id 만 추가한다.
     */
    private const LIST_PATTERN = "/\\['point','deposit','free','dbank'([^\\]]*)\\]/";

    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'markTossMethodsAsPgNotRequired',
                'type' => 'filter',
                // KG(20) 보다 반드시 뒤에 실행되어야 한다 — KG 는 닫는 대괄호까지 포함한 통짜
                // 리터럴을 str_replace 하므로, 토스가 먼저 append 하면 KG 의 매치가 실패해
                // kginicis_* 가 영영 주입되지 않는다. HookManager 는 ksort 오름차순이므로
                // 30 > 20 이 "KG 먼저" 를 불변식으로 고정한다 (플러그인 로드 순서 비의존).
                'priority' => 30,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * @param  array<string, mixed>  $layout  적용된 레이아웃
     * @param  int  $templateId  대상 템플릿 ID
     * @return array<string, mixed>
     */
    public function markTossMethodsAsPgNotRequired(array $layout, int $templateId): array
    {
        if (($layout['layout_name'] ?? '') !== self::TARGET_LAYOUT) {
            return $layout;
        }

        return $this->appendTossMethodsToNoPgLists($layout);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function appendTossMethodsToNoPgLists(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->appendTossMethodsToNoPgLists($value);

                continue;
            }

            if (is_string($value) && str_contains($value, "'dbank'")) {
                $node[$key] = $this->mergeTossIds($value);
            }
        }

        return $node;
    }

    /**
     * 문자열 내 no-PG 리스트 리터럴에 없는 toss_* id 만 append 합니다 (멱등).
     */
    private function mergeTossIds(string $expression): string
    {
        // 결제수단 id SSoT 는 MapsTossPaymentMethods::TOSS_METHOD_MAP 이다 (하드코딩 이중화 금지).
        $tossIds = $this->allTossMethodIds();

        return (string) preg_replace_callback(
            self::LIST_PATTERN,
            function (array $matches) use ($tossIds): string {
                $existing = $matches[1]; // 예: ",'kginicis_samsung_pay',..." (KG 선행 시)
                $additions = '';
                foreach ($tossIds as $id) {
                    if (! str_contains($existing, "'{$id}'")) {
                        $additions .= ",'{$id}'";
                    }
                }

                return "['point','deposit','free','dbank'{$existing}{$additions}]";
            },
            $expression,
        );
    }
}
