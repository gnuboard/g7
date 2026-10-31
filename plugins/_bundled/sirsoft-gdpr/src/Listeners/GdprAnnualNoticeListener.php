<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Gdpr\Enums\PrivacyNoticeStatus;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Privacy\AnnualNoticeManualRunner;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;

/** 통지 데이터 추출과 실제 채널 결과의 동기 기록을 연결합니다. */
class GdprAnnualNoticeListener implements HookListenerInterface
{
    /**
     * 필요한 서비스를 주입합니다.
     *
     * @param  GdprAnnualNoticeService  $notices  통지 서비스 또는 이력 저장소
     */
    public function __construct(private readonly GdprAnnualNoticeService $notices) {}

    /**
     * 통지 관련 훅과 실행 방식을 선언합니다.
     *
     * @return array 처리 결과
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-gdpr.notification.extract_data' => ['method' => 'extract', 'type' => 'filter'],
            'core.notification.after_channel_send' => ['method' => 'sent', 'sync' => true],
            'core.notification.channel_send_failed' => ['method' => 'failed', 'sync' => true],
            'core.notification.filter_default_definitions' => ['method' => 'defaults', 'type' => 'filter', 'priority' => 20],
            'core.privacy.manual_run_targets' => ['method' => 'addManualRunTarget', 'type' => 'filter', 'priority' => 20],
        ];
    }

    /**
     * 코어의 기본값 복원 경로에 이 플러그인이 소유한 알림 정의를 제공합니다.
     *
     * 이것이 없으면 관리자가 템플릿을 고친 뒤 「기본값 복원」을 눌러도 되돌릴 원본이 없어
     * 조용히 아무 일도 일어나지 않는다(형제 확장 4종은 모두 이 훅을 구독한다).
     *
     * @param  array  $definitions  다른 확장이 제공한 기본 정의
     * @return array 소유 정보를 덧붙인 기본 정의 목록
     */
    public function defaults(array $definitions): array
    {
        foreach ((new Plugin)->getNotificationDefinitions() as $definition) {
            $definitions[] = $definition + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-gdpr'];
        }

        return $definitions;
    }

    /**
     * 연간 통지 발송을 코어 관리자 직권 실행 대상으로 등록합니다.
     *
     * 등록만 하면 코어의 접수 API·큐 작업·실행 기록·상태 조회가 따라온다 — 플러그인 설정 화면의
     * 「지금 실행」이 그 경로를 쓴다. DB 에 접근하지 않고 클래스명만 더한다.
     *
     * @param  array  $runners  다른 확장이 등록한 대상 목록
     * @return array 이 플러그인의 대상을 더한 목록
     */
    public function addManualRunTarget(array $runners): array
    {
        $runners[] = AnnualNoticeManualRunner::class;

        return $runners;
    }

    /**
     * 등록된 개별 처리 메서드에 작업을 위임합니다.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 통지 처리 계약을 수행합니다.
     *
     * @param  array  $extracted  기존 추출 데이터
     * @param  string  $type  알림 유형
     * @param  array  $args  훅 인자
     * @return array 처리 결과
     */
    public function extract(array $extracted, string $type, array $args): array
    {
        return $this->notices->extract($extracted, $type, $args);
    }

    /**
     * 성공한 채널 결과를 기록합니다.
     *
     * @param  string  $channel  결과 채널
     * @param  array  $context  발송 컨텍스트
     * @return void 반환값 없음
     */
    public function sent(string $channel, array $context): void
    {
        $this->notices->recordResult($channel, $context, PrivacyNoticeStatus::Sent);
    }

    /**
     * 실패한 채널 결과를 기록합니다.
     *
     * @param  string  $channel  결과 채널
     * @param  array  $context  발송 컨텍스트
     * @return void 반환값 없음
     */
    public function failed(string $channel, array $context): void
    {
        $this->notices->recordResult($channel, $context, PrivacyNoticeStatus::Failed);
    }
}
