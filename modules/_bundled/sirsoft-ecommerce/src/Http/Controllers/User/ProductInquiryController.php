<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateInquiryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateInquiryReplyRequest;
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
     * @return JsonResponse 내 문의 목록 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            $this->logApiUsage('inquiry.user_list');
            $perPage = (int) (request()->query('per_page', 10));
            $filters = array_filter([
                'search'      => request()->query('search'),
                'is_answered' => request()->query('is_answered'),
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
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
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
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
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
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
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
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.reply_delete_failed', 500);
        }
    }
}
