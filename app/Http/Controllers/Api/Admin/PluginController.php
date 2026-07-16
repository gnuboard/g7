<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LanguagePackScope;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Controllers\Concerns\InjectsExtensionLanguagePacks;
use App\Http\Controllers\Concerns\OrchestratesCascadeInstall;
use App\Http\Requests\Plugin\ActivatePluginRequest;
use App\Http\Requests\Plugin\DeactivatePluginRequest;
use App\Http\Requests\Plugin\IndexPluginRequest;
use App\Http\Requests\Plugin\InstallPluginFromFileRequest;
use App\Http\Requests\Plugin\PreviewPluginManifestRequest;
use App\Http\Requests\Plugin\InstallPluginFromGithubRequest;
use App\Http\Requests\Plugin\InstallPluginRequest;
use App\Http\Requests\Plugin\PerformPluginUpdateRequest;
use App\Http\Requests\Plugin\RefreshPluginLayoutsRequest;
use App\Http\Requests\Plugin\UninstallPluginRequest;
use App\Http\Requests\Extension\ChangelogRequest;
use App\Http\Resources\PluginCollection;
use App\Http\Resources\PluginResource;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use App\Services\LicenseService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 플러그인 관리 컨트롤러
 *
 * 관리자가 시스템 플러그인을 설치, 활성화, 비활성화, 제거할 수 있는 기능을 제공합니다.
 */
class PluginController extends AdminBaseController
{
    use InjectsExtensionLanguagePacks;
    use OrchestratesCascadeInstall;

    public function __construct(
        private PluginService $pluginService,
        private TemplateService $templateService,
        private LicenseService $licenseService
    ) {
        parent::__construct();
    }

