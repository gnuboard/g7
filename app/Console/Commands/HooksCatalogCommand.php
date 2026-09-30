<?php

namespace App\Console\Commands;

use App\Extension\HookContract\HookCatalogScanner;
use App\Extension\HookContract\HookContract;
use Illuminate\Console\Command;

/**
 * 공개 훅 계약 목록 생성 Artisan 커맨드.
 *
 * 코어(app/)와 번들 모듈·플러그인(src/)의 훅 발화처를 스캔해 이름·유형·인자 개수 목록을
 * app/Extension/HookContract/data/catalog.php 에 쓴다. 훅을 새로 발화하거나 인자를 바꾼 개발자가 실행해
 * 목록 변화를 코드 리뷰에 드러낸다. `--check` 는 쓰지 않고 목록이 최신인지만 확인한다.
 *
 * 공개 훅의 이름·인자 개수는 서드파티가 코드에 박아 쓰는 계약이다 — 지우거나 바꾸려면 새 훅을 더하고
 * 옛 훅을 data/deprecated.php 에 적는다(docs/extension/hooks.md 「공개 훅 호환 원칙」).
 */
class HooksCatalogCommand extends Command
{
    /**
     * 사람이 읽는 발화 목록 문서 경로 (base_path 기준).
     */
    public const DOC_PATH = 'docs/extension/hook-catalog.md';

    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'hooks:catalog
        {--check : 목록을 쓰지 않고 최신 여부만 확인}
        {--allow-break : deprecated 목록에 없는 훅 삭제·인자 개수 변경도 허용 (메이저 버전에서만)}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = '공개 훅 계약 목록 생성 (app/Extension/HookContract/data/catalog.php)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  HookCatalogScanner  $scanner  발화처 스캐너
     * @return int 종료 코드
     */
    public function handle(HookCatalogScanner $scanner): int
    {
        $result = $scanner->scan(base_path());
        $rendered = self::render($result['hooks']);
        $path = HookContract::DATA_DIR.DIRECTORY_SEPARATOR.'catalog.php';
        $docPath = base_path(self::DOC_PATH);
        $renderedDoc = self::renderDoc($result['hooks'], HookContract::deprecated(), require HookContract::DATA_DIR.DIRECTORY_SEPARATOR.'dynamic.php');

        if ($this->option('check')) {
            $current = is_file($path) ? (string) file_get_contents($path) : '';
            $currentDoc = is_file($docPath) ? (string) file_get_contents($docPath) : '';
            if (str_replace("\r\n", "\n", $current) !== $rendered || str_replace("\r\n", "\n", $currentDoc) !== $renderedDoc) {
                $this->error('훅 계약 목록(또는 목록 문서)이 최신이 아닙니다 — php artisan hooks:catalog 를 실행하세요.');

                return self::FAILURE;
            }
            $this->info('훅 계약 목록이 최신입니다 ('.count($result['hooks']).'개).');

            return self::SUCCESS;
        }

        $previous = is_file($path) ? ((require $path)['hooks'] ?? []) : [];
        $breaks = self::breakingChanges($previous, $result['hooks'], HookContract::deprecated());
        if ($breaks !== [] && ! $this->option('allow-break')) {
            $this->error('공개 훅 계약이 깨집니다 — 서드파티 확장이 이 이름·인자 개수로 구독하고 있을 수 있습니다.');
            foreach ($breaks as $line) {
                $this->line('  - '.$line);
            }
            $this->line('새 이름 훅을 더하고 옛 훅은 그대로 발화하며 data/deprecated.php 에 적으세요. 메이저 버전의 의도된 제거면 --allow-break.');

            return self::FAILURE;
        }

        file_put_contents($path, $rendered);
        file_put_contents($docPath, $renderedDoc);
        HookContract::flush();
        $this->info('훅 계약 목록을 썼습니다 — 발화 '.count($result['hooks']).'개, 동적 발화처 '.count($result['dynamic']).'곳.');

        return self::SUCCESS;
    }

