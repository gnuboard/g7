<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;

/**
 * 관리자 알림 컨트롤러 (더미)
 *
 * 추후 알림 기능 구현 예정. 현재는 빈 목록을 반환합니다.
 */
class NotificationController extends AdminBaseController
{
    /**
     * 알림 목록을 조회합니다.
     *
     * @return JsonResponse 알림 목록 (현재 빈 배열)
     */
    public function index(): JsonResponse
    {
        // TODO: 실제 알림 기능 구현 시 교체 예정
        return $this->success('messages.success', [
            'data' => [],
            'meta' => [
                'total' => 0,
                'unread_count' => 0,
            ],
        ]);
    }
}
