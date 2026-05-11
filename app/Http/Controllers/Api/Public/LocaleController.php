<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\LanguagePackService;
use Illuminate\Http\JsonResponse;

/**
 * 공개 로케일 컨트롤러
 *
 * 활성 코어 언어팩을 기반으로 현재 사이트가 즉시 노출 가능한
 * 로케일 목록을 반환합니다. 언어팩 설치/활성화 직후 사용자
 * 언어 셀렉터를 새로고침 없이 갱신하기 위한 엔드포인트입니다.
 */
class LocaleController extends PublicBaseController
{
    public function __construct(
        private LanguagePackService $languagePackService
    ) {
        parent::__construct();
    }

    /**
     * 활성 로케일 목록을 반환합니다.
     *
     * @return JsonResponse 활성 로케일 배열 + locale_names 매핑
     */
    public function active(): JsonResponse
    {
        return $this->success('locales.fetched', [
            'locales' => $this->languagePackService->getActiveLocales(),
            'locale_names' => (array) config('app.locale_names', []),
        ]);
    }
}
