<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\MessageBizppurio\Concerns\PreventsReplayWebhook;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Enums\ResultCategory;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;

/**
 * 비즈뿌리오 webhook 리포트 처리 서비스 (계획서 §5 Phase4, D3·D13).
 *
 * 컨트롤러가 검증된 리포트 값을 넘기면 상태전이·replay 멱등·잔액부족 알림을 수행한다.
 * 처리 흐름:
 *  ① REFKEY 로 발송 이력 조회 — 없으면 위조로 간주하고 무시(멱등).
 *  ② reported_at 이 이미 있으면 replay — 멱등 처리(상태 재변경·알림 재발송 방지).
 *  ③ RESULT 코드를 분류(성공/실패/잔액부족)해 상태를 전이하고 리포트 필드를 기록.
 *  ④ 잔액부족(9070/7436)이면 관리자 자체 알림을 쿨다운 내 1회만 발송(D3).
 */
class WebhookReportService
{
    use PreventsReplayWebhook;

    /** 플러그인 식별자 (환경설정 조회용) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 잔액부족 알림 쿨다운 기본값(초) — 설정 미지정 시 fallback */
    private const DEFAULT_BALANCE_LOW_COOLDOWN = 3600;

    /**
     * @param  BizppurioDispatchRepositoryInterface  $dispatches  발송 이력 리포지토리
     * @param  ResultCodeResolver  $resolver  결과 코드 해석기
     * @param  CacheInterface  $cache  잔액부족 알림 쿨다운 저장 (contextual binding 주입)
     * @param  PluginSettingsService  $pluginSettings  쿨다운 설정값 조회
     */
    public function __construct(
        private readonly BizppurioDispatchRepositoryInterface $dispatches,
        private readonly ResultCodeResolver $resolver,
        private readonly CacheInterface $cache,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * webhook 리포트 1건을 처리해 발송 이력 상태를 갱신합니다.
     *
     * @param  array<string, mixed>  $report  검증된 리포트 (REFKEY/RESULT/MEDIA/TELRES/KAORES + raw)
     */
    public function apply(array $report): void
    {
        $refkey = (string) ($report['REFKEY'] ?? '');
        $resultCode = (string) ($report['RESULT'] ?? '');

        // ① REFKEY 매칭 — 없으면 위조/미매칭, 멱등 흡수
        $dispatch = $this->dispatches->findByRefkey($refkey);
        if ($dispatch === null) {
            return;
        }

        // ② replay — 이미 리포트 반영된 이력이면 멱등
        if ($this->wasAlreadyReported($dispatch)) {
            $this->logReplayDetected($refkey, $resultCode);

            return;
        }

        // ③ 결과 분류 → 상태 전이 + 리포트 필드 기록
        $category = $this->resolver->categorize($resultCode);
        $status = $category === ResultCategory::Success
            ? DispatchStatus::Success
            : DispatchStatus::Failed;

        $this->dispatches->update($dispatch, [
            'status' => $status->value,
            'media' => $report['MEDIA'] ?? null,
            'result_code' => $resultCode,
            'result_message' => $this->resolver->reason($resultCode),
            'fallback_status' => $this->resolveFallbackStatus($report, $dispatch),
            'reported_at' => now(),
            'raw_payload' => $report['raw'] ?? $report,
        ]);

        // ④ 잔액부족(9070/7436) → 관리자 자체 알림 (쿨다운 내 1회만, D3)
        if ($category === ResultCategory::BalanceLow) {
            $this->notifyBalanceLowOnce($resultCode, $dispatch->channel->value);
        }
    }

    /**
     * 잔액부족 알림을 쿨다운 내 1회만 발송합니다 (D3 중복 방지).
     *
     * 잔액이 부족하면 발송건마다 9070/7436 이 쏟아지므로, 그때마다 알림을 보내면
     * 관리자에게 알림이 폭주한다. 채널별로 캐시 키를 두어, 쿨다운(기본 1시간) 동안
     * 이미 알렸으면 발송을 건너뛴다. 발송 이력의 실패 기록(③)은 이와 무관하게 매 건
     * 남으므로, 실패 내역 자체는 모두 보존된다.
     *
     * 쿨다운은 environment 설정(balance_low_notify_cooldown, 초)으로 조정 가능하며,
     * 화면 노출 없이 설정 파일에서만 관리한다. 잔액을 충전하면 9070/7436 이 더 이상
     * 발생하지 않으므로 알림도 자연히 멈춘다.
     *
     * @param  string  $resultCode  결과 코드(9070 문자 / 7436 알림톡)
     * @param  string  $channel  채널(sms / lms / alimtalk)
     */
    private function notifyBalanceLowOnce(string $resultCode, string $channel): void
    {
        $cacheKey = "bizppurio:balance_low_notified:{$channel}";

        if ($this->cache->has($cacheKey)) {
            return;
        }

        $cooldown = (int) $this->pluginSettings->get(
            self::PLUGIN_IDENTIFIER,
            'balance_low_notify_cooldown',
            self::DEFAULT_BALANCE_LOW_COOLDOWN,
        );

        $this->cache->put($cacheKey, true, $cooldown);

        HookManager::doAction(
            'sirsoft-message_bizppurio.balance.low',
            $resultCode,
            $channel,
        );
    }

    /**
     * 대체발송 결과를 해석합니다 (webhook TELRES/KAORES).
     *
     * 비즈뿌리오는 대체발송 미요청 건에도 TELRES/KAORES 에 "0" 같은 값을 채워 보내므로
     * (실측), webhook 응답값만으로는 대체발송 발생 여부를 판단할 수 없다. 우리가 발송
     * 시점에 실제로 대체발송을 요청했는지(`request_payload` 의 resend/recontent 존재)를
     * 먼저 확인해, 요청하지 않았으면 webhook 값과 무관하게 null 을 반환한다.
     *
     * @param  array<string, mixed>  $report  리포트
     * @param  BizppurioDispatch  $dispatch  webhook 매칭된 발송 이력 (발송 시점 요청 payload 보유)
     * @return string|null 대체발송 결과 코드 또는 null
     */
    private function resolveFallbackStatus(array $report, BizppurioDispatch $dispatch): ?string
    {
        $requestPayload = $dispatch->request_payload ?? [];
        if (! isset($requestPayload['resend'])) {
            return null;
        }

        $telres = $report['TELRES'] ?? null;
        $kaores = $report['KAORES'] ?? null;

        return $telres !== null && $telres !== ''
            ? (string) $telres
            : ($kaores !== null && $kaores !== '' ? (string) $kaores : null);
    }
}
