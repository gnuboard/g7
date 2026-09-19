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

        // 소스 선택은 설치 경로와 **같은 규약**을 쓴다 — 소스 SSoT(`.g7.html`) 우선, 없으면 짝
        // `.json`, `--layout-source=json` 이면 역전. 종전에는 인자 경로를 `json_decode` 로 직행해
        // 읽어 소스를 인자로 줄 수도, 짝 소스를 우선할 수도 없었다(fixture 가 낡은 `.json` 에서
        // 나오는데 화면은 소스로 설치되므로, 그 fixture 로 만든 회귀 테스트가 실물과 갈린다).
        $source = null;

        foreach ($this->layoutSourceCandidates($layoutPath) as $candidate) {
            if (File::exists($candidate['path'])) {
                $source = $candidate;
                break;
            }
        }

        if ($source === null) {
            $this->error("레이아웃 소스를 찾을 수 없습니다: {$layoutPath}");

            return self::FAILURE;
        }

        if ($source['markup']) {
            $this->info('소스로 로드합니다: '.$source['path']);

            try {
                $layoutData = $this->compileMarkupLayoutFile($source['path']);
            } catch (\Throwable $e) {
                $this->error('소스 컴파일 실패: '.$e->getMessage());

                return self::FAILURE;
            }
        } else {
            $layoutData = json_decode(File::get($source['path']), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('JSON 파싱 실패: '.json_last_error_msg());

                return self::FAILURE;
            }
        }

        // Partial 병합 (기준 경로는 **선택된 소스** 기준 — 후보는 같은 디렉토리의 짝이다)
        $basePath = dirname($source['path']);
        $dataSources = $layoutData['data_sources'] ?? [];

        try {
            $mergedLayout = $this->resolveAllPartials($layoutData, $basePath, 0, $dataSources);
        } catch (\Exception $e) {
            $this->error('Partial 병합 실패: '.$e->getMessage());

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
        // 소스를 인자로 받으면 `pathinfo` 가 `x.g7` 을 돌려주므로 확장자 앞머리를 마저 떤다.
        $fallbackName = preg_replace('/\.g7$/i', '', pathinfo($layoutPath, PATHINFO_FILENAME));

        $outputName = $this->option('output')
            ?: ($mergedLayout['layout_name'] ?? $fallbackName);

        $outputPath = $outputDir.'/'.$outputName.'.json';

        // JSON 저장
        $jsonOutput = json_encode($mergedLayout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        File::put($outputPath, $jsonOutput);

        $this->info("Fixture 생성 완료: {$outputPath}");
        $this->info('파일 크기: '.number_format(strlen($jsonOutput)).' bytes');

        return self::SUCCESS;
    }
}
