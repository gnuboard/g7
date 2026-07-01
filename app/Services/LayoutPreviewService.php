<?php

namespace App\Services;

use App\Contracts\Repositories\LayoutPreviewRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Models\TemplateLayoutPreview;
use Illuminate\Support\Str;

/**
 * 레이아웃 미리보기 서비스
 */
class LayoutPreviewService
{
    /**
     * 미리보기 기본 만료 시간 (분)
     */
    private const DEFAULT_TTL_MINUTES = 30;

    /**
     * @param  LayoutPreviewRepositoryInterface  $previewRepository  미리보기 리포지토리
     * @param  LayoutService  $layoutService  레이아웃 서비스
     * @param  LayoutExtensionService  $layoutExtensionService  레이아웃 확장 서비스
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 리포지토리
     */
    public function __construct(
        private readonly LayoutPreviewRepositoryInterface $previewRepository,
        private readonly LayoutService $layoutService,
        private readonly LayoutExtensionService $layoutExtensionService,
        private readonly TemplateRepositoryInterface $templateRepository,
    ) {}

    /**
     * 미리보기를 생성합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @param  array  $content  편집 중인 레이아웃 JSON
     * @param  int  $adminId  관리자 ID
     * @return TemplateLayoutPreview 생성된 미리보기 모델
     */
    public function createPreview(int $templateId, string $layoutName, array $content, int $adminId): TemplateLayoutPreview
    {
        // 훅: 미리보기 생성 전 (IDV 정책 가드 지점)
        HookManager::doAction('core.layout_preview.before_generate', $templateId, $layoutName, $content, $adminId);

        // 기존 동일 조합의 미리보기 삭제
        $this->previewRepository->deleteByLayoutAndAdmin($templateId, $layoutName, $adminId);

        $preview = $this->previewRepository->create([
            'token' => (string) Str::uuid(),
            'template_id' => $templateId,
            'layout_name' => $layoutName,
            'content' => $content,
            'admin_id' => $adminId,
            'expires_at' => now()->addMinutes(self::DEFAULT_TTL_MINUTES),
        ]);

        HookManager::doAction('core.layout_preview.after_generate', $preview);

        return $preview;
    }

    /**
     * 레이아웃 확장 미리보기를 생성합니다.
     *
     * 편집 중인 확장 content 를 대표 레이아웃에 임시 적용하여 렌더링하기 위한
     * 미리보기 레코드를 생성합니다. layout_name 컬럼에 대표 레이아웃명을 저장합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  int  $extensionId  레이아웃 확장 ID
     * @param  string  $previewLayout  대표 레이아웃 이름
     * @param  array  $content  편집 중인 확장 정의 JSON
     * @param  int  $adminId  관리자 ID
     * @return TemplateLayoutPreview 생성된 미리보기 모델
     */
    public function createExtensionPreview(
        int $templateId,
        int $extensionId,
        string $previewLayout,
        array $content,
        int $adminId
    ): TemplateLayoutPreview {
        HookManager::doAction('core.layout_preview.before_generate', $templateId, $previewLayout, $content, $adminId);

        // 기존 동일 조합의 미리보기 삭제
        $this->previewRepository->deleteByLayoutAndAdmin($templateId, $previewLayout, $adminId);

        $preview = $this->previewRepository->create([
            'token' => (string) Str::uuid(),
            'template_id' => $templateId,
            'layout_name' => $previewLayout,
            'preview_type' => 'extension',
            'extension_id' => $extensionId,
            'content' => $content,
            'admin_id' => $adminId,
            'expires_at' => now()->addMinutes(self::DEFAULT_TTL_MINUTES),
        ]);

        HookManager::doAction('core.layout_preview.after_generate', $preview);

        return $preview;
    }

    /**
     * 토큰으로 미리보기 레이아웃을 조회하고 병합합니다.
     *
     * 편집 중인 content에 extends가 있으면 부모 레이아웃과 병합하고,
     * 모듈/플러그인 extension도 적용합니다.
     *
     * @param  string  $token  미리보기 토큰
     * @return array|null 병합된 레이아웃 데이터 또는 null
     */
    public function getPreviewLayout(string $token): ?array
    {
        $preview = $this->previewRepository->findByToken($token);

        if (! $preview) {
            return null;
        }

        $templateId = $preview->template_id;

        // 레이아웃 확장 미리보기: 편집 중인 확장 content 를 대표 레이아웃에 임시 적용
        if (($preview->preview_type ?? 'layout') === 'extension') {
            return $this->renderExtensionPreview($preview, $templateId);
        }

        $layoutData = $preview->content;

        // extends 상속 병합
        if (isset($layoutData['extends'])) {
            $parentLayoutName = $layoutData['extends'];
            $parentLayout = $this->layoutService->loadAndMergeLayout($templateId, $parentLayoutName);
            $layoutData = $this->layoutService->mergeLayouts($parentLayout, $layoutData);
        }

        // 모듈/플러그인 Extension 적용
        $layoutData = $this->layoutExtensionService->applyExtensions($layoutData, $templateId);

        return $layoutData;
    }

    /**
     * 레이아웃 확장 미리보기를 렌더링합니다.
     *
     * 대표 레이아웃을 병합한 뒤, 편집 중인 확장 content 를 해당 확장 자리에
     * 임시 치환하여 applyExtensions 를 실행합니다.
     *
     * @param  TemplateLayoutPreview  $preview  미리보기 모델
     * @param  int  $templateId  템플릿 ID
     * @return array 확장이 적용된 대표 레이아웃
     */
    private function renderExtensionPreview(TemplateLayoutPreview $preview, int $templateId): array
    {
        // 대표 레이아웃 병합 (layout_name 컬럼에 저장됨)
        $layoutData = $this->layoutService->loadAndMergeLayout($templateId, $preview->layout_name);

        // 편집 중인 확장 content 를 임시 적용
        if ($preview->extension_id) {
            $this->layoutExtensionService->setPreviewContentOverride(
                $preview->extension_id,
                $preview->content
            );
        }

        try {
            $layoutData = $this->layoutExtensionService->applyExtensions($layoutData, $templateId);
        } finally {
            $this->layoutExtensionService->clearPreviewContentOverrides();
        }

        return $layoutData;
    }

    /**
     * 토큰으로 미리보기의 템플릿 식별자를 조회합니다.
     *
     * @param  string  $token  미리보기 토큰
     * @return string|null 템플릿 식별자 또는 null
     */
    public function getTemplateIdentifierByToken(string $token): ?string
    {
        $preview = $this->previewRepository->findByToken($token);

        if (! $preview) {
            return null;
        }

        $template = $this->templateRepository->findById($preview->template_id);

        return $template?->identifier;
    }

    /**
     * 만료된 미리보기를 삭제합니다.
     *
     * @return int 삭제된 행 수
     */
    public function cleanupExpired(): int
    {
        return $this->previewRepository->deleteExpired();
    }

    /**
     * 특정 관리자의 특정 레이아웃 미리보기를 삭제합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @param  int  $adminId  관리자 ID
     * @return int 삭제된 행 수
     */
    public function deleteByLayoutAndAdmin(int $templateId, string $layoutName, int $adminId): int
    {
        return $this->previewRepository->deleteByLayoutAndAdmin($templateId, $layoutName, $adminId);
    }
}
