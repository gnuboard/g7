<?php

namespace App\Services\Layout;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Repositories\LayoutPreviewRepositoryInterface;
use App\Contracts\Repositories\TemplateCustomTranslationRepositoryInterface;
use App\Contracts\Repositories\TemplateLayoutAttachmentRepositoryInterface;
use App\Enums\ActivityLogType;
use App\Services\TemplateLayoutAttachmentService;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 제거 때 템플릿 행을 지우기 전에 그 템플릿의 자식 행을 명시적으로 지웁니다 — DB CASCADE 에 기대지 않는다.
 *
 * 순서: ① 그 템플릿에 붙은 모든 확장 조각(다른 출처 포함 — 운영자 수정본은 스냅샷) ② 조각 버전(①이 함께 지운다)
 * ③ 커스텀 번역(편집기로 운영자가 만든 키 — 지우기 직전 활동 로그 `template_custom_translation.deleted`)
 * ④ 레이아웃 첨부(스토리지 파일까지) ⑤ 레이아웃 미리보기. 레이아웃·레이아웃 버전은 호출부가 이어서 지운다.
 */
class TemplateChildrenDeleter
{
    use ResolvesActivityLogType;

    /**
     * @param  LayoutDeletionService  $deletion  레이아웃·조각 영구 삭제
     * @param  TemplateCustomTranslationRepositoryInterface  $customTranslations  커스텀 번역 저장소
     * @param  TemplateLayoutAttachmentRepositoryInterface  $attachments  레이아웃 첨부 저장소
     * @param  TemplateLayoutAttachmentService  $attachmentService  레이아웃 첨부 서비스 (스토리지 파일 삭제)
     * @param  LayoutPreviewRepositoryInterface  $previews  레이아웃 미리보기 저장소
     * @param  StageLayoutTableService  $stageTables  다른 실행 역할 레이아웃 테이블 정리
     */
    public function __construct(
        private LayoutDeletionService $deletion,
        private TemplateCustomTranslationRepositoryInterface $customTranslations,
        private TemplateLayoutAttachmentRepositoryInterface $attachments,
        private TemplateLayoutAttachmentService $attachmentService,
        private LayoutPreviewRepositoryInterface $previews,
        private StageLayoutTableService $stageTables,
    ) {}

    /**
     * 레이아웃을 지우기 전의 자식을 지웁니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return array{extensions: int, custom_translations: int, attachments: int, previews: int, other_role_layouts: int} 지운 행 수
     */
    public function deleteChildrenBeforeLayouts(int $templateId): array
    {
        $stats = ['extensions' => 0, 'custom_translations' => 0, 'attachments' => 0, 'previews' => 0];

        $stats['extensions'] = $this->deletion->forceDeleteExtensionsByTemplate($templateId, 'template_uninstall');

        foreach ($this->customTranslations->getByTemplateId($templateId) as $translation) {
            $this->logActivity('template_custom_translation.deleted', [
                'log_type' => ActivityLogType::System,
                'description_key' => 'activity_log.description.template_custom_translation_deleted',
                'description_params' => ['key' => (string) $translation->translation_key],
                'properties' => [
                    'template_id' => $templateId,
                    'layout_name' => $translation->layout_name,
                    'translation_key' => $translation->translation_key,
                    'values' => $translation->values,
                    'status' => $translation->status,
                    'reason' => 'template_uninstall',
                ],
            ]);
            $this->customTranslations->delete((int) $translation->id);
            $stats['custom_translations']++;
        }

        foreach ($this->attachments->listForTemplate($templateId) as $attachment) {
            try {
                $this->attachmentService->delete($attachment);
                $stats['attachments']++;
            } catch (\Throwable $e) {
                // 파일 하나가 지워지지 않았다고 템플릿 제거를 멈추지 않는다 — 행은 남겨 다음 정리가 다시 시도하게 한다.
                Log::warning('템플릿 제거: 레이아웃 첨부 삭제 실패', ['template_id' => $templateId, 'attachment_id' => $attachment->id, 'error' => $e->getMessage()]);
            }
        }

        $stats['previews'] = $this->previews->deleteByTemplateId($templateId);

        // 다른 실행 역할 테이블(운영 서버라면 검수용 stage_ 테이블)의 그 템플릿 행 — DB CASCADE 에 기대지 않는다
        $stats['other_role_layouts'] = $this->stageTables->deleteOtherRoleRowsForTemplate($templateId);

        return $stats;
    }
}
