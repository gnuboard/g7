<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\IpAddress;
use App\Analytics\TrackingRequest;
use App\Analytics\VisitorRecognizer;
use App\Enums\Analytics\PrivacyMode;
use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSelection;
use App\Extension\Consent\ConsentSubject;
use Illuminate\Support\Facades\Log;

/**
 * 개인정보 처리
 *
 * 저장 직전에 「무엇을 남기고 무엇을 지울지」를 정합니다. 모드와 토글이 여기 한곳에서
 * 적용되므로, 운영자가 설정을 바꾸면 그 순간부터 새로 쌓이는 모든 방문이 같은 규칙을
 * 따릅니다(이미 쌓인 것은 소급 익명화가 따로 처리합니다).
 *
 * 동의 모드인데 동의를 확인할 곳이 없으면 익명으로 내려갑니다 — 아무것도 모으지
 * 않으면 운영자가 이유도 모른 채 데이터를 잃고, 익명 수집은 개인정보를 남기지 않아
 * 법적으로 가장 안전한 쪽입니다.
 *
 * **코어는 동의를 관리하는 확장이 무엇인지 모릅니다.** 동의 판단은 범용 동의 제공자 계약
 * ({@see ConsentManager})의 소비자로서 묻습니다. 운영자가 고른 제공자 하나가 답하고, 제공자가 없는
 * 사이트(`none`)는 코어 기본 동작을 따릅니다.
 *
 *   - 분석 동의 = 스냅샷 `ready` && 재동의 대상 아님 && `analytics`
 *   - 식별자(IP·방문자 쿠키) 추적 허용 = 분석 동의 && `functional` — 동의 관리 확장은 기능 동의 전에
 *     방문자 쿠키를 지우므로, 그 전에는 쿠키 방식으로 셀 수 없다.
 *   - 제공자가 모호하거나 사라졌거나 답하지 못하면(`unknown`) 동의하지 않은 것으로 본다.
 */
class PrivacyService
{
    /** 동의 출처 — 동의 관리 제공자가 있다(선택 상태 auto·selected·ambiguous·missing) */
    public const SOURCE_PROVIDER = 'provider';

    /** 동의 출처 — 동의 관리 제공자가 없다(선택 상태 none) */
    public const SOURCE_NONE = 'none';

    /**
     * 개인정보 처리기를 생성합니다.
     *
     * @param  VisitorRecognizer  $recognizer  방문자 식별기
     * @param  ConsentManager  $consent  동의 제공자 매니저
     */
    public function __construct(
        private readonly VisitorRecognizer $recognizer,
        private readonly ConsentManager $consent,
    ) {}

    /**
     * 동의 출처를 판정합니다.
     *
     * 선택 상태가 `none`(제공자 없음, 또는 고른 제공자가 수집을 꺼 둠)이면 `none`, 그 밖에는 `provider` 다.
     * 모호·누락(ambiguous·missing)도 `provider` 로 본다 — 운영자 확인이 필요한 상태라 동의 없이 식별자를
     * 남기면 안 되고, 그 방문자의 스냅샷은 `unknown` 이라 동의하지 않은 것으로 판정된다.
     *
     * @return string 동의 출처 (`provider`·`none`)
     */
    public function consentSource(): string
    {
        return $this->consentSelection()->governsConsent() ? self::SOURCE_PROVIDER : self::SOURCE_NONE;
    }

    /**
     * 동의 제공자 선택 상태를 반환합니다 (진단 화면의 동의 출처 배지가 읽는다).
     *
     * @return ConsentSelection 선택 상태
     */
    public function consentSelection(): ConsentSelection
    {
        return $this->consent->selection();
    }

    /**
     * 이 요청을 익명 모드로 다뤄야 하는지 판정합니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return bool 익명 모드 여부
     */
    public function isAnonymous(AnalyticsConfig $config): bool
    {
        return $config->resolvesToAnonymous($this->consentSource());
    }

