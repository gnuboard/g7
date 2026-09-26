<?php

namespace App\Listeners\Analytics;

use App\Contracts\Analytics\ConversionRecorderInterface;
use App\Contracts\Extension\HookListenerInterface;

/**
 * 코어 전환(회원가입·로그인) 기록 리스너
 *
 * `config/analytics.php` 의 코어 목표 두 건을 실제 순간에 기록합니다. 브라우저는 가입이나
 * 로그인이 **성공했는지** 확실히 모르므로(응답이 오기 전에 화면을 떠날 수 있다) 서버의
 * 완료 훅을 받습니다.
 *
 * **이 리스너는 큐를 타지 않습니다**(`sync => true`) — 전환은 요청의 쿠키·IP·User-Agent 로
 * 방문을 찾는데, 큐 워커에는 그 요청이 없습니다. 기록기는 예외를 삼키므로 가입·로그인
 * 응답을 막지 않습니다.
 */
class CoreGoalsListener implements HookListenerInterface
{
    /**
     * 리스너를 생성합니다.
     *
     * @param  ConversionRecorderInterface  $recorder  전환 기록기
     */
    public function __construct(private readonly ConversionRecorderInterface $recorder) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 구독 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.after_register' => ['method' => 'handleRegistered', 'priority' => 30, 'sync' => true],
            'core.auth.after_login' => ['method' => 'handleLoggedIn', 'priority' => 30, 'sync' => true],
        ];
    }

    /**
     * 회원가입 완료를 전환으로 남깁니다.
     *
     * @param  mixed  ...$args  훅 인자 (가입 회원, 가입 맥락)
     * @return void
     */
    public function handleRegistered(...$args): void
    {
        $this->recorder->record('core.user_registered');
    }

    /**
     * 로그인 성공을 전환으로 남깁니다.
     *
     * @param  mixed  ...$args  훅 인자 (로그인 회원, 로그인 맥락)
     * @return void
     */
    public function handleLoggedIn(...$args): void
    {
        $this->recorder->record('core.user_logged_in');
    }

    /**
     * 훅 리스너 기본 진입점입니다 (이 리스너는 훅별 메서드를 씁니다).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}
}
