<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Board\Privacy\BoardGuestRetentionHandler;
use Modules\Sirsoft\Board\Privacy\BoardIpRetentionHandler;

/**
 * 게시판 개인정보 보유기간 핸들러를 코어에 등록하는 리스너 (이슈 #636)
 *
 * 코어 `core.privacy.retention_handlers` 필터에 게시판의 두 축(작성 IP · 비회원 작성자
 * 정보)을 덧붙입니다. 코어 보유기간 커맨드(`privacy:process-retention`)와 미리보기가 이 목록을
 * 읽어 실행하므로, 게시판은 스케줄을 따로 두지 않습니다.
 *
 * 클래스명만 덧붙이며 DB 에 접근하지 않습니다 — 켜짐 판정과 실행은 각 핸들러가 게시판
 * 설정(`privacy_retention.*`, 기본 꺼짐)을 읽어 스스로 합니다.
 */
class PrivacyRetentionHandlerListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, mixed> 훅 이름별 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.privacy.retention_handlers' => [
                'method' => 'addBoardHandlers',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * Filter 훅은 getSubscribedHooks 에서 지정한 메서드를 직접 호출하므로
     * 이 메서드는 인터페이스 요구사항 충족을 위해서만 존재합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 게시판 보유기간 핸들러를 추가합니다.
     *
     * @param  array  $handlers  기존 핸들러 클래스 목록
     * @return array 게시판 핸들러가 추가된 목록
     */
    public function addBoardHandlers(array $handlers): array
    {
        $handlers[] = BoardIpRetentionHandler::class;
        $handlers[] = BoardGuestRetentionHandler::class;

        return $handlers;
    }
}
