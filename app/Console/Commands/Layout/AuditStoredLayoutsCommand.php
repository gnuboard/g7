<?php

namespace App\Console\Commands\Layout;

use App\Models\LayoutExtension;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutPreview;
use App\Models\TemplateLayoutVersion;
use App\Rules\LayoutContentGate;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * 이미 저장된 레이아웃을 강화된 저장측 게이트로 재검사하는 커맨드 (KVE-2026-2350)
 *
 * 게이트(SafeLayoutExpressions·NoExternalUrls)를 강화해도 강화 **이전에** 저장된 content 는 그대로
 * 남아 렌더된다. 신고 payload 처럼 top-level `slots` 에 호스트 capability 탈출 표현식이 저장돼 있으면
 * 런타임 평가기가 막더라도 운영자가 그 존재를 알아야 한다. DB 의 레이아웃·확장 조각·버전 이력·
 * 미리보기와 디스크의 레이아웃 JSON(작업본·배포본)을 같은 게이트로 검사해 **보고만** 한다.
 *
 * 자동 정화(`--fix`)는 두지 않는다 — 문자열 단위로 값을 걷어내면 정상 표현식을 오손할 수 있어,
 * 조치는 운영자가 보고를 보고 편집기에서 하거나 버전을 되돌린다(별도 운영 절차).
 *
 * 종료 코드: 위반 없음 0 / 위반 발견 1 — 배포 파이프라인 점검에 그대로 쓸 수 있다.
 */
class AuditStoredLayoutsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'layout:audit-expressions
                            {--source=all : 검사 대상 (db|files|all)}
                            {--json : 결과를 JSON 으로 출력}
                            {--limit=50 : 콘솔에 출력할 위반 상세 개수}';

    /**
     * @var string
     */
    protected $description = '저장된 레이아웃(DB·파일)을 저장측 보안 게이트로 재검사해 위반을 보고합니다 (보고 전용)';

    /**
     * 검사할 DB 모델 → 보고용 출처 이름.
     *
     * @var array<class-string<Model>, string>
     */
    private const DB_SOURCES = [
        TemplateLayout::class => 'template_layouts',
        LayoutExtension::class => 'template_layout_extensions',
        TemplateLayoutVersion::class => 'template_layout_versions',
        TemplateLayoutExtensionVersion::class => 'template_layout_extension_versions',
        TemplateLayoutPreview::class => 'template_layout_previews',
    ];

    /**
     * 디스크에서 레이아웃 JSON 을 찾는 루트 (작업본·배포본 모두 이 아래에 있다).
     *
     * @var array<int, string>
     */
    private const FILE_ROOTS = ['templates', 'modules', 'plugins'];

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드 (위반 없음 0 / 위반 발견 1 / 잘못된 옵션 2)
     */
    public function handle(): int
    {
        $source = (string) $this->option('source');

        if (! in_array($source, ['db', 'files', 'all'], true)) {
            $this->error('--source 는 db|files|all 중 하나여야 합니다.');

            return 2;
        }

        $scanned = 0;
        $findings = [];

        if ($source !== 'files') {
            [$count, $rows] = $this->auditDatabase();
            $scanned += $count;
            $findings = array_merge($findings, $rows);
        }

        if ($source !== 'db') {
            [$count, $rows] = $this->auditFiles();
            $scanned += $count;
            $findings = array_merge($findings, $rows);
        }

        $this->report($scanned, $findings);

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * DB 의 레이아웃 content 를 검사합니다.
     *
     * @return array{0: int, 1: array<int, array{source: string, target: string, violation: string}>} [검사 수, 위반 목록]
     */
    private function auditDatabase(): array
    {
        $scanned = 0;
        $findings = [];

        foreach (self::DB_SOURCES as $modelClass => $label) {
            $modelClass::query()->select(['id', 'content'])->orderBy('id')->chunkById(200, function ($rows) use ($label, &$scanned, &$findings) {
                foreach ($rows as $row) {
                    $scanned++;
                    $content = $row->content;

                    if (! is_array($content)) {
                        continue;
                    }

                    foreach (LayoutContentGate::violations($content) as $violation) {
                        $findings[] = ['source' => $label, 'target' => '#'.$row->id, 'violation' => $violation];
                    }
                }
            });
        }

        return [$scanned, $findings];
    }

    /**
     * 디스크의 레이아웃·확장 JSON 을 검사합니다.
     *
     * @return array{0: int, 1: array<int, array{source: string, target: string, violation: string}>} [검사 수, 위반 목록]
     */
    private function auditFiles(): array
    {
        $scanned = 0;
        $findings = [];

        foreach ($this->layoutFiles() as $path) {
            $content = json_decode((string) file_get_contents($path), true);

            if (! is_array($content)) {
                continue;
            }

            $scanned++;
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen(base_path()))), '/');

            foreach (LayoutContentGate::violations($content) as $violation) {
                $findings[] = ['source' => 'file', 'target' => $relative, 'violation' => $violation];
            }
        }

        return [$scanned, $findings];
    }

    /**
     * 레이아웃·확장 JSON 파일 경로를 수집합니다.
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function layoutFiles(): array
    {
        $out = [];

        foreach (self::FILE_ROOTS as $root) {
            $dir = base_path($root);

            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    fn (\SplFileInfo $file) => ! ($file->isDir() && in_array($file->getFilename(), ['node_modules', 'vendor', 'dist', 'lang', '.git'], true))
                )
            );

            foreach ($iterator as $file) {
                $path = $file->getPathname();

                if (str_ends_with($path, '.json') && preg_match('#[\\\\/](layouts|extensions)[\\\\/]#', $path) === 1) {
                    $out[] = $path;
                }
            }
        }

        sort($out);

        return $out;
    }

    /**
     * 검사 결과를 출력합니다.
     *
     * @param  int  $scanned  검사한 레이아웃 수
     * @param  array<int, array{source: string, target: string, violation: string}>  $findings  위반 목록
     * @return void
     */
    private function report(int $scanned, array $findings): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['scanned' => $scanned, 'violations' => count($findings), 'findings' => $findings],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return;
        }

        $this->info("검사한 레이아웃: {$scanned}건 · 위반: ".count($findings).'건');

        if ($findings === []) {
            return;
        }

        $limit = max(1, (int) $this->option('limit'));
        $this->table(
            ['출처', '대상', '위반'],
            array_map(
                fn (array $row) => [$row['source'], $row['target'], mb_substr($row['violation'], 0, 120)],
                array_slice($findings, 0, $limit)
            )
        );

        if (count($findings) > $limit) {
            $this->line('... 외 '.(count($findings) - $limit).'건 (--limit 또는 --json 으로 전체 확인)');
        }

        $this->warn('조치: 편집기에서 해당 값을 고치거나 이전 버전으로 되돌리세요. 이 커맨드는 값을 바꾸지 않습니다.');
    }
}
