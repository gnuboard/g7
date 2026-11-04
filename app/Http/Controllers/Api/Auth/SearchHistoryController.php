<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Http\Requests\SearchHistory\DestroyAllSearchHistoryRequest;
use App\Http\Requests\SearchHistory\MergeSearchHistoryRequest;
use App\Http\Requests\SearchHistory\SearchHistoryIndexRequest;
use App\Http\Requests\SearchHistory\StoreSearchHistoryRequest;
use App\Http\Requests\SearchHistory\UpdateSearchHistorySettingsRequest;
use App\Http\Resources\SearchHistoryResource;
use App\Services\SearchHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 내 최근 검색어 컨트롤러 (`/api/me/search-history`)
 *
 * 본인 전용 묶음이라 새 권한을 두지 않는다. 검색창 제출 때 엔진(`G7Core.searchHistory`)이 기록하고, 펼침 목록이
 * 읽고 지운다. 비회원 목록은 브라우저에 있고 로그인 직후 `merge` 로 옮겨진다.
 *
 * @since 7.1.5
 */
class SearchHistoryController extends AuthBaseController
{
    /**
     * @param  SearchHistoryService  $searchHistoryService  최근 검색어 서비스
     */
    public function __construct(
        private readonly SearchHistoryService $searchHistoryService,
    ) {
        parent::__construct();
    }

    /**
     * 범위의 내 최근 검색어 목록을 조회합니다.
     *
     * @param  SearchHistoryIndexRequest  $request  목록 조회 요청 (scope)
     * @return JsonResponse `{enabled, auto_save, max_count, items}` 응답
     */
    public function index(SearchHistoryIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->searchHistoryService->list($request->user(), (string) $request->validated('scope'));

            return $this->success('search.history.messages.list_success', [
                'enabled' => $result['enabled'],
                'auto_save' => $result['auto_save'],
                'max_count' => $result['max_count'],
                'items' => SearchHistoryResource::collection($result['items'])->resolve($request),
            ]);
        } catch (\Throwable $e) {
            Log::error('최근 검색어 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error('search.history.messages.failed', 500);
        }
    }

    /**
     * 검색어 하나를 기록합니다 (자동 저장을 껐거나 기능이 꺼져 있으면 기록하지 않고 `recorded=false`).
     *
     * @param  StoreSearchHistoryRequest  $request  기록 요청 (scope, keyword)
     * @return JsonResponse `{recorded, item}` 응답
     */
    public function store(StoreSearchHistoryRequest $request): JsonResponse
    {
        try {
            $row = $this->searchHistoryService->record(
                $request->user(),
                (string) $request->validated('scope'),
                (string) $request->validated('keyword'),
            );

            return $this->success(
                $row ? 'search.history.messages.record_success' : 'search.history.messages.record_skipped',
                [
                    'recorded' => $row !== null,
                    'item' => $row ? (new SearchHistoryResource($row))->resolve($request) : null,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('최근 검색어 기록 실패', ['error' => $e->getMessage()]);

            return $this->error('search.history.messages.failed', 500);
        }
    }

    /**
     * 내 검색어 한 건을 삭제합니다 (다른 회원의 것이면 404).
     *
     * @param  Request  $request  인증 사용자 요청
     * @param  int  $id  검색어 ID
     * @return JsonResponse 삭제 결과 응답
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — user() 만 참조 (검증 대상 필드 없음)
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->searchHistoryService->delete($request->user(), $id)) {
                return $this->notFound('search.history.messages.not_found');
            }

            return $this->success('search.history.messages.delete_success', ['deleted' => 1]);
        } catch (\Throwable $e) {
            Log::error('최근 검색어 삭제 실패', ['error' => $e->getMessage()]);

            return $this->error('search.history.messages.failed', 500);
        }
    }

    /**
     * 내 검색어를 모두 삭제합니다 (범위를 주면 그 범위만).
     *
     * @param  DestroyAllSearchHistoryRequest  $request  전체 삭제 요청 (scope 선택)
     * @return JsonResponse `{deleted}` 응답
     */
    public function destroyAll(DestroyAllSearchHistoryRequest $request): JsonResponse
    {
        try {
            $scope = $request->validated('scope');
            $deleted = $this->searchHistoryService->clear($request->user(), is_string($scope) && $scope !== '' ? $scope : null);

            return $this->success('search.history.messages.clear_success', ['deleted' => $deleted]);
        } catch (\Throwable $e) {
            Log::error('최근 검색어 전체 삭제 실패', ['error' => $e->getMessage()]);

            return $this->error('search.history.messages.failed', 500);
        }
    }

    /**
     * 브라우저(비회원) 최근 검색어를 내 목록에 병합합니다.
     *
     * @param  MergeSearchHistoryRequest  $request  병합 요청 (items)
     * @return JsonResponse `{merged}` 응답
     */
    public function merge(MergeSearchHistoryRequest $request): JsonResponse
    {
        try {
            $merged = $this->searchHistoryService->merge($request->user(), (array) $request->validated('items', []));

            return $this->success('search.history.messages.merge_success', ['merged' => $merged]);
        } catch (\Throwable $e) {
            Log::error('최근 검색어 병합 실패', ['error' => $e->getMessage()]);

            return $this->error('search.history.messages.failed', 500);
        }
    }

    /**
     * 내 검색어 자동 저장 여부를 바꿉니다 (끄면 기록을 멈추고 목록을 감춘다 — 저장된 검색어는 지우지 않는다).
     *
     * @param  UpdateSearchHistorySettingsRequest  $request  설정 요청 (auto_save)
     * @return JsonResponse `{auto_save}` 응답
     */
    public function updateSettings(UpdateSearchHistorySettingsRequest $request): JsonResponse
    {
        try {
            $autoSave = $this->searchHistoryService->setAutoSave($request->user(), $request->boolean('auto_save'));

            return $this->success(
                $autoSave ? 'search.history.messages.auto_save_on' : 'search.history.messages.auto_save_off',
                ['auto_save' => $autoSave],
            );
        } catch (\Throwable $e) {
            Log::error('최근 검색어 자동 저장 설정 실패', ['error' => $e->getMessage()]);

            return $this->error('search.history.messages.failed', 500);
        }
    }
}
