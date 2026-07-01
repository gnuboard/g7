<?php

namespace App\Notifications;

use App\Contracts\Notifications\GuestRecipientInterface;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Notifiable;

/**
 * 비회원(게스트) 알림 수신자
 *
 * user_id 없이 이메일/이름/로케일만 가진 익명 수신자를 1급 Notifiable 로 표현합니다.
 * 회원(User)과 동일하게 Laravel Notifiable 트레잇 + HasLocalePreference 계약을
 * 구현하므로, GenericNotification 의 via()/toMail()/toArray() 및
 * BaseNotification::resolveNotifiableLocale() 이 분기 없이 동일하게 동작합니다.
 *
 * 발송 경로는 회원과 100% 공유합니다 — 훅 → NotificationHookListener →
 * NotificationRecipientResolver → $notifiable->notify(). resolver 가 비회원
 * 컨텍스트(표준 키 guest_recipient)일 때 이 객체를 수신자로 반환합니다.
 *
 * 특정 도메인(이커머스 등)에 종속되지 않는 순수 코어 값 객체입니다. 어떤 확장이든
 * 컨텍스트에 표준 키 `guest_recipient: {email, name, locale}` 를 채우면 재사용됩니다.
 */
final class GuestNotifiable implements GuestRecipientInterface, HasLocalePreference
{
    use Notifiable;

    /**
     * @param  string  $email  수신자 이메일 (필수)
     * @param  string|null  $name  수신자 표시명
     * @param  string|null  $locale  수신자 선호 로케일 (미지정 시 app locale 폴백)
     */
    public function __construct(
        public readonly string $email,
        public readonly ?string $name = null,
        private readonly ?string $locale = null,
    ) {}

    /**
     * 컨텍스트의 표준 guest_recipient 배열에서 수신자를 생성합니다.
     *
     * 이메일이 비어 있거나 형식이 잘못된 경우 null 을 반환하여 발송 대상에서 제외합니다.
     *
     * @param  array  $guest  표준 키 배열 (['email' => ..., 'name' => ..., 'locale' => ...])
     * @return self|null 유효한 수신자 또는 null (무효 시)
     */
    public static function fromContext(array $guest): ?self
    {
        $email = trim((string) ($guest['email'] ?? ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $name = isset($guest['name']) ? trim((string) $guest['name']) : null;
        $locale = isset($guest['locale']) ? trim((string) $guest['locale']) : null;

        return new self(
            email: $email,
            name: ($name === '' ? null : $name),
            locale: ($locale === '' ? null : $locale),
        );
    }

    /**
     * 메일 채널 수신자 라우팅 (Laravel MailChannel 계약).
     *
     * @return array<string, string>|string 표시명이 있으면 [email => name], 없으면 email
     */
    public function routeNotificationForMail(): array|string
    {
        return $this->name !== null && $this->name !== ''
            ? [$this->email => $this->name]
            : $this->email;
    }

    /**
     * 수신자 선호 로케일 (HasLocalePreference 계약).
     *
     * 명시된 locale 이 지원 로케일이면 그 값을, 아니면 null 을 반환하여
     * BaseNotification::resolveNotifiableLocale() 의 app locale 폴백을 태웁니다.
     * (회원은 사이트 기본 로케일을 직접 반환하지만, 게스트는 명시값만 신뢰합니다.)
     *
     * @return string|null 지원 로케일이면 해당 값, 아니면 null
     */
    public function preferredLocale(): ?string
    {
        $supported = config('app.supported_locales', ['ko', 'en']);

        return ($this->locale && in_array($this->locale, $supported, true))
            ? $this->locale
            : null;
    }

    /**
     * dedup/식별용 합성 키.
     *
     * 회원(정수 id)과 절대 충돌하지 않도록 `guest:` prefix + 이메일 해시를 사용합니다.
     * NotificationRecipientResolver 의 중복 제거가 이 키로 게스트를 구분합니다.
     *
     * @return string "guest:" + sha1(소문자 이메일)
     */
    public function getKey(): string
    {
        return 'guest:'.sha1(strtolower($this->email));
    }

    /**
     * 게스트(비회원) 수신자 여부 (GuestRecipientInterface 계약).
     *
     * @return bool 항상 true
     */
    public function isGuest(): bool
    {
        return true;
    }
}
