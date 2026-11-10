<?php

// audit:allow api-doc-coverage 609 후속 — 파일 서빙 계약 사유 주석(file-serving:allow)만 더했다. 라우트, 요청, 응답 모양은 그대로라 docs/api 에 반영할 표면이 없다.

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Thumbnails\ThumbnailQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\MapsClaimErrors;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 클레임 첨부 이미지 서빙 컨트롤러 (한시 서명 / 신청자 본인 / 운영자)
 *
 * 이 경로는 로그인 없이도 열린다 — 비회원 신청자와 알림 메일의 이미지가 **한시 서명 URL** 로
 * 들어오기 때문이다(`optional.sanctum`). 그래서 회원 전용 컨트롤러(`User\ClaimImageController`,
 * 업로드·삭제)와 한 클래스에 두지 않는다. 한 클래스에 있으면 "이 컨트롤러는 인증 사용자만
 * 받는다" 는 전제가 한 메서드에서만 거짓이 되고, 그 전제에 기대어 공용 가드를 얹는 순간 서명
 * URL 이 전부 막힌다.
 *
 * 열람 게이트(서명 유효 / 신청자 본인 / 운영자)는 서비스(`ClaimAttachmentService::download()`)가
 * 판정한다 — 직접(CDN) URL 은 없다.
 */
class ClaimImageDownloadController extends PublicBaseController
{
    use MapsClaimErrors;

    /**
     * 클레임 첨부 서빙 컨트롤러를 생성합니다.
     *
     * @param  ClaimAttachmentService  $attachmentService  첨부 서비스
     */
    public function __construct(
        private ClaimAttachmentService $attachmentService,
    ) {}

    /**
     * 첨부 이미지를 서빙합니다. (한시 서명 / 신청자 본인 / 운영자)
     *
     * `?size={규격}`·`?w={숫자}` 가 붙으면 게이트를 지난 래스터 이미지는 썸네일(비공개 디스크·`private` 캐시)로 낸다.
     * 서명은 그 두 파라미터를 빼고 검증한다 — 화면이 발급받은 서명 주소 뒤에 `size` 를 덧붙여도 서명이 깨지지
     * 않게. 썸네일은 원본과 같은 게이트를 지난 뒤 크기만 바꾸므로 접근 범위는 넓어지지 않는다.
     *
     * @param  Request  $request  요청 (서명 검증·`size`·`w`)
     * @param  string  $hash  첨부 해시
     * @return SymfonyResponse|JsonResponse 파일·썸네일 응답(200·304) 또는 404
     */
    // audit:allow controller-base-request-injection reason: GET 이미지 서빙. 요청 URL 의 한시 서명 검증과 썸네일 파라미터(size·w) 원문 판정만 수행. 검증할 body 없음(hash 는 라우트 파라미터)
    public function download(Request $request, string $hash): SymfonyResponse|JsonResponse
    {
        // file-serving:allow 교환/반품 증빙 이미지는 서명 주소나 신청자 본인만 여는 권한 통로라 코어 헬퍼의 운영 캐시 정책(`public, max-age`)을 입히지 않는다. 서비스가 `private, max-age=300` 과 인라인 미디어 경화(래스터만 인라인, nosniff)를 세우고, 게이트를 지난 `?size=`·`?w=` 요청은 같은 서비스가 썸네일 헬퍼(`ThumbnailResponder`)로 낸다.
        $response = $this->attachmentService->download(
            $hash,
            signatureVerified: $request->hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure()),
            viewerUserId: Auth::id(),
            request: $request,
        );

        if (! $response) {
            return $this->claimNotFound('exceptions.claim_image_not_found');
        }

        return $response;
    }
}
