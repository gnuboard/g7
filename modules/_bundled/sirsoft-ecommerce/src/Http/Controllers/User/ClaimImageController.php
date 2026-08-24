<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\MapsClaimErrors;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UploadClaimImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimImageResource;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 클레임 첨부 이미지 컨트롤러 (회원 업로드·삭제 + 공용 서빙)
 *
 * 업로드(`uploadPending`)는 신청서 작성 중이라 첨부를 **주문에 묶어** 저장하고 신청서에는 매달지 않는다.
 * 소유권을 걸 앵커가 주문뿐이기 때문이다 — 신청 접수 시 `attachPending()` 이 신청서로 옮긴다.
 * 서빙(`download`)은 한시 서명 또는 열람자 게이트를 통과해야 한다 — 직접(CDN) URL 은 없다.
 */
class ClaimImageController extends AuthBaseController
{
    use MapsClaimErrors;

    public function __construct(
        private ClaimAttachmentService $attachmentService,
    ) {}

    /**
     * 신청서 작성 중 이미지 업로드 (주문 스코프 · 신청서 미귀속)
     *
     * @param  UploadClaimImageRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    public function store(UploadClaimImageRequest $request): JsonResponse
    {
        $this->logApiUsage('user.claims.images.store');

        try {
            $image = $this->attachmentService->uploadPending($request->file('image'), $request->getOrder(), Auth::id());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.image_uploaded', new ClaimImageResource($image), 201);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            return $this->claimServerError('첨부 업로드', $e);
        }
    }

    /**
     * 첨부 이미지 삭제 (본인 소유만)
     *
     * @param  int  $image  첨부 ID
     * @return JsonResponse 응답
     */
    public function destroy(int $image): JsonResponse
    {
        $this->logApiUsage('user.claims.images.destroy');

        $model = $this->attachmentService->find($image);

        if (! $model || ! $this->attachmentService->ownedBy($model, Auth::id())) {
            return $this->claimNotFound('exceptions.claim_image_not_found');
        }

        try {
            $this->attachmentService->delete($model);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.image_deleted', ['deleted' => true]);
        } catch (Exception $e) {
            return $this->claimServerError("첨부 삭제(#{$image})", $e);
        }
    }

    /**
     * 첨부 이미지 서빙 (한시 서명 / 신청자 본인 / 운영자)
     *
     * @param  Request  $request  요청 (서명 검증용)
     * @param  string  $hash  첨부 해시
     * @return StreamedResponse|JsonResponse 파일 응답 또는 404
     */
    // audit:allow controller-base-request-injection reason: GET 이미지 서빙. 요청 URL 의 한시 서명 검증(hasValidSignature)만 수행. 검증할 body 없음(hash 는 라우트 파라미터)
    public function download(Request $request, string $hash): StreamedResponse|JsonResponse
    {
        $response = $this->attachmentService->download(
            $hash,
            signatureVerified: $request->hasValidSignature(absolute: false),
            viewerUserId: Auth::id(),
        );

        if (! $response) {
            return $this->claimNotFound('exceptions.claim_image_not_found');
        }

        return $response;
    }
}
