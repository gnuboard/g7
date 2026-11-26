<?php

namespace Plugins\Sirsoft\Popup\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\DisplaySettingsRequest;
use Plugins\Sirsoft\Popup\Support\DisplaySettings;
use Plugins\Sirsoft\Popup\Support\UploadLimit;

/**
 * 표시 방식 조회 컨트롤러 — 목록 상단 요약·등록/수정 미리보기·권장 이미지 크기 안내용(팝업 조회 권한).
 *
 * 설정 저장은 코어 플러그인 설정 화면(`core.plugins.update`)이 한다. 그 권한이 없는 팝업 담당자도 지금 표시 방식을
 * 보고 미리볼 수 있게 읽기만 따로 둔다(히어로 섹션 배너 편집과 같은 이유).
 */
class DisplaySettingsController extends AdminBaseController
{
    /**
     * 표시 방식·업로드 한도·설정 화면 이동 가능 여부·편집 보조값(사이트 시간대·서버 시각)을 반환합니다.
     *
     * @param  DisplaySettingsRequest  $request  요청
     * @return JsonResponse 표시 방식 응답
     */
    public function show(DisplaySettingsRequest $request): JsonResponse
    {
        return $this->success('sirsoft-popup::messages.settings.fetched', [
            'settings' => DisplaySettings::current(),
            'upload' => UploadLimit::describe(),
            'can_update_settings' => PermissionHelper::check('core.plugins.update', $request->user()),
            // 등록·수정 화면의 「지금 저장하면 '예약'으로 표시됩니다」 안내 — 사이트 시간대 벽시계로 비교한다
            'editor' => [
                'timezone' => TimezoneHelper::getSiteTimezone(),
                'server_now' => now()->toIso8601String(),
                // 다국어 탭 순서와 기본 언어 — 비운 언어는 사용자 화면에서 이 언어로 보인다
                'default_locale' => config('app.site_locale', config('app.locale')),
                'locales' => array_values(config('app.supported_locales', [])),
            ],
        ]);
    }
}
