<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\IpAddress;
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
        $config = AnalyticsConfig::fromSettings();

        $found = $this->subjects->find(
            $request->subject(),
            $config,
            $request->input('from'),
            $request->input('to'),
        );

        return $this->success('analytics.privacy.subjects_fetch_success', [
            'visits' => $found['visits']->map(fn ($visit): array => [
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'visit_date' => $visit->visit_date?->toDateString(),
                'first_action_at' => AnalyticsClock::toSiteDateTimeString($visit->first_action_at),
                'last_action_at' => AnalyticsClock::toSiteDateTimeString($visit->last_action_at),
                // 화면의 표가 그리는 값 — 주소로 찾은 사람의 주소, 그 방문이 본 쪽수와 남긴 활동 수.
                // 이 경로는 열람 권한 전용이라 주소를 그대로 보여 준다(익명 구간은 애초에 null 이다).
                'ip' => $visit->ip,
                // 그 주소가 지금 설정대로 가려진 값인지 — 열 이름만 설정을 따르면 소급을 건너뛴 기존
                // 행의 원래 주소가 「마스킹됨」 아래 서서 가려진 값으로 읽힌다(7.1.0 실측 O-E8).
                'ip_masked' => IpAddress::isMaskedBy($visit->ip, $config->ipMaskOctets),
                'visitor_type' => $visit->visitor_type instanceof \BackedEnum ? $visit->visitor_type->value : $visit->visitor_type,
                'pageviews_count' => $visit->pageviews_count,
                'actions_count' => $visit->actions_count,
            ])->all(),
            'notice' => $found['notice'],
            // 익명 모드 안내는 이 화면이 **자기 권한 API** 에서 읽어야 한다. 조회 권한(diagnostics)에서
            // 읽으면 개인정보 권한만 가진 담당자에게 403 이 나고, 삼킨 뒤 남은 fallback 이 「full」로
            // 굳어 안내가 영영 뜨지 않는다 — 0건을 받고도 「기록 없음」인지 「IP 를 저장하지 않음」인지
            // 구분 못 해 GDPR 요청에 잘못 답할 수 있다(#682 S3 라운드 5, 설계 결정 방식).
            // 형제 경로(`VisitorController::index`·`show`)가 이미 같은 키를 같은 뜻으로 싣는다.
            'mode' => $config->privacyMode->value,
            // 형제 경로(`VisitorController::show`)와 같은 뜻으로 싣는다 — 이 표도 주소를 그리므로
            // 마스킹된 값을 원래 주소로 읽으면 GDPR 요청에 잘못 답한다(§4.10 「IP(마스킹 표시)」).
            'ip_mask_octets' => $config->ipMaskOctets,
            // 내보내기 파일은 토큰 동반 blob 으로 받아 화면이 저장 이름을 정한다 — 그 이름을 여기서 알려 주지
            // 않으면 화면이 검색값(방문자 IP)을 파일명에 넣었다. 날짜는 사이트 기준이라 화면이 계산할 수 없다.
            'export_filename' => $this->exportFilename(),
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

        $filename = $this->exportFilename();

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

    /**
     * 정보주체 내보내기 파일명을 만듭니다 — 조회 응답과 내보내기 응답이 같은 이름을 쓰도록 한 곳에 둡니다.
     *
     * @return string `analytics-subject-{사이트 기준 Ymd}.json`
     */
    private function exportFilename(): string
    {
        // 사이트 표준시 날짜 — `now()` 는 UTC 라 사이트가 UTC 보다 앞선 시간대면 전날로 나온다.
        return 'analytics-subject-'.str_replace('-', '', AnalyticsClock::today()).'.json';
    }
}
