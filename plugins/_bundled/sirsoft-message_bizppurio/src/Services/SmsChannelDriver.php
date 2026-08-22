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
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;

/**
 * 코어 알림 시스템의 sms 채널 드라이버 (#597 §3.5).
 *
 * ChannelManager::extend('sms', …)(ServiceProvider::boot)로 등록되어, 코어가
 * `via()` 에서 'sms' 채널을 선택하면 이 드라이버의 send() 가 호출된다. Laravel 채널 계약
 * (`send($notifiable, Notification $notification)`)을 구현한다.
 *
 * 본문 소스는 코어 알림 템플릿이 아니라 bizppurio_templates.sms_body 다 — 비즈뿌리오
 * 탭에서 운영자가 알림별로 입력한 본문(#{var} 치환)이 그대로 발송된다. sms_body 는
 * 로케일별 맵이며 수신자 로케일로 렌더한다(알림톡 content 의 단일 언어 제약은 카카오
 * 승인 규칙 때문이고 SMS 에는 적용되지 않는다 — §14.3).
 *
 * 발송 게이트: 행 존재 + is_active + 어느 로케일에든 본문 있음. sms_only 여부와 무관하다 —
 * sms 채널이 켜진 알림이면 발송하며, sms_only 는 화면 표시·알림톡 게이트용 플래그다.
 *
 * 처리 흐름:
 *  1. 템플릿 행 조회 → 게이트 판정. 불충족 시 NotificationSendSkippedException
 *     (코어 NotificationDispatcher 의 catch 가 실패로 기록 — 이슈 #28 계약 유지).
 *  2. 전화번호 해석: 회원=Notifiable->mobile, 비회원=알림 data 의 _recipient_phone
 *     (게스트 전화번호는 각 도메인 extract_data 리스너가 data 에 주입 — D1).
 *  3. sms_body 의 #{var} 치환 → refkey 생성 → SmsTypeResolver 로 SMS/LMS 판별 →
 *     MessagePayloadBuilder 로 payload 조립 → SendMessageJob 위임(발송·재시도는 Job 책임).
 */
