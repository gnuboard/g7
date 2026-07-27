<?php

namespace App\Extension\Traits;

use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 확장 fragment 의 등록 소스를 고르고 컴파일하는 공통 트레이트.
 *
 * fragment 를 DB 에 등록하는 경로는 세 갈래다 — 모듈(`ModuleManager`) · 플러그인(`PluginManager`) ·
 * 템플릿 오버라이드(`TemplateManager::registerExtensionOverrides`). 셋이 같은 규약
 * (`.g7.html` 저작 SSoT 우선 + 짝 마크업이 없는 `.json`)을 써야 하므로 판별과 컴파일을 여기 한 곳에 둔다.
 *
 * 종전에는 앞의 둘만 `RefreshesLayoutExtensions` 안에서 이 규약을 따랐고 템플릿 오버라이드는
 * `glob('*.json')` 로 남아 저작본을 조용히 무시했다 — 규약이 복제되면 한쪽만 넓힐 때 나머지가
 * 반쪽으로 남는다는 것을 그대로 보여준 자리라, 분리해서 그 재발을 막는다.
 *
 * @see ValidatesLayoutFiles 본 레이아웃(`layouts/`) 쪽 대칭 규약
 */
trait CollectsFragmentSources
{
    use ResolvesLayoutInstallSource;

    /**
     * 등록할 fragment 소스를 수집합니다 — `.g7.html`(있으면 우선) + 짝 `.g7.html` 이 없는 `.json`.
     *
     * 디렉토리의 `.g7.html` 저작 파일을 `.json` 목록 위에 얹어 같은 논리 fragment 는 마크업을 SSoT 로
     * 삼는다. `.g7.html` 은 컴파일로, `.json` 은 직접 읽는다.
     *
     * `config('template.layout_markup.install_source') === 'json'`(커맨드 `--layout-source=json`) 이면
     * 짝 `.json` 이 실재하는 마크업을 건너뛴다 — 본 레이아웃 경로와 같은 규약이다.
     *
     * @param  string  $extensionsPath  확장 fragment 디렉토리 경로
     * @param  array<int, string>  $jsonFiles  이미 알고 있는 `.json` 경로 (모르면 빈 배열 — 디렉토리에서 스캔)
     * @return array<int, array{path: string, markup: bool}> 소스 디스크립터 목록
     */
    protected function collectFragmentSources(string $extensionsPath, array $jsonFiles): array
    {
        $sources = [];
        $markupSiblings = [];
        $jsonForced = $this->layoutInstallSourceIsJsonForced();

        // 호출처가 `.json` 목록을 넘기지 않으면(템플릿 오버라이드) 디렉토리에서 직접 스캔한다.
        if ($jsonFiles === [] && File::isDirectory($extensionsPath)) {
            $jsonFiles = File::glob(rtrim($extensionsPath, '/\\').DIRECTORY_SEPARATOR.'*.json') ?: [];
        }

        $jsonFileKeys = [];
        foreach ($jsonFiles as $jsonFile) {
            $jsonFileKeys[$this->fragmentPathKey($jsonFile)] = true;
        }

        if (File::isDirectory($extensionsPath)) {
            foreach (File::glob(rtrim($extensionsPath, '/\\').DIRECTORY_SEPARATOR.'*.g7.html') ?: [] as $markupFile) {
                $sibling = preg_replace('/\.g7\.html$/i', '.json', $markupFile);
                $siblingKey = $this->fragmentPathKey($sibling);

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
            if (isset($markupSiblings[$this->fragmentPathKey($jsonFile)])) {
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

    /**
     * fragment 경로를 비교용 키로 정규화합니다 (슬래시 통일 + 소문자 — 대소문자 무시 파일시스템 대응).
     *
     * @param  string  $path  경로
     * @return string 정규화 키
     */
    private function fragmentPathKey(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }
}