    /**
     * 이 방문자의 요청을 익명 모드로 다뤄야 하는지 판정합니다.
     *
     * 사이트 전체 판정(`isAnonymous`)과 하나만 다르다 — IP 저장 모드인데 식별자 추적 허용을
     * 방문자마다 정하는 확장이 답하는 사이트에서는 **방문자마다** 가른다. 허용된 방문자는 IP 저장
     * 모드 그대로(IP·방문자 쿠키) 모으고, 그렇지 않은 방문자만 익명으로 모은다. 그런 확장은 허용 전에
     * 방문자 쿠키를 응답에서 지우므로, 쿠키가 남지 못하는 방문자를 쿠키 방식으로 세면 비콘마다 새
     * 방문이 된다(#682 S4 라운드 8, 결정 「동의자는 IP 저장 모드」).
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @return bool 익명 모드 여부
     */
    public function isAnonymousFor(TrackingRequest $request, AnalyticsConfig $config): bool
    {
        $source = $this->consentSource();

        if (! $config->resolvesToAnonymous($source)) {
            return false;
        }

        if ($config->anonymousFallbackReason($source) === AnalyticsConfig::ANONYMOUS_REASON_CONSENT_PLUGIN_BLOCKS_COOKIES) {
            return ! $this->visitorPermitsTracking($request);
        }

        return true;
    }

    /**
     * 사이트의 모든 방문자가 익명 모드로 모이는지 판정합니다.
     *
     * 진단 화면의 「실제 모드」가 읽는다. 추적 허용을 정하는 확장 때문에 **허용 전 방문자만**
     * 익명으로 모이는 경우는 사이트 모드가 여전히 IP 저장이다 — 사유는 따로 실린다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return bool 모든 방문자가 익명이면 true
     */
    public function isAnonymousForEveryone(AnalyticsConfig $config): bool
    {
        $source = $this->consentSource();

        return $config->resolvesToAnonymous($source)
            && $config->anonymousFallbackReason($source) !== AnalyticsConfig::ANONYMOUS_REASON_CONSENT_PLUGIN_BLOCKS_COOKIES;
    }

