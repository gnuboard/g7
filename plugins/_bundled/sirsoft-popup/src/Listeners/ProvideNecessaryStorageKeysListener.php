<?php

namespace Plugins\Sirsoft\Popup\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * GDPR 필수 저장 항목 제공 — 「보지 않기」 기록 쿠키를 strictly necessary 로 등록한다(결정 D3·D6).
 *
 * GDPR 플러그인의 계약 훅(`sirsoft-gdpr.necessary_storage.filter_provided_keys`)에 쿠키 이름 하나를 더할 뿐 GDPR 코드는 참조하지 않는다.
 * GDPR 이 없거나 꺼져 있으면 훅이 불리지 않고, 팝업은 쿠키를 그대로 쓴다(차단기가 없으므로).
 * 쿠키에 설명 문구는 붙이지 않는다 — 계약 훅은 이름만 받는다.
 */
class ProvideNecessaryStorageKeysListener implements HookListenerInterface
{
    /**
     * 스코프별 제공 항목
     */
    private const KEYS = [
        'cookie' => [
            // 「오늘 하루 / N일간 보지 않기」 기록 — 그때 보이던 팝업 id 마다 만료 시각({id: 만료}).
            // 리터럴로 적는다: GDPR 의 제공 키 커버리지 검사가 이 블록의 문자열만 읽는다. `Plugin::DISMISS_COOKIE` 와 같은 값은 테스트가 잠근다
            'g7_popup_dismissed',
        ],
    ];

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-gdpr.necessary_storage.filter_provided_keys' => [
                'method' => 'provide',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 제공 항목을 스코프별로 더합니다 — 앞선 리스너가 넣은 항목은 그대로 둡니다.
     *
     * @param  mixed  $provided  스코프 => 항목 배열
     * @return array<string, array<int, string>> 이 플러그인 항목을 더한 배열
     */
    public function provide(mixed $provided): array
    {
        $provided = is_array($provided) ? $provided : [];

        foreach (self::KEYS as $scope => $keys) {
            $current = is_array($provided[$scope] ?? null) ? $provided[$scope] : [];
            $provided[$scope] = array_values(array_unique([...$current, ...$keys]));
        }

        return $provided;
    }
}
