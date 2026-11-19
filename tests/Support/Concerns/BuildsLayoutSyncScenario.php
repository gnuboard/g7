<?php

namespace Tests\Support\Concerns;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\LayoutExtension;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use ReflectionMethod;

/**
 * 레이아웃 갱신 전략·미리보기·덮어쓰기 이력 테스트가 함께 쓰는 시나리오 — 출처(템플릿·모듈·플러그인)마다 같은 화면을 만든다.
 *
 * 화면 `merge_list` 는 조각 두 개(`part_a`·`part_b`)를 품고, 붙을 곳 `merge_host` 에는 확장 조각 두 개(`frag_a`·`frag_b`)가
 * 붙는다(템플릿은 `extensions/some-module/` 조각 오버라이드). 판(spec)의 문구만 바꿔 새 판을 만든다.
 */
trait BuildsLayoutSyncScenario
{
    use BuildsLayoutMergeFixtures;

    /** 병합 대상 화면 이름 (접두어 제외) */
    protected string $scenarioScreen = 'merge_list';

    /** 템플릿 조각 오버라이드의 대상 디렉토리 */
    protected string $scenarioOverrideTarget = 'some-module';

    /**
     * 출처 3종 데이터 공급자.
     *
     * @return array<string, array{0: string}> [출처]
     */
    public static function scenarioSources(): array
    {
        return ['template' => ['template'], 'module' => ['module'], 'plugin' => ['plugin']];
    }

    /**
     * 첫 판(spec) 값.
     *
     * @return array<string, mixed> 판
     */
    protected function scenarioV1(): array
    {
        return ['screen' => true, 'gone' => true, 'fragments' => true, 'a' => 'A1', 'b' => 'B1', 'title' => 'T1', 'fa' => 'FA1', 'fb' => 'FB1'];
    }

    /**
     * 출처의 첫 판을 설치합니다 (템플릿 행 + 화면 + 조각).
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    protected function installScenario(string $source): void
    {
        $spec = $this->scenarioV1();
        $this->makeFixtureTemplate($this->scenarioHost() + ($source === 'template' ? $this->scenarioScreenFiles('', $spec) : []));

        // 서비스·명령은 템플릿 정보(`template.json`)를 읽어 결과를 만든다.
        $this->writeFixtureTemplateManifest();

        if ($source === 'template') {
            $this->writeTemplateExtensionOverrides($this->scenarioOverrideTarget, $this->scenarioFragmentFiles($spec));
        }

        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, false);

        if ($source === 'module') {
            $this->makeFixtureModule($this->scenarioScreenFiles('admin/', $spec), $this->scenarioFragmentFiles($spec));
            $this->activateScenarioExtension('module');
        } elseif ($source === 'plugin') {
            $this->makeFixturePlugin($this->scenarioScreenFiles('admin/', $spec), $this->scenarioFragmentFiles($spec));
            $this->activateScenarioExtension('plugin');
        }
    }

    /**
     * 새 판 파일을 씁니다 — 첫 판에서 바꿀 값만 넘깁니다.
     *
     * @param  string  $source  출처
     * @param  array<string, mixed>  $changes  첫 판에서 바꿀 값
     * @return void 반환값 없음
     */
    protected function writeScenarioVersion(string $source, array $changes): void
    {
        $spec = $changes + $this->scenarioV1();

        if ($source === 'template') {
            $this->writeTemplateLayouts($this->scenarioHost() + $this->scenarioScreenFiles('', $spec));
            $this->writeTemplateExtensionOverrides($this->scenarioOverrideTarget, $this->scenarioFragmentFiles($spec));
        } elseif ($source === 'module') {
            $this->writeModuleFiles($this->scenarioScreenFiles('admin/', $spec), $this->scenarioFragmentFiles($spec));
        } else {
            $this->writePluginFiles($this->scenarioScreenFiles('admin/', $spec), $this->scenarioFragmentFiles($spec));
        }
    }

    /**
     * 출처 식별자.
     *
     * @param  string  $source  출처
     * @return string 식별자
     */
    protected function scenarioIdentifier(string $source): string
    {
        return match ($source) {
            'template' => $this->fixtureTemplate,
            'module' => $this->fixtureModule,
            'plugin' => $this->fixturePlugin,
        };
    }

