<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Services\ModuleSettingsService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;

/**
 * 비즈뿌리오 문자·알림톡 채널을 코어 알림 시스템에 등록하는 필터 리스너.
 *
 * 코어를 수정하지 않고 다음 4계열 필터 훅을 구독해 sms·alimtalk 채널을 노출·게이트한다.
 *
 * 1) core.notification.filter_available_channels
 *    - 알림 설정 화면의 채널 탭 SSoT. sms·alimtalk 메타(name_key/allow_guest 등)를 추가한다.
 * 2) core.notification.channel_readiness
 *    - 채널별 환경설정 충족 여부({ready, reason})를 반환한다(D2).
 * 3) {prefix}.notification.channels  (core.auth / sirsoft-ecommerce / sirsoft-board)
 *    - 레거시 다채널 자동 결정 경로에서 정의별 채널 후보에 sms·alimtalk 을 더한다(D7 3영역).
 *
 * 실제 발송 위임({prefix}.notification.to_sms/to_alimtalk)은 채널 드라이버가
 * ChannelManager 에 등록되어야 발화하므로, 드라이버 등록은 ServiceProvider::boot() 가
 * 담당한다(이 리스너는 채널 "노출·판정"만 책임진다).
 *
 * 화면 노출은 '비즈뿌리오' 통합 탭 방식이다(#597 §3.3). sms 채널 메타의 hidden_tab 으로
 * 개별 탭만 숨기고(채널 토글 카드·발송 축은 유지), alimtalk 메타의 tab_channels ·
 * tab_label_key 로 통합 탭의 노출 조건·라벨을 선언한다. 알림톡 템플릿 작성·검수·대체 SMS
 * 설정은 코어 목록 행 하단 overlay(row_footer)가 담당하며 저장은 우리 템플릿 API
 * (admin/templates)로 직접 수행한다.
 */