class SmsChannelDriver
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 알림 data 에서 비회원 전화번호를 싣는 표준 키 (extract_data 리스너와 계약) */
    public const RECIPIENT_PHONE_KEY = '_recipient_phone';

    /**
     * @param  NotificationDefinitionService  $definitionService  알림 유형의 사람이 읽는 이름 조회(스킵 예외 메시지·LMS 제목용)
     * @param  BizppurioTemplateRepositoryInterface  $templates  알림 템플릿 행 조회(발송 게이트·본문 소스)
     * @param  AlimtalkPayloadMapper  $payloadMapper  sms_body 의 #{var} 치환
     * @param  SmsTypeResolver  $typeResolver  SMS/LMS byte 판별
     * @param  MessagePayloadBuilder  $payloadBuilder  발송 payload 조립
     * @param  BizppurioDispatchRepositoryInterface  $dispatches  발송 이력 영속화
     * @param  DispatchLinkContext  $linkContext  발송 사이클 refkey↔코어 로그 연결 컨텍스트(A-2)
     * @param  PluginSettingsService  $pluginSettings  검수 모드 여부 조회(이력 스냅샷용)
     */
    public function __construct(
        private readonly NotificationDefinitionService $definitionService,
        private readonly BizppurioTemplateRepositoryInterface $templates,
        private readonly AlimtalkPayloadMapper $payloadMapper,
        private readonly SmsTypeResolver $typeResolver,
        private readonly MessagePayloadBuilder $payloadBuilder,
        private readonly BizppurioDispatchRepositoryInterface $dispatches,
        private readonly DispatchLinkContext $linkContext,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 알림 유형의 사람이 읽는 이름을 반환합니다 (스킵 예외 메시지·LMS 제목용).
     *
     * 정의 조회 실패·이름 미설정 시 코드값(type)을 그대로 반환한다(안전 폴백).
     *
     * LMS 제목으로 쓸 때는 수신자 로케일을 넘긴다 — 인자를 생략하면 앱 로케일이 적용되어
     * 본문과 제목의 언어가 갈린다. 운영자에게 보이는 스킵 예외 메시지는 앱 로케일이 맞다.
     *
     * @param  string  $type  알림 유형 코드값 (welcome 등)
     * @param  string|null  $locale  렌더 로케일 (null 이면 현재 앱 로케일)
     * @return string 사람이 읽는 이름 또는 코드값
     */
    private function resolveTypeLabel(string $type, ?string $locale = null): string
    {
        try {
            $label = $this->definitionService->resolve($type)?->getLocalizedName($locale);

            return $label !== null && $label !== '' ? $label : $type;
        } catch (\Throwable $e) {
            return $type;
        }
    }

    /**
     * 알림을 문자(SMS/LMS)로 발송합니다.
     *
     * Laravel NotificationSender 가 'sms' 채널 드라이버로 이 메서드를 호출한다.
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

        // 1. 템플릿 행 조회 + 게이트 판정 (행 없음·비활성·본문 없음 → skip).
        //    코어 NotificationDispatcher::sendToNotifiable()의 catch(\Exception)가 이 예외를
        //    channel_send_failed 훅으로 연결해, 발송 이력에 "성공"이 아닌 "실패"로 기록되게 한다.
        $template = $this->templates->findByType($type);
        if ($template === null || ! $template->is_active || ! $template->hasSmsBody()) {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.sms_template_missing', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 2. 전화번호 해석 (회원=mobile, 비회원=data 의 _recipient_phone)
        $to = $this->resolvePhone($notifiable, $notification->getData());
        if ($to === null) {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.recipient_phone_missing', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 3. 본문 렌더 — 수신자 로케일의 sms_body 를 골라 #{var} 치환(알림톡 본문과 동일 규칙).
        //    로케일 해석은 코어와 같은 규약(BaseNotification::resolveNotifiableLocale)을 쓴다.
        $locale = BaseNotification::resolveNotifiableLocale($notifiable);
        $message = $this->payloadMapper->substituteText(
            trim($template->getLocalizedSmsBody($locale)),
            $notification->getData()
        );
        if (trim($message) === '') {
            throw new NotificationSendSkippedException(
                __('sirsoft-message_bizppurio::messages.send_skipped.message_body_empty', ['type' => $this->resolveTypeLabel($type)])
            );
        }

        // 4. refkey 생성 → SMS/LMS 판별 → payload 조립.
        //    LMS 제목은 알림 정의 이름이며, 본문과 같은 수신자 로케일로 렌더한다 — 본문만
        //    로케일을 따르고 제목이 앱 로케일이면 한 통 안에서 언어가 갈린다.
        $refkey = $this->generateRefkey();
        $channel = $this->typeResolver->resolve($message);

        $payload = $channel === DispatchChannel::Lms
            ? $this->payloadBuilder->buildLms($to, $message, $refkey, $this->resolveTypeLabel($type, $locale))
            : $this->payloadBuilder->buildSms($to, $message, $refkey);

        // 5. 발송 이력 pending 생성(Phase 4) → Job 위임. Job 이 refkey 로 조회해 sent/failed 갱신.
        $this->dispatches->create([
            'refkey' => $refkey,
            'channel' => $channel->value,
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
     * 수신자가 회원이면 user id 를, 비회원(GuestNotifiable)이면 null 을 반환합니다.
     *
     * @param  object  $notifiable  수신자
     * @return int|null 회원 ID 또는 null
     */
    private function resolveUserId(object $notifiable): ?int
    {
        // User 모델만 회원 PK 로 취급. GuestNotifiable 은 DB 회원이 아니므로 null.
        if ($notifiable instanceof User) {
            return (int) $notifiable->getKey();
        }

        return null;
    }

    /**
     * 수신자의 전화번호를 해석합니다.
     *
     * 회원(Notifiable)은 mobile 속성을, 비회원은 알림 data 의 _recipient_phone 을 사용한다.
     * 숫자·하이픈 외 문자는 제거하고, 값이 없으면 null 을 반환한다.
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
     * 32자 hex(=32byte)로 고정해 발송 payload refkey 제약을 만족한다.
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
