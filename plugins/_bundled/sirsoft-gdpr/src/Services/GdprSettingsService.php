<?php

namespace Plugins\Sirsoft\Gdpr\Services;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;

/**
 * GDPR 관리자 설정 저장 서비스
 *
 * Controller 가 검증된 입력을 본 Service 에 위임하여 settings 를 저장합니다.
 *
 * 정책 버전 발행은 본 Service 의 책임이 아닙니다 — 운영자가 「+ 새 버전 발행」
 * (POST /admin/policy-versions) 으로 명시적으로 트리거합니다 (수동 발행 모델).
 * 자동 분기는 운영자가 "내가 안 누른 발행" 을 인지하지 못하는 회귀를 만들어
 * GDPR Art.30 처리 기록 의무의 *변경 사유* 입증을 약화시키므로 폐기되었습니다.
 */
class GdprSettingsService
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-gdpr';

    /**
     * snapshot 비교/저장 대상 키 화이트리스트.
     *
     * 운영자가 「+ 새 버전 발행」 클릭 시 현재 settings 상태를
     * 새 정책 버전 row 의 snapshot 으로 보존하기 위한 키 화이트리스트.
     *
     * @var array<int, string>
     */
    private const SNAPSHOT_KEYS = [
        'cookie_categories',
        'blocked_domains',
        'privacy_policy_slug',
        'legal_entity_name',
        'data_storage_location',
    ];

    /**
     * GdprSettingsService 생성자
     *
     * @param PluginSettingsService $pluginSettings 코어 플러그인 설정 서비스
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 관리자 설정을 저장합니다.
     *
     * 흐름:
     * 1. 입력값 정규화 (cookie_categories JSON 직렬화 등)
     * 2. settings 저장 (PluginSettingsService)
     *
     * 정책 버전은 자동 발행하지 않습니다. 운영자가 별도로
     * 「+ 새 버전 발행」 을 클릭해야만 새 버전이 발행됩니다.
     *
     * 훅:
     * - before_save: 저장 직전
     * - filter_save_payload: 저장 페이로드 필터 가능
     * - after_save: 저장 후
     *
     * @param array<string, mixed> $input 검증된 FormRequest 입력
     * @return array{settings: array<string, mixed>}
     */
    public function saveAdminSettings(array $input): array
    {
        $payload = $this->preparePayload($input);

        HookManager::doAction(self::PLUGIN_ID.'.settings.before_save', $payload);
        $payload = HookManager::applyFilters(self::PLUGIN_ID.'.settings.filter_save_payload', $payload);

        $this->pluginSettings->save(self::PLUGIN_ID, $payload);

        $savedSettings = $this->loadCurrentSettings();

        HookManager::doAction(self::PLUGIN_ID.'.settings.after_save', $savedSettings);

        return [
            'settings' => $savedSettings,
        ];
    }

    /**
     * 현재 settings 의 snapshot 을 반환합니다 (정책 의미 키 화이트리스트 한정).
     *
     * 운영자 수동 정책 버전 발행 시 *현재 settings 상태 그대로* 스냅샷으로 보존하기 위해 사용.
     *
     * @return array<string, mixed>
     */
    public function getCurrentSnapshot(): array
    {
        return $this->buildSnapshot($this->loadCurrentSettings());
    }

    /**
     * 현재 settings 를 array 로 로드합니다.
     *
     * cookie_categories 는 JSON 문자열로 저장되어 있으므로 디코드합니다.
     *
     * @return array<string, mixed>
     */
    private function loadCurrentSettings(): array
    {
        $settings = $this->pluginSettings->get(self::PLUGIN_ID);
        $settings = is_array($settings) ? $settings : [];

        if (isset($settings['cookie_categories']) && is_string($settings['cookie_categories'])) {
            $decoded = json_decode($settings['cookie_categories'], true);
            $settings['cookie_categories'] = is_array($decoded) ? $decoded : [];
        }

        return $settings;
    }

    /**
     * 저장 페이로드를 준비합니다 (cookie_categories JSON 직렬화 포함).
     *
     * @param array<string, mixed> $input 검증된 입력
     * @return array<string, mixed>
     */
    private function preparePayload(array $input): array
    {
        $payload = $input;

        if (isset($payload['cookie_categories']) && is_array($payload['cookie_categories'])) {
            $payload['cookie_categories'] = json_encode(
                array_values($payload['cookie_categories']),
                JSON_UNESCAPED_UNICODE,
            );
        }

        return $payload;
    }

    /**
     * settings 에서 snapshot 비교 대상 키만 추출합니다.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildSnapshot(array $settings): array
    {
        $snapshot = [];
        foreach (self::SNAPSHOT_KEYS as $key) {
            $snapshot[$key] = $settings[$key] ?? null;
        }

        return $snapshot;
    }
}
