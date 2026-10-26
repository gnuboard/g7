<?php

namespace Plugins\Sirsoft\Sso\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 쿠키 미동의 방문자에게도 필요한 이 확장의 브라우저 저장 키를 개인정보 동의 플러그인에 제공합니다.
 *
 * 동의 관리 플러그인은 동의 전에는 허용목록 밖의 쿠키를 응답에서 지운다. 회사 계정 로그인의 브라우저 묶음 쿠키(`g7_sso_bind`)가 지워지면
 * IdP 에서 돌아온 콜백이 시작한 브라우저를 확인하지 못해 모든 SSO 로그인이 `bind_mismatch` 로 실패한다 — 로그인 CSRF 를 막는 보안 쿠키라
 * 엄격히 필요한 쿠키다(사용자가 요청한 로그인 제공). 코어 로그인 인계 묶음 쿠키(`g7_login_bind`)는 동의 플러그인이 스스로 잠금 필수 목록에
 * 둔다 — 여기서 다시 내지 않는다. 동의 플러그인의 클래스를 참조하지 않는다 — 훅 이름과 배열 모양만이 계약이다. 그
 * 플러그인이 없으면 훅이 발행되지 않아 이 리스너는 호출되지 않는다.
 *
 * @since 1.0.0
 */
class ProvideNecessaryStorageKeysListener implements HookListenerInterface
{
    /**
     * 저장소 종류별 제공 키.
     *
     * @var array<string, array<int, string>>
     */
    private const KEYS = [
        'cookie' => [
            // 회사 계정 로그인 왕복의 브라우저 묶음(로그인 CSRF 방지)
            'g7_sso_bind',
        ],
    ];

    /**
     * 이 리스너가 구독하는 훅 정의를 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정
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
