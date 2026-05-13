<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LanguagePackScope;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Controllers\Concerns\InjectsExtensionLanguagePacks;
use App\Http\Controllers\Concerns\OrchestratesCascadeInstall;
use App\Http\Requests\Template\ActivateTemplateRequest;
use App\Http\Requests\Template\DeactivateTemplateRequest;
use App\Http\Requests\Template\IndexTemplateRequest;
use App\Http\Requests\Template\InstallTemplateFromFileRequest;
use App\Http\Requests\Template\PreviewTemplateManifestRequest;
use App\Http\Requests\Template\InstallTemplateFromGithubRequest;
use App\Http\Requests\Template\InstallTemplateRequest;
use App\Http\Requests\Template\PerformTemplateUpdateRequest;
use App\Http\Requests\Template\RefreshTemplateLayoutsRequest;
use App\Http\Requests\Template\UninstallTemplateRequest;
use App\Http\Requests\Extension\ChangelogRequest;
use App\Http\Resources\TemplateCollection;
use App\Http\Resources\TemplateResource;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use App\Services\LicenseService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 템플릿 관리 컨트롤러
 *
 * 관리자가 시스템 템플릿을 설치, 활성화, 비활성화, 제거할 수 있는 기능을 제공합니다.
 */
class TemplateController extends AdminBaseController
{
    use InjectsExtensionLanguagePacks;
    use OrchestratesCascadeInstall;

    public function __construct(
        private TemplateService $templateService,
        private LicenseService $licenseService
    ) {
        parent::__construct();
    }

