<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Privacy\OrderRetentionHandler;

/**
 * 이커머스 개인정보 보유기간 기여 리스너 (이슈 #636)
 *
 * 코어가 소유한 두 개인정보 필터에 이커머스 몫을 더합니다.
 *
 * - `core.privacy.retention_handlers` — 종결 주문 보유기간 핸들러({@see OrderRetentionHandler})를
 *   등록합니다. 코어 `privacy:process-retention`(매일)이 실행하므로 이 모듈은 스케줄을 두지 않습니다.
 * - `core.privacy.notice_sections` — 개인정보 이용내역 연 1회 통지문에 「주문 정보」 섹션을 싣습니다.
 *   주문 기록의 법정 보존기간(전자상거래법)과, 보유기간 처리를 켰다면 그 뒤의 처리 방식을
 *   수신자 로케일로 적습니다. 통지 플러그인 설치 여부와 무관하게 구독할 수 있습니다 — 발행자가
 *   없으면 이 메서드는 불리지 않습니다.
 *
 * 두 기여 모두 DB 에 접근하지 않습니다 — 설정 판독과 실행은 핸들러가, 조립·발송은 통지 소비자가 합니다.
 */
class PrivacyRetentionListener implements HookListenerInterface
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
                'method' => 'addOrderHandler',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.privacy.notice_sections' => [
                'method' => 'addOrderNoticeSection',
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
     * 종결 주문 보유기간 핸들러를 추가합니다.
     *
     * @param  array  $handlers  기존 핸들러 클래스 목록
     * @return array 이커머스 핸들러가 추가된 목록
     */
    public function addOrderHandler(array $handlers): array
    {
        $handlers[] = OrderRetentionHandler::class;

        return $handlers;
    }

    /**
     * 개인정보 통지문에 주문 정보 섹션을 추가합니다.
     *
     * 개인 식별정보를 넣지 않는다(코어 계약) — 사이트 공통 정책만 적는다. 보유기간 처리가 꺼져
     * 있으면 법정 보존 사실만, 켜져 있으면 보존 뒤 처리 방식(익명화·삭제)과 그 기간까지 적는다.
     *
     * @param  array  $sections  기존 섹션 목록
     * @param  string  $locale  수신자 로케일
     * @return array 주문 정보 섹션이 추가된 목록
     */
    public function addOrderNoticeSection(array $sections, string $locale = 'ko'): array
    {
        $handler = app(OrderRetentionHandler::class);

        $body = __('sirsoft-ecommerce::messages.privacy_retention.notice_section.body', [], $locale);

        if ($handler->isEnabled()) {
            $years = (int) $handler->retentionYears();
            $body .= "\n".trans_choice(
                'sirsoft-ecommerce::messages.privacy_retention.notice_section.after_'.$handler->mode(),
                $years,
                ['years' => $years],
                $locale,
            );
        }

        $sections[] = [
            'title' => __('sirsoft-ecommerce::messages.privacy_retention.notice_section.title', [], $locale),
            'body' => $body,
        ];

        return $sections;
    }
}
