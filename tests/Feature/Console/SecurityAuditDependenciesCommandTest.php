<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SecurityAuditDependenciesCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 의존성 취약점 전수 점검 커맨드 계약 (#126).
 *
 * 이 커맨드의 값은 "루트만이 아니라 전부를 본다" 는 데 있다. 모집단이 조용히 줄어들면
 * 검사는 계속 통과하는데 확장은 감사되지 않는 상태가 되므로, 모집단 도출과 상태 구분을
 * 함께 고정한다.
 *
 * 실제 `npm audit` / `composer audit` 실행은 잠금파일 60개에 대해 수 분이 걸린다. 그래서
 * 여기서는 네트워크·프로세스를 타지 않는 축(모집단 도출 · 빈 잠금 판정 · 상태 집계 ·
 * 동봉 자산 열거)을 리플렉션으로 직접 검사하고, 종단 실행은 `--composer-only` 로 좁힌다.
 *
 * DB 를 쓰지 않으므로 `RefreshDatabase` 를 붙이지 않는다.
 */
class SecurityAuditDependenciesCommandTest extends TestCase
{
    /**
     * 커맨드의 private 메서드를 호출합니다.
     *
     * @param  string  $method  메서드 이름
     * @param  array<int, mixed>  $args  인자
     * @return mixed
     */
    private function invoke(string $method, array $args = []): mixed
    {
        $command = new SecurityAuditDependenciesCommand;

        return (new \ReflectionMethod($command, $method))->invokeArgs($command, $args);
    }

    /**
     * 모집단이 루트를 넘어 확장까지 닿는지 확인합니다.
     *
     * @return void
     */
    public function test_lock_file_population_reaches_extensions(): void
    {
        $npmLocks = $this->invoke('lockFiles', ['package-lock.json']);
        $composerLocks = $this->invoke('lockFiles', ['composer.lock']);

        $this->assertContains(base_path('package-lock.json'), $npmLocks, '루트 npm 잠금이 모집단에 없다.');
        $this->assertContains(base_path('composer.lock'), $composerLocks, '루트 composer 잠금이 모집단에 없다.');

        $relative = array_map(
            fn (string $path): string => str_replace('\\', '/', substr($path, strlen(base_path()) + 1)),
            $npmLocks
        );

        foreach (['templates/', 'modules/', 'plugins/'] as $domain) {
            $this->assertNotEmpty(
                array_filter($relative, static fn (string $p): bool => str_starts_with($p, $domain)),
                sprintf('%s 도메인의 잠금파일이 모집단에서 빠졌다 — 그 확장은 영영 감사되지 않는다.', $domain)
            );
        }

        // 루트 하나만 보는 상태로 축소되면 이 커맨드의 존재 이유가 사라진다.
        $this->assertGreaterThan(10, count($npmLocks), 'npm 잠금 모집단이 비정상적으로 작다.');
    }

    /**
     * 설치 산출물·대기소는 모집단에서 제외되는지 확인합니다.
     *
     * @return void
     */
    public function test_population_excludes_install_artifacts(): void
    {
        $locks = array_merge(
            $this->invoke('lockFiles', ['package-lock.json']),
            $this->invoke('lockFiles', ['composer.lock'])
        );

        foreach ($locks as $path) {
            $normalized = str_replace('\\', '/', $path);

            $this->assertStringNotContainsString('/node_modules/', $normalized);
            $this->assertStringNotContainsString('/_pending/', $normalized);
            $this->assertStringNotContainsString('/storage/', $normalized);
        }
    }

    /**
     * 의존성 없는 잠금과 감사 실패를 구분하는지 확인합니다.
     *
     * 둘을 뭉뚱그리면 "조치할 것 없음" 이 "확인 못 함" 으로, 혹은 그 반대로 읽힌다.
     *
     * @return void
     */
    public function test_empty_lock_is_distinguished_from_unmeasurable(): void
    {
        $emptyComposer = tempnam(sys_get_temp_dir(), 'lock');
        $filledComposer = tempnam(sys_get_temp_dir(), 'lock');
        $emptyNpm = tempnam(sys_get_temp_dir(), 'lock');

        try {
            file_put_contents($emptyComposer, json_encode(['packages' => [], 'packages-dev' => []]));
            file_put_contents($filledComposer, json_encode(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]));
            file_put_contents($emptyNpm, json_encode(['packages' => ['' => ['name' => 'x']]]));

            $this->assertTrue($this->invoke('isEmptyLock', [$emptyComposer, ['packages', 'packages-dev']]));
            $this->assertFalse($this->invoke('isEmptyLock', [$filledComposer, ['packages', 'packages-dev']]));

            // npm 잠금의 `packages` 는 루트 자신("")을 항상 담는다 — 그것만 있으면 빈 것이다.
            $this->assertTrue($this->invoke('isEmptyLock', [$emptyNpm, ['packages', 'dependencies']]));

            $empty = $this->invoke('emptyLock', [$emptyComposer, 'composer']);
            $unmeasurable = $this->invoke('unmeasurable', [$emptyComposer, 'composer', '실행 실패']);

            $this->assertSame('empty', $empty['status']);
            $this->assertSame('unmeasurable', $unmeasurable['status']);
        } finally {
            @unlink($emptyComposer);
            @unlink($filledComposer);
            @unlink($emptyNpm);
        }
    }

    /**
     * 집계가 상태별로 갈라지는지 확인합니다.
     *
     * @return void
     */
    public function test_totals_separate_each_status(): void
    {
        $totals = $this->invoke('totals', [[
            ['status' => 'clean', 'advisories' => 0],
            ['status' => 'vulnerable', 'advisories' => 3],
            ['status' => 'empty', 'advisories' => 0],
            ['status' => 'unmeasurable', 'advisories' => 0],
        ]]);

        $this->assertSame(4, $totals['checked']);
        $this->assertSame(2, $totals['audited']);
        $this->assertSame(1, $totals['vulnerable']);
        $this->assertSame(1, $totals['empty']);
        $this->assertSame(1, $totals['unmeasurable']);
        $this->assertSame(3, $totals['advisories']);
    }

    /**
     * 감사 도구가 볼 수 없는 동봉 자산 축이 노출되는지 확인합니다.
     *
     * @return void
     */
    public function test_vendored_assets_are_listed(): void
    {
        $rows = $this->invoke('vendoredAssets');

        $this->assertNotEmpty($rows, '동봉 자산이 하나도 열거되지 않았다 — 이 축이 조용히 사라졌다.');

        $libraries = array_column($rows, 'library');

        $this->assertContains('monaco-editor', $libraries);
        $this->assertContains('ckeditor5', $libraries, 'ckeditor5 는 어떤 package.json 에도 없어 이 목록이 유일한 노출 통로다.');
    }

    /**
     * 커맨드가 실제로 실행되고 결과를 표로 내는지 확인합니다.
     *
     * @return void
     */
    public function test_command_runs_and_reports(): void
    {
        $this->artisan('security:audit-dependencies', ['--composer-only' => true])
            ->expectsOutputToContain('의존성 취약점 점검')
            ->expectsOutputToContain('동봉 제3자 자산')
            ->assertExitCode(0);
    }

    /**
     * JSON 출력이 기계 판독 가능한 형태인지 확인합니다.
     *
     * @return void
     */
    public function test_json_output_is_parsable(): void
    {
        $exitCode = Artisan::call('security:audit-dependencies', [
            '--composer-only' => true,
            '--json' => true,
        ]);

        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertArrayHasKey('vendored_assets', $decoded);
        $this->assertArrayHasKey('totals', $decoded);
    }
}
