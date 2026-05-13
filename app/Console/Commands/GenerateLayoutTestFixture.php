<?php

namespace App\Console\Commands;

use App\Extension\Traits\ValidatesLayoutFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 레이아웃 테스트 fixtures 생성 커맨드
 *
 * 레이아웃 JSON 파일을 로드하고 partial을 병합하여
 * 테스트용 fixture 파일로 저장합니다.
 */
class GenerateLayoutTestFixture extends Command
{
    use ValidatesLayoutFiles;

    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'layout:generate-fixture
                            {layout : 레이아웃 파일 경로 (modules/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_promotion_coupon_list.json)}
                            {--output= : 출력 파일명 (기본: 레이아웃 이름)}
                            {--dir= : 출력 디렉토리 (기본: resources/js/core/template-engine/__tests__/fixtures)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '레이아웃 JSON을 partial 병합하여 테스트 fixture로 저장합니다';

    /**
     * 커맨드 실행
     */
    public function handle(): int
    {
        $layoutPath = $this->argument('layout');

        // 절대 경로로 변환
        if (! str_starts_with($layoutPath, '/') && ! preg_match('/^[A-Z]:/', $layoutPath)) {
            $layoutPath = base_path($layoutPath);
        }

        // 파일 존재 확인
        if (! File::exists($layoutPath)) {
            $this->error("레이아웃 파일을 찾을 수 없습니다: {$layoutPath}");
            return self::FAILURE;
        }

        $this->info("레이아웃 로드 중: {$layoutPath}");

        // JSON 로드
        $jsonContent = File::get($layoutPath);
        $layoutData = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('JSON 파싱 실패: ' . json_last_error_msg());
            return self::FAILURE;
        }

        // Partial 병합
        $basePath = dirname($layoutPath);
        $dataSources = $layoutData['data_sources'] ?? [];

        try {
            $mergedLayout = $this->resolveAllPartials($layoutData, $basePath, 0, $dataSources);
        } catch (\Exception $e) {
            $this->error('Partial 병합 실패: ' . $e->getMessage());
            return self::FAILURE;
        }

        // 출력 디렉토리 결정
        $outputDir = $this->option('dir')
            ?: base_path('resources/js/core/template-engine/__tests__/fixtures');

        // 디렉토리 생성
        if (! File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
            $this->info("디렉토리 생성: {$outputDir}");
        }

        // 출력 파일명 결정
        $outputName = $this->option('output')
            ?: ($mergedLayout['layout_name'] ?? pathinfo($layoutPath, PATHINFO_FILENAME));

        $outputPath = $outputDir . '/' . $outputName . '.json';

        // JSON 저장
        $jsonOutput = json_encode($mergedLayout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        File::put($outputPath, $jsonOutput);

        $this->info("Fixture 생성 완료: {$outputPath}");
        $this->info("파일 크기: " . number_format(strlen($jsonOutput)) . " bytes");

        return self::SUCCESS;
    }
}
