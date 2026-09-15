<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductInquiryOperationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateInquiryReplyRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateInquiryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserInquiryListRequest;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;

/**
 * 상품 1:1 문의 컨트롤러 (사용자)
 *
 * 로그인 사용자의 문의 목록 조회, 수정/삭제 및 답변 등록/수정/삭제 API를 제공합니다.
 */
class ProductInquiryController extends AuthBaseController
{
    public function __construct(
        private ProductInquiryService $inquiryService
    ) {}

    /**
     * 마이페이지 문의 목록 조회
     *
     * @param  UserInquiryListRequest  $request  목록 조회 요청 (페이지네이션 상·하한 검증)
     * @return JsonResponse 내 문의 목록 JSON 응답
     */
    public function index(UserInquiryListRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('inquiry.user_list');
            $validated = $request->validated();
            $perPage = (int) ($validated['per_page'] ?? 10);
            $filters = array_filter([
                'search' => $validated['search'] ?? null,
                'is_answered' => $validated['is_answered'] ?? null,
            ], fn ($v) => ! is_null($v));
            $result = $this->inquiryService->getUserInquiries(Auth::id(), $filters, $perPage);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.inquiries.fetch_success',
                $result
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.fetch_failed', 500);
        }
    }

    /**
     * 문의 수정
     *
     * @param  UpdateInquiryRequest  $request  문의 수정 요청
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 수정 결과 JSON 응답
     */
    public function update(UpdateInquiryRequest $request, int $inquiryId): JsonResponse
    {
        try {
            $inquiry = $this->inquiryService->findById($inquiryId);

            if (! $inquiry) {
                return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.not_found', 404);
            }

            if ($inquiry->user_id !== Auth::id()) {
                return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.forbidden', 403);
            }

            $this->logApiUsage('inquiry.update', ['inquiry_id' => $inquiryId]);
            $this->inquiryService->updateInquiry($inquiryId, $request->validated());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.inquiries.updated', ['id' => $inquiryId]);
        } catch (ProductInquiryOperationException $e) {
            // 실패 사유(문의 게시판 미설정 등)를 그대로 보여준다 — 일반 문구만 남기면
            // 서버 기록을 봐야만 원인을 알 수 있다.
            //
            // 종전에는 `\RuntimeException` 을 잡았다. 서비스가 도메인 실패를 typed 로
            // 승격한 뒤로 그 catch 에 남는 것은 인프라 예외뿐이었고, 그것까지 422 +
            // 예외 원문으로 뭉갰다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.operation_failed_reason',
                422,
                null,
                ['reason' => __($e->getMessageKey(), $e->getMessageParams())]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.update_failed', 500);
        }
    }

    /**
     * 문의 삭제
     *
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(int $inquiryId): JsonResponse
    {
        try {
            $inquiry = $this->inquiryService->findById($inquiryId);

            if (! $inquiry) {
                return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.not_found', 404);
            }

            if ($inquiry->user_id !== Auth::id()) {
                return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.forbidden', 403);
            }

            $this->logApiUsage('inquiry.destroy', ['inquiry_id' => $inquiryId]);
            $this->inquiryService->deleteInquiry($inquiryId);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.inquiries.deleted', ['deleted' => true]);
        } catch (ProductInquiryOperationException $e) {
            // `deleteInquiry` 도 도메인 실패를 typed 로 던지는데 여기에는 그것을 구분하는
            // catch 가 없어, 운영자가 고칠 수 있는 사유(문의 게시판 미설정)가 일반 500
            // 문구로 나갔다. 형제 메서드(수정·답변)와 같은 폭으로 맞춘다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.operation_failed_reason',
                422,
                null,
                ['reason' => __($e->getMessageKey(), $e->getMessageParams())]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.delete_failed', 500);
        }
    }

    /**
     * 답변 등록
     *
     * 답변 권한(`inquiries.update`)을 가진 사용자만 등록 가능합니다.
     *
     * @param  UpdateInquiryReplyRequest  $request  답변 등록 요청
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 답변 등록 결과 JSON 응답
     */
    public function reply(UpdateInquiryReplyRequest $request, int $inquiryId): JsonResponse
    {
        if (! PermissionHelper::check('sirsoft-ecommerce.inquiries.update', Auth::user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.forbidden', 403);
        }

        try {
            $this->logApiUsage('inquiry.reply', ['inquiry_id' => $inquiryId]);
            $inquiry = $this->inquiryService->createReply($inquiryId, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.inquiries.reply_created',
                ['id' => $inquiry->id, 'is_answered' => $inquiry->is_answered],
                201
            );
        } catch (ProductInquiryOperationException $e) {
            // 실패 사유(문의 게시판 미설정 등)를 그대로 보여준다 — 일반 문구만 남기면
            // 서버 기록을 봐야만 원인을 알 수 있다.
            //
            // 종전에는 `\RuntimeException` 을 잡았다. 서비스가 도메인 실패를 typed 로
            // 승격한 뒤로 그 catch 에 남는 것은 인프라 예외뿐이었고, 그것까지 422 +
            // 예외 원문으로 뭉갰다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.operation_failed_reason',
                422,
                null,
                ['reason' => __($e->getMessageKey(), $e->getMessageParams())]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.reply_failed', 500);
        }
    }

    /**
     * 답변 수정
     *
     * 답변 권한(`inquiries.update`)을 가진 사용자만 수정 가능합니다.
     *
     * @param  UpdateInquiryReplyRequest  $request  답변 수정 요청
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 답변 수정 결과 JSON 응답
     */
    public function updateReply(UpdateInquiryReplyRequest $request, int $inquiryId): JsonResponse
    {
        if (! PermissionHelper::check('sirsoft-ecommerce.inquiries.update', Auth::user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.forbidden', 403);
        }

        try {
            $this->logApiUsage('inquiry.update_reply', ['inquiry_id' => $inquiryId]);
            $this->inquiryService->updateReply($inquiryId, $request->validated());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.inquiries.reply_updated', ['id' => $inquiryId]);
        } catch (ProductInquiryOperationException $e) {
            // 실패 사유(문의 게시판 미설정 등)를 그대로 보여준다 — 일반 문구만 남기면
            // 서버 기록을 봐야만 원인을 알 수 있다.
            //
            // 종전에는 `\RuntimeException` 을 잡았다. 서비스가 도메인 실패를 typed 로
            // 승격한 뒤로 그 catch 에 남는 것은 인프라 예외뿐이었고, 그것까지 422 +
            // 예외 원문으로 뭉갰다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.operation_failed_reason',
                422,
                null,
                ['reason' => __($e->getMessageKey(), $e->getMessageParams())]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.reply_update_failed', 500);
        }
    }

    /**
     * 답변 삭제
     *
     * 답변 권한(`inquiries.update`)을 가진 사용자만 삭제 가능합니다.
     *
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 답변 삭제 결과 JSON 응답
     */
    public function destroyReply(int $inquiryId): JsonResponse
    {
        if (! PermissionHelper::check('sirsoft-ecommerce.inquiries.update', Auth::user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.forbidden', 403);
        }

        try {
            $this->logApiUsage('inquiry.destroy_reply', ['inquiry_id' => $inquiryId]);
            $this->inquiryService->deleteReply($inquiryId);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.inquiries.reply_deleted', ['deleted' => true]);
        } catch (ProductInquiryOperationException $e) {
            // 실패 사유(문의 게시판 미설정 등)를 그대로 보여준다 — 일반 문구만 남기면
            // 서버 기록을 봐야만 원인을 알 수 있다.
            //
            // 종전에는 `\RuntimeException` 을 잡았다. 서비스가 도메인 실패를 typed 로
            // 승격한 뒤로 그 catch 에 남는 것은 인프라 예외뿐이었고, 그것까지 422 +
            // 예외 원문으로 뭉갰다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.operation_failed_reason',
                422,
                null,
                ['reason' => __($e->getMessageKey(), $e->getMessageParams())]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.reply_delete_failed', 500);
        }
    }
}
