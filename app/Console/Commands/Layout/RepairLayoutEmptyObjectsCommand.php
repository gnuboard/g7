<?php

namespace App\Console\Commands\Layout;

use App\Enums\LayoutExtensionType;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use App\Services\LayoutExtensionService;
use App\Services\LayoutService;
use App\Support\LayoutEmptyObjectRepair;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 이미 `[]` 로 굳은 레이아웃 빈 객체(`{}`)를 원본 기준으로 되돌리는 운영자 명령 (#758).
 *
 * 편집기 저장·버전 복원·이름 바꾸기 같은 쓰기 경로가 레이아웃 원문을 연관 배열로 읽어 다시 저장하면 `{}` 가
 * `[]` 로 굳는다. 동기화기는 둘을 같은 문서로 판정하므로 업데이트·재동기화로는 저절로 고쳐지지 않는다.
 * 이 명령은 원본(`original_content`)이 `{}` 이고 저장본의 같은 자리가 `[]` 인 곳만 되돌린다 — 판정 규칙은
 * {@see LayoutEmptyObjectRepair}.
 *
 * | 대상 | 기준 |
 * | --- | --- |
 * | `template_layouts.content` | 같은 행 `original_content` |
 * | `template_layout_extensions.content` | 같은 행 `original_content` |
 * | `template_layout_versions.content` | 소속 레이아웃 `original_content` |
 * | `template_layout_extension_versions.content` | 소속 확장 `original_content` |
 *
 * 기본은 미리보기(쓰기 없음)다. `--apply` 를 붙이면 행 단위 트랜잭션으로 쓰고, 본 행은 `lock_version` 을 올려
 * 열려 있던 편집기의 옛 저장이 409 로 막히게 한다. 새 버전 행은 만들지 않는다 — 내용 변경이 아니라 표기 복원이다.
 */
class RepairLayoutEmptyObjectsCommand extends Command
{
    use ClearsTemplateCaches;

    /**
     * @var string
     */
    protected $signature = 'layout:repair-empty-objects
                            {--apply : 실제로 고친다(없으면 미리보기)}
                            {--template= : 템플릿 식별자로 한정}';

    /**
     * @var string
     */
    protected $description = '편집기 저장·버전 복원 등으로 [] 로 굳은 레이아웃 빈 객체({})를 원본 기준으로 찾아 되돌립니다';

    /** 경로 예시로 보여 줄 최대 개수 */
    private const PATH_EXAMPLES = 3;

    /**
     * 고칠 행 목록 — `{table, id, name, count, paths}`.
     *
     * @var list<array{table: string, id: int, name: string, count: int, paths: list<string>}>
     */
    private array $found = [];

    /** 기준 없음으로 건너뛴 행 수 */
    private int $skipped = 0;

    /**
     * 기준 원문을 객체 디코드한 결과 캐시 (`{kind}:{id}` → 디코드 값, 기준 없음은 null).
     *
     * @var array<string, mixed>
     */
    private array $references = [];

    /**
     * 커맨드를 생성합니다.
     *
     * @param  LayoutService  $layoutService  레이아웃 캐시 무효화
     * @param  LayoutExtensionService  $extensionService  확장 캐시 무효화
     */
    public function __construct(
        private readonly LayoutService $layoutService,
        private readonly LayoutExtensionService $extensionService,
    ) {
        parent::__construct();
        $this->description = __('layouts.repair_empty_objects.description');
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // 같은 프로세스에서 명령 인스턴스가 재사용되면(Artisan::call 반복) 이전 실행의 집계가 남는다
        $this->found = [];
        $this->skipped = 0;
        $this->references = [];

        $templateIds = $this->targetTemplateIds();

        if ($templateIds === []) {
            $this->info(__('layouts.repair_empty_objects.no_templates'));

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        $this->scanLayouts($templateIds, $apply);
        $this->scanExtensions($templateIds, $apply);
        $this->scanLayoutVersions($templateIds, $apply);
        $this->scanExtensionVersions($templateIds, $apply);
        $this->countDeletedRows($templateIds);

        if ($apply && $this->found !== []) {
            // 브라우저 HTTP 캐시(`?v=`)도 넘기도록 전역 캐시 버전을 올린다 — 편집기 저장과 같은 처리
            $this->incrementExtensionCacheVersion();
        }

        $this->report($apply);

        return self::SUCCESS;
    }

    /**
     * 대상 템플릿 ID 를 고릅니다.
     *
     * @return list<int> 템플릿 ID 목록
     */
    private function targetTemplateIds(): array
    {
        $query = Template::query();

        if ($this->option('template')) {
            $query->where('identifier', (string) $this->option('template'));
        }

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * 레이아웃 본 행을 검사합니다 (기준: 같은 행 원본).
     *
     * @param  list<int>  $templateIds  대상 템플릿 ID
     * @param  bool  $apply  실제로 고칠지
     * @return void 반환값 없음
     */
    private function scanLayouts(array $templateIds, bool $apply): void
    {
        $templates = Template::query()->whereIn('id', $templateIds)->pluck('identifier', 'id');

        $this->each(
            TemplateLayout::query()->whereIn('template_id', $templateIds),
            function (TemplateLayout $row) use ($apply, $templates): void {
                $reference = $this->decodeOrNull($row->getRawOriginal('original_content'));
                $this->references['layout:'.$row->id] = $reference;

                $fixed = $this->inspect($row, 'template_layouts', $templates[$row->template_id].'/'.$row->name, $reference);

                if ($fixed !== null && $apply) {
                    $this->writeMainRow($row, $fixed);
                    $this->layoutService->clearDependentLayoutsCache((int) $row->template_id, (string) $row->name);
                }
            }
        );
    }

    /**
     * 확장 본 행을 검사합니다 (기준: 같은 행 원본).
     *
     * @param  list<int>  $templateIds  대상 템플릿 ID
     * @param  bool  $apply  실제로 고칠지
     * @return void 반환값 없음
     */
    private function scanExtensions(array $templateIds, bool $apply): void
    {
        $templates = Template::query()->whereIn('id', $templateIds)->pluck('identifier', 'id');

        $this->each(
            LayoutExtension::query()->whereIn('template_id', $templateIds),
            function (LayoutExtension $row) use ($apply, $templates): void {
                $reference = $this->decodeOrNull($row->getRawOriginal('original_content'));
                $this->references['extension:'.$row->id] = $reference;

                $name = $templates[$row->template_id].'/'.$row->target_name.' ← '.$row->source_identifier;
                $fixed = $this->inspect($row, 'template_layout_extensions', $name, $reference);

                if ($fixed !== null && $apply) {
                    $this->writeMainRow($row, $fixed);
                    $type = $row->extension_type instanceof LayoutExtensionType
                        ? $row->extension_type
                        : LayoutExtensionType::from((string) $row->extension_type);
                    $this->extensionService->invalidateExtensionCache((int) $row->template_id, (string) $row->target_name, $type);
                }
            }
        );
    }

    /**
     * 레이아웃 버전 행을 검사합니다 (기준: 소속 레이아웃 원본 — 삭제된 레이아웃의 버전 행은 countDeletedRows 가 센다).
     *
     * @param  list<int>  $templateIds  대상 템플릿 ID
     * @param  bool  $apply  실제로 고칠지
     * @return void 반환값 없음
     */
    private function scanLayoutVersions(array $templateIds, bool $apply): void
    {
        $owners = TemplateLayout::query()->whereIn('template_id', $templateIds);

        $this->each(
            TemplateLayoutVersion::query()->whereIn('layout_id', $owners->select('id')),
            function (TemplateLayoutVersion $row) use ($apply): void {
                $reference = $this->references['layout:'.$row->layout_id] ?? null;
                $fixed = $this->inspect($row, 'template_layout_versions', 'layout#'.$row->layout_id.' v'.$row->version, $reference);

                if ($fixed !== null && $apply) {
                    $this->writeVersionRow($row, $fixed);
                }
            }
        );
    }

    /**
     * 확장 버전 행을 검사합니다 (기준: 소속 확장 원본 — 삭제된 확장의 버전 행은 countDeletedRows 가 센다).
     *
     * @param  list<int>  $templateIds  대상 템플릿 ID
     * @param  bool  $apply  실제로 고칠지
     * @return void 반환값 없음
     */
    private function scanExtensionVersions(array $templateIds, bool $apply): void
    {
        $owners = LayoutExtension::query()->whereIn('template_id', $templateIds);

        $this->each(
            TemplateLayoutExtensionVersion::query()->whereIn('extension_id', $owners->select('id')),
            function (TemplateLayoutExtensionVersion $row) use ($apply): void {
                $reference = $this->references['extension:'.$row->extension_id] ?? null;
                $fixed = $this->inspect($row, 'template_layout_extension_versions', 'extension#'.$row->extension_id.' v'.$row->version, $reference);

                if ($fixed !== null && $apply) {
                    $this->writeVersionRow($row, $fixed);
                }
            }
        );
    }

    /**
     * 삭제된 본 행과 삭제된 소속 행의 버전 행을 「기준 없음」 으로 셉니다 — 검사 대상이 아니라 손대지 않는다.
     *
     * 모델의 전역 스코프가 삭제된 행을 검사 질의에서 이미 빼므로, 건너뛴 사실이 보고에 남도록 따로 센다.
     *
     * @param  list<int>  $templateIds  대상 템플릿 ID
     * @return void 반환값 없음
     */
    private function countDeletedRows(array $templateIds): void
    {
        $deletedLayouts = TemplateLayout::onlyTrashed()->whereIn('template_id', $templateIds)->select('id');
        $deletedExtensions = LayoutExtension::onlyTrashed()->whereIn('template_id', $templateIds)->select('id');

        $this->skipped += TemplateLayout::onlyTrashed()->whereIn('template_id', $templateIds)->count()
            + LayoutExtension::onlyTrashed()->whereIn('template_id', $templateIds)->count()
            + TemplateLayoutVersion::query()->whereIn('layout_id', $deletedLayouts)->count()
            + TemplateLayoutExtensionVersion::query()->whereIn('extension_id', $deletedExtensions)->count();
    }

    /**
     * 질의 결과를 id 순으로 나눠 순회합니다 (본문이 커서 한 번에 싣지 않는다).
     *
     * @param  Builder<Model>  $query  질의 (삭제된 행은 모델의 전역 스코프가 뺀다)
     * @param  callable(Model): void  $callback  행 처리
     * @return void 반환값 없음
     */
    private function each(Builder $query, callable $callback): void
    {
        $query->chunkById(100, static function ($rows) use ($callback): void {
            foreach ($rows as $row) {
                $callback($row);
            }
        });
    }

    /**
     * 한 행을 판정하고, 고칠 자리가 있으면 기록한 뒤 고친 원문을 돌려줍니다.
     *
     * @param  Model  $row  대상 행
     * @param  string  $table  보고용 테이블 이름
     * @param  string  $name  보고용 이름
     * @param  mixed  $reference  기준 원문을 객체 디코드한 값 (기준 없음은 null)
     * @return string|null 고친 원문 (고칠 자리가 없거나 기준이 없으면 null)
     */
    private function inspect(Model $row, string $table, string $name, mixed $reference): ?string
    {
        if ($reference === null) {
            $this->skipped++;

            return null;
        }

        $target = $this->decodeOrNull($row->getRawOriginal('content'));

        if ($target === null) {
            return null;
        }

        [$fixed, $paths] = LayoutEmptyObjectRepair::repair($target, $reference);

        if ($paths === []) {
            return null;
        }

        $this->found[] = [
            'table' => $table,
            'id' => (int) $row->getKey(),
            'name' => $name,
            'count' => count($paths),
            'paths' => array_slice($paths, 0, self::PATH_EXAMPLES),
        ];

        // 동기화기(LayoutRowSynchronizer::encode)와 같은 플래그로 직렬화한다
        return (string) json_encode($fixed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 본 행(레이아웃·확장)에 고친 원문을 쓰고 lock_version 을 올립니다.
     *
     * 캐스트를 거치지 않도록 질의 빌더로 원문 그대로 쓴다 — 캐스트로 다시 읽으면 고친 `{}` 를 또 잃는다.
     *
     * @param  Model  $row  대상 행
     * @param  string  $content  고친 원문
     * @return void 반환값 없음
     */
    private function writeMainRow(Model $row, string $content): void
    {
        DB::transaction(static function () use ($row, $content): void {
            $row->newQuery()->whereKey($row->getKey())->update([
                'content' => $content,
                'lock_version' => DB::raw('lock_version + 1'),
            ]);
        });
    }

    /**
     * 버전 행에 고친 원문을 씁니다 (lock_version 없음, 새 버전 행 없음).
     *
     * @param  Model  $row  대상 버전 행
     * @param  string  $content  고친 원문
     * @return void 반환값 없음
     */
    private function writeVersionRow(Model $row, string $content): void
    {
        DB::transaction(static function () use ($row, $content): void {
            $row->newQuery()->whereKey($row->getKey())->update(['content' => $content]);
        });
    }

    /**
     * 원문을 객체 디코드합니다.
     *
     * @param  mixed  $raw  원문
     * @return mixed 디코드 결과 (원문이 없거나 객체가 아니면 null)
     */
    private function decodeOrNull(mixed $raw): mixed
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw);

        return $decoded instanceof \stdClass ? $decoded : null;
    }

    /**
     * 결과 표와 합계를 출력합니다.
     *
     * @param  bool  $apply  실제로 고쳤는지
     * @return void 반환값 없음
     */
    private function report(bool $apply): void
    {
        if ($this->found === []) {
            $this->info(__('layouts.repair_empty_objects.nothing'));
        } else {
            $this->table(
                [
                    __('layouts.repair_empty_objects.header_table'),
                    __('layouts.repair_empty_objects.header_id'),
                    __('layouts.repair_empty_objects.header_name'),
                    __('layouts.repair_empty_objects.header_count'),
                    __('layouts.repair_empty_objects.header_paths'),
                ],
                array_map(static fn (array $row): array => [
                    $row['table'], $row['id'], $row['name'], $row['count'], implode(', ', $row['paths']),
                ], $this->found)
            );
        }

        $this->line(__('layouts.repair_empty_objects.summary', [
            'rows' => count($this->found),
            'positions' => array_sum(array_column($this->found, 'count')),
        ]));

        if ($this->skipped > 0) {
            $this->line(__('layouts.repair_empty_objects.skipped', ['count' => $this->skipped]));
        }

        if ($apply) {
            if ($this->found !== []) {
                $this->info(__('layouts.repair_empty_objects.applied', ['rows' => count($this->found)]));
            }

            return;
        }

        if ($this->found !== []) {
            $this->comment(__('layouts.repair_empty_objects.preview_hint'));
        }
    }
}
