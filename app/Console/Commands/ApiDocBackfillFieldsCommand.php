<?php

namespace App\Console\Commands;

use App\Support\ApiDoc\ResourceFieldDescriber;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * 기존 API 문서의 응답 필드 설명 TODO 를 리소스 계약 사전 설명으로 소급 채웁니다.
 *
 * 파라미터 TODO 는 `api:docgen-backfill-params` 가 채우지만, 응답 필드 TODO 는 전용
 * 백필 커맨드가 없어 재생성(`api:docgen`)으로만 갱신되었습니다. 실측 서버 없이
 * 재생성하면 이미 실측된 응답 예시값이 퇴행하므로, 이 커맨드는 재생성 대신 응답 필드
 * 표의 `<!-- TODO: 설명 -->` 셀만 in-place 치환합니다.
 *
 * 채움 규칙 SSoT 는 ApiDocScaffolder::responseFieldTable 과 동일한 ResourceFieldDescriber
 * 입니다 — 이후 정상 재생성(실측 서버 가동 시)도 같은 설명을 산출하므로 멱등합니다.
 *
 * 도메인 특이 필드(ResourceFieldDescriber 가 null 반환)는 TODO 를 그대로 둡니다.
 * 응답 필드 표만 대상으로 하며, 파라미터 표(`<!-- TODO: 용도 -->`)는 건드리지 않습니다.
 */
class ApiDocBackfillFieldsCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'api:docgen-backfill-fields
        {--dry-run : 치환하지 않고 채울 건수만 리포트}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = '기존 API 문서의 응답 필드 설명 TODO 를 리소스 계약 사전 설명으로 소급 채웁니다';

    /**
     * @var string 응답 필드 표를 식별하는 고유 헤더
     */
    private const FIELD_TABLE_HEADER = '| 필드 | 타입 | 실측 예시값 | 용도/설명 |';

    /**
     * @var string 응답 필드 설명 TODO 마커
     */
    private const TODO_MARKER = '<!-- TODO: 설명 -->';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $describer = new ResourceFieldDescriber;
        $dryRun = (bool) $this->option('dry-run');

        $filled = 0;
        $skipped = 0;
        $filesChanged = 0;

        foreach ($this->apiDocFiles() as $file) {
            $content = file_get_contents($file);
            $result = $this->backfillFile($content, $describer, $filled, $skipped);

            if ($result !== $content) {
                $filesChanged++;
                if (! $dryRun) {
                    file_put_contents($file, $result);
                }
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}응답 필드 설명 채움: {$filled}건, TODO 유지(도메인 특이): {$skipped}건, 변경 파일: {$filesChanged}개");

        return self::SUCCESS;
    }

    /**
     * 대상 API 문서 파일 경로를 수집합니다.
     *
     * @return array<int, string> 파일 경로 목록
     */
    private function apiDocFiles(): array
    {
        $dirs = array_filter([
            base_path('docs/backend/api'),
            ...glob(base_path('modules/_bundled/*/docs/api')),
            ...glob(base_path('plugins/_bundled/*/docs/api')),
        ], 'is_dir');

        if ($dirs === []) {
            return [];
        }

        $files = [];
        foreach (Finder::create()->files()->in($dirs)->name('*.md') as $f) {
            $files[] = $f->getRealPath();
        }

        return $files;
    }

    /**
     * 단일 문서의 응답 필드 표 TODO 를 채웁니다.
     *
     * 응답 필드 표 헤더 이후 표 행만 대상으로 하며, `<!-- TODO: 설명 -->` 셀을
     * ResourceFieldDescriber 결과로 치환합니다. 설명이 null(도메인 특이)이면 유지합니다.
     * 파라미터 표는 헤더가 달라 진입하지 않으므로 건드리지 않습니다.
     *
     * @param  string  $content  문서 내용
     * @param  ResourceFieldDescriber  $describer  설명기
     * @param  int  $filled  채운 건수 (참조 누적)
     * @param  int  $skipped  유지 건수 (참조 누적)
     * @return string 치환된 문서 내용
     */
    private function backfillFile(string $content, ResourceFieldDescriber $describer, int &$filled, int &$skipped): string
    {
        $lines = explode("\n", $content);
        $inFieldTable = false;

        foreach ($lines as $i => $line) {
            // 응답 필드 표 헤더 진입 감지
            if (str_contains($line, self::FIELD_TABLE_HEADER)) {
                $inFieldTable = true;

                continue;
            }

            // 표 구분선(| --- | ...) 은 건너뜀
            if ($inFieldTable && preg_match('/^\|\s*-+\s*\|/', $line)) {
                continue;
            }

            // 표가 아닌 라인(빈 줄 또는 | 로 시작하지 않음) → 표 종료
            if ($inFieldTable && ! str_starts_with(ltrim($line), '|')) {
                $inFieldTable = false;

                continue;
            }

            if (! $inFieldTable || ! str_contains($line, self::TODO_MARKER)) {
                continue;
            }

            // 응답 필드 행 파싱: | name | type | `sample` | <!-- TODO: 설명 --> |
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            if (count($cells) < 4) {
                continue;
            }

            [$name, $type] = [$cells[0], $cells[1]];
            $desc = $describer->describe($name, $type);

            if ($desc === null) {
                $skipped++;

                continue;
            }

            $safe = str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $desc);
            $lines[$i] = str_replace(self::TODO_MARKER, $safe, $line);
            $filled++;
        }

        return implode("\n", $lines);
    }
}
