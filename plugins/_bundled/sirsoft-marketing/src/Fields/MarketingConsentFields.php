<?php

namespace Plugins\Sirsoft\Marketing\Fields;

use App\Extension\Helpers\ExtensionLangTexts;
use App\Fields\FieldSet;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;

/**
 * 동의 항목을 코어 회원 항목 집합(`core.user`)의 항목으로 선언한다.
 *
 * 항목은 넷으로 나뉜다. 전체 동의(`marketing_consent`), 수신 채널(운영자가 설정에서 더하고 빼는 `channels`),
 * 법적 동의 둘(`third_party_consent`, `info_disclosure`). 채널은 설정이라 선언 시점에 고정할 수 없으므로
 * 선언(`declarations()`)이 그때의 설정을 읽어 만들고, 설정을 저장할 때마다 다시 동기화한다.
 *
 * 선언은 「있을 수 있는 항목 전부」이고(끈 채널과 끈 법적 동의 포함), 지금 받는 항목은 `availableKeys()` 다.
 * 꺼 둔 항목의 행을 지우지 않는 것은 다시 켰을 때 운영자가 회원 항목 표에서 바꿔 둔 필수, 가입 표시, 순서가
 * 그대로 돌아오게 하려는 것이다.
 *
 * @since 1.1.4
 */
class MarketingConsentFields
{
    /** 플러그인 식별자 */
    public const PLUGIN_ID = 'sirsoft-marketing';

    /** 화면이 약관 동의 묶음으로 그리는 구획 키 */
    public const GROUP = 'agreements';

    /** 가입 경로가 쓰던 옛 요청 키의 접두 (`agree_` + 항목 키) */
    public const LEGACY_PREFIX = 'agree_';

    /** 표시 순서의 시작값 — 코어 항목과 운영자 추가 항목(기본 1000) 뒤에 온다 */
    private const SORT_BASE = 9000;

    /** 지원 언어 설정이 없을 때 번역기로 찾는 언어 */
    private const BUNDLED_LOCALES = ['ko', 'en'];

