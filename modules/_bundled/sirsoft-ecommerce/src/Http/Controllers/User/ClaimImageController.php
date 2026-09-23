<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\MapsClaimErrors;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UploadClaimImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimImageResource;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 클레임 첨부 이미지 컨트롤러 (회원 업로드·삭제)
 *
 * 업로드(`uploadPending`)는 신청서 작성 중이라 첨부를 **주문에 묶어** 저장하고 신청서에는 매달지 않는다.
 * 소유권을 걸 앵커가 주문뿐이기 때문이다 — 신청 접수 시 `attachPending()` 이 신청서로 옮긴다.
 *
 * 인증은 라우트 그룹(`user` 접두 + `auth:sanctum`)이 강제한다 — 이 클래스는 생성자를 재정의하므로
 * `AuthBaseController` 생성자의 미들웨어 등록은 실행되지 않는다. 로그인 없이 열리는 서빙
 * (한시 서명 URL)은 `Public\ClaimImageDownloadController` 가 맡는다.
 */
class ClaimImageController extends AuthBaseController
{
    use MapsClaimErrors;

    /**
     * 회원 클레임 첨부 컨트롤러를 생성합니다.
     *
     * @param  ClaimAttachmentService  $attachmentService  첨부 서비스
     */
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

        if (! $model || ! $this->attachmentService->deletableBy($model, Auth::id())) {
            return $this->claimNotFound('exceptions.claim_image_not_found');
        }

        try {
            $this->attachmentService->delete($model);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.image_deleted', ['deleted' => true]);
        } catch (Exception $e) {
            return $this->claimServerError("첨부 삭제(#{$image})", $e);
        }
    }
}
