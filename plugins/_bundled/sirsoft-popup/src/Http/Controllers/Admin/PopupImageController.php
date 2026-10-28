<?php

namespace Plugins\Sirsoft\Popup\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Popup\Exceptions\PopupImageAttachedException;
use Plugins\Sirsoft\Popup\Exceptions\PopupImageUploadException;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\PopupImageUploadRequest;
use Plugins\Sirsoft\Popup\Http\Resources\PopupImageResource;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Services\PopupImageService;

/**
 * 관리자 팝업 이미지 컨트롤러 — 임시 묶음 업로드(같은 기기 교체)·임시 이미지 삭제.
 *
 * 팝업에 붙은 이미지는 여기서 바꾸거나 지우지 않는다 — 팝업 등록·수정 저장(`PopupService`)만 바꾼다.
 */
class PopupImageController extends AdminBaseController
{
    /**
     * PopupImageController 생성자
     *
     * @param  PopupImageService  $images  이미지 서비스
     */
    public function __construct(private PopupImageService $images)
    {
        parent::__construct();
    }

    /**
     * 이미지를 올립니다.
     *
     * @param  PopupImageUploadRequest  $request  업로드 요청
     * @return JsonResponse 올린 이미지 응답
     */
    public function store(PopupImageUploadRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $image = $this->images->upload($request->file('file'), $data['device'], $data['temp_key'], $request->user()?->id);
        } catch (PopupImageUploadException $e) {
            // 저장소·DB 장애라 입력 오류(4xx)가 아니다 — 서비스가 파일 정리를 시도하고 실패분은 대장에 남긴다
            return $this->error($e->getMessageKey(), 500);
        }

        return $this->successWithResource('sirsoft-popup::messages.image.uploaded', new PopupImageResource($image), 201);
    }

    /**
     * 아직 팝업에 붙지 않은 임시 이미지를 지웁니다.
     *
     * @param  PopupImage  $image  대상 이미지 (라우트 모델 바인딩)
     * @return JsonResponse 결과 응답
     */
    public function destroy(PopupImage $image): JsonResponse
    {
        try {
            $deleted = $this->images->deleteTempImage($image);
        } catch (PopupImageAttachedException $e) {
            return $this->error($e->getMessageKey(), 422);
        }

        if (! $deleted) {
            return $this->error('sirsoft-popup::messages.image.delete_failed', 500);
        }

        return $this->success('sirsoft-popup::messages.image.deleted');
    }
}