    /**
     * 출처의 화면 DB 이름 (모듈·플러그인은 접두어 포함).
     *
     * @param  string  $source  출처
     * @param  string|null  $baseName  접두어를 뺀 이름 (null 이면 병합 대상 화면)
     * @return string DB 이름
     */
    protected function scenarioLayoutName(string $source, ?string $baseName = null): string
    {
        $baseName ??= $this->scenarioScreen;

        return $source === 'template' ? $baseName : $this->scenarioIdentifier($source).'.'.$baseName;
    }

    /**
     * 화면 행을 찾습니다 (지운 행 포함).
     *
     * @param  string  $source  출처
     * @param  string|null  $baseName  접두어를 뺀 이름
     * @return TemplateLayout|null 행
     */
    protected function scenarioLayout(string $source, ?string $baseName = null): ?TemplateLayout
    {
        return TemplateLayout::withTrashed()
            ->where('template_id', $this->fixtureTemplateModel->id)
            ->where('name', $this->scenarioLayoutName($source, $baseName))
            ->first();
    }

    /**
     * 확장 조각 행을 찾습니다 (지운 행 포함).
     *
     * @param  string  $source  출처
     * @param  string  $key  조각 식별자
     * @return LayoutExtension|null 행
     */
    protected function scenarioFragment(string $source, string $key): ?LayoutExtension
    {
        return LayoutExtension::withTrashed()
            ->where('template_id', $this->fixtureTemplateModel->id)
            ->where('source_identifier', $this->scenarioIdentifier($source))
            ->where('fragment_key', $key)
            ->first();
    }

    /**
     * 운영자가 편집기로 화면의 조각 A 문구를 고친 것처럼 만듭니다.
     *
     * @param  string  $source  출처
     * @param  string  $text  새 문구
     * @return TemplateLayout 고친 행
     */
    protected function editScenarioPartA(string $source, string $text = '운영자 A'): TemplateLayout
    {
        $row = $this->scenarioLayout($source);
        $this->assertNotNull($row, '설치 뒤 화면 행이 있어야 한다');

        $this->operatorEdits($row, function (array $content) use ($text): array {
            foreach ($content['slots']['content'] as $i => $node) {
                if (($node['id'] ?? null) === 'part_a') {
                    $content['slots']['content'][$i]['text'] = $text;
                }
            }

            return $content;
        });

        return $row->fresh();
    }

    /**
     * 운영자가 확장 조각 A 문구를 고친 것처럼 만듭니다.
     *
     * @param  string  $source  출처
     * @param  string  $text  새 문구
     * @return LayoutExtension 고친 행
     */
    protected function editScenarioFragmentA(string $source, string $text = '운영자 조각'): LayoutExtension
    {
        $row = $this->scenarioFragment($source, 'frag_a');
        $this->assertNotNull($row, '설치 뒤 조각 행이 있어야 한다');

        $this->operatorEdits($row, function (array $content) use ($text): array {
            $content['injections'][0]['components'][0]['text'] = $text;

            return $content;
        });

        return $row->fresh();
    }

    /**
     * 행 본문의 조각 A 문구를 읽습니다.
     *
     * @param  TemplateLayout  $row  화면 행
     * @return string|null 문구
     */
    protected function scenarioPartAText(TemplateLayout $row): ?string
    {
        $content = json_decode((string) $row->fresh()->getRawOriginal('content'), true);

        foreach ($content['slots']['content'] ?? [] as $node) {
            if (($node['id'] ?? null) === 'part_a') {
                return $node['text'] ?? null;
            }
        }

        return null;
    }

    /**
     * 출처의 레이아웃·조각 행 전체 체크섬 (본문·원본·잠금·삭제 표시 + 버전 수).
     *
     * @return string 체크섬 (sha256 16진수 문자열)
     */
    protected function scenarioChecksum(): string
    {
        $layouts = TemplateLayout::withTrashed()->where('template_id', $this->fixtureTemplateModel->id)->orderBy('id')
            ->get(['id', 'name', 'content', 'original_content', 'original_content_hash', 'lock_version', 'deleted_at'])
            ->map(fn ($r) => [$r->id, $r->name, $r->getRawOriginal('content'), $r->getRawOriginal('original_content'), $r->original_content_hash, $r->lock_version, (string) $r->deleted_at])->all();
        $fragments = LayoutExtension::withTrashed()->where('template_id', $this->fixtureTemplateModel->id)->orderBy('id')
            ->get(['id', 'fragment_key', 'content', 'original_content', 'lock_version', 'deleted_at', 'is_active'])
            ->map(fn ($r) => [$r->id, $r->fragment_key, $r->getRawOriginal('content'), $r->getRawOriginal('original_content'), $r->lock_version, (string) $r->deleted_at, $r->is_active])->all();
        $ids = TemplateLayout::withTrashed()->where('template_id', $this->fixtureTemplateModel->id)->pluck('id');
        $extIds = LayoutExtension::withTrashed()->where('template_id', $this->fixtureTemplateModel->id)->pluck('id');
        $versions = [
            TemplateLayoutVersion::whereIn('layout_id', $ids)->count(),
            TemplateLayoutExtensionVersion::whereIn('extension_id', $extIds)->count(),
        ];

        return hash('sha256', json_encode([$layouts, $fragments, $versions]));
    }

