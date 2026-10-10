<?php

namespace Plugins\Sirsoft\Marketing\Services;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;
use Plugins\Sirsoft\Marketing\Repositories\Contracts\MarketingConsentRepositoryInterface;

/**
 * 마케팅 동의 서비스 클래스
 *
 * 사용자별 마케팅 동의 상태의 비즈니스 로직을 담당합니다.
 * EAV 구조로 동의 항목별 개별 레코드를 관리합니다.
 */
class MarketingConsentService
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-marketing';

    /**
     * MarketingConsentService 생성자
     *
     * @param  MarketingConsentRepositoryInterface  $repository  마케팅 동의 Repository
     * @param  PluginSettingsService  $pluginSettings  플러그인 설정 서비스
     */
    public function __construct(
        private MarketingConsentRepositoryInterface $repository,
        private PluginSettingsService $pluginSettings
    ) {}

    /**
     * 활성화된 채널 목록을 반환합니다.
     *
     * plugin_settings의 channels JSON에서 enabled=true인 채널만 반환합니다.
     *
     * @return array<int, array{key: string, label: array{ko: string, en: string}, page_slug: string, enabled: bool, is_system: bool}>
     */
    public function getRegisteredChannels(): array
    {
        $channels = $this->getAllChannels();

        return array_values(array_filter($channels, fn ($ch) => (bool) ($ch['enabled'] ?? true)));
    }

    /**
     * 비활성 채널 포함 전체 채널 목록을 반환합니다.
     *
     * filterResourceData 등에서 기존 동의 데이터를 포함하여 반환할 때 사용합니다.
     *
     * @return array<int, array{key: string, label: array{ko: string, en: string}, page_slug: string, enabled: bool, is_system: bool}>
     */
    public function getAllChannels(): array
    {
        $raw = $this->pluginSettings->get(self::PLUGIN_ID, 'channels', '[]');
        $channels = json_decode($raw, true);

        return $this->attachSystemLabelKeys(is_array($channels) ? $channels : []);
    }

    /**
     * system 채널의 `label_key` 를 저장값에 다시 붙입니다.
     *
     * `label_key` 는 코드가 소유하는 값이다 — 저장된 설정에는 그 시점에 없었을 수도 있고
     * (구형 저장분), 저장 과정에서 떨어질 수도 있다. 그런데 라벨 해석은 `label_key` 가
     * SSoT 이므로, 그것이 없으면 저장된 ko/en 맵만 남아 **그 두 로케일 밖에서는 항상
     * ko 로 떨어진다**. 읽는 시점에 코드 정의로 다시 채워 자가 복구한다.
     *
     * @param  array<int, array<string, mixed>>  $channels  저장된 채널 목록
     * @return array<int, array<string, mixed>> label_key 가 보강된 목록
     */
    private function attachSystemLabelKeys(array $channels): array
    {
        $systemKeys = [];
        foreach ($this->getDefaultSystemChannels() as $system) {
            if (isset($system['key'], $system['label_key'])) {
                $systemKeys[$system['key']] = $system['label_key'];
            }
        }

        if ($systemKeys === []) {
            return $channels;
        }

        foreach ($channels as $i => $channel) {
            $key = $channel['key'] ?? null;
            if ($key === null || ! empty($channel['label_key']) || ! isset($systemKeys[$key])) {
                continue;
            }
            $channels[$i]['label_key'] = $systemKeys[$key];
        }

        return $channels;
    }

    /**
     * 기본 system 채널 목록을 반환합니다.
     *
     * 저장된 설정과 무관하게 항상 보장되어야 하는 system 채널 정의입니다.
     * 채널 저장 시 system 채널 누락 방지에 사용됩니다.
     *
     * 라벨은 lang key 기반으로 선언되어 활성 언어팩으로 자동 보강됩니다.
     * (registry payload name_key 계약 — 7.0.0-beta.4+)
     *
     * @return array<int, array{key: string, label_key: string, label: array<string, string>, page_slug: string, enabled: bool, is_system: bool}>
     */
    public function getDefaultSystemChannels(): array
    {
        $emailLabelKey = 'sirsoft-marketing::channels.email_subscription.label';

        return [
            [
                'key' => 'email_subscription',
                'label_key' => $emailLabelKey,
                // label 은 plugin_settings 저장 시 fallback (lang pack 미설치 환경 보호)
                'label' => $this->buildFallbackLabelMap($emailLabelKey),
                'page_slug' => '',
                'enabled' => true,
                'is_system' => true,
            ],
        ];
    }

    /**
     * lang key 를 지원 로케일 전체로 펼쳐 폴백 라벨 맵을 만듭니다.
     *
     * 로케일 집합을 ko/en 으로 고정하면, 언어팩이 추가한 로케일(ja 등)에서 이 항목만
     * ko 로 떨어져 같은 목록 안에서 한 줄만 다른 언어로 남는다. 지원 로케일은
     * 언어팩 설치/활성화 시 `app.supported_locales` 로 갱신되므로 그 값을 따른다.
     *
     * @param  string  $labelKey  라벨 lang key
     * @return array<string, string> 로케일 → 라벨
     */
    private function buildFallbackLabelMap(string $labelKey): array
    {
        $locales = array_values(array_unique(array_map(
            'strval',
            (array) config('app.supported_locales', ['ko', 'en'])
        )));

        if ($locales === []) {
            $locales = ['ko', 'en'];
        }

        $map = [];
        foreach ($locales as $locale) {
            $map[$locale] = __($labelKey, [], $locale);
        }

        return $map;
    }

    /**
     * 활성 채널 키 목록을 반환합니다.
     *
     * @return array<int, string>
     */
    public function getActiveChannelKeys(): array
    {
        return array_column($this->getRegisteredChannels(), 'key');
    }

    /**
     * 활성화된 법적 동의 키 목록을 반환합니다.
     *
     * LEGAL_KEYS 중 *_enabled 설정이 false인 항목을 제외합니다.
     *
     * @return array<int, string>
     */
    public function getEnabledLegalKeys(): array
    {
        return array_values(array_filter(MarketingConsent::LEGAL_KEYS, function (string $key) {
            return (bool) $this->pluginSettings->get(self::PLUGIN_ID, $key.'_enabled', true);
        }));
    }

    /**
     * 사용자 ID로 모든 동의 레코드를 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return Collection<int, MarketingConsent>
     */
    public function getAllByUserId(int $userId): Collection
    {
        return $this->repository->getAllByUserId($userId);
    }

    /**
     * 특정 채널 키에 동의(is_consented=true)한 사용자 수를 반환합니다.
     *
     * @param  string  $channelKey  채널 키
     * @return int
     */
    public function countConsentedByKey(string $channelKey): int
    {
        return $this->repository->countConsentedByKey($channelKey);
    }

    /**
     * 채널 라벨을 주어진 로케일로 해석합니다.
     *
     * `label_key` 가 SSoT 다 — 저장된 `label` 맵은 언어팩 미설치 환경 보호용 폴백이고, 저장 시점의
     * 지원 로케일만 담는다. 그 로케일에 번역이 있으면 번역을, 없으면 저장 맵(→ 폴백 로케일 → ko → 키)을 쓴다.
     *
     * @param  array<string, mixed>  $channel  채널 정의
     * @param  string  $locale  로케일
     * @return string 해석된 라벨
     */
    public function resolveChannelLabel(array $channel, string $locale): string
    {
        $labelKey = $channel['label_key'] ?? null;

        if (is_string($labelKey) && $labelKey !== '') {
            $translated = __($labelKey, [], $locale);

            // 번역 부재 시 __() 는 키 원문을 돌려준다 — 그때만 저장된 맵으로 내려간다.
            if (is_string($translated) && $translated !== $labelKey) {
                return $translated;
            }
        }

        $label = $channel['label'] ?? [];

        if (is_string($label)) {
            return $label;
        }

        return $label[$locale] ?? $label[config('app.fallback_locale', 'ko')] ?? $label['ko'] ?? (string) ($channel['key'] ?? '');
    }

    /**
     * 화면이 `$localized` 로 읽을 로케일별 라벨 맵을 만듭니다.
     *
     * 저장된 맵에 `label_key` 번역을 지원 로케일마다 덮어 씌운다. 저장 맵은 저장 당시 로케일만 담고 있어,
     * 언어팩으로 늘어난 로케일(ja 등)은 이 보강이 없으면 화면이 ko 로 떨어진다. 번역이 실제로 있는 로케일만
     * 채운다 — 폴백 로케일의 문구를 그 로케일 값으로 넣으면 저장된 운영자 입력을 가린다.
     *
     * @param  array<string, mixed>  $channel  채널 정의
     * @return array<string, string> 로케일 → 라벨
     */
    public function localizedLabelMap(array $channel): array
    {
        $label = $channel['label'] ?? [];
        $map = is_array($label) ? array_filter($label, static fn ($value) => is_string($value) && $value !== '') : [];

        if (is_string($label) && $label !== '') {
            $map = ['ko' => $label, 'en' => $label];
        }

        $labelKey = $channel['label_key'] ?? null;

        if (is_string($labelKey) && $labelKey !== '') {
            $translator = app('translator');

            foreach ((array) config('app.supported_locales', ['ko', 'en']) as $locale) {
                $locale = (string) $locale;

                if ($translator->hasForLocale($labelKey, $locale)) {
                    $map[$locale] = (string) $translator->get($labelKey, [], $locale, false);
                }
            }
        }

        return $map;
    }

    /**
     * 동의 상태를 업데이트합니다.
     *
     * marketing_consent 철회 시 모든 채널을 자동 철회합니다.
     * 채널 동의 시 marketing_consent를 자동 활성화합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $consentKey  동의 항목 키
     * @param  bool  $value  동의 여부
     * @param  string  $source  변경 경로 (register/profile/admin)
     * @return void
     */
    public function updateConsent(int $userId, string $consentKey, bool $value, string $source): void
    {
        $existing = $this->repository->findByUserAndKey($userId, $consentKey);

        // 이미 같은 상태면 중복 처리 방지 (이력 중복 기록 방지)
        if ($existing && (bool) $existing->is_consented === $value) {
            return;
        }

        $now = now();
        $data = [
            'is_consented' => $value,
            'consented_at' => $value ? $now : null,
            'revoked_at' => $value ? null : $now,
            'last_source' => $source,
        ];

        // 동의 횟수 증가 (동의 시만)
        if ($value) {
            $data['consent_count'] = ($existing?->consent_count ?? 0) + 1;
        }

        $consent = $this->repository->upsert($userId, $consentKey, $data);

        // 이력 기록
        $this->recordHistory($userId, $consentKey, $value ? 'granted' : 'revoked', $source);

        // 정합성 규칙: marketing_consent 철회 시 실제 동의 레코드 기준으로 모든 채널 자동 철회
        if ($consentKey === MarketingConsent::MASTER_KEY && ! $value) {
            $userConsents = $this->repository->getAllByUserId($userId);
            foreach ($userConsents as $userConsent) {
                if ($userConsent->consent_key !== MarketingConsent::MASTER_KEY && $userConsent->is_consented) {
                    $this->updateConsent($userId, $userConsent->consent_key, false, $source);
                }
            }
        }

        // 정합성 규칙: 채널 동의 시 marketing_consent 자동 활성화
        $activeChannelKeys = $this->getActiveChannelKeys();
        if (in_array($consentKey, $activeChannelKeys, true) && $value) {
            $master = $this->repository->findByUserAndKey($userId, MarketingConsent::MASTER_KEY);
            if (! $master?->is_consented) {
                $this->updateConsent($userId, MarketingConsent::MASTER_KEY, true, $source);
            }
        }

        // 훅 발행: 동의/철회 알림
        $hookName = $value ? 'sirsoft-marketing.user.subscribed' : 'sirsoft-marketing.user.unsubscribed';
        HookManager::doAction($hookName, $consent, $consentKey);

        // 훅 발행: 동의 상태 변경 알림
        HookManager::doAction('sirsoft-marketing.user.consent_changed', $consent, [
            'source' => $source,
            'key' => $consentKey,
            'value' => $value,
        ]);
    }

    /**
     * 여러 동의 항목을 일괄 업데이트합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array<string, bool>  $data  동의 데이터 [key => bool]
     * @param  string  $source  변경 경로
     * @return void
     */
    public function updateConsents(int $userId, array $data, string $source): void
    {
        // 다른 확장이 저장 전 동의 데이터를 가공할 기회 — 선언만 있고 발화처가 없던 것을 채웠다(#138 후속)
        $data = HookManager::applyFilters('sirsoft-marketing.filter_consent_data', $data, $userId, $source);

        // marketing_consent(master)를 먼저 처리하여 채널 처리 시 자동 활성화 중복 방지
        if (array_key_exists(MarketingConsent::MASTER_KEY, $data)) {
            $this->updateConsent($userId, MarketingConsent::MASTER_KEY, (bool) $data[MarketingConsent::MASTER_KEY], $source);
        }

        foreach ($data as $consentKey => $value) {
            if ($consentKey === MarketingConsent::MASTER_KEY) {
                continue;
            }
            $this->updateConsent($userId, $consentKey, (bool) $value, $source);
        }
    }

    /**
     * 사용자의 동의 이력을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return Collection<int, MarketingConsentHistory>
     */
    public function getHistories(int $userId): Collection
    {
        return $this->repository->getHistoriesByUserId($userId);
    }

    /**
     * 사용자 ID로 마케팅 동의 데이터를 모두 삭제합니다.
     *
     * DB CASCADE에 의존하지 않고 명시적으로 삭제합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return void
     */
    public function deleteByUserId(int $userId): void
    {
        $this->repository->deleteHistoriesByUserId($userId);
        $this->repository->deleteByUserId($userId);
    }

    /**
     * 동의 이력을 기록합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $channelKey  동의 항목 키
     * @param  string  $action  변경 유형 (granted/revoked)
     * @param  string  $source  변경 경로
     * @return void
     */
    private function recordHistory(int $userId, string $channelKey, string $action, string $source): void
    {
        $this->repository->createHistory([
            'user_id' => $userId,
            'channel_key' => $channelKey,
            'action' => $action,
            'source' => $source,
            'ip_address' => request()->ip(),
        ]);
    }
}
