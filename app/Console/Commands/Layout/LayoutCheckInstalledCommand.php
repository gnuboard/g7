<?php

namespace App\Console\Commands\Layout;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Traits\CollectsFragmentSources;
use App\Extension\Traits\ValidatesLayoutFiles;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use Illuminate\Console\Command;

/**
 * 설치본(DB 행) 전량을 역변환→프로덕션 컴파일→왕복 대조하는 점검 커맨드 (#476 C5).
 *
 * `layout:compile --roundtrip` 은 **디스크 소스**만 본다. 코드 편집기는 `template_layouts`·
 * `template_layout_extensions` 행을 JSON→`.g7.html` 로 열고 저장 때 다시 컴파일하므로, 그 행이 프로덕션
 * 컴파일을 통과하지 못하면 "열 수는 있는데 저장이 안 되는" 문서가 된다. 이 커맨드가 그 경로를 게이트로 만든다.
 *
 * 행마다: 붙어 있는 템플릿의 표로 역변환 → `<json>` 탈출 키 계량 → 프로덕션 컴파일 → 정규화 후 deep-equal.
 * 확장 행에는 두 검사를 더한다 — **부적합 등록**(호스트 레이아웃·확장점이 이 템플릿에 없음)과
 * (출처, 템플릿) 별 **소스 조각 키 집합 vs DB `fragment_key` 집합**(소실·고아).
 *
 * 실패·불일치·부적합·소실·고아 중 하나라도 있으면 exit 1. `--json` 은 기계 판독용이다.
 *
 * 「JSON 조각 참조 누락」 절(#712): 활성 모듈·플러그인과 점검 대상 템플릿의 레이아웃 소스를 등록 없이 다시 검증해, 7.0 JSON
 * 레이아웃의 깨진 조각 참조가 빈 자리로 등록된 곳을 표로 보여 준다. 설치를 막지 않은 경고라 종료 코드에는 반영하지 않는다.
 */
class LayoutCheckInstalledCommand extends Command
{
    use CollectsFragmentSources;
    use ValidatesLayoutFiles;

    /**
     * @var string
     */
    protected $signature = 'layout:check-installed
                            {--template= : 점검할 템플릿 식별자 (기본: 설치된 템플릿 전부)}
                            {--json : 기계 판독용 JSON 출력}';

    /**
     * @var string
     */
    protected $description = '설치된 레이아웃·확장 조각(DB 행)을 역변환→프로덕션 컴파일→왕복 대조하고 조각 등록 정합성을 점검합니다';

    /**
     * 행 단위 결과.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $rows = [];

    /**
     * `<json>` 탈출 키 → 건수.
     *
     * @var array<string, int>
     */
    private array $budget = [];

    /**
     * 조각 등록 정합성 결과 (부적합·소실·고아).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $registration = [];

    /**
     * JSON 레이아웃에서 빈 자리로 등록된 조각 참조 (`{source, identifier, file, partial, reason, message}`).
     *
     * @var array<int, array<string, string>>
     */
    private array $includeWarnings = [];

    /**
     * 엄격한 검증 실패로 「JSON 조각 참조 누락」 점검을 건너뛴 출처 (`{source, identifier, error}`).
     *
     * 설치 자체가 거부되는 레이아웃이라 이 절의 대상은 아니지만, 조용히 넘기면 0건으로 읽힌다 — 건너뛴 사실을 보고한다.
     *
     * @var array<int, array<string, string>>
     */
    private array $includeSkipped = [];

    /**
     * 커맨드를 생성합니다.
     *
     * @param  LayoutMarkupService  $markup  설치본 JSON ↔ 마크업 변환 서비스
     * @param  LayoutExtensionService  $extensions  조각 적용 가능 판정
     * @param  TemplateRepositoryInterface  $templates  점검 대상 템플릿 조회
     * @param  ModuleRepositoryInterface  $modules  활성 모듈 조회 (조각 참조 누락 절)
     * @param  PluginRepositoryInterface  $plugins  활성 플러그인 조회 (조각 참조 누락 절)
     */
    public function __construct(
        private readonly LayoutMarkupService $markup,
        private readonly LayoutExtensionService $extensions,
        private readonly TemplateRepositoryInterface $templates,
        private readonly ModuleRepositoryInterface $modules,
        private readonly PluginRepositoryInterface $plugins,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $templates = $this->targetTemplates();

        if ($templates === []) {
            $this->line($this->option('json') ? json_encode(['templates' => [], 'rows' => [], 'registration' => [], 'include_warnings' => [], 'include_skipped' => [], 'ok' => true]) : '점검할 템플릿이 없습니다.');

            return self::SUCCESS;
        }

        foreach ($templates as $template) {
            $this->checkLayouts($template);
            $this->checkExtensions($template);
            $this->checkRegistration($template);
        }

        $this->checkIncludeWarnings($templates);

        return $this->report(array_map(static fn (Template $t): string => (string) $t->identifier, $templates));
    }

