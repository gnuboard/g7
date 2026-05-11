<?php

namespace App\Listeners\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Extension\Traits\ResolvesLanguageFragments;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * `template.language.merge` HookManager 필터 리스너.
 *
 * TemplateService::getLanguageDataWithModules() 가 마지막에 호출하는 필터를 받아
 * 활성 언어팩의 frontend/*.json 데이터를 병합한 결과를 반환합니다.
 *
 * 병합 우선순위:
 *  - 템플릿 ⊂ 모듈 ⊂ 플러그인 ⊂ 언어팩 (언어팩이 최우선)
 *  - 동일 슬롯에 active 언어팩이 1개만 존재하므로 충돌 가능성은 낮음
 */
// audit:allow listener-must-implement-hooklistenerinterface reason: LanguagePackServiceProvider 가 HookManager::addFilter 로 직접 등록하는 명시 등록 패턴
class MergeFrontendLanguage
{
    use ResolvesLanguageFragments;

    /**
     * @param  LanguagePackRegistry  $registry  활성 언어팩 레지스트리
     */
    public function __construct(
        private readonly LanguagePackRegistry $registry,
    ) {}

    /**
     * 필터 호출 진입점.
     *
     * @param  array<string, mixed>  $data  병합 누적 데이터
     * @param  string  $templateIdentifier  현재 렌더링 템플릿 식별자
     * @param  string  $locale  로케일
     * @return array<string, mixed> 병합된 다국어 데이터
     */
    public function __invoke(array $data, string $templateIdentifier, string $locale): array
    {
        $packs = $this->collectRelevantPacks($templateIdentifier, $locale);

        foreach ($packs as $pack) {
            $frontend = $this->loadFrontendData($pack);
            if (empty($frontend)) {
                continue;
            }

            // module / plugin 팩은 target_identifier 를 root 키로 wrap 한다.
            // TemplateService::loadActiveModulesLanguageData() 가 ko/en 데이터를
            // `[$moduleIdentifier => $data]` 형태로 노출하므로, 동일 구조로 병합되어야
            // 프론트엔드의 `{{$t:sirsoft-ecommerce.admin.settings.basic_info}}` 표현식이
            // ja 활성 시에도 정확한 경로로 해석된다.
            // core / template 팩은 root 에 평탄 병합 (TemplateService 가 동일 구조 사용).
            if ($pack->target_identifier
                && in_array($pack->scope, [LanguagePackScope::Module->value, LanguagePackScope::Plugin->value], true)) {
                $frontend = [$pack->target_identifier => $frontend];
            }

            $data = $this->mergeRecursive($data, $frontend);
        }

        return $data;
    }

    /**
     * 현재 locale + 템플릿 컨텍스트에 적용할 활성 언어팩을 수집합니다.
     *
     * 우선순위 (병합 순서):
     *  1. core 언어팩
     *  2. 활성 모듈 언어팩
     *  3. 활성 플러그인 언어팩
     *  4. 현재 템플릿 언어팩 (가장 마지막 → 최고 우선)
     *
     * @param  string  $templateIdentifier  렌더링 템플릿 식별자
     * @param  string  $locale  로케일
     * @return array<int, LanguagePack> 정렬된 언어팩 목록
     */
    private function collectRelevantPacks(string $templateIdentifier, string $locale): array
    {
        $allActive = $this->registry->getActivePacks()->filter(
            fn (LanguagePack $pack) => $pack->locale === $locale
        );

        $ordered = [];

        foreach ($allActive as $pack) {
            if ($pack->scope === LanguagePackScope::Core->value) {
                $ordered[0][] = $pack;
            }
        }

        foreach ($allActive as $pack) {
            if ($pack->scope === LanguagePackScope::Module->value) {
                $ordered[1][] = $pack;
            }
        }

        foreach ($allActive as $pack) {
            if ($pack->scope === LanguagePackScope::Plugin->value) {
                $ordered[2][] = $pack;
            }
        }

        foreach ($allActive as $pack) {
            if ($pack->scope === LanguagePackScope::Template->value
                && $pack->target_identifier === $templateIdentifier) {
                $ordered[3][] = $pack;
            }
        }

        ksort($ordered);

        $flat = [];
        foreach ($ordered as $bucket) {
            foreach ($bucket as $pack) {
                $flat[] = $pack;
            }
        }

        return $flat;
    }

    /**
     * 언어팩 디렉토리의 frontend 데이터를 로드해 단일 배열로 병합합니다.
     *
     * 로드 우선순위:
     *  1. `frontend/partial/{name}.json` — 파일 basename 을 1단계 키로 사용 (낮은 우선순위)
     *  2. `frontend/{locale}.json` 등 루트 *.json 파일 — `$partial` 디렉티브 해석 후 병합 (덮어씀)
     *
     * 1단계가 있는 이유: 번들 언어팩의 루트 `{locale}.json` 이 잘못된 `$partial` 경로
     * (예: `partial/ko/auth.json`) 를 가리키더라도 partial 디렉토리 내용을 안전하게
     * 로드해 키 누락을 회피한다. partial 파일이 없으면 1단계는 건너뛴다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @return array<string, mixed> 병합된 frontend 데이터
     */
    private function loadFrontendData(LanguagePack $pack): array
    {
        $directory = $pack->resolveDirectory().DIRECTORY_SEPARATOR.'frontend';
        if (! File::isDirectory($directory)) {
            return [];
        }

        $merged = [];

        // 1단계: frontend/partial/*.json 직접 로드 (basename → 키)
        $partialDir = $directory.DIRECTORY_SEPARATOR.'partial';
        if (File::isDirectory($partialDir)) {
            foreach (File::files($partialDir) as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }

                $decoded = json_decode(File::get($file->getRealPath()), true);
                if (! is_array($decoded)) {
                    Log::warning('language-pack frontend partial JSON invalid', [
                        'pack' => $pack->identifier,
                        'file' => $file->getFilename(),
                    ]);

                    continue;
                }

                $key = $file->getFilenameWithoutExtension();
                $existing = $merged[$key] ?? [];
                $merged[$key] = $this->mergeRecursive(is_array($existing) ? $existing : [], $decoded);
            }
        }

        // 2단계: frontend 루트 *.json 파일 — $partial 해석 후 병합
        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $decoded = json_decode(File::get($file->getRealPath()), true);
            if (! is_array($decoded)) {
                Log::warning('language-pack frontend JSON invalid', [
                    'pack' => $pack->identifier,
                    'file' => $file->getFilename(),
                ]);

                continue;
            }

            try {
                $this->resetFragmentStack();
                $resolved = $this->resolveLanguageFragments($decoded, $directory);
            } catch (\RuntimeException $e) {
                // $partial 경로 오류(예: partial/ko/auth.json 같은 잘못된 참조)는
                // 1단계 partial 직접 로드로 보완되므로 경고만 남기고 루트 파일은 스킵.
                Log::warning('language-pack frontend $partial resolution failed', [
                    'pack' => $pack->identifier,
                    'file' => $file->getFilename(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $merged = $this->mergeRecursive($merged, $resolved);
        }

        return $merged;
    }

    /**
     * 재귀 병합 (later wins on scalar conflicts).
     *
     * @param  array<string, mixed>  $base  기본 배열
     * @param  array<string, mixed>  $override  덮어쓸 배열
     * @return array<string, mixed> 병합 결과
     */
    private function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
