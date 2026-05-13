<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreInquiryReplyRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateInquiryReplyRequest;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;

/**
 * 상품 1:1 문의 관리 컨트롤러 (관리자)
 *
 * 관리자가 상품 문의를 삭제하고 답변을 등록/수정/삭제할 수 있는 API를 제공합니다.
 */
class ProductInquiryController extends AdminBaseController
{
    public function __construct(
        private ProductInquiryService $inquiryService
    ) {}

    /**
     * 답변 등록
     *
     * @param  StoreInquiryReplyRequest  $request  답변 작성 요청
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 답변 등록 결과 JSON 응답
     */
    public function reply(StoreInquiryReplyRequest $request, int $inquiryId): JsonResponse
    {
        try {
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
     * 문의 삭제 (관리자)
     *
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(int $inquiryId): JsonResponse
    {
        try {
            $this->inquiryService->deleteInquiry($inquiryId);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.inquiries.deleted', ['deleted' => true]);
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.delete_failed', 500);
        }
    }

    /**
     * 답변 수정 (관리자)
     *
     * @param  UpdateInquiryReplyRequest  $request  답변 수정 요청
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 답변 수정 결과 JSON 응답
     */
    public function updateReply(UpdateInquiryReplyRequest $request, int $inquiryId): JsonResponse
    {
        try {
            $this->inquiryService->updateReply($inquiryId, $request->validated());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.inquiries.reply_updated', ['id' => $inquiryId]);
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.reply_update_failed', 500);
        }
    }

    /**
     * 답변 삭제 (관리자)
     *
     * @param  int  $inquiryId  문의 ID
     * @return JsonResponse 답변 삭제 결과 JSON 응답
     */
    public function destroyReply(int $inquiryId): JsonResponse
    {
        try {
            $this->inquiryService->deleteReply($inquiryId);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.inquiries.reply_deleted', ['deleted' => true]);
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.inquiries.reply_delete_failed', 500);
        }
    }
}
