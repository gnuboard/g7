<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use App\Services\PluginSettingsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchSource;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\NotificationSendSkippedException;
use Plugins\Sirsoft\MessageBizppurio\Jobs\SendMessageJob;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioNotificationBindingRepositoryInterface;

/**
 * 코어 알림 시스템의 alimtalk 채널 드라이버 (계획서 §6-2·Phase 6).
 *
 * ChannelManager::extend('alimtalk', …)(ServiceProvider::boot)로 등록되어, 코어가
 * `via()` 에서 'alimtalk' 채널을 선택하면 이 드라이버의 send() 가 호출된다. Phase 3 까지는
 * no-op 스텁이 등록돼 있었고(크래시 방지), 이 드라이버가 그 스텁을 실발송으로 교체한다.
 *
 * SmsChannelDriver 와 흐름은 같으나 핵심 차이는 "발송 본문·요소의 출처"다:
 *  - SMS: 코어 알림 템플릿(sms 채널) 본문을 그대로 발송한다.
 *  - 알림톡: 관리자가 알림톡 탭에서 연결한 카카오 승인 템플릿의 실제 내용(본문·버튼·요소)을
 *    카카오 상세조회로 가져와 발송한다(B안). 비즈뿌리오 발송 API 는 완성본을 요구하므로
 *    templatecode 만으로는 안 되고, 카카오에 등록된 templateContent·buttons·quickReplies·
 *    title·header·item·itemHighlight·representLink 를 발송 형식으로 변환해 채운다.
 *    연결(binding)이 없으면 발송하지 않는다(알림톡은 임의 본문 발송 불가 — 승인 템플릿 필수).
 *
 * 처리 흐름:
 *  1. 알림 유형(type)으로 활성 binding 조회. 없으면 NotificationSendSkippedException.
 *  2. 카카오 승인 템플릿 내용 조회(KakaoTemplateContentResolver, 캐시). 실패 시 동일 예외.
 *  3. 전화번호 해석(회원=mobile, 비회원=data 의 _recipient_phone — SmsChannelDriver 와 동일 계약).
 *  4. 카카오 내용 → 발송 형식 변환 + 변수(#{var}) 치환(AlimtalkPayloadMapper). 본문 비면 동일 예외.
 *  5. refkey 생성 → payload 조립(버튼·요소는 extra, 대체발송 ON 시 resend/recontent 병합) →
 *     이력 pending → SendMessageJob 위임.
 *
 * 1~4 단계는 비즈뿌리오 API 호출 자체를 시도하지 못하는 사전 조건 미비 상태라
 * NotificationSendSkippedException 을 던진다. 코어 NotificationDispatcher 의
 * catch(\Exception)가 이를 channel_send_failed 훅으로 연결해, 발송 이력에 "성공"이 아닌
 * "실패"로 정확히 기록되게 한다(조용히 return 하면 코어가 "정상 처리 완료"로 오인해 성공으로
 * 기록하는 문제 — 이슈 #28). 이 단계에선 알림톡 발송 자체가 일어나지 않으므로 SMS 대체발송도
 * 트리거되지 않는다 — SMS 대체는 알림톡 payload 에 resend 로 병합되어, 알림톡이 접수된 뒤
 * 비즈뿌리오 측 발송 실패(수신 거부·미가입 등)에만 작동한다(fallback_sms_enabled ON 시).
 * 발송된 알림톡의 개별 실패는 비즈뿌리오 결과코드로 webhook·Job 이 이력에 기록한다(Phase 4).
 */
