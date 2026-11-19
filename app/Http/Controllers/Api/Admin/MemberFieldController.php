<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Fields\MemberFieldInfoRequest;
use App\Services\DisplayNameSettingsService;
use App\Services\MemberFieldSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * 관리자 — 환경설정 회원 탭의 안내 값 컨트롤러.
 *
 * 닉네임 중복 금지를 켜기 전의 겹침 안내와, 켠 뒤 도는 중복 검사 준비 작업의 진행 표시가 쓴다.
 *
 * @since 7.2.0
 */
class MemberFieldController extends AdminBaseController
{
    /**
     * @param  MemberFieldSettingsService  $service  회원 탭 안내 Service
     * @param  DisplayNameSettingsService  $displayNames  표시 이름 구획 Service
     */
    public function __construct(
        protected MemberFieldSettingsService $service,
        protected DisplayNameSettingsService $displayNames,
    ) {
        parent::__construct();
    }

    /**
     * 지금 겹치는 닉네임의 종 수와 회원 수를 조회합니다.
     *
     * @param  MemberFieldInfoRequest  $request  검증된 요청
     * @return JsonResponse 겹침 요약
     */
    public function nicknameDuplicates(MemberFieldInfoRequest $request): JsonResponse
    {
        return $this->success('common.success', $this->service->nicknameDuplicates());
    }

    /**
     * 중복 검사 준비 작업의 진행 상태를 조회합니다.
     *
     * @param  MemberFieldInfoRequest  $request  검증된 요청
     * @return JsonResponse 진행 상태
     */
    public function nicknameRecomputeStatus(MemberFieldInfoRequest $request): JsonResponse
    {
        return $this->success('common.success', $this->service->nicknameRecomputeStatus());
    }

    /**
     * 닉네임이 비어 있는 회원 수를 조회합니다 (표시 이름을 닉네임 종류로 바꾸기 전 안내).
     *
     * @param  MemberFieldInfoRequest  $request  검증된 요청
     * @return JsonResponse 닉네임이 빈 회원 수와 닉네임 항목의 지금 상태
     */
    public function nicknameEmptyCount(MemberFieldInfoRequest $request): JsonResponse
    {
        return $this->success('common.success', $this->displayNames->nicknameEmptyCount());
    }

    /**
     * 표시 이름 구획이 그릴 종류와 영역 목록, 지금 설정을 조회합니다.
     *
     * @param  MemberFieldInfoRequest  $request  검증된 요청
     * @return JsonResponse 종류 목록(미리보기 포함), 영역 목록(지금 방식 포함), 회원 선택 설정
     */
    public function displayNameOptions(MemberFieldInfoRequest $request): JsonResponse
    {
        return $this->success('common.success', $this->displayNames->options());
    }
}
