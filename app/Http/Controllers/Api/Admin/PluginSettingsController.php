<?php

namespace App\Http\Controllers\Api\Admin;

use App\Extension\PluginManager;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\UpdatePluginSettingsRequest;
use App\Services\DriverRegistryService;
use App\Services\PluginSettingsService;
use App\Support\SensitiveSettingMask;
use Illuminate\Http\JsonResponse;

/**
 * 플러그인 설정 API 컨트롤러
 *
 * 플러그인의 설정 조회, 수정, 레이아웃 조회 기능을 제공합니다.
 */
class PluginSettingsController extends AdminBaseController
{
    /**
     * PluginSettingsController 생성자
     *
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     * @param  PluginManager  $pluginManager  설정 스키마(sensitive 플래그) 조회용
     */
    public function __construct(
        private PluginSettingsService $pluginSettingsService,
        private PluginManager $pluginManager
    ) {
        parent::__construct();
    }

    /**
     * 응답에 실을 설정에서 민감값을 마스크로 치환한다.
     *
     * 이 응답은 관리자 화면으로 나가므로 암호화 키·시크릿의 평문이 브라우저·개발자 도구·프록시
     * 로그에 남지 않아야 한다. 값이 저장되어 있다는 사실만 마스크로 알린다. 운영자가 값을 건드리지
     * 않으면 화면이 마스크를 그대로 되돌려 보내고, 저장 단계가 그것을 걸러 기존 값을 보존한다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  array<string, mixed>  $settings  복호화된 설정
     * @return array<string, mixed> 마스킹된 설정
     */
    private function maskSensitive(string $identifier, array $settings): array
    {
        $plugin = $this->pluginManager->getPlugin($identifier);

        return $plugin === null
            ? $settings
            : SensitiveSettingMask::apply($settings, $plugin->getSettingsSchema());
    }

    /**
     * 설정 스키마가 public_asset_disk 키를 선언한 플러그인의 응답에
     * 공개 자산 디스크 카탈로그(available_public_asset_disks)를 부착합니다.
     *
     * 화면이 /api/admin/settings(core.settings.read)를 따로 조회하게 두면 이
     * 화면의 권한(core.plugins.read)과 표면이 갈려, 커스텀 역할에서 카탈로그만
     * 조용히 비는 비대칭이 생깁니다. 설정 응답 단일 표면에서 함께 내려 권한 축을
     * 일치시킵니다 (ecommerce 모듈 설정 응답의 동명 키와 동형 계약).
     * 저장 시에는 스키마 기반 검증 whitelist 가 이 키를 걸러 설정에 남지 않습니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  array<string, mixed>  $settings  응답에 실을 설정
     * @return array<string, mixed> 카탈로그가 부착된 설정
     */
    private function withPublicAssetCatalog(string $identifier, array $settings): array
    {
        $plugin = $this->pluginManager->getPlugin($identifier);

        if ($plugin === null || ! array_key_exists('public_asset_disk', $plugin->getSettingsSchema())) {
            return $settings;
        }

        $settings['available_public_asset_disks'] = app(DriverRegistryService::class)
            ->getAvailableDrivers('public_asset');

        return $settings;
    }

    /**
     * 플러그인 설정을 조회합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return JsonResponse 설정 데이터
     */
    public function show(string $identifier): JsonResponse
    {
        $settings = $this->pluginSettingsService->get($identifier);

        if ($settings === null) {
            return $this->notFound('plugins.not_found');
        }

        return $this->success(
            'common.success',
            $this->withPublicAssetCatalog($identifier, $this->maskSensitive($identifier, $settings))
        );
    }

    /**
     * 플러그인 설정을 업데이트합니다.
     *
     * @param  UpdatePluginSettingsRequest  $request  검증된 요청
     * @param  string  $identifier  플러그인 식별자
     * @return JsonResponse 업데이트 결과
     */
    public function update(UpdatePluginSettingsRequest $request, string $identifier): JsonResponse
    {
        // 검증을 통과한 필드만 저장한다. 과거에는 validated() 가 비면 all() 로 폴백했으나,
        // 그 명분이던 "PluginManager 미등록 플러그인" 은 PluginSettingsService::save() 가
        // 이미 false 로 차단하므로 도달할 수 없었고, 실제로는 설정 스키마 밖의 키가
        // 그대로 설정 파일에 병합되는 경로로만 동작했다 (mass-assignment).
        $settings = $request->validated();

        $result = $this->pluginSettingsService->save($identifier, $settings, $failureReason);

        if (! $result) {
            return $this->error('plugins.settings.update_failed', 500, null, [
                'error' => $failureReason ?? __('plugins.errors.unknown_error'),
            ]);
        }

        // 저장 응답에도 카탈로그 재부착 — 화면 폼 상태가 응답으로 갱신되므로
        // 누락 시 저장 직후 선택지가 비어 보인다 (ecommerce 저장 응답과 동형)
        return $this->success(
            'plugins.settings.updated',
            $this->withPublicAssetCatalog(
                $identifier,
                $this->maskSensitive($identifier, $this->pluginSettingsService->get($identifier) ?? [])
            )
        );
    }

    /**
     * 플러그인 설정 레이아웃을 조회합니다.
     *
     * 설정 페이지 UI 구성과 설정 스키마를 반환합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return JsonResponse 레이아웃 데이터
     */
    public function layout(string $identifier): JsonResponse
    {
        $layout = $this->pluginSettingsService->getLayout($identifier);

        if ($layout === null) {
            return $this->notFound('plugins.not_found');
        }

        return $this->success('common.success', $layout);
    }
}
