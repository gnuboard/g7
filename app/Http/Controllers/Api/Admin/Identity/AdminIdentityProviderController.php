<?php

namespace App\Http\Controllers\Api\Admin\Identity;

use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Resources\Identity\ProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 관리자 — IDV 프로바이더 조회 + 설정 스키마 수집 컨트롤러.
 *
 * S1c 서브섹션의 프로바이더 카드 반복 렌더링용 API.
 * 각 프로바이더가 반환하는 `getSettingsSchema()` 를 `core.identity.settings_schema` 필터 훅으로
 * 확장 가능하도록 허용합니다.
 */
class AdminIdentityProviderController extends AdminBaseController
{
    /**
     * @param  IdentityVerificationManager  $manager  프로바이더 레지스트리 (Manager 는 Service 역할 겸함)
     */
    public function __construct(
        protected IdentityVerificationManager $manager,
    ) {}

    /**
     * 등록된 프로바이더 목록과 각 프로바이더의 설정 스키마를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 프로바이더 목록 (settings_schema 포함)
     */
    public function index(Request $request): JsonResponse
    {
        $providers = array_values($this->manager->all());

        $rows = array_map(function ($provider) use ($request) {
            $resource = (new ProviderResource($provider))->toArray($request);

            // 설정 스키마 포함 (관리자 UI 반복 렌더용)
            $schema = HookManager::applyFilters(
                'core.identity.settings_schema',
                $provider->getSettingsSchema(),
                $provider->getId(),
            );

            return $resource + ['settings_schema' => is_array($schema) ? $schema : []];
        }, $providers);

        return $this->success('messages.success', $rows);
    }
}
