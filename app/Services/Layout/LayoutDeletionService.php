<?php

namespace App\Services\Layout;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutExtensionVersionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Enums\ActivityLogType;
use App\Enums\LayoutSourceType;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Models\LayoutExtension;
use App\Models\TemplateLayout;
use App\Support\LayoutMarkup\EmptyMap;
use Illuminate\Support\Facades\DB;

/**
 * 레이아웃·확장 조각 행을 영구 삭제하는 단일 지점 — 운영자 데이터면 지우기 직전 활동 로그에 스냅샷을 남긴다.
 *
 * 확장 제거·템플릿 제거·덮어쓰기 갱신에서 새 판에 없는 화면·고아 오버라이드 정리·설정 화면 정리가 이 서비스를 지난다.
 * 한 트랜잭션에서 ① 운영자 데이터(운영자가 고친 행, 운영자가 만든 행)면 `activity_logs` 에 스냅샷(본문·원본 해시·최근 버전
 * 50개) ② 버전 행 명시 삭제 ③ 본 행 영구 삭제를 한다. DB CASCADE 에 기대지 않는다(마이그레이션의 CASCADE 는 안전망).
 *
 * 스냅샷은 활동 로그 보관 기간이 지나면 정리된다. 복원 기능은 없다 — 운영자가 지운 내용을 확인하고 손으로 되살리는 근거다.
 */
class LayoutDeletionService
{
    use ResolvesActivityLogType;

    /** 스냅샷에 싣는 최근 버전 수 상한 (activity_logs.properties 는 mediumText) */
    public const MAX_SNAPSHOT_VERSIONS = 50;

    /**
     * @param  LayoutRepositoryInterface  $layouts  레이아웃 저장소
     * @param  LayoutVersionRepositoryInterface  $layoutVersions  레이아웃 버전 저장소
     * @param  LayoutExtensionRepositoryInterface  $extensions  조각 저장소
     * @param  LayoutExtensionVersionRepositoryInterface  $extensionVersions  조각 버전 저장소
     */
    public function __construct(
        private LayoutRepositoryInterface $layouts,
        private LayoutVersionRepositoryInterface $layoutVersions,
        private LayoutExtensionRepositoryInterface $extensions,
        private LayoutExtensionVersionRepositoryInterface $extensionVersions,
    ) {}

    /**
     * 레이아웃 행 하나를 영구 삭제합니다 (운영자 데이터면 스냅샷 먼저).
     *
     * @param  TemplateLayout  $row  행
     * @param  string  $reason  삭제 사유 (`uninstall`·`refresh_overwrite_removed`·`orphan_override`·`settings_layout_removed`·`template_uninstall`)
     * @param  string|null  $template  템플릿 식별자 (기록용, 모르면 행의 템플릿에서 읽는다)
     * @return bool 스냅샷을 남겼으면 true
     */
    public function forceDeleteLayout(TemplateLayout $row, string $reason, ?string $template = null): bool
    {
        return DB::transaction(function () use ($row, $reason, $template): bool {
            $logged = $this->snapshotLayout($row, $reason, $template);
            $this->layouts->forceDelete($row);

            return $logged;
        });
    }

    /**
     * 출처(모듈·플러그인)의 레이아웃 행을 모두 영구 삭제합니다.
     *
     * @param  string  $identifier  출처 식별자
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $reason  삭제 사유
     * @return int 지운 행 수
     */
    public function forceDeleteLayoutsBySource(string $identifier, LayoutSourceType $sourceType, string $reason): int
    {
        return DB::transaction(function () use ($identifier, $sourceType, $reason): int {
            foreach ($this->layouts->getWithTrashedBySource($identifier, $sourceType) as $row) {
                $this->snapshotLayout($row, $reason);
            }

            return $this->layouts->forceDeleteBySource($identifier, $sourceType);
        });
    }

    /**
     * 템플릿의 레이아웃 행을 모두 영구 삭제합니다 (템플릿 제거).
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $reason  삭제 사유
     * @return int 지운 행 수
     */
    public function forceDeleteLayoutsByTemplate(int $templateId, string $reason): int
    {
        return DB::transaction(function () use ($templateId, $reason): int {
            foreach ($this->layouts->getWithTrashedByTemplateId($templateId) as $row) {
                $this->snapshotLayout($row, $reason);
            }

            return $this->layouts->deleteByTemplateId($templateId);
        });
    }

    /**
     * 확장 조각 행 하나를 영구 삭제합니다 (운영자가 고쳤으면 스냅샷 먼저).
     *
     * @param  LayoutExtension  $row  행
     * @param  string  $reason  삭제 사유
     * @return bool 스냅샷을 남겼으면 true
     */
    public function forceDeleteExtension(LayoutExtension $row, string $reason): bool
    {
        return DB::transaction(function () use ($row, $reason): bool {
            $logged = $this->snapshotExtension($row, $reason);
            $this->extensions->forceDeleteRow($row);

            return $logged;
        });
    }

    /**
     * 출처의 확장 조각 행을 모두 영구 삭제합니다 (확장 제거).
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  string  $reason  삭제 사유
     * @return int 지운 행 수
     */
    public function forceDeleteExtensionsBySource(LayoutSourceType $sourceType, string $identifier, string $reason): int
    {
        return DB::transaction(function () use ($sourceType, $identifier, $reason): int {
            foreach ($this->extensions->getWithTrashedBySource($sourceType, $identifier) as $row) {
                $this->snapshotExtension($row, $reason);
            }

            return $this->extensions->forceDeleteBySource($sourceType, $identifier);
        });
    }

