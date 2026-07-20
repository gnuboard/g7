<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\LayoutSourceType;
use App\Services\LayoutExtensionService;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
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
    use ResolvesLayoutInstallSource;

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

        // fragment 소스 수집 — `.g7.html`(있으면 우선) + 짝 `.g7.html` 이 없는 `.json`.
        // `.g7.html` 은 등록 시점에 메모리 컴파일하고(디스크 산출 JSON 미기록), `.json` 은 직접 읽는다.
        $fragmentSources = $this->collectFragmentSources($extension->getExtensionsPath(), $extension->getLayoutExtensions());

        // 파일에서 읽은 확장 데이터 수집 (target_layout 또는 extension_point)
        $fileExtensions = [];
        foreach ($fragmentSources as $source) {
            $extensionFile = $source['path'];

            try {
                if ($source['markup']) {
                    $extensionData = $this->compileMarkupFragmentFile($extensionFile);
                } else {
                    $extensionData = json_decode(File::get($extensionFile), true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::error("레이아웃 확장 JSON 파싱 실패: {$extensionFile}", [
                            $extensionType => $identifier,
                            'error' => json_last_error_msg(),
                        ]);

                        continue;
                    }
                }

                // target_layout (overlay) 또는 extension_point 중 하나가 있어야 함
                $targetKey = $extensionData['target_layout'] ?? $extensionData['extension_point'] ?? null;
                if ($targetKey) {
                    $fileExtensions[$targetKey] = $extensionData;
                }
            } catch (\Throwable $e) {
                Log::error("레이아웃 확장 파일 처리 실패: {$extensionFile}", [
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

    /**
     * 등록할 fragment 소스를 수집합니다 — `.g7.html`(있으면 우선) + 짝 `.g7.html` 이 없는 `.json`.
     *
     * 확장의 `getLayoutExtensions()` 가 반환하는 `.json` 목록에, 디렉토리의 `.g7.html` 저작 파일을
     * 얹어 같은 논리 fragment 는 마크업을 SSoT 로 삼는다. `.g7.html` 은 컴파일로, `.json` 은 직접 읽는다.
     *
     * `config('template.layout_markup.install_source') === 'json'`(커맨드 `--layout-source=json`) 이면
     * 짝 `.json` 이 실재하는 마크업을 건너뛴다 — 본 레이아웃 경로와 같은 규약이다.
     *
     * @param  string  $extensionsPath  확장 fragment 디렉토리 경로
     * @param  array<int, string>  $jsonFiles  `getLayoutExtensions()` 가 반환한 `.json` 경로
     * @return array<int, array{path: string, markup: bool}> 소스 디스크립터 목록
     */
    protected function collectFragmentSources(string $extensionsPath, array $jsonFiles): array
    {
        $sources = [];
        $markupSiblings = [];
        $jsonForced = $this->layoutInstallSourceIsJsonForced();

        $jsonFileKeys = [];
        foreach ($jsonFiles as $jsonFile) {
            $jsonFileKeys[strtolower(str_replace('\\', '/', $jsonFile))] = true;
        }

        if (File::isDirectory($extensionsPath)) {
            foreach (File::glob(rtrim($extensionsPath, '/\\').DIRECTORY_SEPARATOR.'*.g7.html') ?: [] as $markupFile) {
                $sibling = preg_replace('/\.g7\.html$/i', '.json', $markupFile);
                $siblingKey = strtolower(str_replace('\\', '/', $sibling));

                // `--layout-source=json`: 짝 `.json` 이 실재하면 마크업을 건너뛰고 원본 JSON 으로 등록한다.
                if ($jsonForced) {
                    if (isset($jsonFileKeys[$siblingKey])) {
                        continue;
                    }

                    Log::warning('fragment 설치 소스 JSON 강제를 이행하지 못했습니다 (짝 .json 부재 — 마크업 컴파일로 대체)', [
                        'markup' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $markupFile),
                    ]);
                }

                $markupSiblings[$siblingKey] = true;
                $sources[] = ['path' => $markupFile, 'markup' => true];
            }
        }

        foreach ($jsonFiles as $jsonFile) {
            if (isset($markupSiblings[strtolower(str_replace('\\', '/', $jsonFile))])) {
                continue;
            }

            $sources[] = ['path' => $jsonFile, 'markup' => false];
        }

        return $sources;
    }

    /**
     * fragment `.g7.html` 저작 파일을 등록 시점에 메모리로 컴파일합니다 (강검증 포함, 디스크 미기록).
     *
     * @param  string  $markupFile  `.g7.html` 절대 경로
     * @return array<string, mixed> 컴파일된 fragment JSON
     *
     * @throws CompileError 컴파일·강검증 실패 시
     * @throws \RuntimeException 대상 템플릿 컴포넌트 매니페스트를 찾지 못하면
     */
    protected function compileMarkupFragmentFile(string $markupFile): array
    {
        $resolver = new MarkupCompileContextResolver;
        $registry = $resolver->registryFor($markupFile);

        if ($registry === null) {
            throw new \RuntimeException(__('exceptions.layout_markup.registry_not_found'));
        }

        $result = (new LayoutMarkupCompiler($registry))->compile(
            File::get($markupFile),
            $markupFile,
            $resolver->contextFor($markupFile),
        );

        return $result->json;
    }
}