    /**
     * 이 방문자를 식별자로 추적해도 되는지 동의 제공자에게 묻습니다 (분석 동의 + 기능 동의).
     *
     * 확인할 수 없으면 허용하지 않은 것으로 본다 — 모르는 상태에서 IP 를 남기지 않는다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return bool 허용이면 true
     */
    private function visitorPermitsTracking(TrackingRequest $request): bool
    {
        try {
            return $this->consent->snapshotFor($this->subject($request->userId, $request->sessionId))->permitsTracking();
        } catch (\Throwable $e) {
            Log::warning('방문자 통계 추적 허용 확인 실패', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 수집 요청의 식별 값으로 동의 주체를 만듭니다.
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $sessionId  게스트 동의 식별자
     * @return ConsentSubject 동의 주체
     */
    private function subject(?int $userId, ?string $sessionId): ConsentSubject
    {
        return $userId !== null ? ConsentSubject::member($userId) : ConsentSubject::guest($sessionId);
    }

    /**
     * 운영자가 고른 모드 대신 익명으로 내려간 사유를 돌려줍니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return string|null 사유 키 (내려가지 않으면 null)
     */
    public function anonymousFallbackReason(AnalyticsConfig $config): ?string
    {
        return $config->anonymousFallbackReason($this->consentSource());
    }

    /**
     * 동의 모드에서 이 방문자가 동의했는지 확인합니다.
     *
     * 동의 출처가 없으면 판정 자체를 하지 않습니다(null) — 익명으로 내려가므로
     * 동의 여부가 의미를 갖지 않습니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @return bool|null 동의 여부 (판정 대상이 아니면 null)
     */
    public function hasAnalyticsConsent(TrackingRequest $request, AnalyticsConfig $config): ?bool
    {
        if ($config->privacyMode !== PrivacyMode::Consent || $this->consentSource() === self::SOURCE_NONE) {
            return null;
        }

        return $this->visitorHasConsent($request->userId, $request->sessionId);
    }

    /**
     * 방문자가 분석 수집에 동의했는지 동의 제공자에게 묻습니다.
     *
     * 코어는 동의 관리 확장의 클래스를 부르지 않는다 — 운영자가 고른 제공자에게 범용 계약으로 묻는다.
     * 판단할 수 없으면(`unknown`) 동의하지 않은 것으로 본다. 브라우저의 동의 런타임(`/api/consent`)도
     * 같은 매니저와 같은 파생 규칙을 쓴다.
     *
     * @param  int|null  $userId  회원 ID (게스트면 null)
     * @param  string|null  $sessionId  게스트 동의 식별자
     * @return bool 동의 여부
     */
    public function visitorHasConsent(?int $userId, ?string $sessionId): bool
    {
        try {
            return $this->consent->snapshotFor($this->subject($userId, $sessionId))->permitsAnalytics();
        } catch (\Throwable $e) {
            // 동의를 확인할 수 없으면 수집하지 않는다 — 모르는 상태에서 모으는 것보다 안전하다.
            Log::warning('방문자 통계 동의 확인 실패', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 저장할 IP 관련 값을 만듭니다.
     *
     * @param  string|null  $rawIp  원본 IP
     * @param  AnalyticsConfig  $config  설정
     * @param  bool  $anonymous  익명 모드 여부
     * @return array{ip: string|null, ip_hash: string|null, masked: string|null}
     *                                                                           저장할 IP·IP 해시·마스킹된 IP(지역 조회용)
     */
    public function resolveIp(?string $rawIp, AnalyticsConfig $config, bool $anonymous): array
    {
        if ($rawIp === null || $rawIp === '') {
            return ['ip' => null, 'ip_hash' => null, 'masked' => null];
        }

        $masked = $config->ipMaskOctets > 0
            ? IpAddress::mask($rawIp, $config->ipMaskOctets)
            : $rawIp;

        // 익명 모드는 IP 를 남기지 않는다. 대신 그날 솔트로 만든 해시만 둬서
        // 정보주체가 자기 기록을 찾아달라고 할 때 그날 안에서는 맞춰 볼 수 있게 한다.
        if ($anonymous) {
            return [
                'ip' => null,
                'ip_hash' => $this->recognizer->ipHash($rawIp),
                'masked' => $masked,
            ];
        }

        return ['ip' => $masked, 'ip_hash' => null, 'masked' => $masked];
    }

    /**
     * 저장할 회원 식별 값을 만듭니다.
     *
     * @param  int|null  $userId  회원 ID
     * @param  AnalyticsConfig  $config  설정
     * @return array{user_id: int|null, user_hash: string|null} 저장할 회원 ID·해시
     */
    public function resolveUser(?int $userId, AnalyticsConfig $config): array
    {
        if ($userId === null) {
            return ['user_id' => null, 'user_hash' => null];
        }

        if ($config->pseudonymizeUserId) {
            return ['user_id' => null, 'user_hash' => VisitorRecognizer::userHash($userId)];
        }

        return ['user_id' => $userId, 'user_hash' => null];
    }

    /**
     * 설정이 더 강한 쪽으로 바뀌었는지 판정합니다.
     *
     * 약한 쪽으로 바꿀 때는 소급할 것이 없습니다 — 과거 데이터는 이미 그보다 적은
     * 정보만 담고 있습니다. 강한 쪽으로 바뀔 때만 「기존 데이터도 맞출까요」를 묻습니다.
     * 모드가 올라가거나, 주소를 더 가리거나, 회원 번호 가명화를 새로 켜면 강해진 것입니다.
     * 설정 저장 응답(`retro_anonymize_available`)이 이 판정 하나만 씁니다.
     *
     * 강해졌어도 소급 요청이 성립하지 않으면 제안하지 않습니다 — 소급 창은 새 설정의 가릴 폭과 해시 여부
     * (익명 모드)를 그대로 보내고, 요청 검증은 둘 다 비면 거부합니다(`hasRetroTarget`). 「전체 → 동의 연동」
     * 처럼 가릴 폭 0 으로 모드만 올리면 창을 띄워도 적용할 수 없었다(#703 실측).
     *
     * @param  AnalyticsConfig  $before  변경 전 설정
     * @param  AnalyticsConfig  $after  변경 후 설정
     * @return bool 소급 제안 여부
     */
    public static function tightensPrivacy(AnalyticsConfig $before, AnalyticsConfig $after): bool
    {
        $rank = static fn (PrivacyMode $mode): int => match ($mode) {
            PrivacyMode::Full => 0,
            PrivacyMode::Consent => 1,
            PrivacyMode::Anonymous => 2,
        };

        $tightened = $rank($after->privacyMode) > $rank($before->privacyMode)
            || $after->ipMaskOctets > $before->ipMaskOctets
            || ($after->pseudonymizeUserId && ! $before->pseudonymizeUserId);

        return $tightened
            && self::hasRetroTarget($after->ipMaskOctets, $after->privacyMode === PrivacyMode::Anonymous);
    }

    /**
     * 소급 익명화 요청에 가릴 대상이 있는지 판정합니다.
     *
     * 소급 제안(`tightensPrivacy`)과 소급 요청 검증(`AnonymizeRequest`)이 이 판정 하나를 함께 씁니다 —
     * 둘이 다른 기준을 쓰면 띄운 창의 요청이 거부된다.
     *
     * @param  int  $maskOctets  가릴 IP 옥텟 수
     * @param  bool  $hash  주소를 지우고 해시만 남길지 여부
     * @return bool 가릴 대상이 하나라도 있으면 true
     */
    public static function hasRetroTarget(int $maskOctets, bool $hash): bool
    {
        return $maskOctets > 0 || $hash;
    }
}