    /**
     * 옛 목록 대비 계약을 깨는 변화를 찾습니다 — 사라진 훅, 관측되던 인자 개수가 없어진 훅.
     *
     * deprecated 목록에 적힌 훅은 의도된 사용 중단이라 제외한다. 인자 개수가 늘기만 한 경우(뒤에 선택
     * 인자를 더함)는 기존 구독자를 깨지 않으므로 허용한다.
     *
     * @param  array<string, array<string, mixed>>  $previous  옛 목록
     * @param  array<string, array<string, mixed>>  $current  새 목록
     * @param  array<string, array<string, mixed>>  $deprecated  사용 중단 목록
     * @return array<int, string> 깨지는 변화 설명
     */
    public static function breakingChanges(array $previous, array $current, array $deprecated): array
    {
        $breaks = [];
        foreach ($previous as $name => $entry) {
            if (isset($deprecated[$name])) {
                continue;
            }
            if (! isset($current[$name])) {
                $breaks[] = "{$name}: 발화처가 사라졌다";

                continue;
            }
            $lost = array_diff(array_map('strval', $entry['args'] ?? []), array_map('strval', $current[$name]['args'] ?? []));
            if ($lost !== []) {
                $breaks[] = "{$name}: 인자 개수 ".implode(',', $lost).' 발화처가 사라졌다 (현재 '.implode(',', $current[$name]['args']).')';
            }
        }

        return $breaks;
    }

