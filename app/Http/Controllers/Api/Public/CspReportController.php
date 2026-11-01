<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Csp\CspReportRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * 코어 CSP 위반 보고 수신 API (#158)
 *
 * 관리자 환경설정 > 보안의 CSP 모드가 '점검만'·'강제'이면 정책의 `report-uri /api/csp-report` 로
 * 브라우저가 위반을 보낸다. 받은 위반은 로그 채널 `csp`(일 단위 `storage/logs/csp-YYYY-MM-DD.log`)에 한 줄씩 남긴다.
 *
 * **응답은 언제나 204 다**(본문이 16KB 를 넘을 때만 413). 형식이 잘못된 보고도 204 로 끊고 기록만
 * 생략한다 — 보고에 4xx 를 돌려주면 방문자 콘솔에 오류가 쌓인다(방문자 통계 비콘과 같은 관례).
 *
 * 기록하는 것: 문서 주소·차단 주소·원본 파일(쿼리스트링 제거), 지시어, disposition, 줄 번호,
 * 샘플 앞 80자. 요청 IP 는 기록하지 않는다(개인정보 최소 수집). 쿼리스트링에는 토큰·검색어 같은
 * 개인 값이 실릴 수 있어 경로까지만 남긴다.
 *
 * 두 본문 형식(report-uri · Reporting API)의 해석은 {@see CspReportRequest} 가 맡는다.
 */
class CspReportController extends PublicBaseController
{
    /**
     * 위반 보고를 받아 로그에 남깁니다.
     *
     * @param  CspReportRequest  $request  위반 보고 요청
     * @return Response 본문 없는 204 (16KB 초과는 413)
     */
    public function store(CspReportRequest $request): Response
    {
        if ($request->isOversized()) {
            return response('', 413);
        }

        foreach ($request->reports() as $report) {
            Log::channel('csp')->info('csp-violation', $report);
        }

        return response()->noContent();
    }
}