    /**
     * 점검 대상 템플릿 행을 고릅니다.
     *
     * @return array<int, Template>
     */
    private function targetTemplates(): array
    {
        $all = $this->templates->getAll();

        if ($this->option('template')) {
            $wanted = (string) $this->option('template');

            return $all->filter(static fn (Template $t): bool => $t->identifier === $wanted)->values()->all();
        }

        // 기본은 설치된 템플릿 전부다. 종전에는 배포 원본(`_bundled`)에 같은 식별자가 있는 템플릿만 골라, 제3자
        // 템플릿은 옵션을 따로 주지 않으면 점검에서 조용히 빠졌다(2026-09-28 결정 — 번들 여부로 대상을 고르지 않는다).
        return $all->values()->all();
    }

    /**
     * 템플릿의 레이아웃 행을 전량 점검합니다.
     *
     * @param  Template  $template  템플릿
     * @return void
     */
    private function checkLayouts(Template $template): void
    {
        $rows = TemplateLayout::query()->where('template_id', $template->id)->orderBy('name')->get();

        foreach ($rows as $row) {
            $content = is_array($row->content) ? $row->content : json_decode((string) $row->content, true);
            $sourceType = $row->source_type instanceof LayoutSourceType ? $row->source_type->value : ($row->source_type ?? null);

            $this->checkRow(
                'layout',
                (string) $template->identifier,
                (string) $row->name,
                is_array($content) ? $content : [],
                is_string($sourceType) ? $sourceType : null,
                $row->source_identifier ?? null,
            );
        }
    }

    /**
     * 템플릿의 확장 조각 행을 전량 점검합니다 (왕복 + 부적합 등록).
     *
     * @param  Template  $template  템플릿
     * @return void
     */
    private function checkExtensions(Template $template): void
    {
        $rows = LayoutExtension::query()->where('template_id', $template->id)->orderBy('target_name')->orderBy('fragment_key')->get();

        foreach ($rows as $row) {
            $content = is_array($row->content) ? $row->content : [];
            $label = (string) $row->target_name.($row->fragment_key ? " [{$row->fragment_key}]" : '');

            $this->checkRow(
                'extension',
                (string) $template->identifier,
                $label,
                $content,
                $row->source_type?->value,
                $row->source_identifier,
            );

            // 부적합 등록 — 호스트 레이아웃·확장점이 이 템플릿에 없는 행 (템플릿 오버라이드 행은 호스트가 확장 소유라 제외).
            if ($row->source_type !== LayoutSourceType::Template && ! $this->extensions->isExtensionApplicableToTemplate($content, (int) $template->id)) {
                $this->registration[] = [
                    'kind' => 'inapplicable',
                    'template' => $template->identifier,
                    'source' => $row->source_identifier,
                    'target' => $row->target_name,
                    'fragment_key' => $row->fragment_key,
                ];
            }
        }
    }

    /**
     * (출처, 템플릿) 별 소스 조각 키 집합과 DB `fragment_key` 집합을 대조합니다 (소실·고아).
     *
     * 활성 모듈·플러그인만 본다 — 비활성 확장의 행은 soft delete 되어 있어야 하고, 그 판정은 이 커맨드의 몫이 아니다.
     *
     * @param  Template  $template  템플릿
     * @return void
     */
    private function checkRegistration(Template $template): void
    {
        $sources = [];

        foreach (app(ModuleManager::class)->getActiveModules() as $module) {
            $sources[] = [LayoutSourceType::Module, $module->getIdentifier(), $module->getExtensionsPath(), $module->getLayoutExtensions()];
        }

        foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
            $sources[] = [LayoutSourceType::Plugin, $plugin->getIdentifier(), $plugin->getExtensionsPath(), $plugin->getLayoutExtensions()];
        }

