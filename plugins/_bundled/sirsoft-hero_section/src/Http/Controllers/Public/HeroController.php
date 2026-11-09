<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Public;

use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Http\Requests\PublicHeroRequest;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;

/**
 * 공개 히어로섹션 컨트롤러 — 사용자 템플릿 확장점이 부른다.
 */
class HeroController extends PublicBaseController
{
    /**
     * HeroController 생성자
     *
     * @param  PublicHeroService  $hero  공개 노출 서비스
     */
    public function __construct(private PublicHeroService $hero) {}

    /**
     * 위치의 지금 노출 배너와 슬라이더 설정을 반환합니다(현재 요청 언어).
     *
     * 수정 권한(`abilities.can_update`)은 공개 캐시에 담지 않고 요청마다 붙인다 — 캐시는 방문자 공용이다.
     * 사용자 화면은 이 값으로 「관리자에서 수정」 단추를 그린다.
     *
     * @param  PublicHeroRequest  $request  공개 요청
     * @return JsonResponse 공개 응답
     */
    public function show(PublicHeroRequest $request): JsonResponse
    {
        $position = HeroPosition::from($request->validated()['position']);

        return $this->success('sirsoft-hero_section::messages.banner.fetched', $this->hero->forPosition($position, app()->getLocale()) + [
            'abilities' => ['can_update' => PermissionHelper::check('sirsoft-hero_section.banners.update', $request->user())],
        ]);
    }
}
