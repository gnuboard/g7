<?php

namespace Tests\Feature\Upgrades;

use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_2_0\Migrations\PruneStaleBundledFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 7.2.0 업그레이드 — 7.1.x 부모가 번들 디렉토리에 남긴, 7.2.0 에서 지운 파일 정리
 *
 * 7.1.x 의 코어 업데이트(부모 프로세스가 7.1.x 코드로 돈다)는 번들 확장 디렉토리 안의 지워진 파일을 prune 모드에서만
 * 지운다. 그래서 7.2.0 에서 지운 `_bundled` 조각(쇼핑몰 `register-currency-field`, 마케팅 `user-marketing-register` 등)이
 * 남고, 뒤이은 확장 갱신이 `_bundled` 를 활성 디렉토리로 통째로 복사해 옛 조각을 살아 있는 조각으로 다시 등록했다 —
 * 가입 화면에 지워진 문구 키가 그대로 보이고 결제 통화·동의 칸이 두 벌로 나왔다(7.1.6 → 7.2.0 실측).
 *
 * 이 테스트가 잠그는 것:
 *  - 동결 목록에 있고 본문이 이전 배포본과 같은 `_bundled` 파일만 지운다 → 그 뒤 확장 갱신(copyToActive)이 만든
 *    활성 디렉토리에는 새 판 조각만 남는다.
 *  - 본문이 바뀐 파일·목록 밖 파일·사용자 커스텀 확장·활성 디렉토리는 건드리지 않는다.
 *  - 동결 목록은 번들 확장 내부 경로만 담고, 현재 번들에 실재하지 않으며, 7.1.x 태그에서 지운 번들 파일을 빠짐없이 담는다.
 *
 * 시나리오 SSoT: tests/scenarios/upgrade-720-prune-stale-bundled-files.yaml
 */
#[Group('core')]
#[Group('upgrade')]
class Upgrade720PruneStaleBundledFilesTest extends TestCase
{
    /**
     * 동결 목록이 대조하는 7.1.x 태그.
     */
    private const SOURCE_TAGS = ['7.1.0', '7.1.1', '7.1.2', '7.1.3', '7.1.4', '7.1.5', '7.1.6'];

    /**
     * 임시 설치 루트 (base_path 를 흉내 낸다).
     */
    private string $root;

