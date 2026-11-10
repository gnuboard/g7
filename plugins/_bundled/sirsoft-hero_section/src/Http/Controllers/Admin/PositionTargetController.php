<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\PositionTargetRequest;
use Plugins\Sirsoft\HeroSection\Services\PositionSettingService;

/**
 * 관리자 대상 고르기 컨트롤러 — 동적 위치(게시판마다·상품마다 등)의 대상 목록.
 *
 * 히어로 조회 권한으로 대상을 대신 찾아 준다 — 게시판·상품 관리 권한이 없는 히어로 관리자도 대상을 고를 수 있게(Q6).
 * 내주는 것은 고르기에 필요한 키·이름·보조 표시(slug·상품 코드)뿐이다.
 */
class PositionTargetController extends AdminBaseController
{
    /**
     * PositionTargetController 생성자
     *
     * @param  PositionSettingService  $positions  위치 설정 서비스
     */
    public function __construct(private PositionSettingService $positions)
    {
        parent::__construct();
    }

    /**
     * 대상 고르기 목록과 이미 고른 대상의 이름·사이트 주소를 반환합니다.
     *
     * @param  PositionTargetRequest  $request  목록 요청
     * @param  string  $position  위치 키
     * @return JsonResponse 대상 목록 응답 (동적 위치가 아니면 404)
     */
    public function index(PositionTargetRequest $request, string $position): JsonResponse
    {
        $data = $request->validated();
        $targets = $this->positions->targets($position, (string) ($data['keyword'] ?? ''), isset($data['key']) && $data['key'] !== '' ? (string) $data['key'] : null);
        if ($targets === null) {
            return $this->notFound('sirsoft-hero_section::messages.position.not_found');
        }

        return $this->success('sirsoft-hero_section::messages.position.targets_fetched', $targets);
    }
}
