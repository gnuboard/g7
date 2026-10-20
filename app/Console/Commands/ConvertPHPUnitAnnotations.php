<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * PHPUnit Doc-Comment Annotations를 PHP 8 Attributes로 변환하는 Artisan 커맨드
 *
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
        if (! file_exists($scriptPath)) {
            $this->error(__('common.console.phpunit_convert_annotations.script_not_found', ['path' => $scriptPath]));

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
        $this->info(__('common.console.phpunit_convert_annotations.stats_checking'));
        $this->newLine();

        $converter = new \PHPUnitAnnotationConverter(false, false, false);
        $basePath = base_path();
        $specificPath = $this->option('path');

        $stats = $converter->getStats($basePath, $specificPath);

        $this->line('PHPUnit Annotation Conversion Statistics');
        $this->line('=========================================');
        $this->line(__('common.console.phpunit_convert_annotations.total_files', ['count' => $stats['total_files']]));
        $this->line(__('common.console.phpunit_convert_annotations.files_with_annotations', ['count' => $stats['files_with_annotations']]));
        $this->newLine();

        $this->line(__('common.console.phpunit_convert_annotations.annotation_breakdown'));
        $this->line("- @test: {$stats['test']}");
        $this->line("- @dataProvider: {$stats['dataProvider']}");
        $this->line("- @group: {$stats['group']}");
        $this->line("- @depends: {$stats['depends']}");
        $this->newLine();

        if (! empty($stats['files'])) {
            $this->line(__('common.console.phpunit_convert_annotations.file_details'));
            foreach ($stats['files'] as $fileInfo) {
                $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $fileInfo['path']);
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
            $this->warn('[DRY RUN] '.__('common.console.phpunit_convert_annotations.dry_run_notice'));
            $this->newLine();
        }

        if ($backup && ! $dryRun) {
            $this->info(__('common.console.phpunit_convert_annotations.backup_enabled'));
            $this->newLine();
        }

        $this->converter = new \PHPUnitAnnotationConverter($dryRun, $backup, $verbose);

        $basePath = base_path();
        $specificPath = $this->option('path');

        // 파일 스캔
        $this->info(__('common.console.phpunit_convert_annotations.scanning'));
        $files = $this->converter->scanTestFiles($basePath, $specificPath);

        if (empty($files)) {
            $this->warn(__('common.console.phpunit_convert_annotations.no_files'));

            return Command::SUCCESS;
        }

        $this->info(__('common.console.phpunit_convert_annotations.files_found', ['count' => count($files)]));
        $this->newLine();

        // 프로그레스 바 생성
        $bar = $this->output->createProgressBar(count($files));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage(__('common.console.phpunit_convert_annotations.progress_start'));

        if (! $verbose) {
            $bar->start();
        }

        $convertedCount = 0;

        foreach ($files as $file) {
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            if ($verbose) {
                $this->line(__('common.console.phpunit_convert_annotations.processing', ['path' => $relativePath]));
            } else {
                $bar->setMessage($relativePath);
            }

            if ($this->converter->convertFile($file)) {
                $convertedCount++;
            }

            if (! $verbose) {
                $bar->advance();
            }
        }

        if (! $verbose) {
            $bar->finish();
            $this->newLine(2);
        }

        // 결과 출력
        $report = $this->converter->generateReport();
        $this->line($report);

        if ($dryRun) {
            $this->info(__('common.console.phpunit_convert_annotations.dry_run_done'));
            $this->info(__('common.console.phpunit_convert_annotations.dry_run_hint'));
        } else {
            $this->info(__('common.console.phpunit_convert_annotations.done'));
            if ($backup) {
                $this->info(__('common.console.phpunit_convert_annotations.backup_created'));
                $this->warn(__('common.console.phpunit_convert_annotations.backup_delete_hint'));
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
        $this->warn(__('common.console.phpunit_convert_annotations.rollback_unsupported'));
        $this->info(__('common.console.phpunit_convert_annotations.rollback_manual_hint'));
        $this->line('  for file in $(find . -name "*.bak"); do mv "$file" "${file%.bak}"; done');

        return Command::SUCCESS;
    }
}
