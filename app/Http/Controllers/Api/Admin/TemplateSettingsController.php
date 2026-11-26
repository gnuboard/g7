<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ExtensionStatus;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\ResetTemplateSettingsRequest;
use App\Http\Requests\Admin\UpdateTemplateSettingsRequest;
use App\Services\FaviconService;
use App\Services\TemplateSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * 템플릿 환경설정 API 컨트롤러
 *
 * 조회·저장·기본값 되돌리기·레이아웃 조회. 꺼진 템플릿도 설치돼 있으면 설정할 수 있다(켜진 뒤 적용) — 미설치·선언 없는 템플릿은 404.
 */
class TemplateSettingsController extends AdminBaseController
{
    /**
     * @param  TemplateSettingsService  $templateSettingsService  템플릿 설정 서비스
     * @param  FaviconService  $faviconService  파비콘 해석
     */
    public function __construct(
        private TemplateSettingsService $templateSettingsService,
        private FaviconService $faviconService,
    ) {
        parent::__construct();
    }

    /**
     * 템플릿 설정을 조회합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 설정 데이터
     */
    public function show(string $identifier): JsonResponse
    {
        $payload = $this->payload($identifier);

        if ($payload === null) {
            return $this->notFound('templates.settings.not_found');
        }

        return $this->success('common.success', $payload);
    }

    /**
     * 템플릿 설정을 저장합니다.
     *
     * @param  UpdateTemplateSettingsRequest  $request  검증된 요청
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 저장 결과 (조회와 같은 모양)
     */
    public function update(UpdateTemplateSettingsRequest $request, string $identifier): JsonResponse
    {
        if (! $this->templateSettingsService->hasSettings($identifier) || $this->templateSettingsService->findTemplate($identifier) === null) {
            return $this->notFound('templates.settings.not_found');
        }

        // 검증 통과분만 저장한다 — 선언 밖 키가 설정 파일로 들어가지 않게(mass-assignment 차단).
        $result = $this->templateSettingsService->save($identifier, $request->validated(), $failureReason);

        if (! $result) {
            return $this->error('templates.settings.update_failed', 500, null, [
                'error' => $failureReason ?? __('templates.settings.errors.save_failed'),
            ]);
        }

        $this->faviconService->flush();

        return $this->success('templates.settings.updated', $this->payload($identifier));
    }

    /**
     * 템플릿 설정을 기본값으로 되돌립니다 — 섹션 하나(`section`) 또는 전체.
     *
     * @param  ResetTemplateSettingsRequest  $request  검증된 요청
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 결과 (조회와 같은 모양)
     */
    public function reset(ResetTemplateSettingsRequest $request, string $identifier): JsonResponse
    {
        if (! $this->templateSettingsService->hasSettings($identifier) || $this->templateSettingsService->findTemplate($identifier) === null) {
            return $this->notFound('templates.settings.not_found');
        }

        $result = $this->templateSettingsService->reset($identifier, $request->validated('section'), $failureReason);

        if (! $result) {
            return $this->error('templates.settings.reset_failed', 500, null, [
                'error' => $failureReason ?? __('templates.settings.errors.save_failed'),
            ]);
        }

        $this->faviconService->flush();

        return $this->success('templates.settings.reset', $this->payload($identifier));
    }

    /**
     * 템플릿이 직접 쓴 설정 화면 레이아웃을 조회합니다 — 없으면 404(공용 설정 화면을 쓰는 템플릿).
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 레이아웃 데이터
     */
    public function layout(string $identifier): JsonResponse
    {
        if ($this->templateSettingsService->findTemplate($identifier) === null) {
            return $this->notFound('templates.settings.not_found');
        }

        $layout = $this->templateSettingsService->getLayout($identifier);

        if ($layout === null) {
            return $this->notFound('templates.settings.not_found');
        }

        return $this->success('common.success', $layout);
    }

    /**
     * 조회·저장 응답 본문을 만듭니다 — 미설치·선언 없음이면 null.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<string, mixed>|null 응답 본문
     */
    private function payload(string $identifier): ?array
    {
        $template = $this->templateSettingsService->findTemplate($identifier);

        if ($template === null || ! $this->templateSettingsService->hasSettings($identifier)) {
            return null;
        }

        $type = (string) $template->type;
        $isActive = $template->status === ExtensionStatus::Active->value;
        $favicon = $this->faviconService->resolve($type, $identifier);

        return [
            'identifier' => $identifier,
            'name' => $template->getLocalizedName(),
            'type' => $type,
            'status' => $template->status,
            'is_active' => $isActive,
            'settings' => (object) $this->templateSettingsService->getForAdmin($identifier),
            // 섹션(카드)·칸 서술 — 공용 설정 화면이 이것으로 칸을 그린다
            'sections' => $this->templateSettingsService->sectionDescriptors($identifier),
            // 언어별 문구 칸의 탭 — 사이트 활성 언어, 비운 언어는 기본 언어로 표시된다
            'locales' => [
                'available' => array_values((array) config('app.translatable_locales', config('app.supported_locales', ['ko', 'en']))),
                'default' => (string) config('app.locale'),
            ],
            // 이 템플릿이 켜져 있을 때 실제로 쓰일 파비콘 계층 — 꺼진 템플릿은 켠다면 쓰일 값
            'effective_favicon' => [
                'source' => $favicon['source'],
                'url' => $favicon['links'][0]['href'] ?? null,
            ],
            // 화면이 읽기 전용 여부를 정한다 — 판정 식별자는 저장 라우트의 permission 미들웨어와 같다
            'abilities' => [
                'can_update' => $canUpdate = PermissionHelper::check('core.templates.settings', request()->user()),
                'can_reset' => $canUpdate,
            ],
        ];
    }
}
