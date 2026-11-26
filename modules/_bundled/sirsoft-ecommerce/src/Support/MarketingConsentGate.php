<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Extension\HookManager;

/**
 * 광고성 알림의 채널별 마케팅 수신동의 판정
 *
 * 마케팅 플러그인을 직접 참조하지 않고 그 플러그인의 조회 필터(`sirsoft-marketing.consent.is_consented`)에 묻는다.
 * 플러그인이 없거나 꺼져 있으면 구독자가 없어 null 이 돌아오고, 그것을 미동의로 본다(보내지 않는 쪽이 안전하다).
 *
 * | 채널 | 필요한 동의 |
 * | --- | --- |
 * | mail | 마케팅 동의 + 광고성 이메일 수신 |
 * | sms·alimtalk 등 문자 | 마케팅 동의 |
 * | database(사이트 알림) | 필요 없음 — 언제나 허용 |
 */
final class MarketingConsentGate
{
    /** 조회 필터 이름 */
    public const FILTER = 'sirsoft-marketing.consent.is_consented';

    /**
     * 그 채널로 광고성 알림을 보내도 되는지 판정합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $channel  채널 (mail·database·sms·alimtalk 등)
     * @return bool 보내도 되면 true
     */
    public static function allows(int $userId, string $channel): bool
    {
        if ($channel === 'database') {
            return true;
        }

        if (! self::consented($userId, 'marketing_consent')) {
            return false;
        }

        if ($channel === 'mail') {
            return self::consented($userId, 'email_subscription');
        }

        return true;
    }

    /**
     * 채널 목록 중 보내도 되는 채널만 남깁니다.
     *
     * @param  int  $userId  회원 ID
     * @param  array<int, string>  $channels  채널 목록
     * @return array<int, string> 허용 채널 (database 는 늘 포함)
     */
    public static function allowedChannels(int $userId, array $channels): array
    {
        $allowed = array_values(array_filter($channels, fn ($channel) => is_string($channel) && self::allows($userId, $channel)));

        if (! in_array('database', $allowed, true)) {
            $allowed[] = 'database';
        }

        return $allowed;
    }

    /**
     * 동의 키 하나에 동의했는지 묻습니다. 답이 정확히 true 일 때만 동의입니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $consentKey  동의 키
     * @return bool 동의했으면 true
     */
    private static function consented(int $userId, string $consentKey): bool
    {
        return HookManager::applyFilters(self::FILTER, null, $userId, $consentKey) === true;
    }
}