    /**
     * 업그레이드 데이터 파일을 불러오고 임시 설치 루트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 은 AbstractUpgradeStep 이 동적으로 require 하므로 autoload 대상이 아니다.
        require_once base_path('upgrades/data/7.2.0/migrations/11_PruneStaleBundledFiles.php');

        $this->root = storage_path('framework/testing/prune-stale-bundled-720-'.uniqid());
        File::ensureDirectoryExists($this->root);
    }

    /**
     * 임시 설치 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 7.1.6 → 7.2.0 실측 재현: 부모가 남긴 옛 가입 화면 조각이 `_bundled` 에서 지워지고, 이어지는 확장 갱신 뒤 활성
     * 디렉토리에는 새 판 조각만 남는다. 코어 스텝은 아직 갱신 전인 활성 디렉토리를 건드리지 않는다.
     *
     * @scenario case=register_fragments_7_1_6
     *
     * @effects stale_bundled_fragment_removed, update_copies_only_head_fragments, active_dir_untouched_by_core_step
     *
     * @return void 반환값 없음
     */
    public function test_stale_register_fragments_are_removed_so_update_registers_only_head_fragments(): void
    {
        $marketing = 'plugins/_bundled/sirsoft-marketing/resources/extensions';
        $ecommerce = 'modules/_bundled/sirsoft-ecommerce/resources/extensions';
        $headMarketing = $this->seedHeadFragments('plugins', 'sirsoft-marketing');
        $headEcommerce = $this->seedHeadFragments('modules', 'sirsoft-ecommerce');

        // 7.1.x 부모의 증분 적용이 남긴 상태 — 새 판 위에 7.1.6 판 옛 조각이 그대로 있다.
        $this->writeFile("{$marketing}/user-marketing-register.json", $this->fixture('marketing_user-marketing-register.7.1.6.json'));
        $this->writeFile("{$ecommerce}/register-currency-field.json", $this->fixture('ecommerce_register-currency-field.7.1.6.json'));

        // 아직 갱신 전인 활성 디렉토리 (7.1.6 판이 쓰는 파일)
        $active = 'plugins/sirsoft-marketing/resources/extensions/user-marketing-register.json';
        $this->writeFile($active, $this->fixture('marketing_user-marketing-register.7.1.6.json'));

        // 하한 — 재현 전제: 정리 전에는 갱신이 옛 조각까지 활성 디렉토리로 옮긴다.
        $this->assertContains('user-marketing-register', $this->fragmentKeysAfterUpdate('plugins', 'sirsoft-marketing', copy: false));
        $this->assertContains('register-currency-field', $this->fragmentKeysAfterUpdate('modules', 'sirsoft-ecommerce', copy: false));

        $this->runPrune();

        $this->assertFileDoesNotExist($this->path("{$marketing}/user-marketing-register.json"), '7.1.6 판 마케팅 가입 조각이 _bundled 에 남았다');
        $this->assertFileDoesNotExist($this->path("{$ecommerce}/register-currency-field.json"), '7.1.6 판 결제 통화 가입 조각이 _bundled 에 남았다');
        $this->assertFileExists($this->path($active), '코어 스텝이 아직 갱신 전인 활성 디렉토리 파일을 지웠다');

        // 확장 갱신 = _bundled → 활성 디렉토리 원자 교체. 등록은 활성 디렉토리의 `resources/extensions/*.json` glob 이다.
        $this->assertSame($headMarketing, $this->fragmentKeysAfterUpdate('plugins', 'sirsoft-marketing'));
        $this->assertSame($headEcommerce, $this->fragmentKeysAfterUpdate('modules', 'sirsoft-ecommerce'));
        $this->assertFileDoesNotExist($this->path($active), '갱신 뒤 활성 디렉토리에 옛 조각이 남았다');
    }

    /**
     * 본문이 이전 배포본과 다른 파일·목록 밖 파일·사용자 커스텀 확장은 지우지 않고, 바뀐 파일은 경고로 남긴다.
     *
     * @scenario case=guarded_files
     *
     * @effects modified_file_kept_with_warning, unlisted_and_custom_files_kept
     *
     * @return void 반환값 없음
     */
    public function test_modified_unlisted_and_custom_files_are_kept(): void
    {
        $listed = 'plugins/_bundled/sirsoft-sample/resources/extensions/old.json';
        $modified = 'plugins/_bundled/sirsoft-sample/resources/extensions/edited.json';
        $unlisted = 'plugins/_bundled/sirsoft-sample/resources/extensions/live.json';
        $custom = 'plugins/_bundled/acme-custom/resources/extensions/old.json';

        $this->writeFile($listed, "{\"v\":1}\n");
        $this->writeFile($modified, "{\"v\":1,\"edited\":true}\n");
        $this->writeFile($unlisted, "{\"v\":1}\n");
        $this->writeFile($custom, "{\"v\":1}\n");

        $logger = $this->recordingLogger();
        Log::shouldReceive('channel')->with('upgrade')->andReturn($logger);

        $this->runPrune([
            $listed => [$this->hashOf("{\"v\":1}\n")],
            $modified => [$this->hashOf("{\"v\":1}\n")],
        ]);

        $this->assertFileDoesNotExist($this->path($listed));
        $this->assertFileExists($this->path($modified), '본문이 바뀐 파일을 지웠다 — 해시 가드가 없다');
        $this->assertFileExists($this->path($unlisted), '목록 밖 파일을 지웠다');
        $this->assertFileExists($this->path($custom), '사용자 커스텀 확장 파일을 지웠다');

        $warnings = array_filter($logger->records, static fn (array $r): bool => $r[0] === 'warning' && str_contains($r[1].json_encode($r[2]), 'edited.json'));
        $this->assertNotEmpty($warnings, '본문이 바뀌어 남긴 파일을 경고로 알리지 않았다');
    }