    /**
     * 템플릿에 붙은 확장 조각 행을 출처와 무관하게 모두 영구 삭제합니다 (템플릿 제거).
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $reason  삭제 사유
     * @return int 지운 행 수
     */
    public function forceDeleteExtensionsByTemplate(int $templateId, string $reason): int
    {
        return DB::transaction(function () use ($templateId, $reason): int {
            foreach ($this->extensions->getWithTrashedByTemplateId($templateId) as $row) {
                $this->snapshotExtension($row, $reason);
            }

            return $this->extensions->deleteByTemplateId($templateId);
        });
    }

    /**
     * 운영자 데이터인 레이아웃 행의 스냅샷을 활동 로그에 남깁니다.
     *
     * 운영자 데이터 = 운영자가 고친 행 또는 파일에서 온 적이 없는 행(편집기 「새 레이아웃」).
     *
     * @param  TemplateLayout  $row  행
     * @param  string  $reason  삭제 사유
     * @param  string|null  $template  템플릿 식별자
     * @return bool 남겼으면 true
     */
    private function snapshotLayout(TemplateLayout $row, string $reason, ?string $template = null): bool
    {
        $synchronizer = app(LayoutRowSynchronizer::class);

        if (! $synchronizer->isOperatorModified($row) && $synchronizer->hasFileOrigin($row)) {
            return false;
        }

        $template ??= (string) ($row->template?->identifier ?? $row->template_id);
        // 상한 + 1 건만 읽는다 — 1건이 더 있으면 상한을 넘은 것(versions_truncated)
        [$versions, $truncated] = $this->recentVersions($this->layoutVersions->getVersions((int) $row->id, self::MAX_SNAPSHOT_VERSIONS + 1));

        // 사유는 코드로 저장하고 표시할 때 번역한다(LayoutDeletionReasonDescriptionResolver) — 번역문을 저장하면 기록 당시 언어로 굳는다
        $this->logActivity('layout.force_deleted', [
            'log_type' => ActivityLogType::System,
            'description_key' => 'activity_log.description.layout_force_deleted',
            'description_params' => ['name' => (string) $row->name, 'template' => $template, 'reason' => $reason],
            'properties' => [
                'template' => $template,
                'name' => (string) $row->name,
                'source_type' => $row->source_type instanceof LayoutSourceType ? $row->source_type->value : (string) $row->source_type,
                'source_identifier' => $row->source_identifier,
                'reason' => $reason,
                'content' => $this->decode($row->getRawOriginal('content')),
                'original_content_hash' => $row->original_content_hash,
                'operator_created' => ! $synchronizer->hasFileOrigin($row),
                'versions' => $versions,
                'versions_truncated' => $truncated,
            ],
        ]);

        return true;
    }

    /**
     * 운영자가 고친 확장 조각 행의 스냅샷을 활동 로그에 남깁니다.
     *
     * @param  LayoutExtension  $row  행
     * @param  string  $reason  삭제 사유
     * @return bool 남겼으면 true
     */
    private function snapshotExtension(LayoutExtension $row, string $reason): bool
    {
        if (! app(LayoutRowSynchronizer::class)->isOperatorModified($row)) {
            return false;
        }

        $template = (string) ($row->template?->identifier ?? $row->template_id);
        // 목록 조회(getVersions)는 본문을 담지 않으므로 본문까지 읽는 전용 조회를 쓴다. 상한 + 1 건으로 초과 여부를 안다.
        [$versions, $truncated] = $this->recentVersions($this->extensionVersions->getRecentVersionsWithContent((int) $row->id, self::MAX_SNAPSHOT_VERSIONS + 1));

        $this->logActivity('layout_extension.force_deleted', [
            'log_type' => ActivityLogType::System,
            'description_key' => 'activity_log.description.layout_extension_force_deleted',
            'description_params' => ['name' => $row->target_name.':'.$row->fragment_key, 'template' => $template, 'reason' => $reason],
            'properties' => [
                'template' => $template,
                'target_name' => $row->target_name,
                'fragment_key' => $row->fragment_key,
                'source_type' => $row->source_type instanceof LayoutSourceType ? $row->source_type->value : (string) $row->source_type,
                'source_identifier' => $row->source_identifier,
                'reason' => $reason,
                'content' => $this->decode($row->getRawOriginal('content')),
                'original_content_hash' => $row->original_content_hash,
                'versions' => $versions,
                'versions_truncated' => $truncated,
            ],
        ]);

        return true;
    }

    /**
     * 최근 버전을 상한까지 스냅샷 모양으로 바꿉니다.
     *
     * @param  iterable<object>  $versions  버전 목록 (최신순)
     * @return array{0: list<array<string, mixed>>, 1: bool} [버전 목록, 상한을 넘었는지]
     */
    private function recentVersions(iterable $versions): array
    {
        $out = [];
        $truncated = false;

        foreach ($versions as $version) {
            if (count($out) >= self::MAX_SNAPSHOT_VERSIONS) {
                $truncated = true;
                break;
            }

            $out[] = [
                'id' => (int) $version->id,
                'version' => (int) $version->version,
                'content' => $this->decode($version->getRawOriginal('content')),
                'created_at' => (string) $version->created_at,
                'summary' => $version->changes_summary,
            ];
        }

        return [$out, $truncated];
    }

    /**
     * DB 원문을 빈 map 모양을 지키며 디코드합니다.
     *
     * @param  mixed  $raw  원문
     * @return mixed 디코드 결과
     */
    private function decode(mixed $raw): mixed
    {
        return is_string($raw) ? EmptyMap::decodePreserving($raw) : $raw;
    }
}
