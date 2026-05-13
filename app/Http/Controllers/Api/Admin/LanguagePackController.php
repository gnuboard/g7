<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\LanguagePackSlotConflictException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\LanguagePack\InstallFromBundledRequest;
use App\Http\Requests\LanguagePack\IndexLanguagePackRequest;
use App\Http\Requests\LanguagePack\InstallFromFileRequest;
use App\Http\Requests\LanguagePack\InstallFromGithubRequest;
use App\Http\Requests\LanguagePack\InstallFromUrlRequest;
use App\Http\Requests\LanguagePack\ManifestPreviewRequest;
use App\Http\Requests\LanguagePack\UninstallLanguagePackRequest;
use App\Extension\Helpers\ChangelogParser;
use App\Http\Resources\LanguagePackCollection;
use App\Http\Resources\LanguagePackResource;
use App\Services\LanguagePackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 언어팩 관리 컨트롤러.
 *
 * 컨트롤러는 얇게 유지하고 모든 비즈니스 로직은 LanguagePackService 에 위임합니다.
 */
class LanguagePackController extends AdminBaseController
{
    /**
     * @param  LanguagePackService  $service  언어팩 Service
     */
    public function __construct(
        private readonly LanguagePackService $service,
    ) {
        parent::__construct();
    }

    /**
     * 언어팩 목록을 페이지네이션으로 조회합니다.
     *
     * @param  IndexLanguagePackRequest  $request  목록 조회 요청
     * @return JsonResponse 페이지네이션된 언어팩 목록
     */
    public function index(IndexLanguagePackRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $perPage = (int) ($validated['per_page'] ?? 10);

            $paginator = $this->service->list($validated, $perPage);

            $collection = new LanguagePackCollection(collect($paginator->items()));
            $payload = [
                'data' => $collection->toArray($request)['data'],
                'meta' => array_merge(
                    $collection->with($request)['meta'] ?? [],
                    [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ]
                ),
                'abilities' => $collection->resolveCollectionAbilities($request),
            ];

            return $this->success('language_packs.fetch_success', $payload);
        } catch (\Throwable $e) {
            return $this->error('language_packs.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 언어팩 상세 정보를 조회합니다.
     *
     * `{id}` 가 정수면 DB 레코드를, 문자열(번들 식별자)이면 `lang-packs/_bundled/{id}` 의
     * manifest 로부터 합성된 가상 행을 반환합니다 (미설치 번들의 모달 열람용).
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $id  언어팩 ID 또는 번들 식별자
     * @return JsonResponse 언어팩 상세 정보
     */
    // audit:allow controller-base-request-injection reason: GET 상세 조회. 입력 검증 없음 — Resource::toDetailArray($request) 의 Request 인자 전달 목적
    public function show(Request $request, string $id): JsonResponse
    {
        $pack = $this->service->findOrBundled($id);
        if (! $pack) {
            return $this->notFound('language_packs.not_found');
        }

        $resource = new LanguagePackResource($pack);

        return $this->success('language_packs.fetch_success', $resource->toDetailArray($request));
    }

    /**
     * 업로드된 ZIP 파일에서 언어팩을 설치합니다.
     *
     * @param  InstallFromFileRequest  $request  설치 요청
     * @return JsonResponse 설치 결과
     */
    public function installFromFile(InstallFromFileRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $pack = $this->service->installFromFile(
                $request->file('file'),
                (bool) ($validated['auto_activate'] ?? false),
                $this->getCurrentUser()?->id
            );

            return $this->success(
                'language_packs.install_success',
                (new LanguagePackResource($pack))->toArray($request),
                201
            );
        } catch (ValidationException $e) {
            return $this->error('language_packs.manifest_invalid', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->error('language_packs.install_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * GitHub URL 에서 언어팩을 설치합니다.
     *
     * @param  InstallFromGithubRequest  $request  설치 요청
     * @return JsonResponse 설치 결과
     */
    public function installFromGithub(InstallFromGithubRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $pack = $this->service->installFromGithub(
                $validated['github_url'],
                (bool) ($validated['auto_activate'] ?? false),
                $this->getCurrentUser()?->id
            );

            return $this->success(
                'language_packs.install_success',
                (new LanguagePackResource($pack))->toArray($request),
                201
            );
        } catch (ValidationException $e) {
            return $this->error('language_packs.manifest_invalid', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->error('language_packs.install_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * `lang-packs/_bundled/{identifier}` 디렉토리의 번들 소스에서 언어팩을 설치(또는 재설치)합니다.
     *
     * @param  InstallFromBundledRequest  $request  설치 요청
     * @return JsonResponse 설치 결과
     */
    public function installFromBundled(InstallFromBundledRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $pack = $this->service->installFromBundled(
                $validated['identifier'],
                (bool) ($validated['auto_activate'] ?? false),
                $this->getCurrentUser()?->id
            );

            return $this->success(
                'language_packs.install_success',
                (new LanguagePackResource($pack))->toArray($request),
                201
            );
        } catch (ValidationException $e) {
            return $this->error('language_packs.manifest_invalid', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->error('language_packs.install_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 임의 URL 에서 언어팩을 설치합니다.
     *
     * @param  InstallFromUrlRequest  $request  설치 요청
     * @return JsonResponse 설치 결과
     */
    public function installFromUrl(InstallFromUrlRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $pack = $this->service->installFromUrl(
                $validated['url'],
                $validated['checksum'] ?? null,
                (bool) ($validated['auto_activate'] ?? false),
                $this->getCurrentUser()?->id
            );

            return $this->success(
                'language_packs.install_success',
                (new LanguagePackResource($pack))->toArray($request),
                201
            );
        } catch (ValidationException $e) {
            return $this->error('language_packs.manifest_invalid', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->error('language_packs.install_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 언어팩을 활성화합니다 (슬롯 스위칭).
     *
     * @param  Request  $request  HTTP 요청
     * @param  int  $id  언어팩 ID
     * @return JsonResponse 활성화 결과
     */
    // audit:allow controller-base-request-injection reason: force 플래그(boolean) 단순 토글. FormRequest 분리 시 빈 rules() 만 제공 — 검증 가치 없음
    public function activate(Request $request, int $id): JsonResponse
    {
        $pack = $this->service->find($id);
        if (! $pack) {
            return $this->notFound('language_packs.not_found');
        }

        $force = (bool) $request->boolean('force', false);

        try {
            $pack = $this->service->activate($pack, $force);

            return $this->success(
                'language_packs.activate_success',
                (new LanguagePackResource($pack))->toArray($request)
            );
        } catch (LanguagePackSlotConflictException $e) {
            // 동일 슬롯에 다른 활성 팩 존재 — 프론트가 모달로 사용자 확인 후 force=true 로 재호출
            return $this->error('language_packs.errors.slot_conflict', 409, [
                'current' => (new LanguagePackResource($e->current))->toArray($request),
                'target' => (new LanguagePackResource($e->target))->toArray($request),
            ]);
        } catch (\Throwable $e) {
            return $this->error('language_packs.activate_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 여러 언어팩을 일괄 활성화합니다 (요구사항 #7 reactivate 모달 → "활성화" 버튼).
     *
     * @param  \App\Http\Requests\LanguagePack\BulkActivateRequest  $request  요청
     * @return JsonResponse 결과 (succeeded/failed 분리)
     */
    public function bulkActivate(\App\Http\Requests\LanguagePack\BulkActivateRequest $request): JsonResponse
    {
        $ids = $request->validated('ids');
        $result = $this->service->bulkActivate($ids);

        return $this->success('language_packs.bulk_activate_success', $result);
    }

    /**
     * 언어팩을 비활성화합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  int  $id  언어팩 ID
     * @return JsonResponse 비활성화 결과
     */
    // audit:allow controller-base-request-injection reason: 라우트 파라미터만 사용. Request 는 Resource::toArray($request) 전달용
    public function deactivate(Request $request, int $id): JsonResponse
    {
        $pack = $this->service->find($id);
        if (! $pack) {
            return $this->notFound('language_packs.not_found');
        }

        try {
            $pack = $this->service->deactivate($pack);

            return $this->success(
                'language_packs.deactivate_success',
                (new LanguagePackResource($pack))->toArray($request)
            );
        } catch (\Throwable $e) {
            return $this->error('language_packs.deactivate_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 언어팩을 제거합니다.
     *
     * @param  UninstallLanguagePackRequest  $request  제거 요청
     * @param  int  $id  언어팩 ID
     * @return JsonResponse 제거 결과
     */
    public function uninstall(UninstallLanguagePackRequest $request, int $id): JsonResponse
    {
        $pack = $this->service->find($id);
        if (! $pack) {
            return $this->notFound('language_packs.not_found');
        }

        try {
            $cascade = (bool) ($request->validated()['cascade'] ?? false);
            $this->service->uninstall($pack, $cascade);

            return $this->success('language_packs.uninstall_success');
        } catch (\Throwable $e) {
            return $this->error('language_packs.uninstall_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * GitHub 소스 언어팩의 업데이트 가능 여부를 확인합니다.
     *
     * @return JsonResponse 검사 결과 (checked, updates, details)
     */
    public function checkUpdates(): JsonResponse
    {
        try {
            $result = $this->service->checkUpdates();

            return $this->success('language_packs.check_updates_success', $result);
        } catch (\Throwable $e) {
            return $this->error('language_packs.check_updates_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 언어팩을 신버전으로 업데이트합니다 (GitHub 재다운로드 + 적용).
     *
     * @param  Request  $request  HTTP 요청
     * @param  int  $id  언어팩 ID
     * @return JsonResponse 업데이트된 언어팩
     */
    // audit:allow controller-base-request-injection reason: 입력 검증 없음. Request 는 Resource::toArray($request) 전달용
    public function performUpdate(Request $request, int $id): JsonResponse
    {
        $pack = $this->service->find($id);
        if (! $pack) {
            return $this->notFound('language_packs.not_found');
        }

        try {
            $updated = $this->service->performUpdate($pack);

            return $this->success(
                'language_packs.update_success',
                (new LanguagePackResource($updated))->toArray($request)
            );
        } catch (\Throwable $e) {
            return $this->error('language_packs.update_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 번역/레지스트리/템플릿 언어 캐시를 무효화합니다.
     *
     * @return JsonResponse 캐시 갱신 결과
     */
    public function refreshCache(): JsonResponse
    {
        try {
            $result = $this->service->refreshCache();

            return $this->success('language_packs.refresh_cache_success', $result);
        } catch (\Throwable $e) {
            return $this->error('language_packs.refresh_cache_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 설치 전 ZIP 의 manifest 와 검증 결과만 미리 조회합니다 (실제 설치 X).
     *
     * @param  ManifestPreviewRequest  $request  미리보기 요청
     * @return JsonResponse manifest + validation 결과
     */
    public function manifestPreview(ManifestPreviewRequest $request): JsonResponse
    {
        try {
            $result = $this->service->previewManifest($request->file('file'));

            return $this->success('language_packs.preview_success', $result);
        } catch (\Throwable $e) {
            return $this->error('language_packs.preview_failed', 422, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }

    /**
     * 언어팩의 CHANGELOG.md 파일 내용을 반환합니다.
     *
     * `{id}` 가 정수면 DB 레코드를, 문자열(번들 식별자)이면 가상 행을 사용합니다.
     *
     * @param  string  $id  언어팩 ID 또는 번들 식별자
     * @return JsonResponse CHANGELOG 문자열
     */
    public function changelog(string $id): JsonResponse
    {
        $pack = $this->service->findOrBundled($id);
        if (! $pack) {
            return $this->notFound('language_packs.not_found');
        }

        try {
            $directory = $pack->resolveDirectory();
            $changelogPath = $directory.DIRECTORY_SEPARATOR.'CHANGELOG.md';
            $entries = is_file($changelogPath) ? ChangelogParser::parse($changelogPath) : [];
            $rawContent = is_file($changelogPath) ? (string) file_get_contents($changelogPath) : '';

            return $this->success('language_packs.fetch_success', [
                'identifier' => $pack->identifier,
                'entries' => $entries,
                'changelog' => $rawContent,
                'has_changelog' => $entries !== [] || $rawContent !== '',
            ]);
        } catch (\Throwable $e) {
            return $this->error('language_packs.fetch_failed', 500, $e->getMessage(), ['error' => $e->getMessage()]);
        }
    }
}