    /**
     * CRLF 로 체크아웃된 파일도 배포본으로 인식하고, 비게 된 하위 디렉토리는 지우되 확장 루트는 둔다. 두 번째 실행은
     * 아무것도 바꾸지 않는다.
     *
     * @scenario case=line_endings_and_rerun
     *
     * @effects crlf_checkout_recognized, emptied_subdirectories_pruned, extension_root_kept, prune_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_crlf_and_empty_directories_and_rerun(): void
    {
        $deep = 'templates/_bundled/sirsoft-sample/layouts/partials/admin_module_list/_modal_deactivate_warning.json';
        $sibling = 'templates/_bundled/sirsoft-sample/layouts/home.json';
        $this->writeFile($deep, "{\r\n    \"v\": 1\r\n}\r\n");
        $this->writeFile($sibling, "{}\n");

        $manifest = [$deep => [$this->hashOf("{\n    \"v\": 1\n}\n")]];
        $this->runPrune($manifest);

        $this->assertFileDoesNotExist($this->path($deep), 'CRLF 체크아웃을 배포본으로 인식하지 못했다');
        $this->assertDirectoryDoesNotExist($this->path('templates/_bundled/sirsoft-sample/layouts/partials'), '비게 된 디렉토리가 남았다');
        $this->assertFileExists($this->path($sibling));
        $this->assertDirectoryExists($this->path('templates/_bundled/sirsoft-sample'));

        $this->runPrune($manifest);

        $this->assertFileExists($this->path($sibling), '재실행이 다른 파일을 건드렸다');
    }

    /**
     * 확장 소스 폴더(`src/`)의 PHP 클래스 파일은 목록·해시가 맞아도 남긴다 — 확장 갱신이 옮겨 심는 옛 `vendor/composer`
     * 클래스맵과 갱신 끝에야 다시 굽는 훅 캐시가 그 클래스를 가리키는 동안 파일이 없으면 요청이 500 이 된다(
     * 쇼핑몰 `UserPreferenceCoreSaveListener` include 실패). 같은 확장의 화면 조각과 시험 PHP 파일은 지운다.
     *
     * @scenario case=class_files_retained
     *
     * @effects src_php_class_files_retained, non_class_files_still_pruned
     *
     * @return void 반환값 없음
     */
    public function test_src_php_class_files_are_retained_while_fragments_are_pruned(): void
    {
        $listener = 'modules/_bundled/sirsoft-ecommerce/src/Listeners/UserPreferenceCoreSaveListener.php';
        $fragment = 'modules/_bundled/sirsoft-ecommerce/resources/extensions/register-currency-field.json';
        $testFile = 'plugins/_bundled/sirsoft-pay_kginicis/tests/Unit/Listeners/RestoreLayoutExtensionsAfterUpdateListenerTest.php';
        $body = "<?php\n// 7.1.6\n";

        $this->writeFile($listener, $body);
        $this->writeFile($fragment, "{}\n");
        $this->writeFile($testFile, $body);

        $this->runPrune([
            $listener => [$this->hashOf($body)],
            $fragment => [$this->hashOf("{}\n")],
            $testFile => [$this->hashOf($body)],
        ]);

        $this->assertFileExists($this->path($listener), '옛 클래스맵·훅 캐시가 가리키는 클래스 파일을 확장 갱신 전에 지웠다');
        $this->assertFileDoesNotExist($this->path($fragment), '화면 조각은 여전히 지워야 한다');
        $this->assertFileDoesNotExist($this->path($testFile), '시험 PHP 파일은 클래스맵 대상이 아니라 지운다');
    }

    /**
     * 동결 목록: 모든 경로가 번들 확장 내부이고 현재 번들에 실재하지 않으며, 실측된 옛 조각 10종을 담는다.
     *
     * @scenario case=frozen_manifest
     *
     * @effects manifest_paths_inside_bundled_extensions, manifest_paths_absent_from_current_bundle
     *
     * @return void 반환값 없음
     */
    public function test_frozen_manifest_targets_only_removed_bundled_files(): void
    {
        $files = $this->frozenFiles();

        $this->assertNotEmpty($files);

        foreach ($files as $relative => $hashes) {
            $this->assertMatchesRegularExpression('#^(modules|plugins|templates|lang-packs)/_bundled/[a-z0-9][a-z0-9_-]*-[a-z0-9_-]+/.+#', $relative, "번들 확장 내부 경로가 아니다: {$relative}");
            $this->assertStringNotContainsString('..', $relative);
            $this->assertFileDoesNotExist(base_path($relative), "현재 번들에 있는 파일이 정리 목록에 있다 — 살아 있는 파일을 지운다: {$relative}");
            $this->assertNotEmpty($hashes);
            foreach ($hashes as $hash) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
            }
        }

