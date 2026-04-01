<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * PHPUnit Doc-Comment Annotations를 PHP 8 Attributes로 변환하는 Artisan 커맨드
 *
 * @package App\Console\Commands
 * @author sirsoft
 */
class ConvertPHPUnitAnnotations extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'phpunit:convert-annotations
                            {--dry-run : 변환 미리보기 (파일 수정 안 함)}
                            {--backup : 변환 전 백업 생성 (.bak 파일)}
                            {--path= : 특정 경로만 변환}
                            {--stats-only : 통계만 출력}
                            {--rollback : 백업에서 복원}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'PHPUnit doc-comment annotations를 PHP 8 attributes로 변환합니다';

    /**
     * 변환기 인스턴스
     *
     * @var mixed
     */
    private $converter;

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // 스크립트 파일 로드
        $scriptPath = base_path('scripts/convert-phpunit-annotations.php');
        if (!file_exists($scriptPath)) {
            $this->error("스크립트 파일을 찾을 수 없습니다: {$scriptPath}");
            return Command::FAILURE;
        }

        require_once $scriptPath;

        // 롤백 모드
        if ($this->option('rollback')) {
            return $this->handleRollback();
        }

        // 통계만 확인
        if ($this->option('stats-only')) {
            return $this->handleStatsOnly();
        }

        // 변환 실행
        return $this->handleConversion();
    }

    /**
     * 통계만 확인합니다.
     *
     * @return int 종료 코드
     */
    private function handleStatsOnly(): int
    {
        $this->info('PHPUnit Annotation 통계 확인 중...');
        $this->newLine();

        $converter = new \PHPUnitAnnotationConverter(false, false, false);
        $basePath = base_path();
        $specificPath = $this->option('path');

        $stats = $converter->getStats($basePath, $specificPath);

        $this->line('PHPUnit Annotation Conversion Statistics');
        $this->line('=========================================');
        $this->line("총 테스트 파일: {$stats['total_files']}");
        $this->line("Annotations이 있는 파일: {$stats['files_with_annotations']}");
        $this->newLine();

        $this->line('Annotation 분류:');
        $this->line("- @test: {$stats['test']}");
        $this->line("- @dataProvider: {$stats['dataProvider']}");
        $this->line("- @group: {$stats['group']}");
        $this->line("- @depends: {$stats['depends']}");
        $this->newLine();

        if (!empty($stats['files'])) {
            $this->line('파일별 상세:');
            foreach ($stats['files'] as $fileInfo) {
                $relativePath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $fileInfo['path']);
                $this->line("  {$relativePath}");
                foreach ($fileInfo['annotations'] as $annotation) {
                    $this->line("    - {$annotation}");
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 변환을 실행합니다.
     *
     * @return int 종료 코드
     */
    private function handleConversion(): int
    {
        $dryRun = $this->option('dry-run');
        $backup = $this->option('backup');
        $verbose = $this->option('verbose');

        if ($dryRun) {
            $this->warn('[DRY RUN] 변환 미리보기 모드 - 실제 파일은 수정되지 않습니다');
            $this->newLine();
        }

        if ($backup && !$dryRun) {
            $this->info('백업 모드 활성화 - 변환 전 .bak 파일이 생성됩니다');
            $this->newLine();
        }

        $this->converter = new \PHPUnitAnnotationConverter($dryRun, $backup, $verbose);

        $basePath = base_path();
        $specificPath = $this->option('path');

        // 파일 스캔
        $this->info('테스트 파일 스캔 중...');
        $files = $this->converter->scanTestFiles($basePath, $specificPath);

        if (empty($files)) {
            $this->warn('변환할 테스트 파일을 찾을 수 없습니다.');
            return Command::SUCCESS;
        }

        $this->info('총 ' . count($files) . '개의 테스트 파일을 발견했습니다.');
        $this->newLine();

        // 프로그레스 바 생성
        $bar = $this->output->createProgressBar(count($files));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('변환 시작...');

        if (!$verbose) {
            $bar->start();
        }

        $convertedCount = 0;

        foreach ($files as $file) {
            $relativePath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file);

            if ($verbose) {
                $this->line("처리 중: {$relativePath}");
            } else {
                $bar->setMessage($relativePath);
            }

            if ($this->converter->convertFile($file)) {
                $convertedCount++;
            }

            if (!$verbose) {
                $bar->advance();
            }
        }

        if (!$verbose) {
            $bar->finish();
            $this->newLine(2);
        }

        // 결과 출력
        $report = $this->converter->generateReport();
        $this->line($report);

        if ($dryRun) {
            $this->info('Dry-run 완료 - 실제 파일은 변경되지 않았습니다.');
            $this->info('실제 변환을 수행하려면 --dry-run 옵션 없이 실행하세요.');
        } else {
            $this->info('변환 완료!');
            if ($backup) {
                $this->info('.bak 백업 파일이 생성되었습니다.');
                $this->warn('테스트 실행 후 문제가 없으면 백업 파일을 삭제하세요:');
                $this->line('  find tests modules plugins -name "*.bak" -delete');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 백업에서 복원합니다.
     *
     * @return int 종료 코드
     */
    private function handleRollback(): int
    {
        $this->warn('백업 복원 기능은 현재 구현되지 않았습니다.');
        $this->info('수동으로 .bak 파일을 복원하세요:');
        $this->line('  for file in $(find . -name "*.bak"); do mv "$file" "${file%.bak}"; done');

        return Command::SUCCESS;
    }
}
