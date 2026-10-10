<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 쿠키 미동의 방문자에게도 필요한 이 확장의 브라우저 저장 키를 개인정보 동의 플러그인에 제공합니다.
 *
 * 동의 관리 플러그인은 동의 전에는 허용목록 밖의 저장 키를 부팅마다 지운다. 그 플러그인은 어떤
 * 확장이 있는지 모르므로, 각 확장이 자기 키를 그 플러그인이 소유한 필터 훅에 덧붙인다. 그 플러그인이
 * 설치돼 있지 않으면 훅이 발행되지 않아 이 리스너는 호출되지 않고, 이 확장의 동작에는 영향이 없다.
 * 동의 플러그인의 클래스를 참조하지 않는다 — 훅 이름과 배열 모양만이 계약이다.
 *
 * 결제창은 전체 페이지 이동으로 열리고 돌아오므로, 결제 요청 직전에 남긴 기록이 복귀 화면에서 지워지면
 * 결제 실패·창 닫힘 보고가 통째로 누락된다 (주문 이행, Art.6(1)(b)).
 */
class ProvideNecessaryStorageKeysListener implements HookListenerInterface
{
    /**
     * 저장소 종류별 제공 키.
     *
     * @var array<string, array<int, string>>
     */
    private const KEYS = [
        'sessionStorage' => [
            // 결제 진행 기록 — 결제창 복귀 시 종료·실패 사유 보고
            'g7:sirsoft-pay_nhnkcp:pendingClose',
        ],
    ];

    /**
     * 이 리스너가 구독하는 훅 정의를 반환합니다.
     *
     * @return array 훅 이름 => 구독 설정
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
     * 기본 핸들러 (미사용 — provide 에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 지금까지 모인 제공 키에 이 확장의 키를 덧붙입니다.
     *
     * @param  mixed  $keys  저장소 종류 => 키 배열 (다른 리스너가 이미 덧붙인 값 포함)
     * @return array<string, array<int, string>> 이 확장의 키가 더해진 배열
     */
    public function provide(mixed $keys): array
    {
        $keys = is_array($keys) ? $keys : [];

        foreach (self::KEYS as $scope => $items) {
            $current = is_array($keys[$scope] ?? null) ? $keys[$scope] : [];
            $keys[$scope] = array_values(array_unique([...$current, ...$items]));
        }

        return $keys;
    }
}
