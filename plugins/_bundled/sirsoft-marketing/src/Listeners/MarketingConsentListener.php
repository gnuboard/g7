<?php

namespace Plugins\Sirsoft\Marketing\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Helpers\TimezoneHelper;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Marketing\Fields\MarketingConsentFieldHandler;
use Plugins\Sirsoft\Marketing\Fields\MarketingConsentFields;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;

/**
 * 마케팅 동의 훅 리스너
 *
 * 동의 값의 검증과 저장은 회원 항목 선언(`Plugin::getFields()`)과 저장소 어댑터
 * (`Fields\MarketingConsentFieldHandler`)가 맡는다. 이 리스너는 그 선언을 요청 시점의 설정에 맞추고,
 * 회원 응답에 동의 값을 싣고, 회원 삭제 때 기록을 지운다.
 */
class MarketingConsentListener implements HookListenerInterface
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-marketing';

    /**
     * 동의 항목 선언
     */
    private MarketingConsentFields $fields;

    /**
     * MarketingConsentListener 생성자
     *
     * @param  MarketingConsentService  $service  마케팅 동의 서비스
     * @param  PluginSettingsService  $pluginSettings  플러그인 설정 서비스
     * @param  MarketingConsentFields|null  $fields  동의 항목 선언 (없으면 같은 서비스와 설정으로 만든다)
     */
    public function __construct(
        private MarketingConsentService $service,
        private PluginSettingsService $pluginSettings,
        ?MarketingConsentFields $fields = null,
    ) {
        $this->fields = $fields ?? new MarketingConsentFields($service, $pluginSettings);
    }

    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // Filter 훅: 회원 항목 목록을 지금의 플러그인 설정에 맞춘다 (끈 항목 제외, 아직 행이 없는 채널 추가, 약관 링크)
            'core.field.filter_definitions' => [
                'method' => 'filterFieldDefinitions',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Filter 훅: 옛 가입 요청 키(`agree_` 접두)가 검증을 통과해 가입 데이터에 함께 실리게 한다
            'core.auth.register_validation_rules' => [
                'method' => 'addLegacyRegisterRules',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Action 훅: 사용자 삭제 전 마케팅 동의 데이터 명시적 삭제
            // sync: 회원 삭제와 같은 트랜잭션에서 지운다 — 큐로 가면 커밋 뒤로 밀리고, 실패해도 삭제가 되돌려지지 않는다(#724).
            'core.user.before_delete' => [
                'method' => 'beforeDelete',
                'priority' => 10,
                'sync' => true,
            ],

            // Filter 훅: API 응답에 마케팅 동의 데이터 병합
            'core.user.filter_resource_data' => [
                'method' => 'filterResourceData',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Filter 훅: 설정 저장 시 channels 배열을 JSON 문자열로 변환
            'core.plugin_settings.filter_save_data' => [
                'method' => 'normalizeChannelsSaveData',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Action 훅: 설정(채널 목록, 항목 이름)이 바뀌면 회원 항목 선언을 다시 동기화한다
            // sync: 저장 직후 다시 연 화면(회원 항목 표, 가입 화면)에 새 채널이 보여야 한다.
            'core.plugin_settings.after_save' => [
                'method' => 'syncFieldDeclarations',
                'priority' => 10,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * getSubscribedHooks()에서 개별 메서드를 지정하므로,
     * 이 메서드는 호출되지 않지만 인터페이스 준수를 위해 구현
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 회원 항목 목록을 지금의 플러그인 설정에 맞춥니다.
     *
     * | 처리 | 내용 |
     * | --- | --- |
     * | 제외 | 설정에서 끈 동의(전체 동의, 법적 동의), 끄거나 지운 채널의 항목 |
     * | 추가 | 지금 받는 항목인데 행이 아직 없는 것 (설정을 고친 뒤 동기화 전, 항목 키 형식에 맞지 않는 채널 키) |
     * | 약관 링크 | 약관 페이지를 정한 항목에 `ui.link` |
     * | 동의일 | 내 정보 화면(`self_update`)에서 본인이 동의한 항목에 `ui.notice` 로 동의한 날짜 |
     *
     * 다른 출처가 같은 키의 항목을 이미 갖고 있으면 그 키는 더하지 않는다(먼저 있는 쪽을 유지).
     *
     * @param  mixed  $definitions  항목 목록 (Collection<int, FieldDefinition>)
     * @param  mixed  ...$args  집합 식별자, 범위 키, 문맥 배열(`FieldContext::toArray()`)
     * @return mixed 맞춘 항목 목록
     */
    public function filterFieldDefinitions(mixed $definitions, mixed ...$args): mixed
    {
        $set = is_string($args[0] ?? null) ? $args[0] : '';
        $context = is_array($args[2] ?? null) ? $args[2] : [];
        if ($set !== FieldSet::CORE_USER || ! $definitions instanceof Collection) {
            return $definitions;
        }

        $available = $this->fields->availableKeys();

        $result = $definitions
            ->filter(fn ($definition) => ! $this->isConsentField($definition) || in_array($definition->key, $available, true))
            ->values();

        $present = $result
            ->map(fn ($definition) => $definition instanceof FieldDefinition ? $definition->key : null)
            ->all();
        $missing = array_values(array_diff($available, $present));

        if ($missing !== []) {
            foreach ($this->fields->declarations() as $declaration) {
                if (! in_array($declaration['key'], $missing, true)) {
                    continue;
                }
                $result->push(new FieldDefinition([
                    'field_set' => FieldSet::CORE_USER,
                    'key' => $declaration['key'],
                    ...FieldDefinitionSyncHelper::attributesFrom(FieldDefinition::SOURCE_PLUGIN, self::PLUGIN_ID, $declaration),
                ]));
            }
        }

        $consentedOn = $this->consentDatesFor($context);

        foreach ($result as $definition) {
            if (! $this->isConsentField($definition)) {
                continue;
            }

            $ui = $definition->ui ?? [];
            unset($ui['link']);
            $link = $this->fields->termsLink($definition->key, $definition->localized('name'));
            $ui = $link !== null ? [...$ui, 'link' => $link] : $ui;
            if (isset($consentedOn[$definition->key])) {
                $ui['notice'] = (string) __('sirsoft-marketing::fields.consented_on', ['date' => $consentedOn[$definition->key]]);
            }
            $definition->ui = $ui;
        }

        return $result;
    }

    /**
     * 내 정보 화면에서 본인이 동의한 항목의 동의일을 항목 키별로 반환합니다.
     *
     * 내 정보 보기에서 동의 항목 값 아래에 「동의일」을 알림 줄로 보이기 위한 것이다(지운 보기 조각의 동의일 배지를 항목 계약으로 옮김).
     * 본인 화면이 아니거나 저장된 회원이 없으면 빈 배열이다. 날짜는 사용자 시간대의 날짜(Y-m-d)다.
     *
     * @param  array<string, mixed>  $context  문맥 배열 (`surface`, `owner`)
     * @return array<string, string> 항목 키 => 동의일
     */
    private function consentDatesFor(array $context): array
    {
        $owner = $context['owner'] ?? null;
        if (($context['surface'] ?? null) !== FieldContext::SURFACE_SELF_UPDATE || ! $owner instanceof User || ! $owner->exists) {
            return [];
        }

        $timezone = TimezoneHelper::getUserTimezone();
        $dates = [];
        foreach ($this->service->getAllByUserId((int) $owner->id) as $consent) {
            if ($consent->is_consented && $consent->consented_at !== null) {
                $dates[$consent->consent_key] = $consent->consented_at->copy()->setTimezone($timezone)->format('Y-m-d');
            }
        }

        return $dates;
    }

    /**
     * 옛 가입 요청 키(`agree_` + 항목 키)의 검증 규칙을 더합니다.
     *
     * 정식 키의 규칙은 회원 항목 선언이 만든다. 옛 키는 코어 가입 요청이 정식 키로 복사해 저장하고,
     * 여기서 규칙을 주어 검증된 가입 데이터(`core.auth.after_register` 의 `registration_data`)에도 그대로 남긴다 —
     * 옛 키를 읽던 구독자가 계속 읽을 수 있다. 가입 화면에서 받지 않는 항목(정식 키의 규칙이 없는 것)의 옛 키는 받지 않는다.
     *
     * @param  array  $rules  기존 validation rules
     * @return array 옛 키 규칙이 더해진 rules
     */
    public function addLegacyRegisterRules(array $rules): array
    {
        foreach ($this->fields->availableKeys() as $key) {
            $legacy = MarketingConsentFields::legacyKey($key);
            if (array_key_exists($key, $rules) && ! array_key_exists($legacy, $rules)) {
                $rules[$legacy] = ['nullable', 'boolean'];
            }
        }

        return $rules;
    }

    /**
     * 플러그인 설정이 저장되면 회원 항목 선언을 다시 동기화합니다.
     *
     * 채널을 더하거나 지우거나 이름을 바꾸면 회원 항목 표의 행이 따라가야 한다. 운영자가 그 표에서 바꿔 둔 값
     * (필수, 가입 화면 표시, 순서)은 동기화가 보존한다.
     *
     * @param  string  $identifier  저장한 플러그인 식별자
     * @return void 반환값 없음
     */
    public function syncFieldDeclarations(string $identifier = ''): void
    {
        if ($identifier !== self::PLUGIN_ID) {
            return;
        }

        try {
            app(FieldDefinitionSyncHelper::class)->sync(
                FieldDefinition::SOURCE_PLUGIN,
                self::PLUGIN_ID,
                $this->fields->declarations(),
            );
        } catch (\Throwable $e) {
            Log::warning('마케팅 동의 항목 선언 동기화 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 사용자 삭제 전 액션: 마케팅 동의 데이터 명시적 삭제
     *
     * DB CASCADE에 의존하지 않고 명시적으로 삭제합니다.
     *
     * @param  User  $user  삭제될 사용자
     * @return void
     */
    public function beforeDelete(User $user): void
    {
        $this->service->deleteByUserId($user->id);
    }

    /**
     * 리소스 데이터 필터: User API 응답에 마케팅 동의 데이터 병합
     *
     * 회원 폼은 항목 계약(`name`)과 같은 키의 응답 값을 초기값으로 쓴다 — 지금 받는 동의 항목마다 값(boolean)과
     * 동의 시각(`{키}_at`)을 싣는다. 화면이 항목을 그릴지와 약관 링크는 항목 계약이 정하므로 여기서 싣지 않는다.
     * `channels` 와 `consent_histories` 는 관리자 회원 상세의 동의 이력 표가 읽는다.
     *
     * @param  array  $data  API 응답 데이터
     * @param  User  $user  조회 대상 사용자
     * @return array 마케팅 동의 데이터가 병합된 API 응답
     */
    public function filterResourceData(array $data, User $user): array
    {
        $consents = $this->service->getAllByUserId($user->id)->keyBy('consent_key');

        foreach ($this->fields->availableKeys() as $key) {
            $record = $consents->get($key);
            $data[$key] = (bool) ($record?->is_consented ?? false);
            // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 브라우저 표시 변환은 후속(F1)
            $data["{$key}_at"] = $record?->consented_at?->toIso8601String();
        }

        // 채널 목록 — 동의 이력 표가 채널 키를 이름으로 바꿀 때 읽는다 (끈 채널 포함: 지난 이력에 남아 있을 수 있다)
        $locale = app()->getLocale();
        $data['channels'] = array_map(fn (array $channel) => [
            'key' => $channel['key'],
            'label' => $this->resolveChannelLabel($channel, $locale),
            'enabled' => (bool) ($channel['enabled'] ?? true),
        ], $this->service->getAllChannels());

        // 동의 이력 추가
        $data['consent_histories'] = $this->service->getHistories($user->id)
            ->map(fn ($history) => [
                'channel_key' => $history->channel_key,
                'action' => $history->action,
                'source' => $history->source,
                // 화면 값 — 보는 사람(관리자) 시간대. 같은 화면의 가입일과 같은 기준
                'created_at' => TimezoneHelper::toUserDateTimeString($history->created_at),
            ])
            ->toArray();

        return $data;
    }

    /**
     * 설정 저장 시 channels 배열을 JSON 문자열로 변환합니다.
     *
     * PluginSettingsService::save()에서 호출되며, sirsoft-marketing 플러그인의
     * channels 값이 배열로 전달된 경우 JSON 문자열로 직렬화합니다.
     * 이렇게 해야 getAllChannels()에서 json_decode()로 올바르게 읽을 수 있습니다.
     *
     * @param  array  $settings  저장할 설정 데이터
     * @param  string  $identifier  플러그인 식별자
     * @return array channels가 JSON 문자열로 변환된 설정 데이터
     */
    public function normalizeChannelsSaveData(array $settings, string $identifier): array
    {
        if ($identifier !== self::PLUGIN_ID) {
            return $settings;
        }

        if (isset($settings['channels']) && is_array($settings['channels'])) {
            $settings['channels'] = json_encode($settings['channels'], JSON_UNESCAPED_UNICODE);
        }

        return $settings;
    }

    /**
     * 채널 라벨을 현재 로케일로 해석합니다.
     *
     * `label_key` 가 SSoT 다 — 저장된 `label` 맵은 언어팩 미설치 환경 보호용 폴백이고,
     * 그 맵은 저장 시점의 지원 로케일만 담는다. 맵만 읽으면 나중에 추가된 로케일에서
     * 이 항목만 ko 로 떨어져, 같은 목록의 다른 항목이 번역되는 와중에 한 줄만 한국어로 남는다.
     *
     * @param  array<string, mixed>  $channel  채널 정의
     * @param  string  $locale  현재 로케일
     * @return string 해석된 라벨
     */
    private function resolveChannelLabel(array $channel, string $locale): string
    {
        return $this->service->resolveChannelLabel($channel, $locale);
    }

    /**
     * 이 플러그인이 선언한 동의 항목인지 반환합니다.
     *
     * @param  mixed  $definition  항목 정의
     * @return bool 동의 항목이면 true
     */
    private function isConsentField(mixed $definition): bool
    {
        return $definition instanceof FieldDefinition
            && $definition->source_type === FieldDefinition::SOURCE_PLUGIN
            && $definition->source_identifier === self::PLUGIN_ID
            && $definition->storage_target === MarketingConsentFieldHandler::class;
    }
}