    /**
     * 모든 플러그인 목록을 조회합니다 (설치된 플러그인과 미설치 플러그인 포함).
     *
     * 페이지네이션 및 다중 검색 조건을 지원합니다.
     * - search: 단일 검색어 (이름, 식별자, 설명, 벤더 OR 검색)
     * - filters: 다중 검색 조건 (AND 조건)
     *
     * @param  IndexPluginRequest  $request  플러그인 목록 조회 요청
     * @return JsonResponse 플러그인 목록을 포함한 JSON 응답
     */
    public function index(IndexPluginRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $filters = [
                'search' => $validated['search'] ?? null,
                'filters' => $validated['filters'] ?? [],
                'status' => $validated['status'] ?? null,
                'include_hidden' => (bool) ($validated['include_hidden'] ?? false),
            ];
            $perPage = (int) ($validated['per_page'] ?? 12);
            $page = (int) ($validated['page'] ?? 1);

            $result = $this->pluginService->getPaginatedPlugins($filters, $perPage, $page);

            $collection = new PluginCollection(collect($result['data']));

            return $this->success('plugins.list_success', [
                'data' => $collection->toArray($request)['data'],
                'pagination' => [
                    'total' => $result['total'],
                    'current_page' => $result['current_page'],
                    'last_page' => $result['last_page'],
                    'per_page' => $result['per_page'],
                ],
                'meta' => $collection->with($request)['meta'],
                'abilities' => [
                    'can_install' => PermissionHelper::check('core.plugins.install', $request->user()),
                    'can_activate' => PermissionHelper::check('core.plugins.activate', $request->user()),
                    'can_uninstall' => PermissionHelper::check('core.plugins.uninstall', $request->user()),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('plugins.list_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 설치된 플러그인만 조회합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return JsonResponse 설치된 플러그인 목록을 포함한 JSON 응답
     */
    // audit:allow controller-base-request-injection reason: GET 목록 조회. PluginCollection::toArray($request)/with($request) 전달용
    public function installed(Request $request): JsonResponse
    {
        try {
            $plugins = $this->pluginService->getInstalledPluginsOnly();

            $collection = new PluginCollection(collect($plugins));

            return $this->success('plugins.list_success', [
                'data' => $collection->toArray($request)['data'],
                'meta' => $collection->with($request)['meta'],
            ]);
        } catch (\Exception $e) {
            return $this->error('plugins.list_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 플러그인의 상세 정보를 조회합니다.
     *
     * @param  Request  $request  HTTP 요청 (attachLanguagePacks 의 Request 인자 전달용)
     * @param  string  $pluginName  플러그인 식별자
     * @return JsonResponse 플러그인 정보를 포함한 JSON 응답
     */
    // audit:allow controller-base-request-injection reason: GET 상세 조회. attachLanguagePacks($detail, scope, name, $request) 전달용
    public function show(Request $request, string $pluginName): JsonResponse
    {
        try {
            $pluginInfo = $this->pluginService->getPluginInfo($pluginName);

            if (! $pluginInfo) {
                return $this->error('plugins.not_found', 404, null, ['plugin' => $pluginName]);
            }

            // 상세 정보는 toDetailArray() 메서드 사용 + 지원 언어팩 주입
            $resource = new PluginResource($pluginInfo);
            $detail = $this->attachLanguagePacks(
                $resource->toDetailArray(),
                LanguagePackScope::Plugin,
                $pluginName,
                $request,
            );

            return $this->success('plugins.fetch_success', $detail);
        } catch (\Exception $e) {
            return $this->error('plugins.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 플러그인 설치 cascade 프리뷰를 반환합니다 (의존 확장 + 동반 가능 번들 언어팩).
     *
     * @param  string  $pluginName  플러그인 식별자
     * @param  ExtensionInstallPreviewBuilder  $builder  프리뷰 빌더
     * @return JsonResponse cascade 프리뷰 응답
     */
    public function installPreview(string $pluginName, ExtensionInstallPreviewBuilder $builder): JsonResponse
    {
        try {
            $preview = $builder->build(LanguagePackScope::Plugin, $pluginName);

            return $this->success('plugins.fetch_success', $preview);
        } catch (\Exception $e) {
            return $this->error('plugins.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 플러그인을 시스템에 설치합니다.
     *
     * @param  InstallPluginRequest  $request  플러그인 설치 요청 데이터
     * @return JsonResponse 설치된 플러그인 정보를 포함한 JSON 응답
     */
    public function install(InstallPluginRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $pluginName = $validated['plugin_name'];
            $vendorMode = \App\Extension\Vendor\VendorMode::fromStringOrAuto(
                $validated['vendor_mode'] ?? null
            );

            // cascade 1단계: 사용자가 선택한 의존 확장 사전 설치 (실패 시 abort)
            $this->installSelectedDependencies($validated['dependencies'] ?? []);

            $pluginInfo = $this->pluginService->installPlugin($pluginName, $vendorMode);

            if ($pluginInfo) {
                // cascade 2단계: 동반 번들 언어팩 best-effort 설치
                $lpFailures = $this->installSelectedLanguagePacks($validated['language_packs'] ?? []);

                $payload = (new PluginResource($pluginInfo))->toArray($request);
                $payload['language_pack_failures'] = $lpFailures;

                return $this->success('plugins.install_success', $payload);
            } else {
                return $this->error('plugins.install_failed');
            }
        } catch (ValidationException $e) {
            // Service에서 이미 번역된 메시지를 errors에 포함하므로
            // 첫 번째 에러를 top-level message로 직접 사용 (이중 래핑 방지)
            $firstError = collect($e->errors())->flatten()->first()
                ?? __('plugins.install_failed');

            return $this->validationError($e->errors(), $firstError);
        } catch (\Exception $e) {
            return $this->error('plugins.installation_failed', 500, $e->getMessage(), [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 플러그인을 활성화합니다.
     *
     * force 파라미터가 없고 필요한 의존성이 충족되지 않은 경우 경고를 반환합니다.
     *
     * @param  ActivatePluginRequest  $request  플러그인 활성화 요청 데이터
     * @return JsonResponse 활성화된 플러그인 정보를 포함한 JSON 응답
     */
    public function activate(ActivatePluginRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $pluginName = $validated['plugin_name'];
            $force = $validated['force'] ?? false;

            $result = $this->pluginService->activatePlugin($pluginName, $force);

            // 경고 응답인 경우 (필요 의존성 미충족) - 활성화 실패로 처리
            if (isset($result['warning']) && $result['warning'] === true) {
                return $this->error('plugins.activate_warning', 409, [
                    'warning' => true,
                    'missing_modules' => $result['missing_modules'] ?? [],
                    'missing_plugins' => $result['missing_plugins'] ?? [],
                    'message' => $result['message'],
                ]);
            }

            if ($result['success']) {
                $pluginInfo = $result['plugin_info'] ?? null;

                // 요구사항 #7: 재활성화 시 cascade 비활성화됐던 언어팩 목록 응답에 포함
                $pendingLanguagePacks = app(\App\Services\LanguagePack\LanguagePackBundledRegistrar::class)
                    ->getPendingForReactivation('plugin', $pluginName);

                if ($pluginInfo) {
                    return $this->success('plugins.activate_success', [
                        'plugin' => (new PluginResource($pluginInfo))->resolve(),
                        'pending_language_packs' => $pendingLanguagePacks,
                    ]);
                }

                return $this->success('plugins.activate_success', array_merge($result, [
                    'pending_language_packs' => $pendingLanguagePacks,
                ]));
            } else {
                return $this->error('plugins.activate_failed');
            }
        } catch (ValidationException $e) {
            return $this->error(
                'plugins.activate_validation_failed',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            return $this->error(
                'plugins.activate_error',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * 플러그인을 비활성화합니다.
     *
     * force 파라미터가 없고 의존하는 확장이 있는 경우 경고를 반환합니다.
     *
     * @param  DeactivatePluginRequest  $request  플러그인 비활성화 요청 데이터
     * @return JsonResponse 비활성화된 플러그인 정보를 포함한 JSON 응답
     */
    public function deactivate(DeactivatePluginRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $pluginName = $validated['plugin_name'];
            $force = $validated['force'] ?? false;

            $result = $this->pluginService->deactivatePlugin($pluginName, $force);

            // 경고 응답인 경우 (의존 확장 존재) - 비활성화 실패로 처리
            if (isset($result['warning']) && $result['warning'] === true) {
                return $this->error('plugins.deactivate_warning', 409, [
                    'warning' => true,
                    'dependent_templates' => $result['dependent_templates'] ?? [],
                    'dependent_modules' => $result['dependent_modules'] ?? [],
                    'dependent_plugins' => $result['dependent_plugins'] ?? [],
                    'message' => $result['message'],
                ]);
            }

            if ($result['success']) {
                $pluginInfo = $result['plugin_info'] ?? null;

                if ($pluginInfo) {
                    return $this->successWithResource(
                        'plugins.deactivate_success',
                        new PluginResource($pluginInfo)
                    );
                }

                return $this->success('plugins.deactivate_success', $result);
            } else {
                return $this->error('plugins.deactivate_failed');
            }
        } catch (ValidationException $e) {
            return $this->error(
                'plugins.deactivate_validation_failed',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            return $this->error(
                'plugins.deactivate_error',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * 플러그인에 의존하는 템플릿 목록을 조회합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return JsonResponse 의존 템플릿 목록을 포함한 JSON 응답
     */
    public function dependentTemplates(string $identifier): JsonResponse
    {
        try {
            $dependentTemplates = $this->templateService->getTemplatesDependingOnPlugin($identifier);

            return $this->success('plugins.dependent_templates_success', [
                'data' => $dependentTemplates,
                'total' => count($dependentTemplates),
            ]);
        } catch (\Exception $e) {
            return $this->error('plugins.dependent_templates_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 플러그인 삭제 시 삭제될 데이터 정보를 조회합니다.
     *
     * @param  string  $pluginName  플러그인명
     * @return JsonResponse 삭제 정보를 포함한 JSON 응답
     */
    public function uninstallInfo(string $pluginName): JsonResponse
    {
        try {
            $uninstallInfo = $this->pluginService->getPluginUninstallInfo($pluginName);

            if (! $uninstallInfo) {
                return $this->error('plugins.not_found', 404, null, ['plugin' => $pluginName]);
            }

            return $this->success('plugins.uninstall_info_success', $uninstallInfo);
        } catch (\Exception $e) {
            return $this->error('plugins.uninstall_info_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 플러그인을 시스템에서 제거합니다.
     *
     * @param  UninstallPluginRequest  $request  플러그인 제거 요청 데이터
     * @return JsonResponse 제거 결과 JSON 응답
     */
    public function uninstall(UninstallPluginRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $pluginName = $validated['plugin_name'];
            $deleteData = $validated['delete_data'] ?? false;

            $result = $this->pluginService->uninstallPlugin($pluginName, $deleteData);

            if ($result) {
                return $this->success('plugins.uninstall_success');
            } else {
                return $this->error('plugins.uninstall_failed');
            }
        } catch (ValidationException $e) {
            return $this->error(
                'plugins.uninstall_validation_failed',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            return $this->error(
                'plugins.uninstall_error',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * 업로드된 ZIP 의 manifest 와 검증 결과만 추출합니다 (실제 설치 X).
     *
     * @param  PreviewPluginManifestRequest  $request  미리보기 요청
     * @return JsonResponse manifest + validation 결과
     */
    public function manifestPreview(PreviewPluginManifestRequest $request): JsonResponse
    {
        try {
            $result = $this->pluginService->previewManifest($request->file('file'));

            return $this->success('plugins.preview_success', $result);
        } catch (\Throwable $e) {
            return $this->error('plugins.preview_failed', 422, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * ZIP 파일에서 플러그인을 설치합니다.
     *
     * @param  InstallPluginFromFileRequest  $request  파일 설치 요청 데이터
     * @return JsonResponse 설치된 플러그인 정보를 포함한 JSON 응답
     */
    public function installFromFile(InstallPluginFromFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $plugin = $this->pluginService->installFromZipFile($file);

            return $this->successWithResource(
                'plugins.install_success',
                new PluginResource($plugin),
                201
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('plugins.install_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * GitHub 저장소에서 플러그인을 설치합니다.
     *
     * @param  InstallPluginFromGithubRequest  $request  GitHub 설치 요청 데이터
     * @return JsonResponse 설치된 플러그인 정보를 포함한 JSON 응답
     */
    public function installFromGithub(InstallPluginFromGithubRequest $request): JsonResponse
    {
        try {
            $githubUrl = $request->validated()['github_url'];
            $plugin = $this->pluginService->installFromGithub($githubUrl);

            return $this->successWithResource(
                'plugins.install_success',
                new PluginResource($plugin),
                201
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('plugins.install_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 설치된 모든 플러그인의 업데이트를 확인합니다.
     *
     * @return JsonResponse 업데이트 확인 결과 JSON 응답
     */
    public function checkUpdates(): JsonResponse
    {
        try {
            $result = $this->pluginService->checkForUpdates();

            return $this->success('plugins.check_updates_success', $result);
        } catch (ValidationException $e) {
            return $this->error('plugins.check_updates_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('plugins.check_updates_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 플러그인의 수정된 레이아웃을 확인합니다.
     *
     * @param  string  $pluginName  플러그인 식별자
     * @return JsonResponse 수정된 레이아웃 정보를 포함한 JSON 응답
     */
    public function checkModifiedLayouts(string $pluginName): JsonResponse
    {
        try {
            $result = $this->pluginService->checkModifiedLayouts($pluginName);

            return $this->success('plugins.check_modified_layouts_success', $result);
        } catch (ValidationException $e) {
            return $this->error('plugins.check_modified_layouts_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('plugins.check_modified_layouts_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 플러그인을 업데이트합니다.
     *
     * layout_strategy 파라미터로 레이아웃 처리 방식을 결정합니다:
     * - overwrite: 모든 레이아웃을 새 버전으로 교체
     * - keep: 사용자가 수정한 레이아웃을 유지
     *
     * @param  PerformPluginUpdateRequest  $request  업데이트 요청 데이터
     * @param  string  $pluginName  업데이트할 플러그인 identifier
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function performUpdate(PerformPluginUpdateRequest $request, string $pluginName): JsonResponse
    {
        try {
            $validated = $request->validated();
            $vendorMode = \App\Extension\Vendor\VendorMode::fromStringOrAuto(
                $validated['vendor_mode'] ?? null
            );
            $layoutStrategy = $validated['layout_strategy'] ?? 'overwrite';
            $force = (bool) ($validated['force'] ?? false);
            $result = $this->pluginService->updatePlugin($pluginName, $vendorMode, $layoutStrategy, $force);

            $pluginInfo = $result['plugin_info'] ?? null;

            if ($pluginInfo) {
                return $this->successWithResource(
                    'plugins.update_success',
                    new PluginResource($pluginInfo)
                );
            }

            return $this->success('plugins.update_success', $result);
        } catch (ValidationException $e) {
            // Service/Manager에서 이미 번역된 메시지를 errors에 포함하므로
            // 첫 번째 에러를 top-level message로 직접 사용 (이중 래핑 방지)
            $firstError = collect($e->errors())->flatten()->first()
                ?? __('plugins.errors.update_failed', ['plugin' => $pluginName, 'error' => '']);

            return $this->validationError($e->errors(), $firstError);
        } catch (\Exception $e) {
            return $this->error('plugins.errors.update_failed', 500, $e->getMessage(), [
                'plugin' => $pluginName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 플러그인 레이아웃을 갱신합니다.
     *
     * 플러그인의 레이아웃 파일을 다시 읽어 DB에 동기화합니다.
     * 파일에서 변경된 레이아웃은 업데이트되고, 삭제된 레이아웃은 DB에서도 삭제됩니다.
     *
     * @param  RefreshPluginLayoutsRequest  $request  레이아웃 갱신 요청 데이터
     * @return JsonResponse 갱신 결과 JSON 응답
     */
    public function refreshLayouts(RefreshPluginLayoutsRequest $request): JsonResponse
    {
        try {
            $pluginName = $request->validated()['plugin_name'];
            $result = $this->pluginService->refreshPluginLayouts($pluginName);

            if ($result['success']) {
                return $this->success('plugins.refresh_layouts_success', [
                    'layouts_refreshed' => $result['layouts_refreshed'],
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'deleted' => $result['deleted'],
                    'unchanged' => $result['unchanged'],
                ]);
            } else {
                return $this->error('plugins.refresh_layouts_failed');
            }
        } catch (ValidationException $e) {
            return $this->error(
                'plugins.refresh_layouts_validation_failed',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            return $this->error(
                'plugins.refresh_layouts_error',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * 특정 플러그인의 변경 내역(changelog)을 조회합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $identifier  플러그인 식별자
     * @return JsonResponse 변경 내역을 포함한 JSON 응답
     */
    public function changelog(ChangelogRequest $request, string $identifier): JsonResponse
    {
        try {
            $validated = $request->validated();
            $changelog = $this->pluginService->getPluginChangelog(
                $identifier,
                $validated['source'] ?? null,
                $validated['from_version'] ?? null,
                $validated['to_version'] ?? null,
            );

            return $this->success('plugins.fetch_success', ['changelog' => $changelog]);
        } catch (\Exception $e) {
            return $this->error('plugins.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 플러그인의 라이선스 파일 내용을 반환합니다.
     *
     * @param string $identifier 플러그인 식별자
     * @return JsonResponse
     */
    public function license(string $identifier): JsonResponse
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $identifier)) {
            return $this->error('plugins.license_not_found', 404);
        }

        $content = $this->licenseService->getExtensionLicense('plugins', $identifier);

        if ($content === null) {
            return $this->error('plugins.license_not_found', 404);
        }

        return $this->success('plugins.fetch_success', [
            'content' => $content,
        ]);
    }
}