    /**
     * @param  MarketingConsentService  $service  마케팅 동의 서비스 (채널 목록, 라벨)
     * @param  PluginSettingsService  $pluginSettings  플러그인 설정 서비스
     */
    public function __construct(
        private MarketingConsentService $service,
        private PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 항목 선언 전체를 반환합니다 (끈 채널과 끈 법적 동의 포함).
     *
     * 약관 「보기」 링크(`ui.link`)는 싣지 않는다 — 약관 페이지와 요청 언어에 따라 달라지는 값이라
     * 요청 시점에 `termsLink()` 로 붙인다.
     *
     * @return array<int, array<string, mixed>> 항목 선언 목록 (표시 순서)
     */
    public function declarations(): array
    {
        $declarations = [
            $this->declaration(
                MarketingConsent::MASTER_KEY,
                $this->localeMap(MarketingConsent::MASTER_KEY.'.name'),
                $this->localeMap(MarketingConsent::MASTER_KEY.'.description'),
                ['role' => 'master'],
                self::SORT_BASE,
            ),
        ];

        $order = self::SORT_BASE + 10;
        foreach ($this->service->getAllChannels() as $channel) {
            $key = $channel['key'] ?? null;
            if (! is_string($key) || $key === '' || $this->isFixedKey($key)) {
                continue;
            }

            $name = $this->service->localizedLabelMap($channel);
            $declarations[] = $this->declaration(
                $key,
                $name !== [] ? $name : ['ko' => $key, 'en' => $key],
                null,
                ['role' => 'channel', 'parent' => MarketingConsent::MASTER_KEY],
                $order,
            );
            $order += 10;
        }

        $order = self::SORT_BASE + 500;
        foreach (MarketingConsent::LEGAL_KEYS as $key) {
            $declarations[] = $this->declaration(
                $key,
                $this->localeMap($key.'.name'),
                $this->localeMap($key.'.description'),
                ['role' => 'legal'],
                $order,
            );
            $order += 10;
        }

        return $declarations;
    }

    /**
     * 지금 받는 동의 항목 키를 반환합니다.
     *
     * | 항목 | 받는 조건 |
     * | --- | --- |
     * | 전체 동의 | 설정 `marketing_consent_enabled` |
     * | 채널 | 전체 동의를 쓰고 그 채널이 사용 중 |
     * | 법적 동의 | 각 `{키}_enabled` 설정 |
     *
     * @return array<int, string> 항목 키 목록 (표시 순서)
     */
    public function availableKeys(): array
    {
        $keys = [];

        if ((bool) $this->pluginSettings->get(self::PLUGIN_ID, 'marketing_consent_enabled', true)) {
            $keys[] = MarketingConsent::MASTER_KEY;
            foreach ($this->service->getActiveChannelKeys() as $key) {
                if (is_string($key) && $key !== '' && ! $this->isFixedKey($key)) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique([...$keys, ...$this->service->getEnabledLegalKeys()]));
    }

    /**
     * 항목의 약관 「보기」 링크를 반환합니다 (요청 언어로 푼 값).
     *
     * 약관 본문은 페이지 모듈(`sirsoft-page`)이 제공한다. 운영자가 약관 페이지를 정하지 않은 항목은 링크가 없다.
     *
     * | 키 | 뜻 |
     * | --- | --- |
     * | label | 단추 글자 |
     * | title | 약관 창의 제목 |
     * | url | 약관 페이지 주소 (새 창으로 열 때) |
     * | content_api | 약관 본문 API (창 안에 띄울 때. 응답 `data` 의 `content`, `content_mode`, `published_at`) |
     * | requires_module | 이 링크가 기대는 모듈. 꺼져 있으면 화면이 단추를 그리지 않는다 |
     *
     * @param  string  $key  항목 키
     * @param  string|null  $name  항목 이름 (창 제목에 쓴다)
     * @return array{label: string, title: string, url: string, content_api: string, requires_module: string}|null 링크 (약관 페이지가 없으면 null)
     */
    public function termsLink(string $key, ?string $name = null): ?array
    {
        $slug = $this->termsSlug($key);
        if ($slug === '') {
            return null;
        }

        $encoded = rawurlencode($slug);

        return [
            'label' => (string) __('sirsoft-marketing::fields.terms_link.label'),
            'title' => (string) __('sirsoft-marketing::fields.terms_link.title', ['name' => $name ?? $key]),
            'url' => '/page/'.$encoded,
            'content_api' => '/api/modules/sirsoft-page/pages/'.$encoded,
            'requires_module' => 'sirsoft-page',
        ];
    }

    /**
     * 옛 가입 요청 키를 반환합니다.
     *
     * @param  string  $key  항목 키
     * @return string 옛 요청 키 (`agree_` + 항목 키)
     */
    public static function legacyKey(string $key): string
    {
        return self::LEGACY_PREFIX.$key;
    }

    /**
     * 항목의 약관 페이지 slug 를 반환합니다.
     *
     * @param  string  $key  항목 키
     * @return string slug (정하지 않았으면 빈 문자열)
     */
    private function termsSlug(string $key): string
    {
        if ($this->isFixedKey($key)) {
            $slug = $this->pluginSettings->get(self::PLUGIN_ID, $key.'_terms_slug', '');

            return is_string($slug) ? trim($slug) : '';
        }

        foreach ($this->service->getAllChannels() as $channel) {
            if (($channel['key'] ?? null) === $key) {
                $slug = $channel['page_slug'] ?? '';

                return is_string($slug) ? trim($slug) : '';
            }
        }

        return '';
    }

    /**
     * 채널이 아닌 고정 항목(전체 동의, 법적 동의)의 키인지 반환합니다.
     *
     * @param  string  $key  항목 키
     * @return bool 고정 항목이면 true
     */
    private function isFixedKey(string $key): bool
    {
        return $key === MarketingConsent::MASTER_KEY || in_array($key, MarketingConsent::LEGAL_KEYS, true);
    }

    /**
     * 항목 선언 한 건을 만듭니다.
     *
     * @param  string  $key  항목 키 (요청 키와 같다)
     * @param  array<string, string>  $name  이름 (로케일 => 글자)
     * @param  array<string, string>|null  $description  안내 문구 (로케일 => 글자)
     * @param  array<string, mixed>  $ui  표시 정보에 더할 값 (역할, 상위 항목)
     * @param  int  $sortOrder  표시 순서
     * @return array<string, mixed> 항목 선언
     */
    private function declaration(string $key, array $name, ?array $description, array $ui, int $sortOrder): array
    {
        return [
            'field_set' => FieldSet::CORE_USER,
            'key' => $key,
            'type' => 'boolean',
            'storage' => 'handler',
            'storage_target' => MarketingConsentFieldHandler::class,
            'name' => $name,
            'description' => $description ?: null,
            'constraints' => ['widget' => 'checkbox'],
            'enabled' => true,
            'required' => false,
            'show_on_signup' => true,
            'edit_scope' => 'self',
            // 쓸지 말지는 플러그인 설정이 정한다 — 회원 항목 표에서는 잠근다.
            'locks' => ['enabled' => 'sirsoft-marketing::fields.locks.enabled'],
            'legacy_keys' => [self::legacyKey($key)],
            'ui' => ['group' => self::GROUP, ...$ui],
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * 번역 키를 로케일별 글자 맵으로 펼칩니다.
     *
     * 설치 흐름처럼 플러그인의 번역 이름공간이 아직 등록되지 않은 때에는 언어 파일을 직접 읽는다 —
     * 그때 선언에 번역 키 원문이 이름으로 저장되지 않게 하려는 것이다. 직접 읽을 때는 플러그인 자체 파일과
     * 이 플러그인을 대상으로 하는 활성 언어팩의 `backend/{언어}/fields.php` 를 함께 읽는다(코어 `ExtensionLangTexts`).
     * 팩을 막 켠 요청에서는 팩의 번역 경로가 아직 등록되지 않아 번역기가 그 언어를 모르기 때문이다.
     *
     * @param  string  $key  `fields.php` 안의 키 (예: marketing_consent.name)
     * @return array<string, string> 로케일 => 글자
     */
    private function localeMap(string $key): array
    {
        $translator = app('translator');
        $full = 'sirsoft-marketing::fields.'.$key;
        $map = [];

        foreach ((array) config('app.supported_locales', self::BUNDLED_LOCALES) as $locale) {
            $locale = (string) $locale;
            if ($translator->hasForLocale($full, $locale)) {
                $map[$locale] = (string) $translator->get($full, [], $locale, false);
            }
        }

        foreach (ExtensionLangTexts::collect('sirsoft-marketing', dirname(__DIR__, 2).'/lang', 'fields', $key) as $locale => $text) {
            $map[$locale] ??= $text;
        }

        return $map;
    }
}
