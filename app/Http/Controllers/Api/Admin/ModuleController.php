<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LanguagePackScope;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Controllers\Concerns\InjectsExtensionLanguagePacks;
use App\Http\Controllers\Concerns\OrchestratesCascadeInstall;
use App\Http\Requests\Module\ActivateModuleRequest;
use App\Http\Requests\Module\DeactivateModuleRequest;
use App\Http\Requests\Module\IndexModuleRequest;
use App\Http\Requests\Module\InstallModuleFromFileRequest;
use App\Http\Requests\Module\InstallModuleFromGithubRequest;
use App\Http\Requests\Module\InstallModuleRequest;
use App\Http\Requests\Module\PerformModuleUpdateRequest;
use App\Http\Requests\Module\PreviewModuleManifestRequest;
use App\Http\Requests\Module\RefreshModuleLayoutsRequest;
use App\Http\Requests\Module\UninstallModuleRequest;
use App\Http\Requests\Extension\ChangelogRequest;
use App\Http\Resources\ModuleCollection;
use App\Http\Resources\ModuleResource;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use App\Services\LicenseService;
use App\Services\ModuleService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 모듈 관리 컨트롤러
 *
 * 관리자가 시스템 모듈을 설치, 활성화, 비활성화, 제거할 수 있는 기능을 제공합니다.
 */
class ModuleController extends AdminBaseController
{
    use InjectsExtensionLanguagePacks;
    use OrchestratesCascadeInstall;

    public function __construct(
        private ModuleService $moduleService,
        private TemplateService $templateService,
        private LicenseService $licenseService
    ) {
        parent::__construct();
    }