    /**
     * 붙을 곳 화면.
     *
     * @return array<string, array<string, mixed>> 파일
     */
    private function scenarioHost(): array
    {
        return ['merge_host.json' => ['version' => '1.0.0', 'layout_name' => 'merge_host', 'components' => [['id' => 'anchor', 'type' => 'basic', 'name' => 'Div']]]];
    }

    /**
     * 화면 파일 묶음.
     *
     * @param  string  $prefix  경로 접두어 (모듈·플러그인은 `admin/`)
     * @param  array<string, mixed>  $spec  판
     * @return array<string, array<string, mixed>> 파일
     */
    private function scenarioScreenFiles(string $prefix, array $spec): array
    {
        $files = [];

        if ($spec['screen']) {
            $files[$prefix.$this->scenarioScreen.'.json'] = [
                'version' => '1.0.0',
                'layout_name' => $this->scenarioScreen,
                'meta' => ['title' => $spec['title']],
                'data_sources' => [['id' => 'items', 'type' => 'api', 'endpoint' => '/api/items']],
                'slots' => ['content' => [
                    ['id' => 'title', 'type' => 'basic', 'name' => 'H1', 'text' => '목록'],
                    ['partial' => 'partials/merge_list/_a.json'],
                    ['partial' => 'partials/merge_list/_b.json'],
                ]],
            ];
            $files[$prefix.'partials/merge_list/_a.json'] = ['meta' => ['is_partial' => true], 'id' => 'part_a', 'type' => 'basic', 'name' => 'Div', 'text' => $spec['a']];
            $files[$prefix.'partials/merge_list/_b.json'] = ['meta' => ['is_partial' => true], 'id' => 'part_b', 'type' => 'basic', 'name' => 'Div', 'text' => $spec['b']];
        }

        if ($spec['gone']) {
            $files[$prefix.'merge_gone.json'] = ['version' => '1.0.0', 'layout_name' => 'merge_gone', 'components' => [['id' => 'gone', 'type' => 'basic', 'name' => 'Div', 'text' => 'G']]];
        }

        return $files;
    }

    /**
     * 확장 조각 파일 묶음.
     *
     * @param  array<string, mixed>  $spec  판
     * @return array<string, array<string, mixed>> 파일
     */
    private function scenarioFragmentFiles(array $spec): array
    {
        if (! $spec['fragments']) {
            return [];
        }

        $fragment = fn (string $ref, string $text, int $priority) => [
            'target_layout' => 'merge_host',
            'priority' => $priority,
            'injections' => [[
                'target_id' => 'anchor',
                'position' => 'append_child',
                'components' => [['id' => $ref, 'type' => 'basic', 'name' => 'Div', 'text' => $text]],
            ]],
        ];

        return [
            'frag_a.json' => $fragment('frag_a', $spec['fa'], 900),
            'frag_b.json' => $fragment('frag_b', $spec['fb'], 900),
        ];
    }

    /**
     * 모듈·플러그인 픽스처를 켠 것처럼 레이아웃·조각을 등록합니다.
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    private function activateScenarioExtension(string $source): void
    {
        $manager = $source === 'module' ? app(ModuleManager::class) : app(PluginManager::class);
        $instance = $source === 'module' ? $manager->getModule($this->fixtureModule) : $manager->getPlugin($this->fixturePlugin);
        $this->assertNotNull($instance, "{$source} 픽스처가 관리자에 로드돼야 한다");

        foreach ([[$source === 'module' ? 'registerModuleLayouts' : 'registerPluginLayouts', $this->scenarioIdentifier($source)], ['restoreLayoutExtensions', $instance], ['registerLayoutExtensions', $instance]] as [$method, $arg]) {
            $reflection = new ReflectionMethod($manager, $method);
            $reflection->invoke($manager, $arg);
        }
    }
}
