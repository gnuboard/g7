<?php

namespace App\Services;

use App\Extension\Helpers\EditorSpecAssembler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 레이아웃 편집기 스펙 묶음 서비스.
 *
 * 편집기는 편집 대상 템플릿과 활성 모듈·플러그인의 editor-spec 을 모두 받아 병합한다.
 * 종전에는 확장마다 개별 엔드포인트(`/api/{modules|plugins|templates}/{id}/editor-spec`)를
 * 따로 불러 편집기를 열 때 요청이 확장 수만큼 나갔다(개발 서버 27건, 건당 2~5.7초).
 * 이 서비스는 같은 판정을 한 요청 안에서 항목마다 돌려 묶어 낸다.
 *
 * 개별 엔드포인트와 같은 결과를 내기 위해 판정 경로를 공유한다.
 *  - 모듈·플러그인: `ModuleService::getEditorSpec` / `PluginService::getEditorSpec`
 *    (활성 상태 판정, 설치본만 읽기). 비활성·미존재는 그 항목만 404 로 표시한다.
 *  - 템플릿: `templateEditorSpec()` — 개별 템플릿 엔드포인트도 이 메서드를 쓴다.
 *
 * 서버 캐시는 두지 않는다. 개별 엔드포인트도 요청마다 설치본 파일과 확장 상태를 새로 읽으므로,
 * 확장 활성화·비활성화·업데이트가 다음 요청에 바로 반영되는 성질이 묶음에서도 그대로 유지된다.
 */
class EditorSpecBundleService
{
    /**
     * EditorSpecBundleService 생성자
     *
     * @param  ModuleService  $moduleService  모듈 서비스 (모듈 편집기 스펙 판정)
     * @param  PluginService  $pluginService  플러그인 서비스 (플러그인 편집기 스펙 판정)
     */
    public function __construct(
        private ModuleService $moduleService,
        private PluginService $pluginService,
    ) {}

    /**
     * 템플릿 편집기 스펙을 합본해 반환합니다.
     *
     * 설치본(`templates/{id}/editor-spec.json`)만 읽고 배포 원본으로 폴백하지 않습니다.
     * 파일이 없거나 JSON 이 깨져 있으면 null 입니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<string, mixed>|null 합본 스펙, 없으면 null
     */
    public function templateEditorSpec(string $identifier): ?array
    {
        return EditorSpecAssembler::assemble(
            base_path("templates/{$identifier}/editor-spec.json")
        );
    }

    /**
     * 모듈 → 플러그인 → 템플릿 순서로 편집기 스펙 묶음을 만듭니다.
     *
     * 항목 순서는 요청 순서를 그대로 따른다(편집기 병합 순서가 곧 우선순위다 — 템플릿이 마지막).
     * 한 항목이 실패해도 나머지 항목은 계속 만든다. 실패한 항목은 `status` 와 `error` 로 표시한다.
     *
     * @param  string  $templateIdentifier  편집 대상 템플릿 식별자
     * @param  array<int, string>  $moduleIdentifiers  모듈 식별자 목록 (요청 순서)
     * @param  array<int, string>  $pluginIdentifiers  플러그인 식별자 목록 (요청 순서)
     * @return array<int, array{kind: string, identifier: string, status: int, spec: array<string, mixed>|null, error: string|null}> 항목 목록
     */
    public function buildBundle(string $templateIdentifier, array $moduleIdentifiers, array $pluginIdentifiers): array
    {
        $items = [];

        foreach ($this->uniqueIdentifiers($moduleIdentifiers) as $identifier) {
            $items[] = $this->buildItem('module', $identifier, fn () => $this->moduleService->getEditorSpec($identifier));
        }

        foreach ($this->uniqueIdentifiers($pluginIdentifiers) as $identifier) {
            $items[] = $this->buildItem('plugin', $identifier, fn () => $this->pluginService->getEditorSpec($identifier));
        }

        $items[] = $this->buildItem('template', $templateIdentifier, fn () => [
            'success' => true,
            'spec' => $this->templateEditorSpec($templateIdentifier),
            'error' => null,
        ]);

        return $items;
    }

    /**
     * 한 항목을 판정합니다. 판정 중 예외가 나면 그 항목만 500 으로 표시합니다.
     *
     * @param  string  $kind  항목 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @param  callable(): array{success: bool, spec: array<string, mixed>|null, error: string|null}  $resolve  판정 함수
     * @return array{kind: string, identifier: string, status: int, spec: array<string, mixed>|null, error: string|null} 항목
     */
    private function buildItem(string $kind, string $identifier, callable $resolve): array
    {
        try {
            $result = $resolve();
        } catch (Throwable $e) {
            // 한 확장의 스펙 파일 읽기가 실패해도 다른 확장의 스펙은 낸다(부분 실패).
            Log::warning('EditorSpecBundleService: 편집기 스펙 판정 실패', [
                'kind' => $kind,
                'identifier' => $identifier,
                'exception' => $e->getMessage(),
            ]);

            return [
                'kind' => $kind,
                'identifier' => $identifier,
                'status' => 500,
                'spec' => null,
                'error' => 'spec_load_failed',
            ];
        }

        if (! ($result['success'] ?? false)) {
            // 개별 엔드포인트가 404 로 답하는 경우(비활성·미존재)와 같은 판정이다.
            return [
                'kind' => $kind,
                'identifier' => $identifier,
                'status' => 404,
                'spec' => null,
                'error' => $result['error'] ?? "{$kind}_not_found",
            ];
        }

        return [
            'kind' => $kind,
            'identifier' => $identifier,
            'status' => 200,
            'spec' => $result['spec'] ?? null,
            'error' => null,
        ];
    }

    /**
     * 식별자 목록에서 중복을 빼고 처음 나온 순서를 지킵니다.
     *
     * @param  array<int, string>  $identifiers  식별자 목록
     * @return array<int, string> 중복 없는 식별자 목록
     */
    private function uniqueIdentifiers(array $identifiers): array
    {
        return array_values(array_unique($identifiers));
    }
}