    /**
     * 모든 모듈 목록을 조회합니다 (설치된 모듈과 미설치 모듈 포함).
     *
     * 페이지네이션 및 다중 검색 조건을 지원합니다.
     * - search: 단일 검색어 (이름, 식별자, 설명, 벤더 OR 검색)
     * - filters: 다중 검색 조건 (AND 조건)
     * - with[]: 추가 데이터 포함 (예: custom_menus)
     *
     * @param  IndexModuleRequest  $request  모듈 목록 조회 요청
     * @return JsonResponse 모듈 목록을 포함한 JSON 응답
     */
    public function index(IndexModuleRequest $request): JsonResponse
    {
        try {
            $responseData = $this->moduleService->getIndexData(
                $request->validated(),
                $request->hasWithOption('custom_menus')
            );

            // ModuleCollection 변환 (전체 모듈 목록 반환 시에만)
            if (! empty($responseData['data'])) {
                $collection = new ModuleCollection(collect($responseData['data']));
                $responseData['data'] = $collection->toArray($request)['data'];
                $responseData['meta'] = $collection->with($request)['meta'];
                $responseData['abilities'] = $collection->resolveCollectionAbilities($request);
            }

            return $this->success('module.fetch_success', $responseData);
        } catch (\Exception $e) {
            return $this->error('module.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 설치된 모듈만 조회합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return JsonResponse 설치된 모듈 목록을 포함한 JSON 응답
     */
    // audit:allow controller-base-request-injection reason: GET 목록 조회. ModuleCollection::toArray($request)/with($request) 전달용
    public function installed(Request $request): JsonResponse
    {
        try {
            $modules = $this->moduleService->getInstalledModulesOnly();

            $collection = new ModuleCollection(collect($modules));

            return $this->success('module.fetch_success', [
                'data' => $collection->toArray($request)['data'],
                'meta' => $collection->with($request)['meta'],
            ]);
        } catch (\Exception $e) {
            return $this->error('module.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 미설치 모듈만 조회합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return JsonResponse 미설치 모듈 목록을 포함한 JSON 응답
     */
    // audit:allow controller-base-request-injection reason: GET 목록 조회. ModuleCollection::toArray($request)/with($request) 전달용
    public function uninstalled(Request $request): JsonResponse
    {
        try {
            $modules = $this->moduleService->getUninstalledModulesOnly();

            $collection = new ModuleCollection(collect($modules));

            return $this->success('module.fetch_success', [
                'data' => $collection->toArray($request)['data'],
                'meta' => $collection->with($request)['meta'],
            ]);
        } catch (\Exception $e) {
            return $this->error('module.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 모듈의 상세 정보를 조회합니다.
     *
     * @param  Request  $request  HTTP 요청 (attachLanguagePacks 의 Request 인자 전달용)
     * @param  string  $moduleName  모듈 식별자
     * @return JsonResponse 모듈 정보를 포함한 JSON 응답
     */
    // audit:allow controller-base-request-injection reason: GET 상세 조회. attachLanguagePacks($detail, scope, name, $request) 전달용
    public function show(Request $request, string $moduleName): JsonResponse
    {
        try {
            $moduleInfo = $this->moduleService->getModuleInfo($moduleName);

            if (! $moduleInfo) {
                return $this->error('module.not_found', 404, null, ['module' => $moduleName]);
            }

            // 상세 정보는 toDetailArray() 메서드 사용 + 지원 언어팩 주입
            $resource = new ModuleResource($moduleInfo);
            $detail = $this->attachLanguagePacks(
                $resource->toDetailArray(),
                LanguagePackScope::Module,
                $moduleName,
                $request,
            );

            return $this->success('module.fetch_success', $detail);
        } catch (\Exception $e) {
            return $this->error('module.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 모듈 설치 cascade 프리뷰를 반환합니다 (의존 확장 + 동반 가능 번들 언어팩).
     *
     * 인스톨 모달 오픈 시 호출되어 사용자가 선택할 cascade 후보 트리를 노출합니다.
     * `manifest-preview` (POST + ZIP 업로드) 와는 다른 목적의 GET 식별자 기반 API.
     *
     * @param  string  $moduleName  모듈 식별자
     * @param  ExtensionInstallPreviewBuilder  $builder  프리뷰 빌더
     * @return JsonResponse cascade 프리뷰 응답
     */
    public function installPreview(string $moduleName, ExtensionInstallPreviewBuilder $builder): JsonResponse
    {
        try {
            $preview = $builder->build(\App\Enums\LanguagePackScope::Module, $moduleName);

            return $this->success('module.fetch_success', $preview);
        } catch (\Exception $e) {
            return $this->error('module.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 모듈을 시스템에 설치합니다.
     *
     * @param  InstallModuleRequest  $request  모듈 설치 요청 데이터
     * @return JsonResponse 설치된 모듈 정보를 포함한 JSON 응답
     */
    public function install(InstallModuleRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $moduleName = $validated['module_name'];
            $vendorMode = \App\Extension\Vendor\VendorMode::fromStringOrAuto(
                $validated['vendor_mode'] ?? null
            );

            // cascade 1단계: 사용자가 선택한 의존 확장 사전 설치 (실패 시 abort)
            $this->installSelectedDependencies($validated['dependencies'] ?? []);

            $module = $this->moduleService->installModule($moduleName, $vendorMode);

            if ($module) {
                // cascade 2단계: 동반 번들 언어팩 best-effort 설치
                $lpFailures = $this->installSelectedLanguagePacks($validated['language_packs'] ?? []);

                $payload = (new ModuleResource($module))->toArray($request);
                $payload['language_pack_failures'] = $lpFailures;

                return $this->success('module.install_success', $payload, 201);
            } else {
                return $this->error('module.install_failed');
            }
        } catch (ValidationException $e) {
            // Service에서 이미 번역된 메시지를 errors에 포함하므로
            // 첫 번째 에러를 top-level message로 직접 사용 (이중 래핑 방지)
            $firstError = collect($e->errors())->flatten()->first()
                ?? __('module.install_failed');

            return $this->validationError($e->errors(), $firstError);
        } catch (\Exception $e) {
            return $this->error('modules.installation_failed', 500, $e->getMessage(), [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모듈을 활성화합니다.
     *
     * force 파라미터가 없고 필요한 의존성이 충족되지 않은 경우 경고를 반환합니다.
     *
     * @param  ActivateModuleRequest  $request  모듈 활성화 요청 데이터
     * @return JsonResponse 활성화된 모듈 정보를 포함한 JSON 응답
     */
    public function activate(ActivateModuleRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $moduleName = $validated['module_name'];
            $force = $validated['force'] ?? false;

            $result = $this->moduleService->activateModule($moduleName, $force);

            // 경고 응답인 경우 (필요 의존성 미충족) - 활성화 실패로 처리
            if (isset($result['warning']) && $result['warning'] === true) {
                return $this->error('modules.activate_warning', 409, [
                    'warning' => true,
                    'missing_modules' => $result['missing_modules'] ?? [],
                    'missing_plugins' => $result['missing_plugins'] ?? [],
                    'message' => $result['message'],
                ]);
            }

            if ($result['success']) {
                $moduleInfo = $result['module_info'] ?? null;

                // PO #7: 재활성화 시 cascade 비활성화됐던 언어팩 목록 응답에 포함 (PO #8: 빈 배열이면 모달 표시 안 함)
                $pendingLanguagePacks = app(\App\Services\LanguagePack\LanguagePackBundledRegistrar::class)
                    ->getPendingForReactivation('module', $moduleName);

                if ($moduleInfo) {
                    return $this->success('module.activate_success', [
                        'module' => (new ModuleResource($moduleInfo))->resolve(),
                        'pending_language_packs' => $pendingLanguagePacks,
                    ]);
                }

                return $this->success('module.activate_success', array_merge($result, [
                    'pending_language_packs' => $pendingLanguagePacks,
                ]));
            } else {
                return $this->error('module.activate_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('module.activate_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('module.activate_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 모듈을 비활성화합니다.
     *
     * force 파라미터가 없고 의존하는 확장이 있는 경우 경고를 반환합니다.
     *
     * @param  DeactivateModuleRequest  $request  모듈 비활성화 요청 데이터
     * @return JsonResponse 비활성화된 모듈 정보를 포함한 JSON 응답
     */
    public function deactivate(DeactivateModuleRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $moduleName = $validated['module_name'];
            $force = $validated['force'] ?? false;

            $result = $this->moduleService->deactivateModule($moduleName, $force);

            // 경고 응답인 경우 (의존 확장 존재) - 비활성화 실패로 처리
            if (isset($result['warning']) && $result['warning'] === true) {
                return $this->error('modules.deactivate_warning', 409, [
                    'warning' => true,
                    'dependent_templates' => $result['dependent_templates'] ?? [],
                    'dependent_modules' => $result['dependent_modules'] ?? [],
                    'dependent_plugins' => $result['dependent_plugins'] ?? [],
                    'message' => $result['message'],
                ]);
            }

            if ($result['success']) {
                $moduleInfo = $result['module_info'] ?? null;

                if ($moduleInfo) {
                    return $this->successWithResource(
                        'module.deactivate_success',
                        new ModuleResource($moduleInfo)
                    );
                }

                return $this->success('module.deactivate_success', $result);
            } else {
                return $this->error('module.deactivate_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('module.deactivate_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('module.deactivate_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 모듈에 의존하는 템플릿 목록을 조회합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return JsonResponse 의존 템플릿 목록을 포함한 JSON 응답
     */
    public function dependentTemplates(string $identifier): JsonResponse
    {
        try {
            $dependentTemplates = $this->templateService->getTemplatesDependingOnModule($identifier);

            return $this->success('module.dependent_templates_success', [
                'data' => $dependentTemplates,
                'total' => count($dependentTemplates),
            ]);
        } catch (\Exception $e) {
            return $this->error('module.dependent_templates_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 모듈 삭제 시 삭제될 데이터 정보를 조회합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return JsonResponse 삭제 정보를 포함한 JSON 응답
     */
    public function uninstallInfo(string $moduleName): JsonResponse
    {
        try {
            $uninstallInfo = $this->moduleService->getModuleUninstallInfo($moduleName);

            if (! $uninstallInfo) {
                return $this->error('module.not_found', 404, null, ['module' => $moduleName]);
            }

            return $this->success('module.uninstall_info_success', $uninstallInfo);
        } catch (\Exception $e) {
            return $this->error('module.uninstall_info_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 모듈을 시스템에서 제거합니다.
     *
     * @param  UninstallModuleRequest  $request  모듈 제거 요청 데이터
     * @return JsonResponse 제거 결과 JSON 응답
     */
    public function uninstall(UninstallModuleRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $moduleName = $validated['module_name'];
            $deleteData = $validated['delete_data'] ?? false;

            $result = $this->moduleService->uninstallModule($moduleName, $deleteData);

            if ($result) {
                return $this->success('module.uninstall_success');
            } else {
                return $this->error('module.uninstall_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('module.uninstall_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('module.uninstall_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 업로드된 ZIP 의 manifest 와 검증 결과만 추출합니다 (실제 설치 X).
     *
     * 사용자가 모듈 설치 전 module.json 검증 실패 사유를 미리 확인할 수 있도록 합니다.
     *
     * @param  PreviewModuleManifestRequest  $request  미리보기 요청
     * @return JsonResponse manifest + validation 결과
     */
    public function manifestPreview(PreviewModuleManifestRequest $request): JsonResponse
    {
        try {
            $result = $this->moduleService->previewManifest($request->file('file'));

            return $this->success('module.preview_success', $result);
        } catch (\Throwable $e) {
            return $this->error('module.preview_failed', 422, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * ZIP 파일에서 모듈을 설치합니다.
     *
     * @param  InstallModuleFromFileRequest  $request  파일 설치 요청 데이터
     * @return JsonResponse 설치된 모듈 정보를 포함한 JSON 응답
     */
    public function installFromFile(InstallModuleFromFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $module = $this->moduleService->installFromZipFile($file);

            return $this->successWithResource(
                'module.install_success',
                new ModuleResource($module),
                201
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('module.install_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * GitHub 저장소에서 모듈을 설치합니다.
     *
     * @param  InstallModuleFromGithubRequest  $request  GitHub 설치 요청 데이터
     * @return JsonResponse 설치된 모듈 정보를 포함한 JSON 응답
     */
    public function installFromGithub(InstallModuleFromGithubRequest $request): JsonResponse
    {
        try {
            $githubUrl = $request->validated()['github_url'];
            $module = $this->moduleService->installFromGithub($githubUrl);

            return $this->successWithResource(
                'module.install_success',
                new ModuleResource($module),
                201
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('module.install_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 설치된 모든 모듈의 업데이트를 확인합니다.
     *
     * @return JsonResponse 업데이트 확인 결과 JSON 응답
     */
    public function checkUpdates(): JsonResponse
    {
        try {
            $result = $this->moduleService->checkForUpdates();

            return $this->success('modules.check_updates_success', $result);
        } catch (ValidationException $e) {
            return $this->error('modules.check_updates_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('modules.check_updates_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 모듈의 수정된 레이아웃을 확인합니다.
     *
     * 업데이트 전 사용자가 수정한 레이아웃이 있는지 확인하여
     * 레이아웃 전략(overwrite/keep) 선택에 참고할 수 있도록 합니다.
     *
     * @param  string  $moduleName  모듈 식별자
     * @return JsonResponse 수정된 레이아웃 정보를 포함한 JSON 응답
     */
    public function checkModifiedLayouts(string $moduleName): JsonResponse
    {
        try {
            $result = $this->moduleService->checkModifiedLayouts($moduleName);

            return $this->success('modules.check_modified_layouts_success', $result);
        } catch (ValidationException $e) {
            return $this->error('modules.check_modified_layouts_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('modules.check_modified_layouts_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 모듈을 업데이트합니다.
     *
     * layout_strategy 파라미터로 레이아웃 처리 방식을 결정합니다:
     * - overwrite: 모든 레이아웃을 새 버전으로 교체
     * - keep: 사용자가 수정한 레이아웃을 유지
     *
     * @param  PerformModuleUpdateRequest  $request  업데이트 요청 데이터
     * @param  string  $moduleName  업데이트할 모듈 identifier
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function performUpdate(PerformModuleUpdateRequest $request, string $moduleName): JsonResponse
    {
        try {
            $validated = $request->validated();
            $vendorMode = \App\Extension\Vendor\VendorMode::fromStringOrAuto(
                $validated['vendor_mode'] ?? null
            );
            $layoutStrategy = $validated['layout_strategy'] ?? 'overwrite';
            $force = (bool) ($validated['force'] ?? false);
            $result = $this->moduleService->updateModule($moduleName, $vendorMode, $layoutStrategy, $force);

            $moduleInfo = $result['module_info'] ?? null;

            if ($moduleInfo) {
                return $this->successWithResource(
                    'modules.update_success',
                    new ModuleResource($moduleInfo)
                );
            }

            return $this->success('modules.update_success', $result);
        } catch (ValidationException $e) {
            // Service/Manager에서 이미 번역된 메시지를 errors에 포함하므로
            // 첫 번째 에러를 top-level message로 직접 사용 (이중 래핑 방지)
            $firstError = collect($e->errors())->flatten()->first()
                ?? __('modules.errors.update_failed', ['module' => $moduleName, 'error' => '']);

            return $this->validationError($e->errors(), $firstError);
        } catch (\Exception $e) {
            return $this->error('modules.errors.update_failed', 500, $e->getMessage(), [
                'module' => $moduleName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모듈의 레이아웃을 파일에서 다시 읽어 갱신합니다.
     *
     * @param  RefreshModuleLayoutsRequest  $request  레이아웃 갱신 요청 데이터
     * @return JsonResponse 갱신된 모듈 정보를 포함한 JSON 응답
     */
    public function refreshLayouts(RefreshModuleLayoutsRequest $request): JsonResponse
    {
        try {
            $moduleName = $request->validated()['module_name'];
            $module = $this->moduleService->refreshModuleLayouts($moduleName);

            if ($module) {
                return $this->successWithResource(
                    'module.refresh_layouts_success',
                    new ModuleResource($module)
                );
            } else {
                return $this->error('module.refresh_layouts_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('module.refresh_layouts_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('module.refresh_layouts_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 모듈의 변경 내역(changelog)을 조회합니다.
     *
     * @param  ChangelogRequest  $request  검증된 요청
     * @param  string  $identifier  모듈 식별자
     * @return JsonResponse 변경 내역을 포함한 JSON 응답
     */
    public function changelog(ChangelogRequest $request, string $identifier): JsonResponse
    {
        try {
            $validated = $request->validated();
            $changelog = $this->moduleService->getModuleChangelog(
                $identifier,
                $validated['source'] ?? null,
                $validated['from_version'] ?? null,
                $validated['to_version'] ?? null,
            );

            return $this->success('module.fetch_success', ['changelog' => $changelog]);
        } catch (\Exception $e) {
            return $this->error('module.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 모듈의 라이선스 파일 내용을 반환합니다.
     *
     * @param string $identifier 모듈 식별자
     * @return JsonResponse
     */
    public function license(string $identifier): JsonResponse
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $identifier)) {
            return $this->error('module.license_not_found', 404);
        }

        $content = $this->licenseService->getExtensionLicense('modules', $identifier);

        if ($content === null) {
            return $this->error('module.license_not_found', 404);
        }

        return $this->success('module.fetch_success', [
            'content' => $content,
        ]);
    }
}
