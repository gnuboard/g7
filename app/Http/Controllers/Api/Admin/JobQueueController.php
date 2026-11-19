<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Queue\BulkDeleteFailedJobRequest;
use App\Http\Requests\Queue\BulkRetryFailedJobRequest;
use App\Http\Requests\Queue\FailedJobListRequest;
use App\Http\Requests\Queue\PendingJobListRequest;
use App\Http\Requests\Queue\QueueJobLogListRequest;
use App\Http\Resources\FailedJobCollection;
use App\Http\Resources\FailedJobDetailResource;
use App\Http\Resources\PendingJobCollection;
use App\Http\Resources\QueueJobLogCollection;
use App\Http\Resources\QueueJobLogResource;
use App\Services\JobQueueService;
use Illuminate\Http\JsonResponse;

/**
 * 관리자 큐 관리 컨트롤러 — 대기·실패·처리 기록 (#17)
 *
 * 경로는 `/api/admin/schedules/queue/*`(스케줄 관리 화면의 큐 탭). 권한은 라우트 미들웨어
 * `core.queue_jobs.read|retry|delete` 가 건다.
 */
class JobQueueController extends AdminBaseController
{
    /**
     * @param  JobQueueService  $jobQueueService  큐 운영 서비스
     */
    public function __construct(
        private readonly JobQueueService $jobQueueService
    ) {
        parent::__construct();
    }

    /**
     * 큐 상태(요약 + 워커 상태 보고서 + 등록 연결·큐)를 조회합니다.
     *
     * @return JsonResponse 상태 응답
     */
    public function status(): JsonResponse
    {
        return $this->success('queue.messages.status_loaded', $this->jobQueueService->status());
    }

    /**
     * 대기 작업 목록을 조회합니다.
     *
     * @param  PendingJobListRequest  $request  목록 요청
     * @return JsonResponse 목록 응답 (`meta.measurable=false` 면 이 드라이버는 대기 작업을 셀 수 없다)
     */
    public function pending(PendingJobListRequest $request): JsonResponse
    {
        $result = $this->jobQueueService->paginatePending($request->validated());

        return $this->successWithResource(
            'queue.messages.pending_loaded',
            (new PendingJobCollection($result['page']))->withListMeta([
                'measurable' => $result['measurable'],
                'connection' => $result['connection'],
                'driver' => $result['driver'],
            ])
        );
    }

    /**
     * 실패 작업 목록을 조회합니다.
     *
     * @param  FailedJobListRequest  $request  목록 요청
     * @return JsonResponse 목록 응답
     */
    public function failed(FailedJobListRequest $request): JsonResponse
    {
        return $this->successWithResource(
            'queue.messages.failed_loaded',
            new FailedJobCollection($this->jobQueueService->paginateFailed($request->validated()))
        );
    }

    /**
     * 실패 작업 1건의 상세(예외 전문·payload 메타)를 조회합니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return JsonResponse 상세 응답 (없으면 404)
     */
    public function showFailed(string $uuid): JsonResponse
    {
        $job = $this->jobQueueService->findFailed($uuid);

        if ($job === null) {
            return $this->notFound('queue.messages.failed_not_found');
        }

        return $this->successWithResource('queue.messages.failed_detail_loaded', new FailedJobDetailResource($job));
    }

    /**
     * 실패 작업 1건을 다시 큐에 넣습니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return JsonResponse `data.results[] = {uuid, ok, message}` (없으면 404, 넣지 못하면 422)
     */
    public function retry(string $uuid): JsonResponse
    {
        $result = $this->jobQueueService->retry($uuid);

        if (! $result['ok']) {
            return $this->error('queue.messages.retry_failed', $this->isNotFound($uuid) ? 404 : 422, ['results' => [$result]]);
        }

        return $this->success('queue.messages.retry_success', ['results' => [$result]]);
    }

    /**
     * 실패 작업 여러 건을 다시 큐에 넣습니다 (요청당 최대 100건).
     *
     * 일부가 실패해도 200 으로 건별 결과를 돌려준다 — 화면이 성공·실패 건수를 따로 알린다.
     *
     * @param  BulkRetryFailedJobRequest  $request  일괄 재시도 요청
     * @return JsonResponse `data.results[] = {uuid, ok, message}` + 성공·실패 건수
     */
    public function retryBulk(BulkRetryFailedJobRequest $request): JsonResponse
    {
        $results = $this->jobQueueService->retryMany($request->validated()['uuids']);

        return $this->success('queue.messages.retry_bulk_done', $this->bulkPayload($results), 200, $this->bulkCounts($results));
    }

    /**
     * 실패 작업 1건을 지웁니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return JsonResponse `data.results[]` (없으면 404)
     */
    public function destroy(string $uuid): JsonResponse
    {
        $result = $this->jobQueueService->deleteFailed($uuid);

        if (! $result['ok']) {
            return $this->error('queue.messages.failed_not_found', 404, ['results' => [$result]]);
        }

        return $this->success('queue.messages.delete_success', ['results' => [$result]]);
    }

    /**
     * 실패 작업 여러 건을 지웁니다 (요청당 최대 100건).
     *
     * @param  BulkDeleteFailedJobRequest  $request  일괄 삭제 요청
     * @return JsonResponse `data.results[]` + 성공·실패 건수
     */
    public function destroyBulk(BulkDeleteFailedJobRequest $request): JsonResponse
    {
        $results = $this->jobQueueService->deleteFailedMany($request->validated()['uuids']);

        return $this->success('queue.messages.delete_bulk_done', $this->bulkPayload($results), 200, $this->bulkCounts($results));
    }

    /**
     * 처리 기록 목록을 조회합니다.
     *
     * @param  QueueJobLogListRequest  $request  목록 요청
     * @return JsonResponse 목록 응답
     */
    public function logs(QueueJobLogListRequest $request): JsonResponse
    {
        return $this->successWithResource(
            'queue.messages.logs_loaded',
            new QueueJobLogCollection($this->jobQueueService->paginateLogs($request->validated()))
        );
    }

    /**
     * 처리 기록 1건을 조회합니다.
     *
     * @param  int  $id  처리 기록 ID
     * @return JsonResponse 상세 응답 (없으면 404)
     */
    public function showLog(int $id): JsonResponse
    {
        $log = $this->jobQueueService->findLog($id);

        if ($log === null) {
            return $this->notFound('queue.messages.log_not_found');
        }

        return $this->successWithResource('queue.messages.log_detail_loaded', new QueueJobLogResource($log));
    }

    /**
     * 실패 작업이 없어서 실패했는지 판정합니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return bool 없으면 true
     */
    private function isNotFound(string $uuid): bool
    {
        return $this->jobQueueService->findFailed($uuid) === null;
    }

    /**
     * 일괄 처리 응답 본문을 만듭니다.
     *
     * @param  list<array{uuid: string, ok: bool, message: string}>  $results  건별 결과
     * @return array{results: list<array<string, mixed>>, succeeded: int, failed: int} 응답 본문
     */
    private function bulkPayload(array $results): array
    {
        return ['results' => $results, ...$this->bulkCounts($results)];
    }

    /**
     * 일괄 처리 성공·실패 건수를 셉니다.
     *
     * @param  list<array{uuid: string, ok: bool, message: string}>  $results  건별 결과
     * @return array{succeeded: int, failed: int} 건수
     */
    private function bulkCounts(array $results): array
    {
        $succeeded = count(array_filter($results, fn (array $r): bool => $r['ok']));

        return ['succeeded' => $succeeded, 'failed' => count($results) - $succeeded];
    }
}