    /**
     * 모든 템플릿 목록을 조회합니다 (설치된 템플릿과 미설치 템플릿 포함).
     *
     * 페이지네이션 및 다중 검색 조건을 지원합니다.
     * - search: 단일 검색어 (이름, 식별자, 설명, 벤더 OR 검색)
     * - filters: 다중 검색 조건 (AND 조건)
     *
     * @param  IndexTemplateRequest  $request  템플릿 목록 조회 요청
     * @return JsonResponse 템플릿 목록을 포함한 JSON 응답
     */
    public function index(IndexTemplateRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $filters = [
                'search' => $validated['search'] ?? null,
                'filters' => $validated['filters'] ?? [],
                'status' => $validated['status'] ?? null,
                'type' => $validated['type'] ?? null,
                'include_hidden' => (bool) ($validated['include_hidden'] ?? false),
            ];
            $perPage = (int) ($validated['per_page'] ?? 12);
            $page = (int) ($validated['page'] ?? 1);

            $result = $this->templateService->getPaginatedTemplates($filters, $perPage, $page);

            $collection = new TemplateCollection(collect($result['data']));

            return $this->success('templates.fetch_success', [
                'data' => $collection->toArray($request)['data'],
                'pagination' => [
                    'total' => $result['total'],
                    'current_page' => $result['current_page'],
                    'last_page' => $result['last_page'],
                    'per_page' => $result['per_page'],
                ],
                'meta' => $collection->with($request)['meta'],
                'abilities' => [
                    'can_install' => PermissionHelper::check('core.templates.install', $request->user()),
                    'can_activate' => PermissionHelper::check('core.templates.activate', $request->user()),
                    'can_uninstall' => PermissionHelper::check('core.templates.uninstall', $request->user()),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('templates.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 템플릿의 상세 정보를 조회합니다.
     *
     * @param  Request  $request  HTTP 요청 (attachLanguagePacks 의 Request 인자 전달용)
     * @param  string  $templateName  템플릿 식별자
     * @return JsonResponse 템플릿 정보를 포함한 JSON 응답
     */
    // audit:allow controller-base-request-injection reason: GET 상세 조회. attachLanguagePacks($detail, scope, name, $request) 전달용
    public function show(Request $request, string $templateName): JsonResponse
    {
        try {
            $templateInfo = $this->templateService->getTemplateInfo($templateName);

            if (! $templateInfo) {
                return $this->error('templates.not_found', 404, null, ['template' => $templateName]);
            }

            // 상세 정보는 toDetailArray() 메서드 사용 + 지원 언어팩 주입
            $resource = new TemplateResource($templateInfo);
            $detail = $this->attachLanguagePacks(
                $resource->toDetailArray(),
                LanguagePackScope::Template,
                $templateName,
                $request,
            );

            return $this->success('templates.fetch_success', $detail);
        } catch (\Exception $e) {
            return $this->error('templates.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 템플릿 설치 cascade 프리뷰를 반환합니다 (의존 확장 + 동반 가능 번들 언어팩).
     *
     * @param  string  $templateName  템플릿 식별자
     * @param  ExtensionInstallPreviewBuilder  $builder  프리뷰 빌더
     * @return JsonResponse cascade 프리뷰 응답
     */
    public function installPreview(string $templateName, ExtensionInstallPreviewBuilder $builder): JsonResponse
    {
        try {
            $preview = $builder->build(LanguagePackScope::Template, $templateName);

            return $this->success('templates.fetch_success', $preview);
        } catch (\Exception $e) {
            return $this->error('templates.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 템플릿을 시스템에 설치합니다.
     *
     * @param  InstallTemplateRequest  $request  템플릿 설치 요청 데이터
     * @return JsonResponse 설치된 템플릿 정보를 포함한 JSON 응답
     */
    public function install(InstallTemplateRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $templateName = $validated['template_name'];

            // cascade 1단계: 사용자가 선택한 의존 확장 사전 설치 (실패 시 abort)
            $this->installSelectedDependencies($validated['dependencies'] ?? []);

            $template = $this->templateService->installTemplate($templateName);

            if ($template) {
                // cascade 2단계: 동반 번들 언어팩 best-effort 설치
                $lpFailures = $this->installSelectedLanguagePacks($validated['language_packs'] ?? []);

                $payload = (new TemplateResource($template))->toArray($request);
                $payload['language_pack_failures'] = $lpFailures;

                return $this->success('templates.install_success', $payload, 201);
            } else {
                return $this->error('templates.install_failed');
            }
        } catch (ValidationException $e) {
            // Service에서 이미 번역된 메시지를 errors에 포함하므로
            // 첫 번째 에러를 top-level message로 직접 사용 (이중 래핑 방지)
            $firstError = collect($e->errors())->flatten()->first()
                ?? __('templates.install_failed');

            return $this->validationError($e->errors(), $firstError);
        } catch (\Exception $e) {
            return $this->error('templates.errors.installation_failed', 500, $e->getMessage(), [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 템플릿을 활성화합니다.
     *
     * force 파라미터가 없고 필요한 의존성이 충족되지 않은 경우 경고를 반환합니다.
     *
     * @param  ActivateTemplateRequest  $request  템플릿 활성화 요청 데이터
     * @return JsonResponse 활성화된 템플릿 정보를 포함한 JSON 응답
     */
    public function activate(ActivateTemplateRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $templateName = $validated['template_name'];
            $force = $validated['force'] ?? false;

            $result = $this->templateService->activateTemplate($templateName, $force);

            // 경고 응답인 경우 (필요 의존성 미충족) - 활성화 실패로 처리
            if (isset($result['warning']) && $result['warning'] === true) {
                return $this->error('templates.activate_warning', 409, [
                    'warning' => true,
                    'missing_modules' => $result['missing_modules'] ?? [],
                    'missing_plugins' => $result['missing_plugins'] ?? [],
                    'message' => $result['message'],
                ]);
            }

            if ($result['success']) {
                $templateInfo = $result['template_info'] ?? null;

                // 요구사항 #7: 재활성화 시 cascade 비활성화됐던 언어팩 목록 응답에 포함
                $pendingLanguagePacks = app(\App\Services\LanguagePack\LanguagePackBundledRegistrar::class)
                    ->getPendingForReactivation('template', $templateName);

                if ($templateInfo) {
                    return $this->success('templates.activate_success', [
                        'template' => (new TemplateResource($templateInfo))->resolve(),
                        'pending_language_packs' => $pendingLanguagePacks,
                    ]);
                }

                return $this->success('templates.activate_success', array_merge($result, [
                    'pending_language_packs' => $pendingLanguagePacks,
                ]));
            } else {
                return $this->error('templates.activate_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('templates.activate_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('templates.activate_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 템플릿을 비활성화합니다.
     *
     * @param  DeactivateTemplateRequest  $request  템플릿 비활성화 요청 데이터
     * @return JsonResponse 비활성화된 템플릿 정보를 포함한 JSON 응답
     */
    public function deactivate(DeactivateTemplateRequest $request): JsonResponse
    {
        try {
            $templateName = $request->validated()['template_name'];
            $template = $this->templateService->deactivateTemplate($templateName);

            if ($template) {
                return $this->successWithResource(
                    'templates.deactivate_success',
                    new TemplateResource($template)
                );
            } else {
                return $this->error('templates.deactivate_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('templates.deactivate_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('templates.deactivate_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 템플릿을 시스템에서 제거합니다.
     *
     * @param  UninstallTemplateRequest  $request  템플릿 제거 요청 데이터
     * @return JsonResponse 제거 결과 JSON 응답
     */
    public function uninstall(UninstallTemplateRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $templateName = $validated['template_name'];
            $deleteData = $validated['delete_data'] ?? false;

            $result = $this->templateService->uninstallTemplate($templateName, $deleteData);

            if ($result) {
                return $this->success('templates.uninstall_success');
            } else {
                return $this->error('templates.uninstall_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('templates.uninstall_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('templates.uninstall_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 템플릿 삭제 시 삭제될 데이터 정보를 조회합니다.
     *
     * @param  string  $templateName  템플릿명
     * @return JsonResponse 삭제 정보를 포함한 JSON 응답
     */
    public function uninstallInfo(string $templateName): JsonResponse
    {
        try {
            $uninstallInfo = $this->templateService->getTemplateUninstallInfo($templateName);

            if (! $uninstallInfo) {
                return $this->error('templates.not_found', 404, null, ['template' => $templateName]);
            }

            return $this->success('templates.uninstall_info_success', $uninstallInfo);
        } catch (\Exception $e) {
            return $this->error('templates.uninstall_info_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 업로드된 ZIP 의 manifest 와 검증 결과만 추출합니다 (실제 설치 X).
     *
     * @param  PreviewTemplateManifestRequest  $request  미리보기 요청
     * @return JsonResponse manifest + validation 결과
     */
    public function manifestPreview(PreviewTemplateManifestRequest $request): JsonResponse
    {
        try {
            $result = $this->templateService->previewManifest($request->file('file'));

            return $this->success('templates.preview_success', $result);
        } catch (\Throwable $e) {
            return $this->error('templates.preview_failed', 422, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * ZIP 파일에서 템플릿을 설치합니다.
     *
     * @param  InstallTemplateFromFileRequest  $request  파일 설치 요청 데이터
     * @return JsonResponse 설치된 템플릿 정보를 포함한 JSON 응답
     */
    public function installFromFile(InstallTemplateFromFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $template = $this->templateService->installFromZipFile($file);

            return $this->successWithResource(
                'templates.install_success',
                new TemplateResource($template),
                201
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('templates.install_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * GitHub 저장소에서 템플릿을 설치합니다.
     *
     * @param  InstallTemplateFromGithubRequest  $request  GitHub 설치 요청 데이터
     * @return JsonResponse 설치된 템플릿 정보를 포함한 JSON 응답
     */
    public function installFromGithub(InstallTemplateFromGithubRequest $request): JsonResponse
    {
        try {
            $githubUrl = $request->validated()['github_url'];
            $template = $this->templateService->installFromGithub($githubUrl);

            return $this->successWithResource(
                'templates.install_success',
                new TemplateResource($template),
                201
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('templates.install_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 템플릿의 레이아웃을 파일에서 다시 읽어 갱신합니다.
     *
     * @param  RefreshTemplateLayoutsRequest  $request  레이아웃 갱신 요청 데이터
     * @return JsonResponse 갱신된 템플릿 정보를 포함한 JSON 응답
     */
    public function refreshLayouts(RefreshTemplateLayoutsRequest $request): JsonResponse
    {
        try {
            $templateName = $request->validated()['template_name'];
            $template = $this->templateService->refreshTemplateLayouts($templateName);

            if ($template) {
                return $this->successWithResource(
                    'templates.refresh_layouts_success',
                    new TemplateResource($template)
                );
            } else {
                return $this->error('templates.refresh_layouts_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('templates.refresh_layouts_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('templates.refresh_layouts_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 설치된 모든 템플릿의 업데이트를 확인합니다.
     *
     * @return JsonResponse 업데이트 확인 결과 JSON 응답
     */
    public function checkUpdates(): JsonResponse
    {
        try {
            $result = $this->templateService->checkForUpdates();

            return $this->success('templates.check_updates_success', $result);
        } catch (ValidationException $e) {
            return $this->error('templates.check_updates_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('templates.check_updates_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 템플릿의 수정된 레이아웃을 확인합니다.
     *
     * 업데이트 전 사용자가 수정한 레이아웃이 있는지 확인하여
     * 레이아웃 전략(overwrite/keep) 선택에 참고할 수 있도록 합니다.
     *
     * @param  string  $templateName  템플릿 식별자
     * @return JsonResponse 수정된 레이아웃 정보를 포함한 JSON 응답
     */
    public function checkModifiedLayouts(string $templateName): JsonResponse
    {
        try {
            $result = $this->templateService->checkModifiedLayouts($templateName);

            return $this->success('templates.check_modified_layouts_success', $result);
        } catch (ValidationException $e) {
            return $this->error('templates.check_modified_layouts_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('templates.check_modified_layouts_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 특정 템플릿을 업데이트합니다.
     *
     * layout_strategy 파라미터로 레이아웃 처리 방식을 결정합니다:
     * - overwrite: 모든 레이아웃을 새 버전으로 교체
     * - keep: 사용자가 수정한 레이아웃을 유지
     *
     * @param  PerformTemplateUpdateRequest  $request  업데이트 요청 데이터
     * @param  string  $templateName  업데이트할 템플릿 identifier
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function performUpdate(PerformTemplateUpdateRequest $request, string $templateName): JsonResponse
    {
        try {
            $validated = $request->validated();
            $layoutStrategy = $validated['layout_strategy'] ?? 'overwrite';
            $force = (bool) ($validated['force'] ?? false);
            $result = $this->templateService->performVersionUpdate($templateName, $layoutStrategy, $force);

            $templateInfo = $result['template_info'] ?? null;

            if ($templateInfo) {
                return $this->successWithResource(
                    'templates.update_success',
                    new TemplateResource($templateInfo)
                );
            }

            return $this->success('templates.update_success', $result);
        } catch (ValidationException $e) {
            // Service/Manager에서 이미 번역된 메시지를 errors에 포함하므로
            // 첫 번째 에러를 top-level message로 직접 사용 (이중 래핑 방지)
            $firstError = collect($e->errors())->flatten()->first()
                ?? __('templates.errors.update_failed', ['template' => $templateName, 'error' => '']);

            return $this->validationError($e->errors(), $firstError);
        } catch (\Exception $e) {
            return $this->error('templates.errors.update_failed', 500, $e->getMessage(), [
                'template' => $templateName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 특정 템플릿의 변경 내역(changelog)을 조회합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 변경 내역을 포함한 JSON 응답
     */
    public function changelog(ChangelogRequest $request, string $identifier): JsonResponse
    {
        try {
            $validated = $request->validated();
            $changelog = $this->templateService->getTemplateChangelog(
                $identifier,
                $validated['source'] ?? null,
                $validated['from_version'] ?? null,
                $validated['to_version'] ?? null,
            );

            return $this->success('template.fetch_success', ['changelog' => $changelog]);
        } catch (\Exception $e) {
            return $this->error('template.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 템플릿의 라이선스 파일 내용을 반환합니다.
     *
     * @param string $identifier 템플릿 식별자
     * @return JsonResponse
     */
    public function license(string $identifier): JsonResponse
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $identifier)) {
            return $this->error('templates.license_not_found', 404);
        }

        $content = $this->licenseService->getExtensionLicense('templates', $identifier);

        if ($content === null) {
            return $this->error('templates.license_not_found', 404);
        }

        return $this->success('templates.fetch_success', [
            'content' => $content,
        ]);
    }
}