class RegisterNotificationChannelsListener implements HookListenerInterface
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** lang 네임스페이스 접두사 (plugin.php 와 동일) */
    private const LANG = 'sirsoft-message_bizppurio::messages';

    /** 이 플러그인이 등록하는 채널 ID 목록 (Plugin::cleanupChannelContributions 에서도 참조) */
    public const CHANNEL_IDS = ['sms', 'alimtalk'];

    /** 3영역 채널 후보 확장을 구독할 hookPrefix (D7) */
    private const CHANNEL_HOOK_PREFIXES = ['core.auth', 'sirsoft-ecommerce', 'sirsoft-board'];

    /**
     * @param  PluginSettingsService  $pluginSettings  readiness 검사를 위한 환경설정 조회
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 구독할 훅 목록 반환.
     *
     * filter_available_channels / channel_readiness 는 고정 훅이고,
     * {prefix}.notification.channels 는 3영역 prefix 별로 동적으로 펼쳐 등록한다.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        $hooks = [
            'core.notification.filter_available_channels' => [
                'method' => 'addChannels',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.notification.channel_readiness' => [
                'method' => 'checkReadiness',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.notification.channel_enabled' => [
                'method' => 'gateChannelEnabled',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];

        foreach (self::CHANNEL_HOOK_PREFIXES as $prefix) {
            $hooks["{$prefix}.notification.channels"] = [
                'method' => 'addChannelCandidates',
                'priority' => 20,
                'type' => 'filter',
            ];
        }

        return $hooks;
    }

    /**
     * 기본 핸들러 (미사용 — 필터 메서드로 처리).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 사용 가능한 채널 목록에 sms·alimtalk 메타를 추가합니다.
     *
     * 코어 NotificationChannelService::getAvailableChannels() 가 name_key/description_key/
     * source_label_key 를 활성 locale 로 해석하므로(localized_payload), lang key 로 선언한다.
     * 중복 방지: 이미 존재하는 id 는 다시 추가하지 않는다.
     *
     * allow_guest:true — 비회원 문자 발송 허용(D1). 실제 게스트 전화번호는 SmsChannelDriver 가
     * data 에서 해석한다.
     *
     * @param  array<int, array<string, mixed>>  $channels  현재 채널 메타 목록
     * @return array<int, array<string, mixed>> sms·alimtalk 이 추가된 목록
     */
    public function addChannels(array $channels): array
    {
        $existingIds = array_column($channels, 'id');

        // 검수(테스트) 모드 여부를 채널 메타에 실어 프론트(availableChannels)로 전달한다.
        // 알림톡·SMS 탭 상단 상태 배너가 이 값으로 "테스트 모드 — 실제 발송 안 됨"을 노출한다.
        // 코어 getAvailableChannels 는 임의 필드를 보존하므로 프론트까지 그대로 도달한다.
        $isTestMode = $this->isTestMode();

        foreach ($this->channelMetas() as $meta) {
            if (! in_array($meta['id'], $existingIds, true)) {
                $meta['is_test_mode'] = $isTestMode;
                $channels[] = $meta;
            }
        }

        return $channels;
    }

    /**
     * 채널 준비 상태를 검사합니다 (core.notification.channel_readiness).
     *
     * 코어/타 플러그인 채널의 판정({ready,reason})은 그대로 통과시키고, 우리 채널(sms/alimtalk)
     * 일 때만 환경설정 충족 여부로 교체한다(D2).
     *
     * - sms: bizppurio_id + password + sender_number
     * - alimtalk: sms 조건 + api_key + sender_key
     *
     * @param  array{ready: bool, reason: string|null}  $result  이전 필터까지의 판정
     * @param  string  $channelId  검사 대상 채널
     * @return array{ready: bool, reason: string|null}
     */
    public function checkReadiness(array $result, string $channelId): array
    {
        return match ($channelId) {
            'sms' => $this->checkSmsReadiness(),
            'alimtalk' => $this->checkAlimtalkReadiness(),
            default => $result,
        };
    }

    /**
     * 우리 채널(sms/alimtalk)의 "미저장=비활성(OFF)" 정책을 적용합니다
     * (core.notification.channel_enabled 필터).
     *
     * 코어의 기본 판정은 "채널 설정 엔트리가 없으면 활성(true)" 이다(하위호환). 하지만
     * sms·alimtalk 은 플러그인이 나중에 주입한 채널이라, 관리자가 명시적으로 켜기 전에는
     * 발송·기록하지 않아야 한다(opt-in). 게스트 발송 정책 isChannelGuestAllowed 의
     * "확장 채널은 미선언=차단" 과 같은 방향이다.
     *
     * 따라서 우리 채널이면서 해당 확장의 notifications.channels 저장소에 엔트리가 없을 때만
     * false 로 덮어쓴다. 엔트리가 저장되어 있으면(켜짐/꺼짐 모두) 코어가 이미 그 값을 반영해
     * $enabled 로 넘겨주므로 그대로 통과시킨다. 우리 채널이 아니면 항상 원본을 통과시킨다.
     *
     * @param  bool  $enabled  코어가 계산한 활성 여부
     * @param  string  $extensionType  확장 타입 (core/module/plugin)
     * @param  string|null  $extensionIdentifier  확장 식별자
     * @param  string  $channelId  채널 식별자
     * @return bool 최종 활성 여부
     */
    public function gateChannelEnabled(
        bool $enabled,
        string $extensionType,
        ?string $extensionIdentifier,
        string $channelId
    ): bool {
        if (! in_array($channelId, self::CHANNEL_IDS, true)) {
            return $enabled;
        }

        // 저장 엔트리가 있으면 코어 판정($enabled)을 존중, 없으면 미저장 → OFF
        if ($this->hasSavedChannelEntry($extensionType, $extensionIdentifier, $channelId)) {
            return $enabled;
        }

        return false;
    }

    /**
     * 해당 확장의 notifications.channels 저장소에 특정 채널 엔트리가 존재하는지 확인합니다.
     *
     * 저장소는 확장 타입별로 분리됩니다:
     *   - core   → SettingsService
     *   - module → ModuleSettingsService
     *   - plugin → PluginSettingsService
     *
     * 조회 실패(예외)나 미지원 타입은 "미저장"(false)으로 간주해 안전측(OFF)으로 처리한다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string|null  $extensionIdentifier  확장 식별자
     * @param  string  $channelId  채널 식별자
     * @return bool 저장 엔트리 존재 여부
     */
    private function hasSavedChannelEntry(
        string $extensionType,
        ?string $extensionIdentifier,
        string $channelId
    ): bool {
        try {
            $channels = match ($extensionType) {
                'core' => app(SettingsService::class)
                    ->getSetting('notifications.channels', []),
                'module' => empty($extensionIdentifier)
                    ? []
                    : app(ModuleSettingsService::class)
                        ->get($extensionIdentifier, 'notifications.channels', []),
                'plugin' => empty($extensionIdentifier)
                    ? []
                    : app(PluginSettingsService::class)
                        ->get($extensionIdentifier, 'notifications.channels', []),
                default => [],
            };
        } catch (\Throwable $e) {
            return false;
        }

        if (! is_array($channels)) {
            return false;
        }

        foreach ($channels as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $channelId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 레거시 다채널 자동 결정 경로에서 채널 후보에 sms·alimtalk 을 더합니다.
     *
     * {prefix}.notification.channels 는 채널 미지정(다채널) 발송 경로에서만 발화하며(코어 확인),
     * 채널 지정 경로에서는 filter_available_channels 가 SSoT 다. 두 경로 모두에서 채널이
     * 누락되지 않도록 후보에 우리 채널 id 를 더한다(중복 제거).
     *
     * @param  array<int, string>  $channels  정의별 채널 id 후보
     * @param  string  $type  알림 정의 유형 (미사용)
     * @param  object|null  $notifiable  수신자 (미사용)
     * @return array<int, string> 우리 채널이 더해진 후보
     */
    public function addChannelCandidates(array $channels, string $type = '', ?object $notifiable = null): array
    {
        foreach (self::CHANNEL_IDS as $id) {
            if (! in_array($id, $channels, true)) {
                $channels[] = $id;
            }
        }

        return array_values($channels);
    }

    /**
     * sms·alimtalk 채널 메타 정의를 반환합니다.
     *
     * '비즈뿌리오' 통합 탭 메타(#597 §3.3 — 범용 키, 확장명 하드코딩 없음):
     *  - sms.hidden_tab: 탭만 숨긴다(채널 토글 카드·발송 축은 그대로).
     *  - alimtalk.tab_channels: 이 목록 중 하나라도 활성 저장이면 탭을 노출한다.
     *  - alimtalk.tab_label_key: 탭 라벨용 프론트 lang 키($t 해석 — 카드 라벨(name)과 분리).
     *  - alimtalk.hidden_template_editor: 코어 알림 템플릿 [편집] 모달의 제목/본문 입력·미리보기·
     *    코어 [저장]을 숨긴다(PO 결정 2026-08-23). 알림톡 본문은 카카오 규격(#{var}, 유형별 필드)이라
     *    코어 본문과 다른 편집기가 필요하고, 그 편집기·통합 저장은 플러그인이 같은 모달의
     *    extension_point(notification_template_form_sections / _footer_actions)에 주입한다.
     *    코어 채널 템플릿 행(제목/본문)은 발송에 쓰이지 않으므로 숨겨도 동작 손실이 없다.
     * 코어 getAvailableChannels 는 임의 필드를 보존하므로 프론트까지 그대로 도달한다
     * (is_test_mode 와 동일 경로).
     *
     * @return array<int, array<string, mixed>>
     */
    private function channelMetas(): array
    {
        return [
            [
                'id' => 'sms',
                'name_key' => self::LANG.'.channels.sms.name',
                'description_key' => self::LANG.'.channels.sms.description',
                'icon' => 'fas fa-comment-sms',
                'source' => self::PLUGIN_IDENTIFIER,
                'source_label_key' => self::LANG.'.channels.source_label',
                'allow_guest' => true,
                'hidden_tab' => true,
            ],
            [
                'id' => 'alimtalk',
                'name_key' => self::LANG.'.channels.alimtalk.name',
                'description_key' => self::LANG.'.channels.alimtalk.description',
                'icon' => 'fas fa-comment-dots',
                'source' => self::PLUGIN_IDENTIFIER,
                'source_label_key' => self::LANG.'.channels.source_label',
                'allow_guest' => true,
                'tab_channels' => ['sms', 'alimtalk'],
                'tab_label_key' => self::PLUGIN_IDENTIFIER.'.channels.bizppurio_tab',
                'hidden_template_editor' => true,
            ],
        ];
    }

    /**
     * SMS 채널 준비 상태를 검사합니다.
     *
     * @return array{ready: bool, reason: string|null}
     */
    private function checkSmsReadiness(): array
    {
        if ($this->missing('bizppurio_id') || $this->missing('password')) {
            return $this->notReady('sms_credentials_missing');
        }

        if ($this->missing('sender_number')) {
            return $this->notReady('sms_sender_number_missing');
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * 알림톡 채널 준비 상태를 검사합니다.
     *
     * @return array{ready: bool, reason: string|null}
     */
    private function checkAlimtalkReadiness(): array
    {
        if ($this->missing('bizppurio_id') || $this->missing('password')) {
            return $this->notReady('sms_credentials_missing');
        }

        if ($this->missing('sender_number')) {
            return $this->notReady('sms_sender_number_missing');
        }

        if ($this->missing('api_key')) {
            return $this->notReady('alimtalk_api_key_missing');
        }

        if ($this->missing('sender_key')) {
            return $this->notReady('alimtalk_sender_key_missing');
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * 환경설정 값이 비어 있는지 확인합니다.
     *
     * @param  string  $key  설정 키
     * @return bool 비어 있으면 true
     */
    private function missing(string $key): bool
    {
        $value = $this->pluginSettings->get(self::PLUGIN_IDENTIFIER, $key, '');

        return trim((string) $value) === '';
    }

    /**
     * 검수(테스트) 모드 여부를 반환합니다.
     *
     * is_test_mode=true 이면 실제 발송이 이뤄지지 않는다(검수 환경). 상태 배너 노출 기준.
     *
     * @return bool 검수 모드면 true
     */
    private function isTestMode(): bool
    {
        return (bool) $this->pluginSettings->get(self::PLUGIN_IDENTIFIER, 'is_test_mode', true);
    }

    /**
     * ready=false 판정을 lang reason key 와 함께 반환합니다.
     *
     * @param  string  $reasonKey  messages.readiness.* 하위 키
     * @return array{ready: bool, reason: string}
     */
    private function notReady(string $reasonKey): array
    {
        return ['ready' => false, 'reason' => self::LANG.'.readiness.'.$reasonKey];
    }
}
