<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ConcurrentModificationException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\TemplateCustomTranslation\BulkDestroyCustomTranslationRequest;
use App\Http\Requests\TemplateCustomTranslation\IndexCustomTranslationRequest;
use App\Http\Requests\TemplateCustomTranslation\StoreCustomTranslationRequest;
use App\Http\Requests\TemplateCustomTranslation\UpdateCustomTranslationRequest;
use App\Http\Resources\TemplateCustomTranslationResource;
use App\Services\TemplateCustomTranslationService;
use App\Services\TemplateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 템플릿 커스텀 다국어 키 컨트롤러.
 *
 * 레이아웃 편집기 인라인 편집/번역 탭에서 동적 다국어 키를
 * CRUD 합니다. 권한은 `core.templates.layouts.edit` 미들웨어에서 처리합니다.
 */
class TemplateCustomTranslationController extends AdminBaseController
{
    /**
     * @param  TemplateCustomTranslationService  $service  커스텀 다국어 키 서비스
     * @param  TemplateService  $templateService  템플릿 서비스 (식별자 → ID 변환)
     */
    public function __construct(
        private readonly TemplateCustomTranslationService $service,
        private readonly TemplateService $templateService,
    ) {
        parent::__construct();
    }

    /**
     * 커스텀 다국어 키 목록 조회
     *
     * @param  IndexCustomTranslationRequest  $request  목록 조회 요청
     * @param  string  $templateName  템플릿 identifier
     * @return JsonResponse 커스텀 키 목록 응답
     */
    public function index(IndexCustomTranslationRequest $request, string $templateName): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $list = $this->service->getList(
            $template->id,
            $request->input('layout_name'),
            $request->input('status'),
        );

        return $this->success(
            'common.success',
            TemplateCustomTranslationResource::collection($list),
        );
    }

    /**
     * 커스텀 다국어 키 생성 (인라인 편집 확정)
     *
     * @param  StoreCustomTranslationRequest  $request  생성 요청
     * @param  string  $templateName  템플릿 identifier
     * @return JsonResponse 생성된 커스텀 키 응답
     */
    public function store(StoreCustomTranslationRequest $request, string $templateName): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        try {
            DB::beginTransaction();

            $model = $this->service->createKey(
                templateId: $template->id,
                layoutName: $request->input('layout_name'),
                locale: $request->input('locale'),
                value: $request->input('value'),
                createdBy: $this->getCurrentUser()?->id,
            );

            DB::commit();

            return $this->success(
                'common.success',
                new TemplateCustomTranslationResource($model),
                201,
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('common.failed', 500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 커스텀 다국어 키 수정 (번역 탭 일괄 편집, 낙관적 잠금)
     *
     * @param  UpdateCustomTranslationRequest  $request  수정 요청
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $id  커스텀 키 ID
     * @return JsonResponse 수정된 커스텀 키 응답 (충돌 시 409)
     */
    public function update(UpdateCustomTranslationRequest $request, string $templateName, int $id): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $model = $this->service->find($id);

        if ($model === null || (int) $model->template_id !== (int) $template->id) {
            return $this->notFound('common.not_found');
        }

        try {
            DB::beginTransaction();

            $updated = $this->service->updateValues(
                id: $id,
                values: $request->input('values'),
                expectedLockVersion: (int) $request->input('expected_lock_version'),
                updatedBy: $this->getCurrentUser()?->id,
            );

            DB::commit();

            return $this->success(
                'common.success',
                new TemplateCustomTranslationResource($updated),
            );
        } catch (ConcurrentModificationException $e) {
            DB::rollBack();

            return $this->error(
                'exceptions.concurrent_modification',
                409,
                [
                    'error' => 'concurrent_modification',
                    'current_version' => $e->currentVersion,
                    'your_version' => $e->expectedVersion,
                    'resource' => $e->resource,
                ],
                ['resource' => $e->resource, 'current' => $e->currentVersion, 'expected' => $e->expectedVersion],
            );
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return $this->notFound('common.not_found');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('common.failed', 500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 커스텀 다국어 키 삭제
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $id  커스텀 키 ID
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroy(string $templateName, int $id): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $model = $this->service->find($id);

        if ($model === null || (int) $model->template_id !== (int) $template->id) {
            return $this->notFound('common.not_found');
        }

        try {
            DB::beginTransaction();

            $this->service->deleteKey($id);

            DB::commit();

            return $this->success('common.success');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('common.failed', 500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 커스텀 다국어 키 일괄 삭제 (관리 모달 "선택 삭제"/"미사용 전체 삭제")
     *
     * 요청 ids 중 해당 템플릿 소속 키만 삭제합니다(교차 템플릿 삭제 차단).
     *
     * @param  BulkDestroyCustomTranslationRequest  $request  일괄 삭제 요청
     * @param  string  $templateName  템플릿 identifier
     * @return JsonResponse 삭제 결과 응답 (삭제 건수 포함)
     */
    public function bulkDestroy(BulkDestroyCustomTranslationRequest $request, string $templateName): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        // 요청 ids 중 해당 템플릿 소속만 추려 교차 템플릿 삭제를 차단.
        $ownedIds = [];
        foreach ((array) $request->input('ids', []) as $id) {
            $model = $this->service->find((int) $id);
            if ($model !== null && (int) $model->template_id === (int) $template->id) {
                $ownedIds[] = (int) $id;
            }
        }

        if ($ownedIds === []) {
            return $this->success('common.success', ['deleted' => 0]);
        }

        try {
            DB::beginTransaction();

            $deleted = $this->service->deleteKeys($ownedIds);

            DB::commit();

            return $this->success('common.success', ['deleted' => $deleted]);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('common.failed', 500, ['error' => $e->getMessage()]);
        }
    }
}
