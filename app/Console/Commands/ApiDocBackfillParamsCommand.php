<?php

namespace App\Console\Commands;

use App\Support\ApiDoc\ParameterDescriber;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * 기존 API 문서의 파라미터 용도 TODO 를 공통 파라미터 설명으로 소급 채웁니다.
 *
 * 실측 서버 없이 재생성하면 이미 실측된 응답표·사람 서술이 퇴행하므로, 이 커맨드는
 * 재생성 대신 파라미터 표의 `<!-- TODO: 용도 -->` 셀만 in-place 치환합니다.
 * 채움 규칙 SSoT 는 ApiDocScaffolder 와 동일한 ParameterDescriber 입니다 —
 * 이후 정상 재생성(실측 서버 가동 시)도 같은 설명을 산출하므로 멱등합니다.
 *
 * 도메인 특이 파라미터(ParameterDescriber 가 null 반환)는 TODO 를 그대로 둡니다.
 */
class ApiDocBackfillParamsCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'api:docgen-backfill-params
        {--dry-run : 치환하지 않고 채울 건수만 리포트}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = '기존 API 문서의 파라미터 용도 TODO 를 공통 파라미터 설명으로 소급 채웁니다';

    /**
     * @var string 파라미터 표를 식별하는 고유 헤더
     */
    private const PARAM_TABLE_HEADER = '| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |';

    /**
     * @var string 파라미터 용도 TODO 마커
     */
    private const TODO_MARKER = '<!-- TODO: 용도 -->';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $describer = new ParameterDescriber;
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
        $this->info("{$prefix}파라미터 용도 채움: {$filled}건, TODO 유지(도메인 특이): {$skipped}건, 변경 파일: {$filesChanged}개");

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
     * 단일 문서의 파라미터 표 TODO 를 채웁니다.
     *
     * 파라미터 표 헤더 이후 표 행만 대상으로 하며, `<!-- TODO: 용도 -->` 셀을
     * ParameterDescriber 결과로 치환합니다. 설명이 null(도메인 특이)이면 유지합니다.
     *
     * @param  string  $content  문서 내용
     * @param  ParameterDescriber  $describer  설명기
     * @param  int  $filled  채운 건수 (참조 누적)
     * @param  int  $skipped  유지 건수 (참조 누적)
     * @return string 치환된 문서 내용
     */
    private function backfillFile(string $content, ParameterDescriber $describer, int &$filled, int &$skipped): string
    {
        $lines = explode("\n", $content);
        $inParamTable = false;

        foreach ($lines as $i => $line) {
            // 파라미터 표 헤더 진입 감지
            if (str_contains($line, self::PARAM_TABLE_HEADER)) {
                $inParamTable = true;

                continue;
            }

            // 표 구분선(| --- | ...) 은 건너뜀
            if ($inParamTable && preg_match('/^\|\s*-+\s*\|/', $line)) {
                continue;
            }

            // 표가 아닌 라인(빈 줄 또는 | 로 시작하지 않음) → 표 종료
            if ($inParamTable && ! str_starts_with(ltrim($line), '|')) {
                $inParamTable = false;

                continue;
            }

            if (! $inParamTable || ! str_contains($line, self::TODO_MARKER)) {
                continue;
            }

            // 파라미터 행 파싱: | name | location | type | 필수 | 허용값 | <!-- TODO: 용도 --> |
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            if (count($cells) < 5) {
                continue;
            }

            [$name, $location, $type] = [$cells[0], $cells[1], $cells[2]];
            $desc = $describer->describe($name, $location, $type);

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
