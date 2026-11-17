<?php

namespace App\Services\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSyncMode;
use App\Exceptions\ExtensionUpdatePreviewException;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Layout\LayoutSyncOptions;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Update\ExtensionUpdatePreviewStore;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;

/**
 * 확장 업데이트 미리보기 — 업데이트하지 않고, 새 판으로 바꾸면 화면마다 어떻게 되는지와 지워질 선언 항목을 계산합니다.
 *
 * 관리자 [업데이트] 창(API `POST …/{id}/update/preview`)과 CLI `<type>:update <id> --dry-run --json` 이 이 한 메서드를
 * 부른다(판정식 사본 금지). 새 판 파일은 소스 기준이다 — 번들이면 `_bundled/{id}`, GitHub 면 `_pending` 에 내려받고
 * 토큰(`pending_token`)을 돌려준다. 이어지는 업데이트가 토큰을 넘기면 같은 파일을 다시 쓴다({@see ExtensionUpdatePreviewStore}).
 *
 * 레이아웃 판정은 실제 업데이트와 같은 동기화 코드를 미리보기(dry-run)로 돌린다 — 새 판 디렉토리를 파일 출처로, 지금
 * 활성 디렉토리를 이전 판(병합 기준)으로 쓴다. 꺼 둔 확장은 업데이트 때 레이아웃을 갱신하지 않으므로 판정 목록이 비고
 * `layouts_skipped=inactive` 로 알린다. 켜진 확장인데 활성 디렉토리가 없으면(파일이 지워진 설치본) 병합 기준이 없어
 * `layouts_skipped=files_missing` 로 알린다.
 */
class ExtensionUpdatePreviewService
{
    /**
     * @param  ModuleManager  $modules  모듈 관리자
     * @param  PluginManager  $plugins  플러그인 관리자
     * @param  TemplateManager  $templates  템플릿 관리자
     * @param  ExtensionUpdatePreviewStore  $store  내려받은 준비본 표
     * @param  ExtensionDeclarativeCleanupPlanner  $cleanupPlanner  선언 항목 삭제 예정 판정
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 저장소
     * @param  PluginRepositoryInterface  $pluginRepository  플러그인 저장소
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 저장소
     */
    public function __construct(
        private ModuleManager $modules,
        private PluginManager $plugins,
        private TemplateManager $templates,
        private ExtensionUpdatePreviewStore $store,
        private ExtensionDeclarativeCleanupPlanner $cleanupPlanner,
        private ModuleRepositoryInterface $moduleRepository,
        private PluginRepositoryInterface $pluginRepository,
        private TemplateRepositoryInterface $templateRepository,
    ) {}

