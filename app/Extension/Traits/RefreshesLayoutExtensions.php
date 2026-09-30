<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\LayoutSourceType;
use App\Enums\LayoutSyncMode;
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

            foreach ($definitions as $definition) {
                $extensionData = $definition['data'];
                $fragmentKey = $definition['key'];
                $targetKey = $extensionData['target_layout'] ?? $extensionData['extension_point'] ?? null;

                if (! $targetKey) {
                    continue;
                }

                try {
                    // 대상(target_layout / extension_point)이 이 템플릿에 존재하지 않으면 등록하지 않는다.
                    // 모든 활성 템플릿에 일괄 적용 시, admin 레이아웃 대상 확장이 user 템플릿에
                    // (또는 그 반대로) 잘못 등록되는 것을 방지한다.
                    if (! $this->layoutExtensionService->isExtensionApplicableToTemplate($extensionData, $template->id)) {
                        // 과거 무차별 등록으로 잘못 생성된 행이 있으면 정리
                        if ($this->layoutExtensionService->removeInapplicableExtension(
                            $extensionData,
                            $sourceType,
                            $identifier,
                            $template->id,
                            $fragmentKey
                        )) {
                            $stats['deleted']++;
                        }

                        continue;
                    }

                    $result = $this->layoutExtensionService->registerExtension(
                        $extensionData,
                        $sourceType,
                        $identifier,
                        $template->id,
                        $preserveModified,
                        $fragmentKey,
                        $backupFragments[$fragmentKey] ?? null,
                        $report
                    );

                    if ($result === 'created') {
                        $stats['created']++;
                    } elseif ($result === 'updated') {
                        $stats['updated']++;
                    } elseif ($result === 'skipped') {
                        $stats['skipped']++;
                    }
                    $stats['refreshed']++;
                } catch (\Exception $e) {
                    Log::error("레이아웃 확장 갱신 실패: {$targetKey}", [
                        $extensionType => $identifier,
                        'template' => $template->identifier,
                        'fragment_key' => $fragmentKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 소스에 더는 없는 조각의 행 정리 (같은 출처·같은 템플릿). 옛 5축 키(`target_name`) 행도 파일 키로 정정된다.
            $stats['deleted'] += $this->layoutExtensionService->removeOrphanFragments(
                $sourceType,
                $identifier,
                $template->id,
                $liveKeys,
                LayoutSyncMode::fromPreserve($preserveModified),
                $report
            );
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
