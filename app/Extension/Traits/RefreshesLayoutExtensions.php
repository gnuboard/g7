<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\LayoutSourceType;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
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
    /**
     * 확장(모듈/플러그인)의 레이아웃 확장을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  ModuleInterface|PluginInterface  $extension  모듈 또는 플러그인 인스턴스
     * @param  Collection  $adminTemplates  admin 템플릿 컬렉션
     * @param  LayoutSourceType  $sourceType  소스 타입 (Module 또는 Plugin)
     * @return array{refreshed: int, created: int, updated: int, deleted: int} 갱신 통계
     */
    protected function refreshExtensionLayoutExtensions(
        ModuleInterface|PluginInterface $extension,
        Collection $adminTemplates,
        LayoutSourceType $sourceType
    ): array {
        $extensionFiles = $extension->getLayoutExtensions();
        $identifier = $extension->getIdentifier();
        $extensionType = $sourceType === LayoutSourceType::Module ? 'module' : 'plugin';
        $stats = ['refreshed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0];

        if ($adminTemplates->isEmpty()) {
            return $stats;
        }

        // 파일에서 읽은 확장 데이터 수집 (target_layout 또는 extension_point)
        $fileExtensions = [];
        foreach ($extensionFiles as $extensionFile) {
            try {
                $content = File::get($extensionFile);
                $extensionData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error("레이아웃 확장 JSON 파싱 실패: {$extensionFile}", [
                        $extensionType => $identifier,
                        'error' => json_last_error_msg(),
                    ]);

                    continue;
                }

                // target_layout (overlay) 또는 extension_point 중 하나가 있어야 함
                $targetKey = $extensionData['target_layout'] ?? $extensionData['extension_point'] ?? null;
                if ($targetKey) {
                    $fileExtensions[$targetKey] = $extensionData;
                }
            } catch (\Exception $e) {
                Log::error("레이아웃 확장 파일 읽기 실패: {$extensionFile}", [
                    $extensionType => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 각 admin 템플릿에 대해 확장 동기화
        foreach ($adminTemplates as $template) {
            // 파일에서 읽은 확장 등록/업데이트
            foreach ($fileExtensions as $targetKey => $extensionData) {
                try {
                    $result = $this->layoutExtensionService->registerExtension(
                        $extensionData,
                        $sourceType,
                        $identifier,
                        $template->id
                    );

                    if ($result === 'created') {
                        $stats['created']++;
                    } elseif ($result === 'updated') {
                        $stats['updated']++;
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
