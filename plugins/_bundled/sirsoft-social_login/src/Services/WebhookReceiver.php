<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Extension\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookEventType;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookStatus;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Jobs\ProcessWebhookEventJob;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\WebhookEventRepositoryInterface;

/**
 * 제공자 웹훅 수신 — 검증·멱등 저장·처리 잡 예약.
 *
 * 검증은 드라이버(`verifyWebhook`)가 하고, 이 서비스는 요청 안에서 끝나야 하는 일(검증·저장)만 한다. 처리(연결 해제·탈퇴)는
 * 큐 잡이 맡는다 — 카카오는 3초 안에 답하지 않으면 실패로 보고, 네이버는 다시 보내지 않는다.
 * 검증에 실패한 요청도 `ignored` 로 남긴다(진단 화면이 거절 수와 사유를 보인다). 본문은 비밀·서명·암호문을 뺀 요약만 남긴다.
 *
 * @since 1.0.0
 */
class WebhookReceiver
{
    /** 수신 직후 훅 (인자: SocialLoginWebhookEvent — 비밀 없는 요약) */
    public const ACTION_AFTER_RECEIVE = 'sirsoft-social_login.webhook.after_receive';

    /** 수신 결과: 새로 받아 처리 예약 */
    public const ACCEPTED = 'accepted';

    /** 수신 결과: 이미 받은 알림 */
    public const DUPLICATE = 'duplicate';

    /** 수신 결과: 검증 실패 */
    public const IGNORED = 'ignored';

    /**
     * @param  ProviderRegistry  $registry  제공자 레지스트리
     * @param  WebhookEventRepositoryInterface  $events  웹훅 저장소
     */
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly WebhookEventRepositoryInterface $events,
    ) {}

    /**
     * 웹훅 1건을 받습니다.
     *
     * @param  string  $providerKey  제공자 키 (내장 kakao·naver·facebook)
     * @param  Request  $request  요청
     * @return array{result: string, event: SocialLoginWebhookEvent|null, error: string|null} 수신 결과
     */
    public function receive(string $providerKey, Request $request): array
    {
        $driver = $this->registry->get($providerKey);
        if ($driver === null || ! (bool) ($driver->supports()['webhook'] ?? false)) {
            return $this->ignore($providerKey, $request, 'provider_unavailable');
        }

        try {
            $draft = $driver->verifyWebhook($request);
        } catch (ProviderException $e) {
            return $this->ignore($providerKey, $request, $e->errorCode);
        }

        if ($draft === null) {
            return $this->ignore($providerKey, $request, 'webhook_unsupported');
        }

        $attributes = $draft->getAttributes();
        $attributes['payload'] = $draft->payload;
        $attributes['status'] = WebhookStatus::Received->value;
        if ($draft->event_type === WebhookEventType::DataDeletion->value) {
            $attributes['confirmation_code'] = Str::random(20);
        }

        $event = $this->events->recordIfNew($attributes);
        if ($event === null) {
            return ['result' => self::DUPLICATE, 'event' => $this->events->findByEventKey((string) $draft->event_key), 'error' => null];
        }

        HookManager::doAction(self::ACTION_AFTER_RECEIVE, $event);

        try {
            ProcessWebhookEventJob::dispatch((int) $event->getKey());
        } catch (\Throwable $e) {
            // 큐에 넣지 못해도 제공자에게는 받았다고 답한다 — 행은 `received` 로 남아 진단 화면에 보인다.
            Log::error('[sirsoft-social_login] 웹훅 처리 예약 실패', ['event_id' => $event->getKey(), 'error' => class_basename($e)]);
        }

        return ['result' => self::ACCEPTED, 'event' => $event, 'error' => null];
    }

    /**
     * 형식이 틀린 요청(입력 검증 실패)을 `ignored` 로 남깁니다 — 제공자에게는 호출자가 규약 응답을 준다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  Request  $request  요청
     * @return array{result: string, event: SocialLoginWebhookEvent|null, error: string} 수신 결과
     */
    public function rejectMalformed(string $providerKey, Request $request): array
    {
        return $this->ignore($providerKey, $request, 'webhook_payload_invalid');
    }

    /**
     * 검증에 실패한 요청을 `ignored` 로 남깁니다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  Request  $request  요청
     * @param  string  $reason  거절 코드
     * @return array{result: string, event: SocialLoginWebhookEvent|null, error: string} 수신 결과
     */
    private function ignore(string $providerKey, Request $request, string $reason): array
    {
        $event = $this->events->recordIfNew([
            'provider_key' => $providerKey,
            'event_key' => $providerKey.':ignored:'.Str::uuid()->toString(),
            'event_type' => WebhookEventType::Unlink->value,
            'payload' => ['ip' => $request->ip()],
            'status' => WebhookStatus::Ignored->value,
            'error' => $reason,
            'processed_at' => now(),
        ]);

        return ['result' => self::IGNORED, 'event' => $event, 'error' => $reason];
    }
}