class AlimtalkChannelDriver
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 알림 data 에서 비회원 전화번호를 싣는 표준 키 (SmsChannelDriver 와 동일 계약) */
    public const RECIPIENT_PHONE_KEY = '_recipient_phone';

    /**
     * @param  NotificationTemplateService  $templateService  SMS 대체발송 본문(코어 alimtalk 템플릿) resolve
     * @param  NotificationDefinitionService  $definitionService  알림 유형의 사람이 읽는 이름 조회(스킵 예외 메시지용)
     * @param  BizppurioNotificationBindingRepositoryInterface  $bindings  이벤트↔템플릿 연결 조회
     * @param  MessagePayloadBuilder  $payloadBuilder  발송 payload 조립
     * @param  BizppurioDispatchRepositoryInterface  $dispatches  발송 이력 영속화
     * @param  DispatchLinkContext  $linkContext  발송 사이클 refkey↔코어 로그 연결 컨텍스트(A-2)
     * @param  KakaoTemplateContentResolver  $kakaoContent  카카오 승인 템플릿 내용 조회·캐시(B안)
     * @param  AlimtalkPayloadMapper  $payloadMapper  카카오 내용 → 발송 형식 변환·치환(B안)
     * @param  PluginSettingsService  $pluginSettings  검수 모드 여부 조회(이력 스냅샷용)
     */
    public function __construct(
        private readonly NotificationTemplateService $templateService,
        private readonly NotificationDefinitionService $definitionService,
        private readonly BizppurioNotificationBindingRepositoryInterface $bindings,
        private readonly MessagePayloadBuilder $payloadBuilder,
        private readonly BizppurioDispatchRepositoryInterface $dispatches,
        private readonly DispatchLinkContext $linkContext,
        private readonly KakaoTemplateContentResolver $kakaoContent,
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

        // 1. 이벤트↔알림톡 템플릿 연결 조회 (미연결/비활성이면 알림톡 미발송)
        //    코어 NotificationDispatcher::sendToNotifiable()의 catch(\Exception)가 이 예외를
        //    channel_send_failed 훅으로 연결해, 발송 이력에 "성공"이 아닌 "실패"로 기록되게 한다.
        $binding = $this->bindings->findActive($type, DispatchChannel::Alimtalk->value);
        if ($binding === null) {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.alimtalk_binding_missing', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 2. 카카오 승인 템플릿 내용(본문·버튼·요소) 조회 — 발송 API 는 완성본을 요구하므로,
        //    templatecode 만으로는 안 되고 카카오에 등록된 실제 내용을 가져와 채운다(B안).
        //    조회 실패(고아·장애·rate limit)면 알림톡 본문 소스가 없으므로 skip 한다.
        $kakaoContent = $this->kakaoContent->resolve($binding->template_code);
        if ($kakaoContent === null) {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.alimtalk_kakao_content_unavailable', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 3. 전화번호 해석 (회원=mobile, 비회원=data 의 _recipient_phone)
        $to = $this->resolvePhone($notifiable, $notification->getData());
        if ($to === null) {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.recipient_phone_missing', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 4. 카카오 내용 → 발송 형식 변환 + 변수(#{var}) 치환. 본문이 비면 발송 불가라 skip.
        $mapped = $this->payloadMapper->map($kakaoContent, $notification->getData());
        $message = (string) ($mapped['message'] ?? '');
        if (trim($message) === '') {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.message_body_empty', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 5. refkey 생성 → payload 조립 (버튼·바로연결·요소는 extra 로 전달, 대체발송 ON 시 SMS resend 병합)
        $refkey = $this->generateRefkey();
        $payload = $this->payloadBuilder->buildAlimtalk(
            $to,
            $binding->template_code,
            $message,
            $refkey,
            (array) ($mapped['extra'] ?? []),
        );

        if ($binding->fallback_sms_enabled) {
            $payload = $this->withSmsFallback($payload, $this->smsFallbackBody($type, $notifiable, $notification));
        }

        // 6. 발송 이력 pending 생성 → Job 위임. Job/webhook 이 refkey 로 조회해 상태 갱신.
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
     * SMS 대체발송 본문을 코어 alimtalk 템플릿에서 렌더합니다 (B안).
     *
     * 알림톡 본문은 카카오 승인 템플릿에서 오지만, 실패 시 대체할 SMS 본문은 카카오와 무관하므로
     * 코어 알림 템플릿(alimtalk 채널) 본문을 그대로 재사용한다. 코어 템플릿이 없거나 비활성이면
     * 빈 문자열을 반환하고, withSmsFallback 이 빈 본문이면 대체를 병합하지 않는다(엣지 C2).
     *
     * @param  string  $type  알림 유형
     * @param  object  $notifiable  수신자
     * @param  GenericNotification  $notification  발송 대상 알림
     * @return string 치환 완료된 대체 SMS 본문 (없으면 빈 문자열)
     */
    private function smsFallbackBody(string $type, object $notifiable, GenericNotification $notification): string
    {
        $template = $this->templateService->resolve($type, DispatchChannel::Alimtalk->value);
        if ($template === null || ! $template->is_active) {
            return '';
        }

        $locale = BaseNotification::resolveNotifiableLocale($notifiable);
        $rendered = $template->replaceVariables($notification->getData(), $locale);

        return (string) ($rendered['body'] ?? '');
    }

    /**
     * 알림톡 payload 에 SMS 대체발송(resend/recontent)을 병합합니다 (개별 대체발송, 계획서 §6-2).
     *
     * 알림톡 실패 시(수신 거부·미가입 등) 비즈뿌리오가 SMS 로 대체 발송한다. 대체 SMS 본문은
     * 코어 알림 본문(치환 완료 텍스트)을 재사용한다. 부록 C-2 의 `resend:{first:"sms"}` +
     * `recontent:{sms:{message}}` 구조를 따른다. 대체 본문이 비어 있으면(코어 템플릿 부재)
     * 빈 SMS 를 보내지 않도록 병합하지 않는다(엣지 C2).
     *
     * @param  array<string, mixed>  $payload  알림톡 발송 payload
     * @param  string  $renderedBody  치환 완료된 코어 본문(대체 SMS 내용)
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
