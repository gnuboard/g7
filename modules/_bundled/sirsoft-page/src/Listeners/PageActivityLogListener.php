<?php

namespace Modules\Sirsoft\Page\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Models\PageVersion;

/**
 * 페이지 모듈 활동 로그 리스너
 *
 * 페이지 서비스에서 발행하는 훅을 구독하여
 * Log::channel('activity')를 통해 활동 로그를 기록합니다.
 *
 * Monolog 기반 아키텍처:
 * Service -> doAction -> PageActivityLogListener -> Log::channel('activity') -> ActivityLogHandler -> DB
 */
class PageActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // ─── Page ───
            'sirsoft-page.page.after_create' => ['method' => 'handlePageAfterCreate', 'priority' => 20],
            'sirsoft-page.page.after_update' => ['method' => 'handlePageAfterUpdate', 'priority' => 20],
            'sirsoft-page.page.after_delete' => ['method' => 'handlePageAfterDelete', 'priority' => 20],
            'sirsoft-page.page.after_publish' => ['method' => 'handlePageAfterPublish', 'priority' => 20],
            'sirsoft-page.page.after_restore' => ['method' => 'handlePageAfterRestore', 'priority' => 20],

            // ─── PageAttachment ───
            'sirsoft-page.attachment.after_upload' => ['method' => 'handleAttachmentAfterUpload', 'priority' => 20],
            'sirsoft-page.attachment.after_delete' => ['method' => 'handleAttachmentAfterDelete', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ═══════════════════════════════════════════
    // Page 핸들러
    // ═══════════════════════════════════════════

    /**
     * 페이지 생성 후 로그 기록
     *
     * @param Page $page 생성된 페이지
     * @param array $data 생성 데이터
     */
    public function handlePageAfterCreate(Page $page, array $data): void
    {
        $this->logActivity('page.create', [
            'loggable' => $page,
            'description_key' => 'sirsoft-page::activity_log.description.page_create',
            'description_params' => ['title' => $page->title ?? ''],
            'properties' => ['title' => $page->title, 'slug' => $page->slug ?? ''],
        ]);
    }

    /**
     * 페이지 수정 후 로그 기록
     *
     * @param Page $page 수정된 페이지
     * @param array $data 수정 데이터
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     */
    public function handlePageAfterUpdate(Page $page, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($page, $snapshot);

        $this->logActivity('page.update', [
            'loggable' => $page,
            'description_key' => 'sirsoft-page::activity_log.description.page_update',
            'description_params' => ['title' => $page->title ?? ''],
            'changes' => $changes,
        ]);
    }

    /**
     * 페이지 삭제 후 로그 기록
     *
     * @param Page $page 삭제된 페이지
     */
    public function handlePageAfterDelete(Page $page): void
    {
        $this->logActivity('page.delete', [
            'loggable' => $page,
            'description_key' => 'sirsoft-page::activity_log.description.page_delete',
            'description_params' => ['title' => $page->title ?? ''],
            'properties' => ['title' => $page->title, 'slug' => $page->slug ?? ''],
        ]);
    }

    /**
     * 페이지 공개/비공개 전환 후 로그 기록
     *
     * @param Page $page 대상 페이지
     * @param bool $published 공개 여부 (true: 공개, false: 비공개)
     */
    public function handlePageAfterPublish(Page $page, bool $published): void
    {
        $action = $published ? 'page.publish' : 'page.unpublish';
        $descriptionKey = $published
            ? 'sirsoft-page::activity_log.description.page_publish'
            : 'sirsoft-page::activity_log.description.page_unpublish';

        $this->logActivity($action, [
            'loggable' => $page,
            'description_key' => $descriptionKey,
            'description_params' => ['title' => $page->title ?? ''],
            'properties' => ['published' => $published],
        ]);
    }

    /**
     * 페이지 버전 복원 후 로그 기록
     *
     * @param Page $page 복원된 페이지
     * @param PageVersion $version 복원 대상 버전
     */
    public function handlePageAfterRestore(Page $page, PageVersion $version): void
    {
        $this->logActivity('page.restore', [
            'loggable' => $page,
            'description_key' => 'sirsoft-page::activity_log.description.page_restore',
            'description_params' => [
                'title' => $page->title ?? '',
                'version_id' => $version->id,
            ],
            'properties' => ['version_id' => $version->id, 'version_number' => $version->version ?? null],
        ]);
    }

    // ═══════════════════════════════════════════
    // PageAttachment 핸들러
    // ═══════════════════════════════════════════

    /**
     * 페이지 첨부파일 업로드 후 로그 기록
     *
     * @param PageAttachment $attachment 업로드된 첨부파일
     */
    public function handleAttachmentAfterUpload(PageAttachment $attachment): void
    {
        $attachment->loadMissing('page');

        $this->logActivity('page_attachment.upload', [
            'loggable' => $attachment,
            'description_key' => 'sirsoft-page::activity_log.description.page_attachment_upload',
            'description_params' => ['title' => $attachment->page?->title ?? ''],
            'properties' => [
                'original_name' => $attachment->original_name ?? '',
                'size' => $attachment->size ?? 0,
                'page_id' => $attachment->page_id ?? null,
                'title' => $attachment->page?->title ?? '',
            ],
        ]);
    }

    /**
     * 페이지 첨부파일 삭제 후 로그 기록
     *
     * @param PageAttachment $attachment 삭제된 첨부파일
     */
    public function handleAttachmentAfterDelete(PageAttachment $attachment): void
    {
        $attachment->loadMissing('page');

        $this->logActivity('page_attachment.delete', [
            'loggable' => $attachment,
            'description_key' => 'sirsoft-page::activity_log.description.page_attachment_delete',
            'description_params' => ['title' => $attachment->page?->title ?? ''],
            'properties' => [
                'original_name' => $attachment->original_name ?? '',
                'page_id' => $attachment->page_id ?? null,
                'title' => $attachment->page?->title ?? '',
            ],
        ]);
    }

}
