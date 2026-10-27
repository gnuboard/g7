<?php

namespace App\Console\Commands\Concerns;

use App\Enums\LanguagePackScope;
use App\Extension\Vendor\VendorMode;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\Extension\ExtensionInstallPreviewBuilder;

/**
 * 확장 설치 명령의 연쇄 설치 옵션 — `--with-dependencies`·`--with-language-packs[=all|none|id,...]`.
 *
 * 관리자 화면 설치 창과 같은 서비스(CascadeInstallOrchestrator)·같은 후보(ExtensionInstallPreviewBuilder)를 쓴다.
 * 터미널은 셸 접근이 곧 권한이라 화면의 항목별 권한 판정은 하지 않는다(UserGradeGuard·totp:reset 과 같은 관례).
 *
 * 명령 시그니처에 다음 두 옵션을 둔다:
 *   {--with-dependencies : …}  {--with-language-packs=none : …}
 */
trait RunsCascadeInstall
{
    /** 설치 전에 정한 언어팩 후보 (본 확장 설치 뒤에는 미리보기가 후보를 다시 계산하지 못한다) */
    private array $cascadeLanguagePackCandidates = [];

    /** 함께 설치·활성화한 의존 확장 — 본 확장이 실패하면 남겨 둔 목록을 알린다 */
    private array $cascadeDependencyResult = ['installed' => [], 'activated' => [], 'skipped' => []];

    /**
     * 본 확장 설치 전 — 의존 확장을 확인하고, 옵션이 있으면 함께 설치·활성화한다.
     *
     * 옵션이 없는데 의존이 미충족이면 무엇이 왜 모자란지와 `--with-dependencies` 안내를 출력하고 실패한다.
     *
     * @param  string  $type  module|plugin|template
     * @param  string  $identifier  본 확장 식별자
     * @param  VendorMode  $vendorMode  본 확장과 같은 vendor 설치 방식 (의존 확장에도 적용)
     * @return bool 계속 진행해도 되면 true
     */
    protected function runCascadeDependencies(string $type, string $identifier, VendorMode $vendorMode): bool
    {
        $orchestrator = app(CascadeInstallOrchestrator::class);
        $plan = $orchestrator->resolveDependencyPlan($type, $identifier);

        $languagePacks = $this->resolveCascadeLanguagePacks($type, $identifier);
        if ($languagePacks === null) {
            return false;
        }
        $this->cascadeLanguagePackCandidates = $languagePacks;

        foreach ($plan['errors'] as $error) {
            $this->error('❌ '.$error);
        }
        if ($plan['errors'] !== []) {
            return false;
        }

        if ($plan['steps'] === []) {
            return true;
        }

        if (! $this->option('with-dependencies')) {
            foreach ($plan['steps'] as $step) {
                $this->error('❌ '.__('extensions.commands.cascade.dependency_unmet', [
                    'identifier' => $step['identifier'],
                    'required' => $step['required_version'],
                    'state' => __('extensions.commands.cascade.state_'.$step['action']),
                ]));
            }
            $this->line('   '.__('extensions.commands.cascade.use_with_dependencies'));

            return false;
        }

        foreach ($plan['steps'] as $step) {
            $this->line('   '.__('extensions.commands.cascade.dependency_step', [
                'identifier' => $step['identifier'],
                'action' => __('extensions.commands.cascade.state_'.$step['action']),
                'required_by' => $step['required_by'],
            ]));
        }

        try {
            $result = $orchestrator->installDependencies($plan['steps'], $vendorMode);
        } catch (\RuntimeException $e) {
            $this->error('❌ '.$e->getMessage());

            return false;
        }

        $this->cascadeDependencyResult = $result;
        $this->info('✓ '.__('extensions.commands.cascade.dependencies_done', [
            'installed' => count($result['installed']),
            'activated' => count($result['activated']),
        ]));

        return true;
    }

    /**
     * 본 확장 설치가 실패했을 때 — 앞서 설치·활성화해 남겨 둔 의존 확장을 알린다(되돌리지 않는 현행 — D14).
     *
     * @return void 반환값 없음
     */
    protected function reportKeptCascadeDependencies(): void
    {
        $notice = CascadeInstallOrchestrator::keptDependenciesNotice($this->cascadeDependencyResult);
        if ($notice !== null) {
            $this->warn('⚠️  '.$notice);
        }
    }

    /**
     * 본 확장 설치 뒤 — `--with-language-packs` 에 따라 번들 언어팩을 설치(자동 활성화, best-effort)한다.
     *
     * @return void 반환값 없음
     */
    protected function runCascadeLanguagePacks(): void
    {
        if ($this->cascadeLanguagePackCandidates === []) {
            return;
        }

        $failures = app(CascadeInstallOrchestrator::class)->installLanguagePacks($this->cascadeLanguagePackCandidates, null, $awaiting);

        foreach ($failures as $failure) {
            $this->warn('⚠️  '.__('extensions.commands.cascade.language_pack_failed', $failure));
        }
        foreach ($awaiting ?? [] as $identifier) {
            $this->line('   '.__('extensions.commands.cascade.language_pack_awaiting', ['identifier' => $identifier]));
        }
        $this->info('✓ '.__('extensions.commands.cascade.language_packs_done', [
            'count' => count($this->cascadeLanguagePackCandidates) - count($failures),
        ]));
    }

    /**
     * `--with-language-packs` 값을 설치할 번들 언어팩 목록으로 바꾼다.
     *
     * none(기본) = 없음 · all = 설치 창의 후보 전부 · 쉼표 목록 = 그 식별자 (후보에 없으면 오류).
     *
     * @param  string  $type  module|plugin|template
     * @param  string  $identifier  본 확장 식별자
     * @return array<int, string>|null 언어팩 식별자 목록 (옵션 오류면 null)
     */
    private function resolveCascadeLanguagePacks(string $type, string $identifier): ?array
    {
        $raw = trim((string) ($this->option('with-language-packs') ?? 'none'));
        if ($raw === '' || $raw === 'none') {
            return [];
        }

        $candidates = array_column(
            app(ExtensionInstallPreviewBuilder::class)->build(LanguagePackScope::from($type), $identifier)['language_packs'],
            'bundled_identifier'
        );

        if ($raw === 'all') {
            return $candidates;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
        $unknown = array_diff($requested, $candidates);
        if ($unknown !== []) {
            $this->error('❌ '.__('extensions.commands.cascade.language_pack_not_candidate', ['list' => implode(', ', $unknown)]));

            return null;
        }

        return $requested;
    }
}
