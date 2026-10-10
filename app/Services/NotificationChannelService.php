<?php

namespace App\Services;

use App\Extension\HookManager;
use Illuminate\Support\Arr;

class NotificationChannelService
{
    /**
     * 확장 × 채널 조합별 전역 활성 여부 메모이제이션 캐시.
     *
     * @var array<string, bool>
     */
    private array $channelEnabledCache = [];

    /**
     * 사용 가능한 알림 채널 목록을 반환합니다.
     *
     * config 기본 채널 + Filter 훅으로 플러그인 채널 확장 가능.
     *
     * @return array 채널 메타데이터 배열 (id, name, description, source_label 등)
     */
    public function getAvailableChannels(): array
    {
        $defaultChannels = config('notification.default_channels', []);

        $channels = HookManager::applyFilters(
            'core.notification.filter_available_channels',
            $defaultChannels
        );

        // name_key / description_key / source_label_key 를 활성 locale 기준으로 해석하여
        // name / description / source_label 키로 반환 (registry payload name_key 계약).
        return array_map(function (array $channel): array {
            foreach (['name', 'description', 'source_label'] as $field) {
                $resolved = localized_payload($channel, $field);
                if ($resolved !== '') {
                    $channel[$field] = $resolved;
                }
            }

            $channel['template_editor'] = $this->resolveTemplateEditor($channel);

            return $channel;
        }, $channels);
    }

    /**
     * 채널이 요구하는 템플릿 편집기 종류를 정규화합니다. (`rich` | `plain` | `none`)
     *
     * 화면이 이 한 값만 읽으면 되도록 **서버에서** 정규화한다 — 화면마다 "선언이 있으면 그
     * 값, 없으면 hidden 플래그, 그것도 없으면 기본" 을 다시 쓰면 그 판정이 3벌(코어·게시판·
     * 이커머스 모달)이 되고, 한쪽만 고쳐졌을 때 그 화면만 다른 편집기를 그린다.
     *
     * 종전 플래그 `hidden_template_editor: true` 는 `none` 과 같은 뜻이다 — 그 플래그로
     * 이미 편집기를 감추고 있는 채널(외부 발송 채널)이 이 변경으로 기본 편집기를 다시
     * 드러내면 안 된다.
     *
     * 알 수 없는 값은 `plain` 으로 떨어뜨린다. 리치로 떨어뜨리면 평문으로 그려지는 채널의
     * 본문에 HTML 태그가 섞여 사용자 화면에 **태그가 글자 그대로** 보인다.
     *
     * @param  array<string, mixed>  $channel  채널 메타
     * @return string 편집기 종류
     */
    private function resolveTemplateEditor(array $channel): string
    {
        if (($channel['hidden_template_editor'] ?? false) === true) {
            return 'none';
        }

        $declared = $channel['template_editor'] ?? null;

        return in_array($declared, ['rich', 'plain', 'none'], true) ? $declared : 'plain';
    }

    /**
     * 특정 채널이 사용 가능한지 확인합니다.
     *
     * @param  string  $channelId  채널 식별자
     * @return bool 사용 가능 여부
     */
    public function isChannelAvailable(string $channelId): bool
    {
        $channels = $this->getAvailableChannels();

        return collect($channels)->contains('id', $channelId);
    }

    /**
     * 특정 채널이 비회원(게스트) 발송을 허용하는지 확인합니다.
     *
     * 채널 메타데이터의 `allow_guest` 플래그를 조회합니다 (config 기본 채널 +
     * `core.notification.filter_available_channels` 훅으로 추가된 모듈/플러그인 채널 공통).
     * 미선언 채널은 기본 `false`(차단) — 비회원 개인정보(이메일 등)가 의도치 않게
     * 새 프로바이더로 노출되는 것을 방지하기 위한 opt-in 정책입니다.
     * (확장 단위 채널 활성 `isChannelEnabledForExtension` 의 "미선언=활성" 과 반대 방향)
     *
     * `core.notification.channel_guest_allowed` 필터 훅으로 동적 재정의 가능합니다.
     *
     * @param  string  $channelId  채널 식별자 (mail, database, sms 등)
     * @return bool true = 비회원 발송 허용, false = 차단
     */
    public function isChannelGuestAllowed(string $channelId): bool
    {
        $channels = $this->getAvailableChannels();

        $allow = false;
        foreach ($channels as $channel) {
            if (($channel['id'] ?? null) === $channelId) {
                $allow = (bool) ($channel['allow_guest'] ?? false);
                break;
            }
        }

        return (bool) HookManager::applyFilters(
            'core.notification.channel_guest_allowed',
            $allow,
            $channelId
        );
    }