        foreach ([
            'modules/_bundled/sirsoft-ecommerce/resources/extensions/register-currency-field.json',
            'modules/_bundled/sirsoft-ecommerce/resources/extensions/register-shipping-country-field.json',
            'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin-user-currency-field.json',
            'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin-user-shipping-country-field.json',
            'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-register.json',
            'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-form.json',
            'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-profile.json',
            'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-profile-view.json',
            'modules/_bundled/sirsoft-board/resources/extensions/user-notification-settings.json',
            'modules/_bundled/sirsoft-board/resources/extensions/user-notification-detail.json',
        ] as $expected) {
            $this->assertArrayHasKey($expected, $files);
        }

        // 고정물이 동결 목록이 허용하는 7.1.6 본문인지 — 아니면 위 재현 시험이 다른 본문으로 초록이 된다.
        $this->assertContains(
            $this->hashOf($this->fixture('marketing_user-marketing-register.7.1.6.json')),
            $files['plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-register.json']
        );
        $this->assertContains(
            $this->hashOf($this->fixture('ecommerce_register-currency-field.7.1.6.json')),
            $files['modules/_bundled/sirsoft-ecommerce/resources/extensions/register-currency-field.json']
        );
    }

    /**
     * 동결 목록이 7.1.x 태그의 번들에 있었고 지금 번들에 없는 파일을 빠짐없이 담는다 — 출시 전 번들 파일을 더 지우고
     * 목록을 다시 산출하지 않으면 여기서 잡힌다. 태그를 읽을 수 없는 복제본에서는 건너뛴다.
     *
     * @scenario case=frozen_manifest
     *
     * @effects manifest_covers_every_removed_bundled_file
     *
     * @return void 반환값 없음
     */
    public function test_frozen_manifest_covers_every_bundled_file_removed_since_7_1_tags(): void
    {
        $files = $this->frozenFiles();
        $missing = [];

        foreach (self::SOURCE_TAGS as $tag) {
            $process = new Process(['git', 'ls-tree', '-r', '--full-tree', $tag, '--', 'modules/_bundled', 'plugins/_bundled', 'templates/_bundled', 'lang-packs/_bundled'], base_path());
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->markTestSkipped($tag.' 태그를 읽을 수 없는 복제본이다');
            }

            foreach (preg_split('~\r\n|\r|\n~', trim($process->getOutput())) ?: [] as $line) {
                if ($line === '' || ! str_contains($line, "\t")) {
                    continue;
                }

                [$meta, $relative] = explode("\t", $line, 2);
                [$mode, $type] = explode(' ', $meta);

                // 정리 대상은 번들 확장 디렉토리 내부뿐이다 — `_bundled` 바로 아래 파일은 스텝이 건드리지 않는다.
                if ($type !== 'blob' || $mode === '120000' || ! preg_match('#^(modules|plugins|templates|lang-packs)/_bundled/[a-z0-9][a-z0-9_-]*-[a-z0-9_-]+/.+#', $relative) || file_exists(base_path($relative))) {
                    continue;
                }

                if (! isset($files[$relative])) {
                    $missing[$relative] = $tag;
                }
            }
        }

        $this->assertSame([], $missing, '7.1.x 태그 번들에 있었고 지금 번들에 없는 파일이 동결 목록에 없다 — stale_bundled_files.snapshot.json 을 다시 산출할 것');
    }

    /**
     * 동결 목록의 파일 표를 읽습니다.
     *
     * @return array<string, array<int, string>> 경로 → 허용 해시 목록
     */
    private function frozenFiles(): array
    {
        $manifest = json_decode((string) file_get_contents(base_path('upgrades/data/7.2.0/stale_bundled_files.snapshot.json')), true);

        return is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
    }

    /**
     * 기록만 하는 로거를 만듭니다.
     *
     * @return AbstractLogger 기록 로거 (`records` 속성에 [레벨, 메시지, 문맥] 으로 쌓인다)
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger
        {
            /** @var array<int, array{0: string, 1: string, 2: array<mixed>}> */
            public array $records = [];

            /**
             * 로그 한 건을 기록합니다.
             *
             * @param  mixed  $level  레벨
             * @param  string|\Stringable  $message  메시지
             * @param  array<mixed>  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
    }

    /**
     * 정리 마이그레이션을 임시 설치 루트에서 실행합니다.
     *
     * @param  array<string, array<int, string>>|null  $manifest  주입할 목록 (null 이면 동결 목록)
     * @return void 반환값 없음
     */
    private function runPrune(?array $manifest = null): void
    {
        (new PruneStaleBundledFiles($this->root, $manifest))->run(new UpgradeContext('7.1.6', '7.2.0', '7.2.0'));
    }

    /**
     * 저장소의 새 판 조각 파일을 임시 `_bundled` 로 옮기고 그 조각 키 목록을 돌려줍니다.
     *
     * @param  string  $type  `modules` 또는 `plugins`
     * @param  string  $identifier  확장 식별자
     * @return array<int, string> 정렬된 조각 키
     */
    private function seedHeadFragments(string $type, string $identifier): array
    {
        $from = base_path("{$type}/_bundled/{$identifier}/resources/extensions");
        $to = $this->path("{$type}/_bundled/{$identifier}/resources/extensions");
        File::copyDirectory($from, $to);

        return $this->keysOf($from);
    }

    /**
     * `_bundled` → 활성 디렉토리 갱신 뒤 등록 대상이 될 조각 키를 돌려줍니다.
     *
     * @param  string  $type  `modules` 또는 `plugins`
     * @param  string  $identifier  확장 식별자
     * @param  bool  $copy  false 면 갱신 없이 `_bundled` 의 키를 본다 (갱신이 그대로 옮길 목록)
     * @return array<int, string> 정렬된 조각 키
     */
    private function fragmentKeysAfterUpdate(string $type, string $identifier, bool $copy = true): array
    {
        $bundled = $this->path("{$type}/_bundled/{$identifier}");

        if (! $copy) {
            return $this->keysOf($bundled.'/resources/extensions');
        }

        $active = $this->path("{$type}/{$identifier}");
        ExtensionPendingHelper::copyToActive($bundled, $active);

        return $this->keysOf($active.'/resources/extensions');
    }

    /**
     * 디렉토리의 `*.json` 조각 키(파일 이름)를 정렬해 돌려줍니다 — 확장의 `getLayoutExtensions()` 와 같은 glob.
     *
     * @param  string  $dir  조각 디렉토리
     * @return array<int, string> 정렬된 조각 키
     */
    private function keysOf(string $dir): array
    {
        $keys = array_map(static fn (string $file): string => basename($file, '.json'), glob($dir.'/*.json') ?: []);
        sort($keys);

        return $keys;
    }

    /**
     * 임시 설치 루트 아래에 파일을 씁니다.
     *
     * @param  string  $relative  설치 루트 기준 상대 경로
     * @param  string  $content  본문
     * @return void 반환값 없음
     */
    private function writeFile(string $relative, string $content): void
    {
        File::ensureDirectoryExists(dirname($this->path($relative)));
        File::put($this->path($relative), $content);
    }

    /**
     * 임시 설치 루트 기준 절대 경로를 돌려줍니다.
     *
     * @param  string  $relative  상대 경로
     * @return string 절대 경로
     */
    private function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    /**
     * 7.1.6 판 고정물 본문을 읽습니다.
     *
     * @param  string  $name  고정물 파일 이름
     * @return string 본문
     */
    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Upgrades/V7_2_0/'.$name));
    }

    /**
     * 동결 목록과 같은 방식(LF 정규화 sha256)으로 본문 해시를 냅니다.
     *
     * @param  string  $content  본문
     * @return string 해시
     */
    private function hashOf(string $content): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }
}