    /**
     * 업데이트를 미리 봅니다.
     *
     * @param  string  $type  확장 종류 (`module`·`plugin`·`template`)
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $source  업데이트 소스 (`bundled`·`github`, null 이면 자동)
     * @param  LayoutSyncMode  $mode  레이아웃 처리 방식 (화면은 keep 을 기본 선택으로 보여 준다)
     * @param  array<string, string>  $decisions  화면별 결정
     * @return array{type: string, identifier: string, update_available: bool, update_source: string|null, from_version: string|null, to_version: string|null, pending_token: string|null, layouts_skipped: string|null, layouts: array<string, mixed>, declarative_deletions: list<array<string, mixed>>, check_error: string|null} 미리보기 (`check_error` 는 GitHub 조회 실패 사유)
     *
     * @throws ExtensionUpdatePreviewException 알 수 없는 확장 종류·설치되지 않은 확장
     * @throws \RuntimeException 새 판을 내려받지 못할 때
     */
    public function preview(string $type, string $identifier, ?string $source, LayoutSyncMode $mode = LayoutSyncMode::Keep, array $decisions = []): array
    {
        [$manager, $record, $dir] = $this->resolve($type, $identifier);

        $info = match ($type) {
            'module' => $manager->checkModuleUpdate($identifier, $source),
            'plugin' => $manager->checkPluginUpdate($identifier, $source),
            'template' => $manager->checkTemplateUpdate($identifier, $source),
        };

        $updateSource = $info['update_source'] ?? null;
        $toVersion = $info['latest_version'] ?? null;
        $base = [
            'type' => $type,
            'identifier' => $identifier,
            'update_available' => (bool) ($info['update_available'] ?? false),
            'update_source' => $updateSource,
            'from_version' => $info['current_version'] ?? $record->version,
            'to_version' => $toVersion,
            'pending_token' => null,
            'layouts_skipped' => null,
            'layouts' => ['strategy' => $mode->value, 'entries' => [], 'summary' => [], 'has_conflicts' => false, 'conflict_screens' => []],
            'declarative_deletions' => [],
            // GitHub 조회에 실패했으면 그 사유 — 「업데이트 없음」·번들 대체와 「확인하지 못함」을 가른다
            'check_error' => isset($info['check_error']) ? (string) $info['check_error'] : null,
        ];

        if (! $base['update_available'] || $toVersion === null) {
            return $base;
        }

        // GitHub 새 판은 받아 서명을 검증하고 풀어 둔 디렉토리가 기준이다 — 준비본 표에 검증 결과와 함께 등록해 업데이트가 다시 쓴다
        $acquired = $updateSource === 'github' ? $manager->prepareUpdatePreviewSource($identifier, $toVersion) : null;
        $root = $acquired['extracted_dir'] ?? ExtensionPendingHelper::getBundledPath(base_path($dir), $identifier);

        if ($acquired !== null) {
            $base['pending_token'] = $this->store->put($type, $identifier, $toVersion, $acquired['extracted_dir'], $acquired['verification']->toArray(), $acquired['temp_dir']);
        }

        $activePath = base_path($dir.DIRECTORY_SEPARATOR.$identifier);

        if ($record->status !== ExtensionStatus::Active->value) {
            $base['layouts_skipped'] = 'inactive';
        } elseif (! is_dir($activePath) || ! $this->isLoaded($manager, $type, $identifier)) {
            // 켜진 확장인데 활성 디렉토리가 없다(파일이 지워진 설치본) — 병합 기준이 없어 판정하지 않는다. 실제 업데이트는 새 판
            // 파일을 활성 디렉토리로 복사한 뒤 레이아웃을 동기화한다.
            $base['layouts_skipped'] = 'files_missing';
        } else {
            $result = LayoutSyncOptions::run(
                new LayoutSyncOptions(true, $decisions, $root, 'extension_update'),
                fn () => match ($type) {
                    'module' => $manager->refreshModuleLayouts($identifier, $mode, $activePath),
                    'plugin' => $manager->refreshPluginLayouts($identifier, $mode, $activePath),
                    'template' => $manager->refreshTemplateLayouts($identifier, $mode, $activePath),
                }
            );
            $base['layouts'] = $result['layout_preview'];
        }

        // 지워질 선언 항목(알림 정의·메뉴·권한·역할 등) — 운영자 수정본 여부 포함. 실제 정리와 같은 판정식이다.
        $base['declarative_deletions'] = $this->cleanupPlanner->plan($type, $identifier, $root);

        return $base;
    }

    /**
     * 관리자가 그 확장을 불러왔는지 확인합니다 (활성 디렉토리의 확장 클래스를 읽었는지).
     *
     * @param  ModuleManager|PluginManager|TemplateManager  $manager  확장 관리자
     * @param  string  $type  확장 종류
     * @param  string  $identifier  확장 식별자
     * @return bool 불러왔으면 true
     */
    private function isLoaded(ModuleManager|PluginManager|TemplateManager $manager, string $type, string $identifier): bool
    {
        return match ($type) {
            'module' => $manager->getModule($identifier) !== null,
            'plugin' => $manager->getPlugin($identifier) !== null,
            'template' => $manager->getTemplate($identifier) !== null,
        };
    }

    /**
     * 확장 종류에서 관리자·DB 행·디렉토리를 고릅니다.
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  확장 식별자
     * @return array{0: ModuleManager|PluginManager|TemplateManager, 1: Module|Plugin|Template, 2: string} [관리자, 행, 디렉토리명]
     *
     * @throws ExtensionUpdatePreviewException 알 수 없는 확장 종류·설치되지 않은 확장
     */
    private function resolve(string $type, string $identifier): array
    {
        [$manager, $repository, $dir] = match ($type) {
            'module' => [$this->modules, $this->moduleRepository, 'modules'],
            'plugin' => [$this->plugins, $this->pluginRepository, 'plugins'],
            'template' => [$this->templates, $this->templateRepository, 'templates'],
            default => throw ExtensionUpdatePreviewException::unknownType($type),
        };

        match ($type) {
            'module' => $manager->loadModules(),
            'plugin' => $manager->loadPlugins(),
            'template' => $manager->loadTemplates(),
        };

        $record = $repository->findByIdentifier($identifier);

        if ($record === null) {
            throw ExtensionUpdatePreviewException::notInstalled($identifier);
        }

        return [$manager, $record, $dir];
    }
}
