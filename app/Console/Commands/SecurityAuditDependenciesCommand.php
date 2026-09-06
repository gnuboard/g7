<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * 의존성 취약점 전수 점검 커맨드 (#126)
 *
 * 이 저장소는 잠금파일이 60개 가까이 흩어져 있다 — 코어 하나와 확장 수십 개가 각자
 * `package-lock.json` / `composer.lock` 을 갖는다. 루트만 감사하면 확장 전부가 사각이
 * 되는데, 그 사각은 오류도 경고도 남기지 않는다: 취약한 라이브러리가 정상 동작하는 것이
 * 유일한 증상이다.
 *
 * 동봉(vendored) 자산은 어떤 잠금파일에도 없어 `npm audit` 이 **원리상** 볼 수 없다.
 * 그 축은 판정하지 않고 목록으로 노출한다 — 사람이 봐야 하는 대상이기 때문이다.
 *
 * 종료 코드: 취약점이 하나라도 있으면 비-0. 비-0 은 "커맨드 실행 실패" 가 아니라
 *          **조치 대상 발견 신호**다.
 */
class SecurityAuditDependenciesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'security:audit-dependencies
                            {--json : 결과를 JSON 으로 출력합니다}
                            {--npm-only : npm 잠금파일만 점검합니다}
                            {--composer-only : composer 잠금파일만 점검합니다}';

    /**
     * The console command description.
     */
    protected $description = '저장소의 모든 잠금파일(npm·composer) 운영 의존성 취약점을 점검합니다 (취약점 발견 시 비-0 종료)';

    /** 잠금파일 탐색에서 제외할 경로 조각 */
    private const EXCLUDED_SEGMENTS = ['node_modules', 'vendor', '_pending', 'storage'];

    /** 개별 감사 프로세스 제한 시간(초) */
    private const PROCESS_TIMEOUT = 300;

    /**
     * Execute the console command.
     *
     * @return int 취약점이 없으면 0, 있으면 1
     */
    public function handle(): int
    {
        $npmOnly = (bool) $this->option('npm-only');
        $composerOnly = (bool) $this->option('composer-only');

        $results = [];

        if (! $composerOnly) {
            foreach ($this->lockFiles('package-lock.json') as $lock) {
                $results[] = $this->auditNpm($lock);
            }
        }

        if (! $npmOnly) {
            foreach ($this->lockFiles('composer.lock') as $lock) {
                $results[] = $this->auditComposer($lock);
            }
        }

        $vendored = $this->vendoredAssets();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'results' => $results,
                'vendored_assets' => $vendored,
                'totals' => $this->totals($results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->totals($results)['vulnerable'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->renderTable($results);
        $this->renderVendored($vendored);

        $totals = $this->totals($results);

        $this->line('');

        if ($totals['unmeasurable'] > 0) {
            $this->warn(sprintf(
                '점검 불가 %d건 — 감사 도구가 실행되지 않았습니다. "취약점 없음" 과 다릅니다.',
                $totals['unmeasurable']
            ));
        }

        if ($totals['vulnerable'] === 0) {
            $this->info(sprintf(
                '운영 의존성 취약점 0건 (감사 %d개 / 대상 없음 %d개 / 발견 %d개 잠금파일).',
                $totals['audited'],
                $totals['empty'],
                $totals['checked']
            ));

            return $totals['unmeasurable'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->error(sprintf(
            '%d개 잠금파일에서 운영 의존성 취약점이 발견되었습니다 (총 %d건).',
            $totals['vulnerable'],
            $totals['advisories']
        ));

        return self::FAILURE;
    }

    /**
     * 저장소에서 잠금파일을 전수 탐색합니다.
     *
     * @param  string  $fileName  잠금파일 이름
     * @return array<int, string> 잠금파일 절대 경로 목록
     */
    private function lockFiles(string $fileName): array
    {
        $root = base_path();
        $found = [];

        // 제외 디렉토리는 **내려가기 전에** 잘라낸다. 파일 단계에서 거르면 `node_modules`
        // 수만 개를 전부 훑고 나서 버리게 되어 한 번 실행에 수 분이 걸린다.
        $directories = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $pruned = new \RecursiveCallbackFilterIterator(
            $directories,
            function (\SplFileInfo $entry): bool {
                if (! $entry->isDir()) {
                    return true;
                }

                return ! in_array($entry->getFilename(), self::EXCLUDED_SEGMENTS, true);
            }
        );

        foreach (new \RecursiveIteratorIterator($pruned) as $entry) {
            if (! $entry->isFile() || $entry->getFilename() !== $fileName) {
                continue;
            }

            $found[] = $entry->getPathname();
        }

        sort($found);

        return $found;
    }

    /**
     * npm 잠금파일 하나를 감사합니다.
     *
     * @param  string  $lockPath  잠금파일 절대 경로
     * @return array{target: string, kind: string, status: string, advisories: int, severities: array<string,int>, detail: string}
     */
    private function auditNpm(string $lockPath): array
    {
        if ($this->isEmptyLock($lockPath, ['packages', 'dependencies'])) {
            return $this->emptyLock($lockPath, 'npm');
        }

        $dir = dirname($lockPath);
        $process = $this->runProcess(['npm', 'audit', '--omit=dev', '--json'], $dir);
        $decoded = json_decode($process['output'], true);

        if (! is_array($decoded) || ! isset($decoded['metadata']['vulnerabilities'])) {
            return $this->unmeasurable($lockPath, 'npm', trim($process['error']) ?: 'npm audit 출력을 해석할 수 없습니다.');
        }

        $severities = array_filter(
            array_map('intval', $decoded['metadata']['vulnerabilities']),
            static fn (int $count, string $key): bool => $count > 0 && $key !== 'total',
            ARRAY_FILTER_USE_BOTH
        );
        $total = (int) ($decoded['metadata']['vulnerabilities']['total'] ?? 0);

        return [
            'target' => $this->relative($lockPath),
            'kind' => 'npm',
            'status' => $total > 0 ? 'vulnerable' : 'clean',
            'advisories' => $total,
            'severities' => $severities,
            'detail' => $total > 0 ? implode(', ', array_keys($decoded['vulnerabilities'] ?? [])) : '',
        ];
    }

    /**
     * composer 잠금파일 하나를 감사합니다.
     *
     * @param  string  $lockPath  잠금파일 절대 경로
     * @return array{target: string, kind: string, status: string, advisories: int, severities: array<string,int>, detail: string}
     */
    private function auditComposer(string $lockPath): array
    {
        if ($this->isEmptyLock($lockPath, ['packages', 'packages-dev'])) {
            return $this->emptyLock($lockPath, 'composer');
        }

        $dir = dirname($lockPath);
        $process = $this->runProcess(['composer', 'audit', '--locked', '--no-dev', '--format=json'], $dir);
        $decoded = json_decode($this->firstJsonObject($process['output']), true);

        if (! is_array($decoded) || ! array_key_exists('advisories', $decoded)) {
            return $this->unmeasurable($lockPath, 'composer', trim($process['error']) ?: 'composer audit 출력을 해석할 수 없습니다.');
        }

        $severities = [];
        $total = 0;

        foreach ($decoded['advisories'] as $advisories) {
            foreach ((array) $advisories as $advisory) {
                $total++;
                $severity = (string) ($advisory['severity'] ?? 'unknown');
                $severities[$severity] = ($severities[$severity] ?? 0) + 1;
            }
        }

        return [
            'target' => $this->relative($lockPath),
            'kind' => 'composer',
            'status' => $total > 0 ? 'vulnerable' : 'clean',
            'advisories' => $total,
            'severities' => $severities,
            'detail' => $total > 0 ? implode(', ', array_keys($decoded['advisories'])) : '',
        ];
    }

    /**
     * 잠금파일이 의존성을 하나도 담고 있지 않은지 판정합니다.
     *
     * 확장 대부분은 composer 의존성이 없어 빈 잠금을 갖는다. 그 상태에서 감사 도구는
     * "설치본이 없다" 는 오류를 내는데, 그것을 점검 불가로 세면 조치할 것이 없는 대상이
     * 24건씩 경고로 쌓여 진짜 점검 불가를 가린다.
     *
     * @param  string  $lockPath  잠금파일 절대 경로
     * @param  array<int, string>  $keys  의존성이 담기는 최상위 키
     * @return bool
     */
    private function isEmptyLock(string $lockPath, array $keys): bool
    {
        $decoded = json_decode((string) @file_get_contents($lockPath), true);

        if (! is_array($decoded)) {
            return false;
        }

        foreach ($keys as $key) {
            $entries = $decoded[$key] ?? [];

            if (! is_array($entries)) {
                continue;
            }

            // npm 잠금의 `packages` 는 루트 자신을 빈 문자열 키로 항상 담는다 — 그것만 있으면
            // 빈 것이다. composer 잠금의 `packages` 는 리스트라 키가 정수이므로 건드리지 않는다.
            $meaningful = array_filter(
                array_keys($entries),
                static fn ($name): bool => $name !== ''
            );

            if ($meaningful !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * 의존성이 없는 잠금파일을 기록합니다.
     *
     * @param  string  $lockPath  잠금파일 절대 경로
     * @param  string  $kind  npm|composer
     * @return array{target: string, kind: string, status: string, advisories: int, severities: array<string,int>, detail: string}
     */
    private function emptyLock(string $lockPath, string $kind): array
    {
        return [
            'target' => $this->relative($lockPath),
            'kind' => $kind,
            'status' => 'empty',
            'advisories' => 0,
            'severities' => [],
            'detail' => '의존성 없음',
        ];
    }

    /**
     * 감사 도구를 실행할 수 없었던 대상을 기록합니다.
     *
     * "점검 불가" 는 "취약점 없음" 과 다르다 — 뭉뚱그리면 운영자가 정상으로 읽는다.
     *
     * @param  string  $lockPath  잠금파일 절대 경로
     * @param  string  $kind  npm|composer
     * @param  string  $reason  사유
     * @return array{target: string, kind: string, status: string, advisories: int, severities: array<string,int>, detail: string}
     */
    private function unmeasurable(string $lockPath, string $kind, string $reason): array
    {
        return [
            'target' => $this->relative($lockPath),
            'kind' => $kind,
            'status' => 'unmeasurable',
            'advisories' => 0,
            'severities' => [],
            'detail' => mb_substr($reason, 0, 200),
        ];
    }

    /**
     * 외부 명령을 실행합니다 ( 과 충돌하지 않도록 이름을 분리).
     *
     * @param  array<int, string>  $command  명령과 인자
     * @param  string  $cwd  작업 디렉토리
     * @return array{output: string, error: string, exit: int}
     */
    private function runProcess(array $command, string $cwd): array
    {
        $process = new Process($command, $cwd, null, null, self::PROCESS_TIMEOUT);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['output' => '', 'error' => $e->getMessage(), 'exit' => -1];
        }

        return [
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
            'exit' => (int) $process->getExitCode(),
        ];
    }

    /**
     * 앞쪽 잡음을 걷어내고 첫 JSON 객체만 남깁니다.
     *
     * composer 는 오토로드 경고를 표준출력에 함께 흘릴 수 있다.
     *
     * @param  string  $output  명령 출력
     * @return string JSON 문자열 (없으면 빈 문자열)
     */
    private function firstJsonObject(string $output): string
    {
        $start = strpos($output, '{');

        return $start === false ? '' : substr($output, $start);
    }

    /**
     * 동봉 제3자 자산의 버전 디렉토리를 나열합니다.
     *
     * 감사 도구가 원리상 볼 수 없는 축이므로 판정하지 않고 노출만 한다.
     *
     * @return array<int, array{extension: string, library: string, versions: array<int, string>}>
     */
    private function vendoredAssets(): array
    {
        $rows = [];

        foreach (['templates', 'modules', 'plugins'] as $kind) {
            foreach ([$kind.'/*/dist/vendor', $kind.'/_bundled/*/dist/vendor'] as $pattern) {
                foreach ((array) glob(base_path($pattern), GLOB_ONLYDIR) as $vendorRoot) {
                    foreach ((array) glob($vendorRoot.'/*', GLOB_ONLYDIR) as $libDir) {
                        $versions = array_map(
                            'basename',
                            (array) glob($libDir.'/*', GLOB_ONLYDIR)
                        );

                        $rows[] = [
                            'extension' => $this->relative(dirname($vendorRoot, 2)),
                            'library' => basename($libDir),
                            'versions' => array_values($versions),
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * 결과 집계를 계산합니다.
     *
     * @param  array<int, array<string, mixed>>  $results  감사 결과
     * @return array{checked: int, vulnerable: int, unmeasurable: int, advisories: int}
     */
    private function totals(array $results): array
    {
        return [
            'checked' => count($results),
            'audited' => count(array_filter($results, static fn (array $r): bool => in_array($r['status'], ['clean', 'vulnerable'], true))),
            'empty' => count(array_filter($results, static fn (array $r): bool => $r['status'] === 'empty')),
            'vulnerable' => count(array_filter($results, static fn (array $r): bool => $r['status'] === 'vulnerable')),
            'unmeasurable' => count(array_filter($results, static fn (array $r): bool => $r['status'] === 'unmeasurable')),
            'advisories' => array_sum(array_column($results, 'advisories')),
        ];
    }

    /**
     * 결과 표를 출력합니다.
     *
     * @param  array<int, array<string, mixed>>  $results  감사 결과
     * @return void
     */
    private function renderTable(array $results): void
    {
        $this->line('');
        $this->line('<options=bold>의존성 취약점 점검 (운영 의존성 기준)</>');
        $this->line('');

        $rows = [];

        foreach ($results as $result) {
            $severities = [];

            foreach ($result['severities'] as $severity => $count) {
                $severities[] = $severity.' '.$count;
            }

            $rows[] = [
                $result['kind'],
                $result['target'],
                match ($result['status']) {
                    'clean' => '이상 없음',
                    'empty' => '대상 없음',
                    'vulnerable' => '취약',
                    default => '점검 불가',
                },
                $result['advisories'] > 0 ? (string) $result['advisories'] : '-',
                $severities === [] ? ($result['detail'] !== '' ? mb_substr($result['detail'], 0, 60) : '-') : implode(', ', $severities),
            ];
        }

        $this->table(['종류', '대상', '상태', '건수', '내역'], $rows);
    }

    /**
     * 동봉 자산 목록을 출력합니다.
     *
     * @param  array<int, array<string, mixed>>  $vendored  동봉 자산 목록
     * @return void
     */
    private function renderVendored(array $vendored): void
    {
        $this->line('');
        $this->line('<options=bold>동봉 제3자 자산 (감사 도구가 볼 수 없는 축 — 사람이 확인)</>');
        $this->line('');

        if ($vendored === []) {
            $this->line('  (없음)');

            return;
        }

        $this->table(
            ['확장', '라이브러리', '버전 디렉토리'],
            array_map(
                static fn (array $row): array => [
                    $row['extension'],
                    $row['library'],
                    $row['versions'] === [] ? '(버전 디렉토리 없음)' : implode(', ', $row['versions']),
                ],
                $vendored
            )
        );
    }

    /**
     * 저장소 상대 경로로 바꿉니다.
     *
     * @param  string  $path  절대 경로
     * @return string
     */
    private function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_replace('\\', '/', str_starts_with($path, $base) ? substr($path, strlen($base)) : $path);
    }
}
