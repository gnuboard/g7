<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\UpdateTargetSettingRequest;
use Plugins\Sirsoft\HeroSection\Services\PositionSettingService;

/**
 * 관리자 대상별 슬라이더 설정 컨트롤러 — 동적 위치의 한 대상만 위치 기본값 위에 재정의·초기화한다.
 *
 * 정적 위치·등록되지 않은 위치는 404. 대상 키는 해석기가 준 정규 키(최대 100자)다. 지금 없는 대상(지워진 게시판 등)은 조회·초기화만 받고
 * 새 재정의 저장은 422 로 막는다 — 배너와 같은 계약(지워진 대상의 배너는 남아 「없는 대상」으로 관리)이다.
 */
class TargetSettingController extends AdminBaseController
{
    /**
     * TargetSettingController 생성자
     *
     * @param  PositionSettingService  $settings  위치 설정 서비스
     */
    public function __construct(private PositionSettingService $settings)
    {
        parent::__construct();
    }

    /**
     * 한 대상의 설정(위치 기본값·재정의·최종 값·출처)을 반환합니다.
     *
     * @param  string  $position  위치 키
     * @param  string  $target  대상 키
     * @return JsonResponse 대상 설정 응답
     */
    public function show(string $position, string $target): JsonResponse
    {
        if (! $this->settings->isDynamic($position)) {
            return $this->notFound('sirsoft-hero_section::messages.position.not_found');
        }

        return $this->success('sirsoft-hero_section::messages.target_setting.fetched', $this->present($this->settings->targetDetail($position, $target)));
    }

    /**
     * 한 대상의 재정의 묶음을 저장합니다(빠진 항목은 상속).
     *
     * @param  UpdateTargetSettingRequest  $request  재정의 요청
     * @param  string  $position  위치 키
     * @param  string  $target  대상 키
     * @return JsonResponse 저장한 대상 설정 응답
     */
    public function update(UpdateTargetSettingRequest $request, string $position, string $target): JsonResponse
    {
        if (! $this->settings->isDynamic($position)) {
            return $this->notFound('sirsoft-hero_section::messages.position.not_found');
        }
        if (! $this->settings->targetExists($position, $target)) {
            return $this->error('sirsoft-hero_section::validation.target.not_found', 422, ['target' => [__('sirsoft-hero_section::validation.target.not_found')]]);
        }

        return $this->success(
            'sirsoft-hero_section::messages.target_setting.updated',
            $this->present($this->settings->updateTarget($position, $target, $request->overrides(), $request->user()?->id)),
        );
    }

    /**
     * 한 대상의 재정의를 모두 지워 위치 기본값으로 되돌립니다.
     *
     * @param  string  $position  위치 키
     * @param  string  $target  대상 키
     * @return JsonResponse 되돌린 대상 설정 응답
     */
    public function destroy(string $position, string $target): JsonResponse
    {
        if (! $this->settings->isDynamic($position)) {
            return $this->notFound('sirsoft-hero_section::messages.position.not_found');
        }

        return $this->success('sirsoft-hero_section::messages.target_setting.reset', $this->present($this->settings->resetTarget($position, $target)));
    }

    /**
     * 응답 모양을 맞춥니다 — 재정의가 없어도 overrides 는 JSON 객체(`{}`)로 낸다.
     *
     * 빈 PHP 배열은 `[]` 로 직렬화되는데, 관리 화면은 작업본(`{}`)과 저장값을 JSON 문자열로 비교해 바뀐 것이 없어도
     * 저장 단추가 풀린다. 항목이 있으면 키 순서 그대로 객체다.
     *
     * @param  array<string, mixed>  $detail  대상 설정 상세
     * @return array<string, mixed> 응답 데이터
     */
    private function present(array $detail): array
    {
        $detail['overrides'] = (object) $detail['overrides'];

        return $detail;
    }
}
