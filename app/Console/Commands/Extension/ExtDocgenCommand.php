<?php

namespace App\Console\Commands\Extension;

use App\Support\ExtensionDoc\ExtensionDocContext;
use App\Support\ExtensionDoc\ExtensionDocScaffolder;
use App\Support\ExtensionDoc\ExtensionInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * 확장 개발자 문서 생성 커맨드
 *
 * 번들 확장의 `AGENTS.md` · `README.md` · `docs/**` 를 스캐폴딩하고, 코드에서 실측 가능한
 * 부분(훅·라우트·권한·모델·레이아웃·테스트 경로)을 자동 생성 블록 안에 갱신합니다.
 *
 * 기본 동작이 **블록 안쪽 교체**이므로 사람이 쓴 서술이 소실될 경로가 없습니다.
 * 그래서 `--force` 같은 파괴적 플래그를 두지 않고, 신규 파일 생성만 `--init` 으로 분리합니다.
 */
class ExtDocgenCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'ext:docgen
        {--scope=all : 범위 (all, module:vendor-id, plugin:vendor-id, template:vendor-id)}
        {--init : 문서가 없는 확장에 골격 파일 생성 (기존 파일은 건너뜀)}
        {--check : 생성하지 않고 누락·드리프트만 리포트}
        {--json : 기계 판독 출력}
        {--dry-run : 대상과 실측 집계만 출력}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = '번들 확장의 개발자 문서(AGENTS.md/README.md/docs)를 실측 기반으로 생성·갱신합니다';

    /**
     * @var array<int, array{type: string, id: string, manifest: string, reason: string}> manifest 를 읽지 못해 대상에서 빠진 디렉토리
     */
    private array $malformed = [];

    /**
     * 커맨드를 실행합니다.
     *
     * @param  ExtensionInventory  $inventory  번들 확장 인벤토리
     * @param  ExtensionDocContext  $context  수집 컨텍스트 조립기
     * @param  ExtensionDocScaffolder  $scaffolder  문서 스캐폴더
     * @return int 종료 코드
     */
    public function handle(
        ExtensionInventory $inventory,
        ExtensionDocContext $context,
        ExtensionDocScaffolder $scaffolder
    ): int {
        $scope = (string) $this->option('scope');

        // `--check --dry-run` 은 dry-run 분기가 먼저 반환해 이슈 배열이 전부 빈 채로 남는다 —
        // 결과가 "이상 0건" 과 같은 모양이라 검사한 적 없는 실행이 통과로 보인다. 두 모드는
        // 함께 쓸 수 없다고 명시적으로 거부한다.
        // `--init` 도 같은 성질이다 — dry-run·check 와 함께 주면 조용히 무시되어
        // "골격을 만들라고 시켰는데 아무 일도 없었다" 가 성공으로 보인다.
        $conflict = match (true) {
            $this->option('check') && $this->option('dry-run') => '--check 와 --dry-run 은 함께 쓸 수 없습니다 (dry-run 은 검사를 수행하지 않습니다).',
            $this->option('init') && $this->option('dry-run') => '--init 과 --dry-run 은 함께 쓸 수 없습니다 (dry-run 은 파일을 만들지 않습니다).',
            $this->option('init') && $this->option('check') => '--init 과 --check 는 함께 쓸 수 없습니다 (check 는 파일을 만들지 않습니다).',
            default => null,
        };

        if ($conflict !== null) {
            $message = $conflict;

            if ($this->option('json')) {
                $this->line((string) json_encode(
                    ['scope' => $scope, 'extensions' => [], 'error' => $message],
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ));

                return self::FAILURE;
            }

            $this->error($message);

            return self::FAILURE;
        }

        try {
            $records = $inventory->collect($scope);
            $this->malformed = $inventory->malformed();
        } catch (InvalidArgumentException $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(
                    ['scope' => $scope, 'extensions' => [], 'error' => $e->getMessage()],
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ));

                return self::FAILURE;
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($records === []) {
            $message = "범위 '{$scope}' 에 해당하는 번들 확장이 없습니다.";

            if ($this->option('json')) {
                $this->line((string) json_encode(['scope' => $scope, 'extensions' => [], 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                return self::FAILURE;
            }

            $this->warn($message);

            return self::FAILURE;
        }

        $results = [];

        foreach ($records as $record) {
            $ctx = $context->build($record);

            $results[] = $this->processExtension($ctx, $scaffolder);
        }

        return $this->report($results, $scope);
    }

    /**
     * 단일 확장을 처리합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @param  ExtensionDocScaffolder  $scaffolder  문서 스캐폴더
     * @return array<string, mixed> 처리 결과
     */
    private function processExtension(array $ctx, ExtensionDocScaffolder $scaffolder): array
    {
        $record = $ctx['record'];
        $stats = ExtensionDocScaffolder::statsOf($ctx);

        $result = [
            'type' => $record['type'],
            'id' => $record['id'],
            'relPath' => $record['relPath'],
            'version' => $record['version'],
            'stats' => $stats,
            'surfaceAvailable' => $ctx['surface']['available'],
            'surfaceReason' => $ctx['surface']['reason'],
            'surfaceErrors' => $ctx['surface']['errors'],
            'documents' => [],
            'created' => [],
            'updated' => [],
            'missingDocuments' => [],
            'missingSections' => [],
            'missingBlocks' => [],
            'driftedBlocks' => [],
            'orphanBlocks' => [],
            'unfilled' => [],
        ];

        if ($this->option('dry-run')) {
            $result['documents'] = ExtensionDocScaffolder::documentsForType($record['type']);

            return $result;
        }

        // --init 은 파일을 만든 **뒤에** 블록을 다시 렌더한다.
        // `docs-index` · `doc-toc` 블록은 문서 파일의 존재 여부를 읽어 링크와 상태를 채우므로,
        // 생성 전에 렌더한 본문은 방금 만든 문서를 전부 "미작성" 으로 표기한다.
        if ($this->option('init') && ! $this->option('check')) {
            $this->initSkeletons($ctx, $scaffolder, $result);
        }

        $bodies = $scaffolder->renderBlocks($ctx);

        foreach (ExtensionDocScaffolder::documentsForType($record['type']) as $doc) {
            $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $doc);
            $meta = ExtensionDocScaffolder::DOCUMENTS[$doc];

            if (! is_file($abs)) {
                $result['missingDocuments'][] = $doc;

                continue;
            }

            $content = (string) File::get($abs);

            // 섹션 골격 검사 — 헤딩 누락은 문서가 형식을 벗어났다는 신호다.
            // 유형별로 절 이름이 달라지는 자리가 있으므로 sectionsFor() 판정을 쓴다.
            foreach (ExtensionDocScaffolder::sectionsFor($doc, $record['type']) as $section) {
                if (! ExtensionDocScaffolder::hasSection($content, $section)) {
                    $result['missingSections'][] = $doc.' → '.$section;
                }
            }

            // 미채움 마커 잔량
            foreach (ExtensionDocScaffolder::todoMarkers() as $marker) {
                $count = substr_count($content, $marker);
                if ($count > 0) {
                    $result['unfilled'][] = ['doc' => $doc, 'marker' => $marker, 'count' => $count];
                }
            }

            // 마커를 지우기만 하고 서술을 안 쓴 자리도 미채움이다. 이 축이 없으면
            // "TODO 를 삭제한 문서" 가 "채운 문서" 와 같은 모양으로 통과한다.
            $emptyIntents = ExtensionDocScaffolder::emptyIntentBlocks($content);

            if ($emptyIntents > 0) {
                $result['unfilled'][] = [
                    'doc' => $doc,
                    'marker' => ExtensionDocScaffolder::EMPTY_INTENT_LABEL,
                    'count' => $emptyIntents,
                ];
            }

            $docBodies = [];
            foreach (ExtensionDocScaffolder::blocksFor($doc) as $key) {
                if (array_key_exists($key, $bodies)) {
                    $docBodies[$key] = $bodies[$key];
                }
            }

            $merged = ExtensionDocScaffolder::replaceBlocks($content, $docBodies);

            foreach ($merged['missing'] as $key) {
                $result['missingBlocks'][] = $doc.' → '.$key;
            }

            // 문서에 실재하지만 `DOCUMENTS` 가 모르는 블록 — 생성기가 순회 대상으로 삼지
            // 않으므로 **영영 갱신되지 않고 누락으로도 보고되지 않는다**. 낡은 실측을 단 채
            // `--check` 는 이상 0건을 보고한다. 절을 옮기거나 목록을 재편하면 즉시 생긴다.
            foreach (ExtensionDocScaffolder::presentBlockKeys($content) as $key) {
                if (! in_array($key, ExtensionDocScaffolder::blocksFor($doc), true)) {
                    $result['orphanBlocks'][] = $doc.' → '.$key;
                }
            }

            if ($this->option('check')) {
                if (! $merged['unchanged']) {
                    foreach ($merged['replaced'] as $key) {
                        if ($this->blockDiffers($content, $key, $docBodies[$key])) {
                            $result['driftedBlocks'][] = $doc.' → '.$key;
                        }
                    }
                }

                continue;
            }

            if (! $merged['unchanged']) {
                File::put($abs, $merged['content']);

                // 방금 만든 파일은 '생성' 으로만 보고한다 (같은 실행의 2차 렌더는 생성의 일부).
                if (! in_array($doc, $result['created'], true)) {
                    $result['updated'][] = $doc;
                }
            }
        }

        return $result;
    }

    /**
     * 문서가 없는 자리에 골격 파일을 만듭니다 (기존 파일은 건드리지 않습니다).
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @param  ExtensionDocScaffolder  $scaffolder  문서 스캐폴더
     * @param  array<string, mixed>  $result  처리 결과 (created 누적)
     */
    private function initSkeletons(array $ctx, ExtensionDocScaffolder $scaffolder, array &$result): void
    {
        $record = $ctx['record'];

        foreach (ExtensionDocScaffolder::documentsForType($record['type']) as $doc) {
            $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $doc);

            if (is_file($abs)) {
                continue;
            }

            File::ensureDirectoryExists(dirname($abs));
            File::put($abs, $scaffolder->skeleton($doc, $ctx));
            $result['created'][] = $doc;
        }
    }

    /**
     * 문서에 이미 들어 있는 블록 본문이 새로 렌더한 본문과 다른지 판정합니다.
     *
     * @param  string  $content  문서 내용
     * @param  string  $key  블록 키
     * @param  string  $body  새 본문
     * @return bool 다르면 true
     */
    private function blockDiffers(string $content, string $key, string $body): bool
    {
        $startPattern = '/<!--\s*@generated:'.preg_quote($key, '/').'\s+START\b.*?-->/s';
        $endPattern = '/<!--\s*@generated:'.preg_quote($key, '/').'\s+END\s*-->/s';

        if (! preg_match($startPattern, $content, $sm, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $from = (int) $sm[0][1] + strlen($sm[0][0]);

        if (! preg_match($endPattern, $content, $em, PREG_OFFSET_CAPTURE, $from)) {
            return false;
        }

        $existing = trim(substr($content, $from, ((int) $em[0][1]) - $from));

        return $existing !== trim($body);
    }

    /**
     * 처리 결과를 출력하고 종료 코드를 결정합니다.
     *
     * @param  array<int, array<string, mixed>>  $results  확장별 결과
     * @param  string  $scope  범위
     * @return int 종료 코드
     */
    private function report(array $results, string $scope): int
    {
        $issues = 0;
        foreach ($results as $result) {
            $issues += count($result['missingDocuments'])
                + count($result['missingSections'])
                + count($result['missingBlocks'])
                + count($result['driftedBlocks'])
                + count($result['orphanBlocks']);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'scope' => $scope,
                'mode' => $this->mode(),
                'extensions' => $results,
                'malformed' => $this->malformed,
                'issues' => $issues,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return ($this->option('check') && $issues > 0) ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(count($results).'개 확장 (scope='.$scope.')');
            $this->newLine();

            foreach ($results as $result) {
                $parts = [];
                foreach ($result['stats'] as $label => $value) {
                    // 세지 못한 지표는 `null` 로 온다. 그대로 보간하면 빈 칸이 되어
                    // "0" 과도 "확인 못함" 과도 구분되지 않는다.
                    $parts[] = $value === null
                        ? "{$label} ".ExtensionDocScaffolder::STAT_UNMEASURED
                        : "{$label} {$value}";
                }

                $this->line(sprintf('  [%s] %s v%s', $result['type'], $result['id'], $result['version']));
                $this->line('      '.implode(' · ', $parts));
                $this->line('      문서 '.count($result['documents']).'종: '.implode(', ', $result['documents']));

                if (! $result['surfaceAvailable'] && $result['surfaceReason'] !== null) {
                    $this->line('      선언형 표면: '.$result['surfaceReason']);
                }
                if ($result['surfaceErrors'] !== []) {
                    foreach ($result['surfaceErrors'] as $getter => $message) {
                        $this->line("      ⚠ {$getter}(): {$message}");
                    }
                }
            }

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $label = sprintf('[%s] %s', $result['type'], $result['id']);

            if ($result['created'] !== []) {
                $this->info("{$label} 생성: ".implode(', ', $result['created']));
            }
            if ($result['updated'] !== []) {
                $this->info("{$label} 갱신: ".implode(', ', $result['updated']));
            }
            if ($result['missingDocuments'] !== []) {
                $this->warn("{$label} 문서 없음: ".implode(', ', $result['missingDocuments']));
            }
            if ($result['missingBlocks'] !== []) {
                $this->warn("{$label} 자동 생성 블록 없음: ".implode(', ', $result['missingBlocks']));
            }
            if ($result['missingSections'] !== []) {
                $this->warn("{$label} 필수 섹션 없음: ".implode(', ', $result['missingSections']));
            }
            if ($result['driftedBlocks'] !== []) {
                $this->warn("{$label} 블록 드리프트(코드 실측과 불일치): ".implode(', ', $result['driftedBlocks']));
            }
            if ($result['orphanBlocks'] !== []) {
                $this->warn("{$label} 갱신 대상이 아닌 자동 생성 블록(고아): ".implode(', ', $result['orphanBlocks']));
            }
            // 미채움 잔량은 계산만 하고 `--json` 에만 실려 있었다 — 계획이 이 마커를 둔
            // 이유가 "잔량 집계" 이므로 사람이 읽는 출력에도 낸다.
            if ($result['unfilled'] !== []) {
                $total = array_sum(array_column($result['unfilled'], 'count'));
                $this->line("{$label} 미채움 마커 {$total}건: ".implode(', ', array_map(
                    static fn (array $u): string => $u['doc'].' → '.$u['marker'].'×'.$u['count'],
                    $result['unfilled'],
                )));
            }
            foreach ($result['surfaceErrors'] as $getter => $message) {
                $this->warn("{$label} 선언형 표면 수집 실패 {$getter}(): {$message}");
            }
        }

        foreach ($this->malformed as $bad) {
            $this->warn(sprintf(
                '[%s] %s — %s 를 읽지 못해 검사 대상에서 빠졌습니다 (%s). "확장이 없음" 이 아니라 "읽지 못함" 입니다.',
                $bad['type'], $bad['id'], $bad['manifest'], $bad['reason'],
            ));
        }

        $this->newLine();
        $this->info(sprintf('%d개 확장 처리 (scope=%s, mode=%s) — 이슈 %d건', count($results), $scope, $this->mode(), $issues));

        if ($this->option('check') && $issues > 0) {
            $this->line('`php artisan ext:docgen --init` 으로 골격을 만들고, `php artisan ext:docgen` 으로 블록을 갱신하세요.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 현재 실행 모드를 반환합니다.
     *
     * @return string 모드 문자열
     */
    private function mode(): string
    {
        if ($this->option('dry-run')) {
            return 'dry-run';
        }
        if ($this->option('check')) {
            return 'check';
        }
        if ($this->option('init')) {
            return 'init';
        }

        return 'update';
    }
}