    /**
     * 확장(core/module/plugin) 단위의 채널 전역 활성 여부를 확인합니다.
     *
     * 코어 환경설정 / 모듈 환경설정 / 플러그인 환경설정의 "알림 채널 관리"
     * 섹션에서 토글된 `notifications.channels[*].is_active` 값을 조회합니다.
     *
     * 엔트리 자체가 저장되어 있지 않으면 `true`(활성)를 반환합니다 —
     * 하위호환과 플러그인이 신규 채널을 추가했을 때 기본 활성을 보장합니다.
     *
     * `core.notification.channel_enabled` 필터 훅으로 재정의 가능합니다.
     *
     * @param  string  $extensionType  확장 타입 (core, module, plugin)
     * @param  string|null  $extensionIdentifier  확장 식별자 (core는 'core' 허용)
     * @param  string  $channelId  채널 식별자 (mail, database 등)
     * @return bool true = 발송 허용, false = 발송 차단
     */
    public function isChannelEnabledForExtension(
        string $extensionType,
        ?string $extensionIdentifier,
        string $channelId
    ): bool {
        $cacheKey = $extensionType.'|'.($extensionIdentifier ?? '').'|'.$channelId;

        if (array_key_exists($cacheKey, $this->channelEnabledCache)) {
            return $this->channelEnabledCache[$cacheKey];
        }

        $channels = $this->resolveExtensionChannels($extensionType, $extensionIdentifier);
        $enabled = $this->extractChannelActive($channels, $channelId);

        $enabled = HookManager::applyFilters(
            'core.notification.channel_enabled',
            $enabled,
            $extensionType,
            $extensionIdentifier,
            $channelId
        );

        $this->channelEnabledCache[$cacheKey] = (bool) $enabled;

        return $this->channelEnabledCache[$cacheKey];
    }

    /**
     * 알림 한 건의 발송 채널 후보를 결정합니다 (채널 후보의 코어 단일 진입점).
     *
     * 채널을 제공하는 확장(문자·알림톡·앱 푸시 등)은 도메인을 알지 못한 채 코어 훅 하나에만
     * 답하고, 도메인(코어 인증·게시판·이커머스 등, 앞으로 생길 도메인 포함)은 이 메서드로 후보를
     * 받습니다. 순서:
     *
     * 1. `core.notification.channels` (filter) — 채널 제공 확장이 모든 도메인 공통으로 후보를 더한다.
     *    인자: `(array $channels, string $type, ?object $notifiable, string $hookPrefix)`.
     * 2. `{hookPrefix}.notification.channels` (filter) — 도메인이 자기 설정으로 후보를 좁히거나 더한다.
     *    인자: `(array $channels, string $type, ?object $notifiable)` (종전과 같음).
     *
     * 확장 단위 활성·준비 상태·게스트 허용 판정은 여기서 하지 않습니다 — 발송 경로가 이 결과에
     * 이어서 적용합니다.
     *
     * @param  string  $hookPrefix  알림 정의의 훅 접두사 (core.auth, 도메인 확장 식별자 등)
     * @param  array<int, string>  $channels  정의가 선언한 기본 채널 후보
     * @param  string  $type  알림 정의 유형
     * @param  object|null  $notifiable  수신자 (화면 표시용 계산처럼 수신자가 없으면 null)
     * @return array<int, string> 최종 채널 후보
     */
    public function resolveChannelCandidates(
        string $hookPrefix,
        array $channels,
        string $type,
        ?object $notifiable = null
    ): array {
        $channels = HookManager::applyFilters(
            'core.notification.channels',
            $channels,
            $type,
            $notifiable,
            $hookPrefix
        );

        $channels = HookManager::applyFilters(
            "{$hookPrefix}.notification.channels",
            is_array($channels) ? $channels : [],
            $type,
            $notifiable
        );

        return is_array($channels) ? array_values($channels) : [];
    }

    /**
     * 메모이제이션 캐시를 초기화합니다. (테스트 및 설정 변경 후 사용)
     *
     * @return void 반환값 없음
     */
    public function clearChannelEnabledCache(): void
    {
        $this->channelEnabledCache = [];
    }

    /**
     * 확장 타입별 notifications.channels 배열을 조회합니다.
     *
     * @param  string  $extensionType  확장 타입 (`core` / `module` / `plugin`)
     * @param  string|null  $extensionIdentifier  확장 식별자 (core 는 불필요)
     * @return array 채널 설정 배열 (조회 실패·미설정이면 빈 배열)
     */
    private function resolveExtensionChannels(string $extensionType, ?string $extensionIdentifier): array
    {
        try {
            switch ($extensionType) {
                case 'core':
                    $settingsService = app(SettingsService::class);
                    $value = $settingsService->getSetting('notifications.channels', []);

                    return is_array($value) ? $value : [];

                case 'module':
                    if (empty($extensionIdentifier)) {
                        return [];
                    }
                    $moduleSettings = app(ModuleSettingsService::class);
                    $value = $moduleSettings->get($extensionIdentifier, 'notifications.channels', []);

                    return is_array($value) ? $value : [];

                case 'plugin':
                    if (empty($extensionIdentifier)) {
                        return [];
                    }
                    $pluginSettings = app(PluginSettingsService::class);
                    $value = $pluginSettings->get($extensionIdentifier, 'notifications.channels', []);

                    return is_array($value) ? $value : [];
            }
        } catch (\Throwable $e) {
            // 설정 조회 실패 시 기본 활성 동작을 위해 빈 배열 반환
            return [];
        }

        return [];
    }

    /**
     * notifications.channels 배열에서 특정 채널의 is_active 값을 추출합니다.
     *
     * 엔트리가 없으면 true(기본 활성)를 반환합니다.
     *
     * @param  array  $channels  채널 설정 배열
     * @param  string  $channelId  채널 식별자
     * @return bool 활성 여부
     */
    private function extractChannelActive(array $channels, string $channelId): bool
    {
        foreach ($channels as $entry) {
            $id = Arr::get($entry, 'id');
            if ($id === $channelId) {
                return (bool) Arr::get($entry, 'is_active', true);
            }
        }

        return true;
    }
}
