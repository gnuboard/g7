<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\IpAddress;
use App\Analytics\TrackingRequest;
use App\Analytics\VisitorRecognizer;
use App\Enums\Analytics\PrivacyMode;
use App\Extension\HookManager;
use App\Extension\PluginManager;
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
 */
class PrivacyService
{
    /** GDPR 플러그인 식별자 */
    private const GDPR_PLUGIN = 'sirsoft-gdpr';

    /** 동의 여부를 묻는 필터 이름 */
    private const CONSENT_FILTER = 'core.analytics.visitor_has_consent';

    /**
     * 개인정보 처리기를 생성합니다.
     *
     * @param  VisitorRecognizer  $recognizer  방문자 식별기
     * @param  PluginManager  $plugins  플러그인 관리자 (동의 출처 판정용)
     */
    public function __construct(
        private readonly VisitorRecognizer $recognizer,
        private readonly PluginManager $plugins,
    ) {}

    /**
     * 동의 출처를 판정합니다.
     *
     * @return string 동의 출처 (`filter`·`gdpr`·`none`)
     */
    public function consentSource(): string
    {
        // 동의 필터에 답하는 확장이 있으면 그것이 출처다. 코어는 어느 확장이 답하는지
        // 알 필요가 없다 — 답이 오는지만 본다.
        if (array_key_exists(self::CONSENT_FILTER, HookManager::getFilters())) {
            return 'filter';
        }

        try {
            return array_key_exists(self::GDPR_PLUGIN, $this->plugins->getActivePlugins()) ? 'gdpr' : 'none';
        } catch (\Throwable $e) {
            // 플러그인 목록을 못 읽으면 동의 출처가 없는 것으로 본다 — 익명으로 내려간다.
            return 'none';
        }
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
        if ($config->privacyMode !== PrivacyMode::Consent || $this->consentSource() === 'none') {
            return null;
        }

        try {
            // 코어는 동의 관리 플러그인의 클래스를 직접 부르지 않는다 — 코어가 플러그인에
            // 의존하면 그 플러그인 없이는 코어가 서지 않는다. 대신 필터를 열어 두고
            // 답할 수 있는 확장이 답하게 한다. 아무도 답하지 않으면 기본값(false)이 남는다.
            $granted = HookManager::applyFilters(
                self::CONSENT_FILTER,
                null,
                $request->userId,
                $request->sessionId,
            );

            if ($granted !== null) {
                return (bool) $granted;
            }

            // 아직 이 필터에 답하는 확장이 없는 설치를 위한 폴백. 동의 관리 플러그인이
            // 설치돼 있으면 그 서비스에 직접 묻는다 — 클래스가 없으면 묻지 않으므로
            // 코어가 플러그인 없이도 그대로 선다.
            return $this->askInstalledConsentPlugin($request);
        } catch (\Throwable $e) {
            // 동의를 확인할 수 없으면 수집하지 않는다 — 모르는 상태에서 모으는 것보다 안전하다.
            Log::warning('방문자 통계 동의 확인 실패', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 설치돼 있는 동의 관리 플러그인에 동의 여부를 묻습니다.
     *
     * 클래스 이름을 문자열로만 들고 있고 존재할 때만 해석합니다 — `use` 로 가져오면
     * 코어가 그 플러그인에 컴파일 단위로 묶여 플러그인 없는 설치에서 깨집니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return bool 동의 여부 (물을 곳이 없으면 false)
     */
    private function askInstalledConsentPlugin(TrackingRequest $request): bool
    {
        $serviceClass = 'Plugins\\Sirsoft\\Gdpr\\Services\\GdprConsentService';

        if (! class_exists($serviceClass)) {
            return false;
        }

        $consents = app($serviceClass)->getCurrentCookieConsents($request->userId, $request->sessionId);

        return is_array($consents) && (bool) ($consents['analytics'] ?? false);
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
     *
     * @param  array<string, mixed>  $before  변경 전 설정 값
     * @param  array<string, mixed>  $after  변경 후 설정 값
     * @return bool 소급 제안 여부
     */
    public static function tightensPrivacy(array $before, array $after): bool
    {
        $rank = [
            PrivacyMode::Full->value => 0,
            PrivacyMode::Consent->value => 1,
            PrivacyMode::Anonymous->value => 2,
        ];

        $beforeMode = $rank[$before['privacy_mode'] ?? PrivacyMode::Full->value] ?? 0;
        $afterMode = $rank[$after['privacy_mode'] ?? PrivacyMode::Full->value] ?? 0;

        if ($afterMode > $beforeMode) {
            return true;
        }

        return (int) ($after['ip_mask_octets'] ?? 0) > (int) ($before['ip_mask_octets'] ?? 0);
    }
}
