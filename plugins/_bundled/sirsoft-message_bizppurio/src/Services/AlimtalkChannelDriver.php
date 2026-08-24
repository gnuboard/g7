<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use App\Services\PluginSettingsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchSource;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\NotificationSendSkippedException;
use Plugins\Sirsoft\MessageBizppurio\Jobs\SendMessageJob;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;

/**
 * 코어 알림 시스템의 alimtalk 채널 드라이버 (#597 §3.5).
 *
 * ChannelManager::extend('alimtalk', …)(ServiceProvider::boot)로 등록되어, 코어가
 * `via()` 에서 'alimtalk' 채널을 선택하면 이 드라이버의 send() 가 호출된다.
 *
 * 발송 본문·요소의 출처는 bizppurio_templates 의 승인 스냅샷(approved_content)이다 —
 * 운영자가 시스템에서 작성해 검수 신청하고 승인된 그 내용 그대로다. 발송 시점의
 * 카카오 실시간 조회는 없다(DB 가 유일한 판정 근거, 이슈 #597 요구).
 *
 * 발송 게이트: 행 존재 + alimtalk_enabled + is_active + status=approved +
 * approved_content 존재(BizppurioTemplate::isAlimtalkSendable). 승인 취소로 status 가
 * draft 로 복귀하면 스냅샷이 남아 있어도 즉시 차단된다.
 *
 * 처리 흐름:
 *  1. 알림 유형(type)으로 템플릿 행 조회 → 발송 게이트 판정. 불충족 시
 *     NotificationSendSkippedException(코어가 실패로 기록 — 이슈 #28 계약 유지).
 *  2. 전화번호 해석(회원=mobile, 비회원=data 의 _recipient_phone — SmsChannelDriver 와 동일 계약).
 *  3. 승인 스냅샷 → 발송 형식 변환 + 변수(#{var}) 치환(AlimtalkPayloadMapper — 등록
 *     페이로드와 kapi 상세의 필드명이 동일해 스냅샷을 그대로 공급한다).
 *  4. refkey 생성 → payload 조립(버튼·요소는 extra, fallback_sms_enabled ON 시
 *     resend/recontent 병합 — 대체 본문은 행의 sms_body) → 이력 pending → SendMessageJob 위임.
 */
