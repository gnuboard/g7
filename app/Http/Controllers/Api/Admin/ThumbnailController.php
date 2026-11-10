<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Thumbnail\ThumbnailPregenerateRequest;
use App\Http\Requests\Admin\Thumbnail\ThumbnailPurgeRequest;
use App\Http\Requests\Admin\Thumbnail\ThumbnailStatsRequest;
use App\Services\ThumbnailAdminService;
use App\Support\SafeExceptionMessage;
use Illuminate\Http\JsonResponse;

/**
 * 썸네일 캐시 관리 컨트롤러 (환경설정 > 업로드 탭 「저장 위치 · 캐시」)
 *
 * 저장 위치별 사용량 조회, 비우기, 기존 이미지 썸네일 만들기(분할 처리)를 제공합니다.
 */
class ThumbnailController extends AdminBaseController
{
    /**
     * ThumbnailController 생성자
     *
     * @param  ThumbnailAdminService  $thumbnailAdminService  썸네일 캐시 관리 서비스
     */
    public function __construct(
        private readonly ThumbnailAdminService $thumbnailAdminService
    ) {
        parent::__construct();
    }

    /**
     * 저장 위치(공개·권한)별 사용량과 화면 선택지(규격·원본 종류)를 조회합니다.
     *
     * @param  ThumbnailStatsRequest  $request  조회 요청
     * @return JsonResponse 사용량 JSON 응답
     */
    public function stats(ThumbnailStatsRequest $request): JsonResponse
    {
        try {
            return $this->success('thumbnail.messages.stats_loaded', $this->thumbnailAdminService->stats());
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, ['error' => SafeExceptionMessage::for($e, ['operation' => __METHOD__])]);
        }
    }

    /**
     * 고른 저장 위치(·규격)의 썸네일을 한 걸음 지웁니다 — 화면이 `next_cursor` 가 null 이 될 때까지 다시 부른다.
     *
     * @param  ThumbnailPurgeRequest  $request  비우기 요청
     * @return JsonResponse 위치별 지운 파일 수와 다음 커서 JSON 응답
     */
    public function purge(ThumbnailPurgeRequest $request): JsonResponse
    {
        try {
            $result = $this->thumbnailAdminService->purge(
                (string) $request->validated('location'),
                $request->validated('preset'),
                $request->validated('cursor')
            );

            return $this->success('thumbnail.messages.purged', $result);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, ['error' => SafeExceptionMessage::for($e, ['operation' => __METHOD__])]);
        }
    }

    /**
     * 기존 이미지 썸네일 만들기 한 번분을 처리합니다 — 화면이 `next_cursor` 가 null 이 될 때까지 다시 부른다.
     *
     * @param  ThumbnailPregenerateRequest  $request  미리 만들기 요청
     * @return JsonResponse 진행 결과 JSON 응답
     */
    public function pregenerate(ThumbnailPregenerateRequest $request): JsonResponse
    {
        try {
            $result = $this->thumbnailAdminService->pregenerate(
                $request->validated('cursor'),
                array_values((array) ($request->validated('sources') ?? [])),
                array_values((array) ($request->validated('presets') ?? []))
            );

            return $this->success('thumbnail.messages.pregenerate_progressed', $result);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, ['error' => SafeExceptionMessage::for($e, ['operation' => __METHOD__])]);
        }
    }
}
