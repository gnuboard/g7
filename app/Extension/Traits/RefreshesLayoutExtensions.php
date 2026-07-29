<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\LayoutSourceType;
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
     * @param  bool  $preserveModified  관리자가 편집한 확장을 보존할지 여부 (--layout-strategy=keep)
     * @return array{refreshed: int, created: int, updated: int, deleted: int, skipped: int} 갱신 통계
     */
    protected function refreshExtensionLayoutExtensions(
        ModuleInterface|PluginInterface $extension,
        Collection $adminTemplates,
        LayoutSourceType $sourceType,
        bool $preserveModified = false
    ): array {
        $identifier = $extension->getIdentifier();
        $extensionType = $sourceType === LayoutSourceType::Module ? 'module' : 'plugin';
        $stats = ['refreshed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];

        if ($adminTemplates->isEmpty()) {
            return $stats;
        }

        // fragment 소스 선택·읽기는 규약 SSoT 가 끝낸다 — `.g7.html`(있으면 우선)은 메모리 컴파일,
        // 짝 `.g7.html` 이 없는 `.json` 은 파싱. 읽기 실패는 그 건만 건너뛰고 로그로 남는다.
        // 여기서 다시 읽으면 그 자리가 다음 사본이 된다 (CollectsFragmentSources 독블록 참조).
        $definitions = $this->loadFragmentDefinitions(
            $extension->getExtensionsPath(),
            $extension->getLayoutExtensions(),
            [$extensionType => $identifier]
        );

        // 등록 대상 키(target_layout = overlay, extension_point = 확장점)로 색인
        $fileExtensions = [];
        foreach ($definitions as $definition) {
            $targetKey = $definition['data']['target_layout'] ?? $definition['data']['extension_point'] ?? null;

            if ($targetKey) {
                $fileExtensions[$targetKey] = $definition['data'];
            }
        }

        // 각 admin 템플릿에 대해 확장 동기화
        foreach ($adminTemplates as $template) {
            // 파일에서 읽은 확장 등록/업데이트
            foreach ($fileExtensions as $targetKey => $extensionData) {
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
                            $template->id
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
                        $preserveModified
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
                        'error' => $e->getMessage(),
                    ]);
                }
            }
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