class AlimtalkChannelDriver
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 알림 data 에서 비회원 전화번호를 싣는 표준 키 (SmsChannelDriver 와 동일 계약) */
    public const RECIPIENT_PHONE_KEY = '_recipient_phone';

    /**
     * @param  NotificationDefinitionService  $definitionService  알림 유형의 사람이 읽는 이름 조회(스킵 예외 메시지용)
     * @param  BizppurioTemplateRepositoryInterface  $templates  알림 템플릿 행 조회(발송 게이트·본문 소스)
     * @param  MessagePayloadBuilder  $payloadBuilder  발송 payload 조립
     * @param  BizppurioDispatchRepositoryInterface  $dispatches  발송 이력 영속화
     * @param  DispatchLinkContext  $linkContext  발송 사이클 refkey↔코어 로그 연결 컨텍스트(A-2)
     * @param  AlimtalkPayloadMapper  $payloadMapper  승인 스냅샷 → 발송 형식 변환·치환
     * @param  PluginSettingsService  $pluginSettings  검수 모드 여부 조회(이력 스냅샷용)
     */
    public function __construct(
        private readonly NotificationDefinitionService $definitionService,
        private readonly BizppurioTemplateRepositoryInterface $templates,
        private readonly MessagePayloadBuilder $payloadBuilder,
        private readonly BizppurioDispatchRepositoryInterface $dispatches,
        private readonly DispatchLinkContext $linkContext,
        private readonly AlimtalkPayloadMapper $payloadMapper,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 알림 유형의 사람이 읽는 이름을 반환합니다 (스킵 예외 메시지용).
     *
     * 정의 조회 실패·이름 미설정 시 코드값(type)을 그대로 반환한다(안전 폴백).
     *
     * @param  string  $type  알림 유형 코드값 (welcome 등)
     * @return string 사람이 읽는 이름 또는 코드값
     */
    private function resolveTypeLabel(string $type): string
    {
        try {
            $label = $this->definitionService->resolve($type)?->getLocalizedName();

            return $label !== null && $label !== '' ? $label : $type;
        } catch (\Throwable $e) {
            return $type;
        }
    }

    /**
     * 알림을 카카오 알림톡으로 발송합니다.
     *
     * Laravel NotificationSender 가 'alimtalk' 채널 드라이버로 이 메서드를 호출한다.
     * GenericNotification 이 아닌 알림은 대상이 아니므로 조용히 무시한다.
     *
     * @param  object  $notifiable  수신자 (User 또는 GuestNotifiable)
     * @param  Notification  $notification  발송 대상 알림
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof GenericNotification) {
            return;
        }

        $type = $notification->getType();

        // 1. 템플릿 행 조회 + 발송 게이트 판정 (미승인·비활성·행 없음 → skip).
        //    코어 NotificationDispatcher::sendToNotifiable()의 catch(\Exception)가 이 예외를
        //    channel_send_failed 훅으로 연결해, 발송 이력에 "성공"이 아닌 "실패"로 기록되게 한다.
        $template = $this->templates->findByType($type);
        if ($template === null || ! $template->isAlimtalkSendable()) {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.alimtalk_template_not_approved', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 2. 전화번호 해석 (회원=mobile, 비회원=data 의 _recipient_phone)
        $to = $this->resolvePhone($notifiable, $notification->getData());
        if ($to === null) {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.recipient_phone_missing', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 3. 승인 스냅샷 → 발송 형식 변환 + 변수(#{var}) 치환. 본문이 비면 발송 불가라 skip.
        $mapped = $this->payloadMapper->map((array) $template->approved_content, $notification->getData());
        $message = (string) ($mapped['message'] ?? '');
        if (trim($message) === '') {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.message_body_empty', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 4. refkey 생성 → payload 조립 (버튼·바로연결·요소는 extra, 대체발송 ON 시 SMS resend 병합)
        $refkey = $this->generateRefkey();
        $payload = $this->payloadBuilder->buildAlimtalk(
            $to,
            (string) $template->template_code,
            $message,
            $refkey,
            (array) ($mapped['extra'] ?? []),
        );

        if ($template->fallback_sms_enabled) {
            $payload = $this->withSmsFallback($payload, $this->smsFallbackBody(
                $template,
                $notification,
                BaseNotification::resolveNotifiableLocale($notifiable),
            ));
        }

        // 5. 발송 이력 pending 생성 → Job 위임. Job/webhook 이 refkey 로 조회해 상태 갱신.
        $this->dispatches->create([
            'refkey' => $refkey,
            'channel' => DispatchChannel::Alimtalk->value,
            'to_number' => $to,
            'to_name' => $notifiable->name ?? null,
            'to_user_id' => $this->resolveUserId($notifiable),
            'content' => $message,
            'request_payload' => $this->payloadBuilder->forHistory($payload),
            'notification_type' => $type,
            'status' => DispatchStatus::Pending->value,
            'source' => DispatchSource::Auto->value,
            'is_test_mode' => $this->isTestMode(),
            'sent_at' => now(),
        ]);

        // A-2: 이 발송 사이클 직후 발화할 코어 알림 로그(after_log_sent)에 이 dispatch 를 잇도록
        // refkey 를 컨텍스트에 남긴다. LinkNotificationLogListener 가 그 로그 id 를 여기에 연결한다.
        $this->linkContext->remember($refkey);

        SendMessageJob::dispatch($payload, $refkey);
    }

    /**
     * SMS 대체발송 본문을 행의 sms_body 에서 렌더합니다 (#597 §3.5).
     *
     * 대체 SMS 본문 소스는 코어 알림 템플릿이 아니라 bizppurio_templates.sms_body 다.
     * `#{var}` 표기를 알림 data 로 치환하며, 본문이 비어 있으면 빈 문자열을 반환하고
     * withSmsFallback 이 병합하지 않는다(빈 SMS 방지).
     *
     * sms_body 는 로케일별 맵이므로 SmsChannelDriver 와 동일하게 수신자 로케일로 렌더한다 —
     * 같은 알림의 SMS 단독 발송과 대체발송이 서로 다른 언어로 나가면 안 된다(§14.3).
     *
     * @param  BizppurioTemplate  $template  템플릿 행
     * @param  GenericNotification  $notification  발송 대상 알림
     * @param  string|null  $locale  수신자 로케일 (null 이면 현재 앱 로케일)
     * @return string 치환 완료된 대체 SMS 본문 (없으면 빈 문자열)
     */
    private function smsFallbackBody(object $template, GenericNotification $notification, ?string $locale = null): string
    {
        $body = trim($template->getLocalizedSmsBody($locale));
        if ($body === '') {
            return '';
        }

        return $this->payloadMapper->substituteText($body, $notification->getData());
    }

    /**
     * 알림톡 payload 에 SMS 대체발송(resend/recontent)을 병합합니다 (개별 대체발송).
     *
     * 알림톡 실패 시(수신 거부·미가입 등) 비즈뿌리오가 SMS 로 대체 발송한다.
     * `resend:{first:"sms"}` + `recontent:{sms:{message}}` 구조를 따른다(현행 방식 유지 —
     * G7 레벨 재발송 없음). 대체 본문이 비어 있으면 빈 SMS 를 보내지 않도록 병합하지 않는다.
     *
     * @param  array<string, mixed>  $payload  알림톡 발송 payload
     * @param  string  $renderedBody  치환 완료된 대체 SMS 본문
     * @return array<string, mixed> resend/recontent 가 병합된 payload (빈 본문이면 원본 그대로)
     */
    private function withSmsFallback(array $payload, string $renderedBody): array
    {
        if (trim($renderedBody) === '') {
            return $payload;
        }

        $payload['resend'] = ['first' => 'sms'];
        $payload['recontent'] = ['sms' => ['message' => $renderedBody]];

        return $payload;
    }

    /**
     * 수신자가 회원이면 user id 를, 비회원(GuestNotifiable)이면 null 을 반환합니다.
     *
     * @param  object  $notifiable  수신자
     * @return int|null 회원 ID 또는 null
     */
    private function resolveUserId(object $notifiable): ?int
    {
        if ($notifiable instanceof User) {
            return (int) $notifiable->getKey();
        }

        return null;
    }

    /**
     * 수신자의 전화번호를 해석합니다 (SmsChannelDriver 와 동일 규칙).
     *
     * 회원(Notifiable)은 mobile 속성을, 비회원은 알림 data 의 _recipient_phone 을 사용한다.
     * 숫자 외 문자는 제거하고, 값이 없으면 null 을 반환한다.
     *
     * @param  object  $notifiable  수신자
     * @param  array<string, mixed>  $data  알림 data
     * @return string|null 정규화된 전화번호 또는 null
     */
    private function resolvePhone(object $notifiable, array $data): ?string
    {
        $raw = $notifiable->mobile
            ?? ($data[self::RECIPIENT_PHONE_KEY] ?? null);

        $normalized = preg_replace('/[^0-9]/', '', (string) $raw);

        return ($normalized === null || $normalized === '') ? null : $normalized;
    }

    /**
     * webhook 매칭용 refkey(UTF-8 최대 32byte, unique)를 생성합니다.
     *
     * @return string 32자 refkey
     */
    private function generateRefkey(): string
    {
        return Str::random(32);
    }

    /**
     * 검수 모드 여부를 반환합니다 (발송 이력 스냅샷용).
     *
     * 기본값(미설정)은 안전하게 검수(true)로 간주한다(BizppurioApiClient::baseUrl() 과 동일 정책).
     *
     * @return bool 검수 모드면 true
     */
    private function isTestMode(): bool
    {
        return (bool) $this->pluginSettings->get(self::PLUGIN_IDENTIFIER, 'is_test_mode', true);
    }
}
