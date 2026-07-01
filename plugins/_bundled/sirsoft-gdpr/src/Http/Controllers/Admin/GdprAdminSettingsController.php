<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Gdpr\Http\Requests\UpdateAdminSettingsRequest;
use Plugins\Sirsoft\Gdpr\Services\GdprSettingsService;

/**
 * GDPR 관리자 설정 컨트롤러
 *
 * - GET  /api/plugins/sirsoft-gdpr/admin/settings : 전체 설정 조회 (3탭)
 * - PUT  /api/plugins/sirsoft-gdpr/admin/settings : 설정 저장 (정책 버전 발행 없음 — 수동 발행 전용)
 *
 * 권한: settings.manage (라우트 미들웨어에서 검증)
 *
 * 정책 버전 발행은 본 컨트롤러의 책임이 아닙니다. 운영자가 「+ 새 버전 발행」
 * (POST /admin/policy-versions) 으로 명시 트리거합니다 — GdprAdminPolicyVersionController.
 */
class GdprAdminSettingsController extends AdminBaseController
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-gdpr';

    /**
     * GdprAdminSettingsController 생성자
     *
     * @param PluginSettingsService $pluginSettings 플러그인 설정 서비스 (코어 — 조회용)
     * @param GdprSettingsService $settingsService GDPR 설정 저장 서비스
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettings,
        private readonly GdprSettingsService $settingsService,
    ) {
        parent::__construct();
    }

    /**
     * 현재 GDPR 플러그인 설정 전체를 반환합니다 (관리자 화면 폼 바인딩용).
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $settings = $this->pluginSettings->get(self::PLUGIN_ID);

        $settings = $this->normalizeJsonFields(is_array($settings) ? $settings : []);

        return ResponseHelper::success('messages.success', [
            'settings' => $settings,
        ]);
    }

    /**
     * GDPR 플러그인 설정을 저장합니다.
     *
     * 정책 버전은 자동 발행되지 않습니다. 운영자가 별도로
     * 「+ 새 버전 발행」 을 클릭해야 발행됩니다.
     *
     * @param UpdateAdminSettingsRequest $request 검증된 요청
     * @return JsonResponse
     */
    public function update(UpdateAdminSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // 동적 스키마 fallback (validation.md "동적 스키마 기반 FormRequest 패턴")
        if (empty($validated)) {
            $validated = $request->all();
        }

        // 옛 자동 발행 흐름의 change_memo 필드는 더 이상 사용하지 않음 — 정책 버전 발행은 별도 엔드포인트로 일원화
        unset($validated['change_memo']);

        $result = $this->settingsService->saveAdminSettings($validated);

        return ResponseHelper::success('sirsoft-gdpr::messages.settings.saved', [
            'settings' => $this->normalizeJsonFields($result['settings']),
        ]);
    }

    /**
     * 응답에서 JSON 필드는 디코드하여 객체/배열로 노출합니다.
     *
     * @param array<string, mixed> $settings 설정 배열
     * @return array<string, mixed>
     */
    private function normalizeJsonFields(array $settings): array
    {
        foreach (['cookie_categories'] as $jsonKey) {
            if (! array_key_exists($jsonKey, $settings)) {
                continue;
            }

            $value = $settings[$jsonKey];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $settings[$jsonKey] = is_array($decoded) ? $decoded : [];
            }
        }

        return $settings;
    }
}
