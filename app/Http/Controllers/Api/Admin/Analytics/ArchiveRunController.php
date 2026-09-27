<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Enums\Analytics\ArchiveRunType;
use App\Extension\HookManager;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\ArchiveRunIndexRequest;
use App\Http\Requests\Admin\Analytics\ArchiveRunStoreRequest;
use App\Jobs\Analytics\ArchiveRangeJob;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Services\Analytics\ArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * 집계 실행 API
 *
 * 화면의 「지금 집계」·「다시 집계」 버튼과 그 진행률 폴링을 받습니다.
 *
 * 몇 달치 재집계는 요청 하나가 몇 분씩 걸리므로 워커에게 넘기고 실행 이력 번호만
 * 바로 돌려줍니다. 화면은 그 번호로 진행률을 물어봅니다.
 */
class ArchiveRunController extends AdminBaseController
{
    /**
     * 집계 실행 컨트롤러를 생성합니다.
     *
     * @param  ArchiveRunRepositoryInterface  $runs  실행 이력 저장소
     * @param  ArchiveService  $archive  집계 서비스
     */
    public function __construct(
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly ArchiveService $archive,
    ) {
        parent::__construct();
    }

    /**
     * 실행 이력 목록을 조회합니다.
     *
     * @param  ArchiveRunIndexRequest  $request  이력 목록 요청
     * @return JsonResponse 이력 목록 응답
     */
    public function index(ArchiveRunIndexRequest $request): JsonResponse
    {
        $paginator = $this->runs->getPaginated(
            array_filter([
                'run_type' => $request->input('run_type'),
                'status' => $request->input('status'),
            ]),
            (int) $request->input('per_page', 20),
        );

        return $this->success('analytics.archive_runs.fetch_success', [
            'data' => array_map([$this, 'runForScreen'], $paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * 실행 이력 하나를 조회합니다 (진행률 폴링).
     *
     * @param  int  $run  실행 이력 ID
     * @return JsonResponse 이력 응답
     */
    public function show(int $run): JsonResponse
    {
        $found = $this->runs->findById($run);

        if ($found === null) {
            return $this->error('analytics.archive_runs.not_found', 404);
        }

        return $this->success('analytics.archive_runs.fetch_success', $this->runForScreen($found));
    }

    /**
     * 집계를 수동으로 실행합니다.
     *
     * @param  ArchiveRunStoreRequest  $request  실행 요청
     * @return JsonResponse 실행 응답 (202)
     */
    public function store(ArchiveRunStoreRequest $request): JsonResponse
    {
        $runType = (string) $request->input('run_type');
        $userId = $request->user()?->id;

        HookManager::doAction('core.analytics.archive_requested', [
            'run_type' => $runType,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'triggered_by' => $userId,
        ]);

        if ($runType === ArchiveRunType::Reaggregate->value) {
            // 재집계는 얼마나 걸릴지 모른다 — 요청을 붙잡아 두지 않고 워커에게 넘긴다.
            ArchiveRangeJob::dispatch(
                (string) $request->input('from'),
                (string) $request->input('to'),
                $request->boolean('force'),
                $userId,
            );

            return $this->success('analytics.archive_runs.queued', ['queued' => true], 202);
        }

        $date = $runType === ArchiveRunType::Day->value
            ? Carbon::parse(AnalyticsClock::today())->subDay()->toDateString()
            : AnalyticsClock::today();

        $run = $this->archive->archiveDay($date, [
            'run_type' => $runType,
            'trigger' => 'manual',
            'is_final' => $runType === ArchiveRunType::Day->value,
            'triggered_by' => $userId,
        ]);

        // 다른 집계가 자물쇠를 쥐고 있으면 이력에 「건너뜀」만 남는다 — 상태를 함께 돌려주지
        // 않으면 화면이 성공과 구별하지 못해 돌지도 않은 집계를 돌았다고 알린다.
        return $this->success('analytics.archive_runs.queued', [
            'run_id' => $run?->id,
            'status' => $run?->status instanceof \BackedEnum ? (string) $run->status->value : $run?->status,
            'queued' => false,
        ], 202);
    }

    /**
     * 실행 이력 한 행을 화면이 읽을 모양으로 바꿉니다.
     *
     * 집계 경계는 사이트 자정에 잠겨 있고 저장만 UTC 다(`AnalyticsClock::dayRangeUtc`). 원본을
     * 그대로 내보내면 KST 사이트에서 기간이 「15:00 ~ 15:00」으로 보여, 같은 표의 다른 시각 열과
     * 기준이 갈린다 — 운영자가 9시간 어긋난 구간을 읽는다(#682 S3 라운드 6, 설계 결정).
     *
     * @param  AnalyticsArchiveRun  $run  실행 이력
     * @return array<string, mixed> 화면용 실행 이력
     */
    private function runForScreen(AnalyticsArchiveRun $run): array
    {
        return $run->toScreenArray();
    }
}
