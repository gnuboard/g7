<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\LayoutSourceType;
use App\Extension\Layout\LayoutSyncReport;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 확장 갱신 공통 로직을 제공하는 Trait
 *
 * 모듈과 플러그인의 레이아웃 확장(extensions) 파일을 파일 시스템에서 읽어
 * DB에 동기화하는 공통 로직을 제공합니다.
 *
 * 이 Trait를 사용하는 클래스는 반드시 다음 속성을 제공해야 합니다:
 * - $layoutExtensionService: LayoutExtensionService 인스턴스
 * - layoutSynchronizer(): {@see SynchronizesLayoutRows} 를 함께 쓰고 생성자로 `$layoutRowSynchronizer` 를 받는다
 *
 * @property LayoutExtensionService $layoutExtensionService
 */
trait RefreshesLayoutExtensions
{
    use CollectsFragmentSources;

    /**
     * 확장(모듈/플러그인)의 레이아웃 확장을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  ModuleInterface|PluginInterface  $extension  모듈 또는 플러그인 인스턴스
     * @param  Collection  $adminTemplates  admin 템플릿 컬렉션
     * @param  LayoutSourceType  $sourceType  소스 타입 (Module 또는 Plugin)
     * @param  bool  $preserveModified  관리자가 편집한 확장을 보존(병합)할지 여부 (--layout-strategy=keep)
     * @param  string|null  $backupPath  업데이트 백업 디렉토리 — 원본 본문이 없는 조각 행의 비교 기준을 만든다
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return array{refreshed: int, created: int, updated: int, deleted: int, skipped: int} 갱신 통계
     */
    protected function refreshExtensionLayoutExtensions(
        ModuleInterface|PluginInterface $extension,
        Collection $adminTemplates,
        LayoutSourceType $sourceType,
        bool $preserveModified = false,
        ?string $backupPath = null,
        ?LayoutSyncReport $report = null
    ): array {
        $identifier = $extension->getIdentifier();
        $extensionType = $sourceType === LayoutSourceType::Module ? 'module' : 'plugin';
        $stats = ['refreshed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];

        if ($adminTemplates->isEmpty()) {
            return $stats;
        }

        $extensionsPath = $extension->getExtensionsPath();
        $jsonFiles = $extension->getLayoutExtensions();

        // 소스 디렉토리에 실재하는 조각 키 집합 — 컴파일 성공 여부와 무관하다. 고아 정리는 이 집합으로 판정해,
        // 컴파일에 실패한 조각의 행을 지우지 않는다(실패는 로그, 행은 보존).
        $liveKeys = $this->fragmentSourceKeys($extensionsPath, $jsonFiles);

        // 각 템플릿에 대해 확장 동기화 — 정의 로드를 템플릿 루프 안에서 한다. 태그→컴포넌트 표가 템플릿마다
        // 다르므로 조각은 붙을 템플릿의 표로 컴파일해야 한다 (C10). 색인은 조각 식별자(`fragment_key`) 기준이다 —
        // 종전 대상(target) 색인은 같은 대상에 조각이 둘이면 하나를 잃었다 (C13).
        $synchronizer = $this->layoutSynchronizer();

        foreach ($adminTemplates as $template) {
            $backupFragments = $synchronizer->compileBackupFragments($backupPath, $identifier, (string) $template->identifier);
            $definitions = $this->loadFragmentDefinitions(
                $extensionsPath,
                $jsonFiles,
                [$extensionType => $identifier, 'template' => $template->identifier],
                (string) $template->identifier
            );

            // 등록 + 백필된 옛 키 행 잇기(운영자 수정본 유지) + 오등록·고아 행 정리 — 조각 행을 쓰는 모든 경로가 같은
            // 수렴 절차를 탄다 (#703). 종전에는 이 갱신 경로만 고아를 정리했고, 옛 키 행은 새 행에 밀려 지워져
            // 운영자가 고친 옛 행을 keep 전략이 보지 못했다.
            $templateStats = $this->layoutExtensionService->syncSourceFragmentsToTemplate(
                $definitions,
                $liveKeys,
                $sourceType,
                $identifier,
                $template->id,
                $preserveModified,
                $backupFragments,
                $report
            );

            $stats['refreshed'] += $templateStats['registered'];
            $stats['created'] += $templateStats['created'];
            $stats['updated'] += $templateStats['updated'];
            $stats['skipped'] += $templateStats['skipped'];
            $stats['deleted'] += $templateStats['deleted'];
        }

        if ($stats['refreshed'] > 0) {
            $typeLabel = $sourceType === LayoutSourceType::Module ? '모듈' : '플러그인';
            Log::info("{$typeLabel} 레이아웃 확장 갱신 완료", [
                $extensionType => $identifier,
                'created' => $stats['created'],
                'updated' => $stats['updated'],
            ]);
        }

        return $stats;
    }
}