        foreach ($sources as [$sourceType, $identifier, $path, $jsonFiles]) {
            $definitions = $this->loadFragmentDefinitions($path, $jsonFiles, ['source' => $identifier, 'template' => $template->identifier], (string) $template->identifier);
            $liveKeys = array_fill_keys($this->fragmentSourceKeys($path, $jsonFiles), true);

            $dbKeys = LayoutExtension::query()
                ->where('template_id', $template->id)
                ->where('source_type', $sourceType)
                ->where('source_identifier', $identifier)
                ->pluck('fragment_key')
                ->map(static fn ($key): string => (string) $key)
                ->all();
            $dbKeySet = array_fill_keys($dbKeys, true);

            // 소실 — 소스에 있고 이 템플릿에 붙을 자리가 있는데 DB 행이 없다.
            foreach ($definitions as $definition) {
                if (isset($dbKeySet[$definition['key']])) {
                    continue;
                }

                if (! $this->extensions->isExtensionApplicableToTemplate($definition['data'], (int) $template->id)) {
                    continue;
                }

                $this->registration[] = [
                    'kind' => 'missing',
                    'template' => $template->identifier,
                    'source' => $identifier,
                    'target' => $definition['data']['target_layout'] ?? $definition['data']['extension_point'] ?? null,
                    'fragment_key' => $definition['key'],
                ];
            }

            // 고아 — DB 행의 키가 소스 디렉토리에 없다.
            foreach ($dbKeys as $key) {
                if (isset($liveKeys[$key])) {
                    continue;
                }

                $this->registration[] = [
                    'kind' => 'orphan',
                    'template' => $template->identifier,
                    'source' => $identifier,
                    'target' => null,
                    'fragment_key' => $key,
                ];
            }
        }
    }

    /**
     * 행 하나를 역변환→프로덕션 컴파일→왕복 대조합니다.
     *
     * @param  string  $kind  `layout` | `extension`
     * @param  string  $templateId  템플릿 식별자
     * @param  string  $name  행 이름 (보고용)
     * @param  array<string, mixed>  $json  행 content
     * @param  string|null  $sourceKind  행 `source_type`
     * @param  string|null  $identifier  행 `source_identifier`
     * @return void
     */
    private function checkRow(string $kind, string $templateId, string $name, array $json, ?string $sourceKind, ?string $identifier): void
    {
        $started = hrtime(true);
        $record = ['kind' => $kind, 'template' => $templateId, 'name' => $name, 'verdict' => 'ok', 'escapes' => 0, 'warnings' => 0, 'error' => null, 'ms' => 0];

        try {
            ['markup' => $markup, 'escapes' => $escapes] = $this->markup->toMarkup($json, $templateId);
            $record['escapes'] = count($escapes);

            foreach ($escapes as $key) {
                $this->budget[$key] = ($this->budget[$key] ?? 0) + 1;
            }

            $result = $this->markup->toJson($markup, $templateId, $sourceKind, $identifier ?? $templateId, "{$name}.g7.html");
            $record['warnings'] = count($result->warnings);

            $source = LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($json));
            $recompiled = LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($result->json));

            if (LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($source))
                !== LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($recompiled))) {
                $record['verdict'] = 'mismatch';
            }
        } catch (\Throwable $error) {
            $record['verdict'] = 'fail';
            $record['error'] = $error->getMessage();
        }

        $record['ms'] = (int) round((hrtime(true) - $started) / 1_000_000);
        $this->rows[] = $record;
    }

    /**
     * 「JSON 조각 참조 누락」 — 레이아웃 소스를 등록 없이 다시 검증해 빈 자리로 등록된 조각 참조를 모읍니다 (#712).
     *
     * 대상은 활성 모듈·플러그인 전부와 점검 대상 템플릿이다. 설치 경로와 같은 검증({@see validateLayoutFiles()})을 돌리므로
     * 판정 규칙이 갈리지 않는다. 엄격 갈래 실패(설치 자체가 거부되는 레이아웃)는 이 절의 대상이 아니라 건너뛴다.
     *
     * @param  array<int, Template>  $templates  점검 대상 템플릿
     * @return void 반환값 없음
     */
    private function checkIncludeWarnings(array $templates): void
    {
        $sources = [];

        foreach ($this->modules->getActiveModuleIdentifiers() as $identifier) {
            $sources[] = ['module', (string) $identifier, base_path("modules/{$identifier}/resources/layouts")];
        }

        foreach ($this->plugins->getActivePluginIdentifiers() as $identifier) {
            $sources[] = ['plugin', (string) $identifier, base_path("plugins/{$identifier}/resources/layouts")];
        }

        foreach ($templates as $template) {
            $sources[] = ['template', (string) $template->identifier, base_path("templates/{$template->identifier}/layouts")];
        }

        foreach ($sources as [$type, $identifier, $path]) {
            try {
                $this->validateLayoutFiles($path, $identifier, $type, true);
            } catch (\Exception $e) {
                // 엄격한 검증 실패(설치가 거부되는 레이아웃) — 이 절의 대상이 아니지만 건너뛴 사실은 남긴다.
                // 코드 결함(TypeError 등 Error)은 잡지 않는다 — 점검 결과를 0건으로 위장하지 않게.
                $this->takeLayoutIncludeWarnings();
                $this->includeSkipped[] = ['source' => $type, 'identifier' => $identifier, 'error' => strtok($e->getMessage(), "\n") ?: ''];

                continue;
            }

            foreach ($this->takeLayoutIncludeWarnings() as $warning) {
                $this->includeWarnings[] = ['source' => $type, 'identifier' => $identifier] + $warning;
            }
        }
    }

    /**
     * 결과를 보고하고 종료 코드를 정합니다.
     *
     * @param  array<int, string>  $templates  점검한 템플릿 식별자
     * @return int 종료 코드
     */
    private function report(array $templates): int
    {
        $bad = array_values(array_filter($this->rows, static fn (array $r): bool => $r['verdict'] !== 'ok'));
        $ok = $bad === [] && $this->registration === [];

        if ($this->option('json')) {
            $this->line(json_encode([
                'templates' => $templates,
                'rows' => $this->rows,
                'budget' => $this->budget,
                'registration' => $this->registration,
                'include_warnings' => $this->includeWarnings,
                'include_skipped' => $this->includeSkipped,
                'ok' => $ok,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['종류', '템플릿', '이름', '판정', 'escape', '경고', 'ms'],
            array_map(static fn (array $r): array => [$r['kind'], $r['template'], $r['name'], $r['verdict'], $r['escapes'], $r['warnings'], $r['ms']], $this->rows),
        );

        foreach ($bad as $row) {
            $this->newLine();
            $this->error("✗ {$row['kind']} {$row['template']}/{$row['name']} — {$row['verdict']}".($row['error'] !== null ? ": {$row['error']}" : ''));
        }

        foreach ($this->registration as $item) {
            $this->error(sprintf(
                '✗ %s %s/%s target=%s fragment_key=%s',
                $item['kind'],
                $item['template'],
                $item['source'],
                $item['target'] ?? '-',
                $item['fragment_key'] ?? '-',
            ));
        }

        $this->reportBudget();
        $this->reportIncludeWarnings();

        $counts = array_count_values(array_column($this->rows, 'verdict'));
        $registrationCounts = array_count_values(array_column($this->registration, 'kind'));

        $this->newLine();
        $this->info(sprintf(
            '설치본 점검 — 템플릿 %d개, 행 %d개 (ok %d · fail %d · mismatch %d), 등록 정합성 — 부적합 %d · 소실 %d · 고아 %d, JSON 조각 참조 누락 %d.',
            count($templates),
            count($this->rows),
            $counts['ok'] ?? 0,
            $counts['fail'] ?? 0,
            $counts['mismatch'] ?? 0,
            $registrationCounts['inapplicable'] ?? 0,
            $registrationCounts['missing'] ?? 0,
            $registrationCounts['orphan'] ?? 0,
            count($this->includeWarnings),
        ));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 「JSON 조각 참조 누락」 절을 표로 보고합니다 (경고 — 종료 코드에 반영하지 않는다).
     *
     * @return void 반환값 없음
     */
    private function reportIncludeWarnings(): void
    {
        foreach ($this->includeSkipped as $skipped) {
            $this->warn(sprintf('조각 참조 점검 건너뜀 — %s %s: %s', $skipped['source'], $skipped['identifier'], $skipped['error']));
        }

        if ($this->includeWarnings === []) {
            return;
        }

        $this->newLine();
        $this->line('<comment>=== JSON 조각 참조 누락 (빈 자리로 등록됨) ===</comment>');
        $this->table(
            ['출처', '식별자', '파일', '조각', '사유'],
            array_map(static fn (array $w): array => [$w['source'], $w['identifier'], $w['file'], $w['partial'], $w['reason']], $this->includeWarnings),
        );
    }

    /**
     * `<json>` 탈출구 예산을 보고합니다 (`layout:compile --roundtrip` 과 같은 형식).
     *
     * @return void
     */
    private function reportBudget(): void
    {
        if ($this->budget === []) {
            $this->info('<json> 탈출구 방출 0건 — 전 행이 첫 급부 문법으로 왕복.');

            return;
        }

        arsort($this->budget);
        $this->newLine();
        $this->line('<comment>=== &lt;json&gt; 탈출구 예산 ===</comment>');

        foreach ($this->budget as $key => $count) {
            $this->line(sprintf('  %-24s %d건', $key, $count));
        }

        $this->line(sprintf('  총 %d건', array_sum($this->budget)));
    }
}
