<?php

namespace Tests\Feature\Extension;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\DependencyVersionWarnings;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 갱신 경로의 의존 버전 검사 — 부족하면 경고·보고, 거부하지 않는다 (7.1.0 출시 점검 Q-6 결정).
 *
 * 의존성 검사는 설치 때만 돌아, 이커머스 1.2.1 위에 1.3.0 을 요구하는 결제 플러그인을 올려도 아무 말이 없었다(PU-2 의
 * 근본 원인). 갱신은 그대로 진행하되, 새 판 manifest 가 요구하는 의존 확장이 없거나 꺼져 있거나 버전이 모자라면
 * 결과의 `dependency_warnings` 와 완료 문구로 알린다. 번들 일괄 업데이트는 같은 종류 안에서도 의존하는 쪽을 뒤에 둔다.
 */
class UpdateDependencyVersionWarningTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 정리할 임시 파일 */
    private array $tmpFiles = [];

    /**
     * 임시 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            File::delete($path);
        }

        parent::tearDown();
    }

    /**
     * 의존 확장 버전이 모자라면 그 확장·요구 버전·설치 버전을 담은 경고 한 건을 낸다.
     *
     * @scenario dependency_state=version_unmet, extension_type=plugin
     *
     * @effects unmet_dependency_version_is_reported_as_warning
     *
     * @return void 반환값 없음
     */
    public function test_version_unmet_dependency_is_reported(): void
    {
        Module::factory()->create(['identifier' => 'acme-shop', 'version' => '1.2.1', 'status' => ExtensionStatus::Active->value]);

        $warnings = DependencyVersionWarnings::forDependencies(['modules' => ['acme-shop' => '>=1.3.0'], 'plugins' => []]);

        $this->assertCount(1, $warnings);
        $this->assertSame('acme-shop', $warnings[0]['identifier']);
        $this->assertSame('module', $warnings[0]['type']);
        $this->assertSame('>=1.3.0', $warnings[0]['required_version']);
        $this->assertSame('1.2.1', $warnings[0]['installed_version']);
        $this->assertStringContainsString('1.2.1', $warnings[0]['message']);
        $this->assertStringContainsString('>=1.3.0', $warnings[0]['message']);
    }

    /**
     * 의존 확장이 없거나 꺼져 있어도 경고하고, 충족되면 아무것도 내지 않는다.
     *
     * @scenario dependency_state=not_installed_or_inactive, extension_type=template
     *
     * @effects missing_or_inactive_dependency_is_reported, satisfied_dependency_yields_no_warning
     *
     * @return void 반환값 없음
     */
    public function test_missing_inactive_and_satisfied_dependencies(): void
    {
        Module::factory()->create(['identifier' => 'acme-board', 'version' => '1.2.0', 'status' => ExtensionStatus::Active->value]);
        Plugin::factory()->create(['identifier' => 'acme-postcode', 'version' => '1.1.0', 'status' => ExtensionStatus::Inactive->value]);

        $warnings = DependencyVersionWarnings::forDependencies([
            'modules' => ['acme-board' => '>=1.2.0', 'acme-missing' => '>=1.0.0'],
            'plugins' => ['acme-postcode' => '>=1.0.0'],
        ]);

        $this->assertSame(['acme-missing', 'acme-postcode'], array_column($warnings, 'identifier'), '충족된 acme-board 는 빠지고 미설치·비활성만 남아야 합니다.');
        $this->assertNull($warnings[0]['installed_version']);
        $this->assertFalse($warnings[1]['is_active']);
    }

    /**
     * 갱신된 manifest 파일을 읽어 검사하고, 읽을 수 없는 파일은 조용히 빈 목록 — 경고 수집이 업데이트를 깨뜨리지 않는다.
     *
     * @scenario dependency_state=unreadable_manifest, extension_type=module
     *
     * @effects unreadable_manifest_yields_no_warning
     *
     * @return void 반환값 없음
     */
    public function test_reads_manifest_file_and_tolerates_broken_file(): void
    {
        Module::factory()->create(['identifier' => 'acme-shop', 'version' => '1.0.0', 'status' => ExtensionStatus::Active->value]);

        $ok = $this->tmp(json_encode(['identifier' => 'acme-pay', 'dependencies' => ['modules' => ['acme-shop' => '>=2.0.0']]]));
        $broken = $this->tmp('{not json');

        $this->assertCount(1, DependencyVersionWarnings::forManifestFile($ok));
        $this->assertSame([], DependencyVersionWarnings::forManifestFile($broken));
        $this->assertSame([], DependencyVersionWarnings::forManifestFile($ok.'.absent'));
    }

    /**
     * 완료 문구에 경고를 이어 붙인다 — 관리자 업데이트 창은 서버가 보낸 완료 문구를 그대로 보여 준다.
     *
     * @scenario dependency_state=version_unmet, extension_type=module
     *
     * @effects update_message_carries_dependency_warning
     *
     * @return void 반환값 없음
     */
    public function test_warning_is_appended_to_completion_message(): void
    {
        $warnings = [['message' => 'W1'], ['message' => 'W2']];

        $message = DependencyVersionWarnings::appendToMessage('완료', $warnings);

        $this->assertStringStartsWith('완료', $message);
        $this->assertStringContainsString('W1', $message);
        $this->assertStringContainsString('W2', $message);
        $this->assertSame('완료', DependencyVersionWarnings::appendToMessage('완료', []));
    }

    // 세 매니저(모듈·플러그인·템플릿)를 한 시험이 함께 잰다.
    // @scenario dependency_state=version_unmet, extension_type=template

    /**
     * 모듈·플러그인·템플릿 갱신 결과가 경고를 싣는다 — 세 매니저 모두 같은 헬퍼를 성공 결과에서 부른다.
     *
     * @scenario dependency_state=version_unmet, extension_type=all
     *
     * @effects update_result_includes_dependency_warnings
     *
     * @return void 반환값 없음
     */
    public function test_update_results_include_dependency_warnings(): void
    {
        foreach ([
            'app/Extension/ModuleManager.php' => 'updateModule',
            'app/Extension/PluginManager.php' => 'updatePlugin',
            'app/Extension/TemplateManager.php' => 'updateTemplate',
        ] as $file => $method) {
            $source = file_get_contents(base_path($file));
            $start = strpos($source, "public function {$method}(");
            $this->assertNotFalse($start, "{$method}() 를 찾지 못했습니다.");
            $next = strpos($source, "\n    public function ", $start + 10);
            $body = substr($source, $start, ($next === false ? strlen($source) : $next) - $start);

            $this->assertStringContainsString("'dependency_warnings' =>", $body, "{$method}() 결과에 dependency_warnings 가 없습니다.");
            $this->assertStringContainsString('DependencyVersionWarnings::', $body, "{$method}() 가 의존 버전 검사를 하지 않습니다.");
        }
    }

    /**
     * 번들 일괄 업데이트는 같은 종류 안에서도 의존 대상을 먼저 갱신한다 — 순환·미지 의존이 있어도 항목을 잃지 않는다.
     *
     * @scenario dependency_state=bundled_order, extension_type=module
     *
     * @effects bundled_update_orders_dependencies_first_within_type
     *
     * @return void 반환값 없음
     */
    public function test_bundled_entries_are_ordered_dependencies_first(): void
    {
        $entries = [
            ['identifier' => 'c', 'strategy' => 'keep'],
            ['identifier' => 'a', 'strategy' => 'overwrite'],
            ['identifier' => 'b', 'strategy' => 'overwrite'],
            ['identifier' => 'x', 'strategy' => 'overwrite'],
            ['identifier' => 'y', 'strategy' => 'overwrite'],
        ];
        $deps = ['c' => ['b'], 'b' => ['a', 'zzz-not-in-list'], 'x' => ['y'], 'y' => ['x']];

        $sorted = ExecuteBundledUpdatesCommand::orderByDependencies($entries, fn (string $id) => $deps[$id] ?? []);
        $ids = array_column($sorted, 'identifier');

        $this->assertCount(5, $sorted, '정렬이 항목을 잃으면 안 됩니다.');
        $this->assertLessThan(array_search('b', $ids, true), array_search('a', $ids, true));
        $this->assertLessThan(array_search('c', $ids, true), array_search('b', $ids, true));
        $this->assertSame('keep', $sorted[array_search('c', $ids, true)]['strategy'], '항목 내용(전략)은 그대로여야 합니다.');
        $this->assertContains('x', $ids);
        $this->assertContains('y', $ids);
    }

    /**
     * 임시 manifest 파일을 만듭니다.
     *
     * @param  string  $content  파일 내용
     * @return string 파일 경로
     */
    private function tmp(string $content): string
    {
        $path = storage_path('framework/testing/dep_manifest_'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }
}
