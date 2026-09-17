<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\AnonymizeRequest;
use App\Http\Requests\Admin\Analytics\SubjectDeleteRequest;
use App\Http\Requests\Admin\Analytics\SubjectRequest;
use App\Jobs\Analytics\AnonymizeLedgerJob;
use App\Services\Analytics\DataSubjectService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 개인정보 요청 API
 *
 * 「내 기록을 보여 달라」「지워 달라」는 요청과, 쌓인 기록을 나중에 익명화하는 길을
 * 다룹니다.
 *
 * 지우는 것은 원장뿐입니다 — 집계에는 개인을 가리키는 값이 없어 지울 것이 없고,
 * 집계를 함께 지우면 그 기간 통계가 통째로 사라집니다.
 */
class PrivacyController extends AdminBaseController
{
    /**
     * 개인정보 컨트롤러를 생성합니다.
     *
     * @param  DataSubjectService  $subjects  정보주체 처리기
     */
    public function __construct(
        private readonly DataSubjectService $subjects,
    ) {
        parent::__construct();
    }

    /**
     * 정보주체의 방문을 찾습니다.
     *
     * @param  SubjectRequest  $request  정보주체 조회 요청
     * @return JsonResponse 조회 응답
     */
    public function subjects(SubjectRequest $request): JsonResponse
    {
        $found = $this->subjects->find(
            $request->subject(),
            AnalyticsConfig::fromSettings(),
            $request->input('from'),
            $request->input('to'),
        );

        return $this->success('analytics.privacy.subjects_fetch_success', [
            'visits' => $found['visits']->map(fn ($visit): array => [
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'visit_date' => $visit->visit_date?->toDateString(),
                'first_action_at' => $visit->first_action_at?->toIso8601String(),
                'last_action_at' => $visit->last_action_at?->toIso8601String(),
                'actions_count' => $visit->actions_count,
            ])->all(),
            'notice' => $found['notice'],
        ]);
    }

    /**
     * 정보주체의 기록을 파일로 내보냅니다.
     *
     * 열람권 이행의 산출물이라 운영자가 요청한 사람에게 그대로 건넬 수 있어야 한다 —
     * 화면에 그려 주는 대신 내려받을 파일로 흘려보낸다.
     *
     * @param  SubjectRequest  $request  정보주체 조회 요청
     * @return StreamedResponse 내려받기 응답
     */
    public function export(SubjectRequest $request): StreamedResponse
    {
        $payload = $this->subjects->export(
            $request->subject(),
            AnalyticsConfig::fromSettings(),
            $request->input('from'),
            $request->input('to'),
        );

        $filename = 'analytics-subject-'.AnalyticsClock::now()->format('Ymd').'.json';

        return new StreamedResponse(function () use ($payload): void {
            // 기록을 받는 사람이 읽을 파일이다 — 사람이 볼 수 있게 들여쓰고,
            // 주소·한글 라벨이 이스케이프 코드로 바뀌지 않게 둔다.
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * 정보주체의 기록을 지웁니다.
     *
     * @param  SubjectDeleteRequest  $request  정보주체 삭제 요청
     * @return JsonResponse 삭제 응답
     */
    public function deleteSubject(SubjectDeleteRequest $request): JsonResponse
    {
        return $this->success('analytics.privacy.subject_delete_success', [
            'deleted' => $this->subjects->delete(
                $request->subject(),
                AnalyticsConfig::fromSettings(),
                $request->input('from'),
                $request->input('to'),
            ),
        ]);
    }

    /**
     * 쌓인 기록에 마스킹·해시를 소급 적용합니다.
     *
     * @param  AnonymizeRequest  $request  소급 익명화 요청
     * @return JsonResponse 실행 응답 (202)
     */
    public function anonymize(AnonymizeRequest $request): JsonResponse
    {
        // 쌓인 방문이 많으면 오래 걸린다 — 요청을 붙잡아 두지 않고 워커에게 넘기고,
        // 화면은 실행 이력의 진행률을 물어본다.
        AnonymizeLedgerJob::dispatch(
            (int) $request->input('mask_octets', 0),
            $request->boolean('hash'),
            $request->input('from'),
            $request->input('to'),
            $request->user()?->id,
        );

        return $this->success('analytics.privacy.anonymize_queued', ['queued' => true], 202);
    }
}