    /**
     * 발화 목록을 사람이 읽는 마크다운 문서로 만듭니다 (소유자별 표 + 사용 중단 + 동적 발화처).
     *
     * 훅마다 서술을 손으로 붙이지 않아도 공개 훅 전부가 문서에 나오게 한다 — 서술 설명은 훅을 소유한
     * 도메인 문서(activity-log-hooks.md·seo-system.md·각 확장의 확장 지점 문서 등)가 맡는다.
     *
     * @param  array<string, array<string, mixed>>  $hooks  발화 목록
     * @param  array<string, array<string, mixed>>  $deprecated  사용 중단 목록
     * @param  array<string, array<string, mixed>>  $dynamic  동적 발화처 목록
     * @return string 문서 내용
     */
    public static function renderDoc(array $hooks, array $deprecated, array $dynamic): string
    {
        $byOwner = [];
        foreach ($hooks as $name => $entry) {
            $byOwner[$entry['owner']][$name] = $entry;
        }
        uksort($byOwner, fn ($a, $b) => $a === 'core' ? -1 : ($b === 'core' ? 1 : strcmp($a, $b)));

        $out = [
            '# 공개 훅 목록 (Hook Catalog)',
            '',
            '> 자동 생성 문서 — 손으로 고치지 않는다. 생성: `php artisan hooks:catalog` · 확인: `php artisan hooks:catalog --check`',
            '> 원칙: [hooks.md 「공개 훅 호환 원칙」](hooks.md#공개-훅-호환-원칙) — 공개 훅의 이름·인자 위치는 마이너 버전에서 바꾸지 않는다.',
            '',
            '코어와 번들 확장이 실제로 발화하는 훅 전부다(소스 스캔). **인자 개수**는 훅 이름 뒤 인자 수이고(`*` = 전개 인자),',
            '발화처가 여럿이면 관측된 개수를 모두 적는다. 훅별 서술은 그 훅을 소유한 도메인 문서를 본다.',
            '',
            '## 목차',
            '',
        ];
        foreach (array_keys($byOwner) as $owner) {
            $out[] = '- ['.($owner === 'core' ? '코어' : $owner).'](#'.self::anchor($owner === 'core' ? '코어' : $owner).') ('.count($byOwner[$owner]).')';
        }
        $out[] = '- [사용 중단](#사용-중단)';
        $out[] = '- [이름을 조립하는 발화처](#이름을-조립하는-발화처)';
        $out[] = '';

        foreach ($byOwner as $owner => $entries) {
            $out[] = '## '.($owner === 'core' ? '코어' : $owner);
            $out[] = '';
            $out[] = '| 훅 | 유형 | 인자 개수 | 비고 |';
            $out[] = '|----|------|-----------|------|';
            foreach ($entries as $name => $entry) {
                $note = '';
                if (isset($entry['replacement'])) {
                    $note = '호환용 옛 이름 → `'.$entry['replacement'].'`';
                } elseif (isset($deprecated[$name])) {
                    $replacement = $deprecated[$name]['replacement'] ?? null;
                    $note = 'deprecated'.($replacement ? ' → `'.$replacement.'`' : '');
                }
                $out[] = '| `'.$name.'` | '.$entry['type'].' | '.implode(', ', array_map('strval', $entry['args'])).' | '.$note.' |';
            }
            $out[] = '';
        }

        $out[] = '## 사용 중단';
        $out[] = '';
        $out[] = '`app/Extension/HookContract/data/deprecated.php` 가 원본이다. 구독하면 등록 시점에 경고가 남는다.';
        $out[] = '';
        $out[] = '| 훅 | 대체 | 공개 배포 발화 이력 | 사유 |';
        $out[] = '|----|------|--------------------|------|';
        foreach ($deprecated as $name => $info) {
            $out[] = '| `'.$name.'` | '.(($info['replacement'] ?? null) ? '`'.$info['replacement'].'`' : '없음').' | '.(($info['published'] ?? false) ? '있음' : '없음').' | '.($info['note'] ?? '').' |';
        }
        $out[] = '';

        $out[] = '## 이름을 조립하는 발화처';
        $out[] = '';
        $out[] = '스캐너가 이름을 풀지 못하는 발화처와 그 이름 패턴이다(`*` 는 점을 포함한 아무 문자열). 원본은 `app/Extension/HookContract/data/dynamic.php`.';
        $out[] = '';
        $out[] = '| 발화처 | 이름 패턴 | 설명 |';
        $out[] = '|--------|----------|------|';
        foreach ($dynamic as $site => $info) {
            [$file] = explode('|', $site, 2);
            $patterns = array_map(fn ($p) => '`'.$p.'`', $info['patterns'] ?? []);
            $out[] = '| `'.$file.'` | '.($patterns === [] ? '(호출부에서 수집)' : implode('<br>', $patterns)).' | '.($info['note'] ?? '').' |';
        }
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * 마크다운 제목 앵커를 만듭니다.
     *
     * @param  string  $heading  제목
     * @return string 앵커
     */
    private static function anchor(string $heading): string
    {
        return strtolower(preg_replace('/[^\p{L}\p{N}_-]+/u', '', str_replace(' ', '-', $heading)));
    }

    /**
     * 발화 목록을 PHP 파일 내용으로 만듭니다 (LF, 안정 정렬).
     *
     * @param  array<string, array<string, mixed>>  $hooks  발화 목록
     * @return string 파일 내용
     */
    public static function render(array $hooks): string
    {
        $lines = [];
        foreach ($hooks as $name => $entry) {
            $args = implode(', ', array_map(fn ($a) => is_int($a) ? (string) $a : "'".$a."'", $entry['args']));
            $replacement = isset($entry['replacement']) ? ", 'replacement' => '".$entry['replacement']."'" : '';
            $lines[] = "        '".$name."' => ['type' => '".$entry['type']."', 'args' => [".$args."], 'owner' => '".$entry['owner']."'".$replacement.'],';
        }

        return "<?php\n\n"
            ."/**\n"
            ." * 훅 계약 — 코어·번들 확장이 발화하는 공개 훅 목록 (자동 생성, 손으로 고치지 않는다).\n"
            ." *\n"
            ." * 생성: php artisan hooks:catalog · 확인: php artisan hooks:catalog --check\n"
            ." * args 는 훅 이름 뒤 인자 개수('*' = 전개 인자). 발화처가 여럿이면 관측된 개수를 모두 적는다.\n"
            ." * replacement 는 applyFiltersWithLegacyName 의 옛 이름에 붙는 표준 이름이다.\n"
            ." */\n\n"
            ."return [\n"
            ."    'hooks' => [\n"
            .implode("\n", $lines)."\n"
            ."    ],\n"
            ."];\n";
    }
}
