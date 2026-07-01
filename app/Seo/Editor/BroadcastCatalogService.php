<?php

namespace App\Seo\Editor;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;

/**
 * 웹소켓 채널/이벤트 카탈로그 수집 서비스 — 데이터소스 websocket 후보.
 *
 * 데이터소스 종류=websocket 편집 시 채널을 "구독 가능한 목록 기준 검색 드롭다운"으로
 * 제시하기 위해, "지금 이 설치본에서 구독 가능한 채널"을 런타임 수집한다. 코어/확장이
 * `getChannels()` 오버라이드로 등록한 채널명을 출처(core/module/plugin)와 함께 모은다
 * (CLAUDE.md 채널 프리픽스 규약 — 코어 `core.*`, 모듈 `module.{id}.*`, 플러그인
 * `plugin.{id}.*`).
 *
 * 이벤트는 `HookManager::broadcast($channel, $eventName, $payload)` 호출 시점에 이름이
 * 정해지는 동적 값이라 정적 레지스트리가 없다. 따라서 이벤트 카탈로그는 비우고
 * 편집기가 자유 텍스트로 폴백하게 한다(허위 이벤트 목록 생성 금지). 채널은 확실하므로
 * 검색 드롭다운으로 제공한다.
 *
 * Controller 가 가드(`core.templates.layouts.edit`)·응답을 담당하고 본 서비스는 수집만.
 *
 * @since 7.0.0-beta.?
 */
class BroadcastCatalogService
{
    /**
     * 코어가 `routes/channels.php` 에 등록한 채널 (편집기 후보용 화이트리스트).
     *
     * `App.Models.User.{id}` 같은 Laravel 내부 채널은 데이터소스 구독 대상이 아니라 제외.
     * 코어 공개 실시간 채널만 노출한다.
     *
     * @var array<int, string>
     */
    private const CORE_CHANNELS = [
        'core.admin.dashboard',
        'core.user.notifications.{uuid}',
    ];

    public function __construct(
        private readonly ModuleManagerInterface $moduleManager,
        private readonly PluginManagerInterface $pluginManager,
    ) {}

    /**
     * 채널/이벤트 카탈로그를 수집합니다.
     *
     * @return array{channels: array<int, array{name: string, source: array}>, events: array}
     */
    public function collect(): array
    {
        $channels = [];

        // 코어 채널.
        foreach (self::CORE_CHANNELS as $name) {
            $channels[] = ['name' => $name, 'source' => ['kind' => 'core']];
        }

        // 모듈 채널.
        foreach ($this->moduleManager->getActiveModules() as $module) {
            foreach ($this->extractChannels($module) as $name) {
                $channels[] = [
                    'name' => $name,
                    'source' => ['kind' => 'module', 'identifier' => $module->getIdentifier()],
                ];
            }
        }

        // 플러그인 채널.
        foreach ($this->pluginManager->getActivePlugins() as $plugin) {
            foreach ($this->extractChannels($plugin) as $name) {
                $channels[] = [
                    'name' => $name,
                    'source' => ['kind' => 'plugin', 'identifier' => $plugin->getIdentifier()],
                ];
            }
        }

        return [
            'channels' => $channels,
            // 이벤트는 동적 발행이라 정적 카탈로그 없음 — 편집기 자유 텍스트 폴백.
            'events' => [],
        ];
    }

    /**
     * 확장의 `getChannels()` 에서 채널명 목록을 추출합니다(격리 호출).
     *
     * @param  object  $extension  확장 인스턴스
     * @return array<int, string> 채널명 목록
     */
    private function extractChannels(object $extension): array
    {
        if (! method_exists($extension, 'getChannels')) {
            return [];
        }
        try {
            $channels = $extension->getChannels();
        } catch (\Throwable) {
            return [];
        }
        if (! is_array($channels)) {
            return [];
        }

        $names = [];
        foreach (array_keys($channels) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
