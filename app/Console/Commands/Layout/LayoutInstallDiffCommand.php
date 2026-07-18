<?php

namespace App\Console\Commands\Layout;

use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutProvenance;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 한 확장의 `.g7.html` 저작이 그 옆의 산출 `.json` 과 어긋나지 않는지 검사하는 이관 보조 커맨드 (S7).
 *
 * 설치는 산출 `.json` 을 등록 소스로 읽고(Architecture B), `.g7.html` 은 provenance 표식으로만
 * 짝을 확인한다. 그래서 이관 웨이브(S10~)에서 진짜 위험은 "소스는 고쳤는데 산출 `.json` 을 다시
 * 컴파일하지 않아 둘이 벌어진" 경우다. 이 커맨드는 확장의 모든 `.g7.html` 을 **지금 컴파일러로**
 * 다시 컴파일해, 그 결과가 커밋된 산출 `.json` 과 (provenance 표식을 걷어낸 뒤) 정확히 같은지
 * deep-equal 로 확인하고 어긋난 파일만 보고한다. DB 를 건드리지 않으므로 언제든 안전하게 돌린다.
 *
 * DB 등록 동등성(②)은 설치 Feature 테스트가 담보한다 — 이 커맨드는 그 앞단의 파일 동등성(①)을
 * 웨이브 운영자가 한 번에 훑는 용도다.
 */
class LayoutInstallDiffCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'layout:install-diff
                            {extension : 검사할 확장 식별자 (예: sirsoft-page)}
                            {--template= : 대상 템플릿 강제 지정 (기본: 경로의 admin/·user/ 로 판별)}';

    /**
     * @var string
     */
    protected $description = '확장의 .g7.html 저작이 산출 .json 과 어긋나지 않는지(재컴파일 deep-equal) 검사합니다';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $extension = (string) $this->argument('extension');

        $root = $this->resolveExtensionRoot($extension);

        if ($root === null) {
            $this->error("확장을 찾지 못했습니다: {$extension} (_bundled 모듈·플러그인·템플릿)");

            return self::FAILURE;
        }

        $markupFiles = $this->scanMarkupFiles($root);

        if ($markupFiles === []) {
            $this->info("검사할 .g7.html 이 없습니다: {$extension}");

            return self::SUCCESS;
        }

        $resolver = new MarkupCompileContextResolver;
        $templateOverride = $this->option('template') ? (string) $this->option('template') : null;

        $mismatched = 0;
        $missing = 0;

        foreach ($markupFiles as $file) {
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file));

            switch ($this->diffOne($file, $resolver, $templateOverride)) {
                case 'match':
                    $this->line("<info>✓</info> {$relative}");
                    break;
                case 'missing':
                    $this->line("<comment>?</comment> {$relative} — 산출 .json 없음 (layout:compile 필요)");
                    $missing++;
                    break;
                case 'mismatch':
                    $this->line("<error>✗</error> {$relative} — 산출 .json 이 재컴파일 결과와 다름");
                    $mismatched++;
                    break;
                default:
                    $this->line("<error>✗</error> {$relative} — 컴파일 실패");
                    $mismatched++;
            }
        }

        $this->newLine();
        $this->info(sprintf('완료 — 파일 %d개, 불일치 %d개, 미컴파일 %d개.', count($markupFiles), $mismatched, $missing));

        return ($mismatched === 0 && $missing === 0) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 파일 하나를 재컴파일해 산출 `.json` 과 비교합니다.
     *
     * @param  string  $file  `.g7.html` 절대 경로
     * @param  MarkupCompileContextResolver  $resolver  레지스트리·문맥 해석기
     * @param  string|null  $templateOverride  대상 템플릿 강제 지정
     * @return string `match`·`missing`·`mismatch`·`error`
     */
    private function diffOne(string $file, MarkupCompileContextResolver $resolver, ?string $templateOverride): string
    {
        $jsonFile = preg_replace('/\.g7\.html$/i', '.json', $file);

        if (! File::exists($jsonFile)) {
            return 'missing';
        }

        $registry = $resolver->registryFor($file, $templateOverride);

        if ($registry === null) {
            return 'error';
        }

        try {
            $result = (new LayoutMarkupCompiler($registry))->compile(
                File::get($file),
                $file,
                $resolver->contextFor($file, $templateOverride),
            );
        } catch (CompileError) {
            return 'error';
        }

        $committed = json_decode(File::get($jsonFile), true);

        if (! is_array($committed)) {
            return 'mismatch';
        }

        // 산출 `.json` 은 provenance 표식이 얹혀 있으므로 걷어낸 뒤 컴파일 결과와 비교한다.
        return LayoutProvenance::strip($committed) === $result->json ? 'match' : 'mismatch';
    }

    /**
     * 확장 식별자에서 _bundled 루트 디렉토리를 찾습니다.
     *
     * @param  string  $extension  확장 식별자
     * @return string|null 확장 루트 절대 경로, 없으면 null
     */
    private function resolveExtensionRoot(string $extension): ?string
    {
        foreach (['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'] as $group) {
            $candidate = base_path("{$group}/{$extension}");

            if (File::isDirectory($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 확장 루트에서 모든 `.g7.html` 파일을 스캔합니다.
     *
     * @param  string  $root  확장 루트 절대 경로
     * @return array<int, string> `.g7.html` 절대 경로 목록 (정렬)
     */
    private function scanMarkupFiles(string $root): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.g7.html')) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
