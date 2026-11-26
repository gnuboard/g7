<?php

namespace Tests\Feature\Console\Commands\Extension;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\Cache\CacheDriverFactory;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Models\LanguagePack;
use App\Models\Module;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `extension:sync-files` — 같은 판인데 파일이 다른 확장의 활성 폴더만 번들 내용으로 맞춘다.
 *
 * 판 번호를 올리지 않은 번들 변경이나 DB 를 함께 쓰는 두 번째 서버에서 활성 폴더가 옛 파일에 머물렀다. 종전 수단
 * (`module:update --force`)은 같은 판 스텝 재실행·호환 검사 생략이 함께 묶여 있었다. 판정은 내용 해시(상대경로 정렬 +
 * sha256)이며 판이 다르면 대상이 아니다. 이 시험은 실재하지 않는 시험용 식별자의 폴더만 만들고 지운다.
 *
 * @scenario-manifest tests/scenarios/extension-sync-files.yaml
 */
class SyncExtensionFilesCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** 시험용 모듈 식별자 */
    private const ID = 'acme-syncprobe';

    /** 시험용 언어팩 식별자 */
    private const PACK_ID = 'acme-syncprobe-zz';

    /**
     * 시험 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('modules/'.self::ID));
        File::deleteDirectory(base_path('modules/_bundled/'.self::ID));
        File::deleteDirectory(base_path('lang-packs/'.self::PACK_ID));
        File::deleteDirectory(base_path('lang-packs/_bundled/'.self::PACK_ID));

        parent::tearDown();
    }

    /**
     * 언어팩 설치 행과 활성·번들 폴더를 만듭니다.
     *
     * @param  string  $installed  DB 판
     * @param  string  $bundled  번들 manifest 판
     * @param  string  $activeBody  활성 폴더 번역 파일 내용
     * @param  string  $bundledBody  번들 폴더 번역 파일 내용
     * @return void 반환값 없음
     */
    private function preparePack(string $installed, string $bundled, string $activeBody, string $bundledBody): void
    {
        LanguagePack::query()->create([
            'identifier' => self::PACK_ID,
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'zz',
            'locale_name' => 'Probe',
            'locale_native_name' => 'Probe',
            'text_direction' => 'ltr',
            'version' => $installed,
            'status' => LanguagePackStatus::Installed->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'bundled',
        ]);
        foreach ([['lang-packs/'.self::PACK_ID, $installed, $activeBody], ['lang-packs/_bundled/'.self::PACK_ID, $bundled, $bundledBody]] as [$dir, $version, $body]) {
            File::ensureDirectoryExists(base_path($dir.'/backend/zz'));
            File::put(base_path($dir.'/language-pack.json'), (string) json_encode(['identifier' => self::PACK_ID, 'version' => $version, 'scope' => 'core', 'locale' => 'zz']));
            File::put(base_path($dir.'/backend/zz/probe.php'), $body);
        }
    }

    /**
     * 언어팩 대상으로 명령을 실행하고 JSON 결과를 돌려줍니다.
     *
     * @param  array<string, mixed>  $options  옵션 (`--type` 을 주지 않으면 종류를 정하지 않고 실행)
     * @return array{0: int, 1: array<string, mixed>} [종료 코드, 결과]
     */
    private function runPackSync(array $options = []): array
    {
        $buffer = new BufferedOutput;
        $code = Artisan::call('extension:reset-files-from-bundled', ['identifier' => self::PACK_ID, '--json' => true] + $options, $buffer);
        $output = trim($buffer->fetch());
        $start = strpos($output, '{');

        return [$code, $start === false ? [] : (array) json_decode(substr($output, $start), true)];
    }

    /**
     * 설치 행과 활성·번들 폴더를 만듭니다.
     *
     * @param  string  $installed  DB 판
     * @param  string  $bundled  번들 manifest 판
     * @param  string  $activeBody  활성 폴더 파일 내용
     * @param  string  $bundledBody  번들 폴더 파일 내용
     * @return void 반환값 없음
     */
    private function prepare(string $installed, string $bundled, string $activeBody, string $bundledBody): void
    {
        Module::factory()->create(['identifier' => self::ID, 'version' => $installed]);
        foreach ([['modules/'.self::ID, $installed, $activeBody], ['modules/_bundled/'.self::ID, $bundled, $bundledBody]] as [$dir, $version, $body]) {
            File::ensureDirectoryExists(base_path($dir.'/src'));
            File::put(base_path($dir.'/module.json'), (string) json_encode(['identifier' => self::ID, 'version' => $version]));
            File::put(base_path($dir.'/src/Probe.php'), $body);
        }
        // 운영자 자리(custom/)는 해시·교체에서 빠진다
        File::ensureDirectoryExists(base_path('modules/'.self::ID.'/custom'));
        File::put(base_path('modules/'.self::ID.'/custom/site.css'), 'body{}');
    }

    /**
     * 명령을 실행하고 JSON 결과를 돌려줍니다.
     *
     * @param  array<string, mixed>  $options  옵션
     * @return array{0: int, 1: array<string, mixed>} [종료 코드, 결과]
     */
    private function runSync(array $options = []): array
    {
        // 명령 안에서 다른 artisan 명령(route:clear 등)을 부르면 Artisan::output() 이 그 출력으로 바뀐다 — 버퍼를 따로 준다
        $buffer = new BufferedOutput;
        $code = Artisan::call('extension:reset-files-from-bundled', ['identifier' => self::ID, '--type' => 'module', '--json' => true] + $options, $buffer);
        $output = trim($buffer->fetch());

        return [$code, (array) json_decode(substr($output, (int) strpos($output, '{')), true)];
    }

    /**
     * 같은 판·다른 내용이면 활성 폴더를 번들 내용으로 바꾸고, 운영자 `custom/` 은 남긴다.
     *
     * @scenario case=same_version_differs
     *
     * @effects sync_replaces_active_files_only_when_same_version_differs
     *
     * @return void 반환값 없음
     */
    public function test_same_version_with_different_files_is_replaced(): void
    {
        $this->prepare('1.0.0', '1.0.0', 'old', 'new');

        [$code, $result] = $this->runSync();

        $this->assertSame(0, $code);
        $this->assertSame(1, $result['synced']);
        $this->assertSame('new', File::get(base_path('modules/'.self::ID.'/src/Probe.php')));
        $this->assertTrue(File::exists(base_path('modules/'.self::ID.'/custom/site.css')), '운영자 custom/ 은 보존');
        $this->assertSame('1.0.0', Module::query()->where('identifier', self::ID)->value('version'), '판 번호는 그대로');
    }

    /**
     * 같은 내용이면 바꾸지 않는다.
     *
     * @scenario case=same_version_same_content
     *
     * @effects sync_replaces_active_files_only_when_same_version_differs
     *
     * @return void 반환값 없음
     */
    public function test_same_content_is_left_alone(): void
    {
        $this->prepare('1.0.0', '1.0.0', 'same', 'same');

        [$code, $result] = $this->runSync();

        $this->assertSame(0, $code);
        $this->assertSame(0, $result['synced']);
        $this->assertSame('in_sync', $result['items'][0]['status']);
    }

    /**
     * 판이 다르면 대상이 아니고(업데이트 안내), 요청한 일을 하지 않았으므로 1 이다.
     *
     * @scenario case=version_differs
     *
     * @effects version_mismatch_is_not_a_sync_target
     *
     * @return void 반환값 없음
     */
    public function test_different_version_is_not_a_target(): void
    {
        $this->prepare('1.0.0', '1.0.1', 'old', 'new');

        [$code, $result] = $this->runSync();

        $this->assertSame(1, $code);
        $this->assertSame('version_differs', $result['items'][0]['status']);
        $this->assertSame('old', File::get(base_path('modules/'.self::ID.'/src/Probe.php')));
    }

    /**
     * 미리보기는 판정만 하고 파일을 바꾸지 않는다.
     *
     * @scenario case=dry_run
     *
     * @effects dry_run_changes_no_files
     *
     * @return void 반환값 없음
     */
    public function test_dry_run_changes_nothing(): void
    {
        $this->prepare('1.0.0', '1.0.0', 'old', 'new');

        [$code, $result] = $this->runSync(['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('differs', $result['items'][0]['status']);
        $this->assertSame('old', File::get(base_path('modules/'.self::ID.'/src/Probe.php')));
    }

    /**
     * 같은 확장의 다른 작업이 잠금을 쥐고 있으면 바꾸지 않고 1 이다.
     *
     * @scenario case=lock_contended
     *
     * @effects sync_respects_extension_operation_lock
     *
     * @return void 반환값 없음
     */
    public function test_lock_contention_fails(): void
    {
        $this->prepare('1.0.0', '1.0.0', 'old', 'new');
        // 다른 프로세스가 쥔 잠금 — 이 프로세스의 재진입 기록을 거치지 않고 저장소에서 직접 잡는다
        $held = Cache::lock(ExtensionOperationLock::key('module', self::ID), 60);
        $this->assertTrue($held->get());

        try {
            [$code, $result] = $this->runSync();
        } finally {
            $held->forceRelease();
        }

        $this->assertSame(1, $code);
        $this->assertSame('locked', $result['items'][0]['status']);
        $this->assertSame('old', File::get(base_path('modules/'.self::ID.'/src/Probe.php')));
    }

    /**
     * 사람이 읽는 출력은 바꾸기 전에 방향(번들 → 활성 폴더)과 교체 대상 수를 먼저 알린다.
     *
     * 종전 이름 `sync-files` 는 방향이 없어 「활성 폴더를 번들로 올린다」로도 읽혔고, 확인·백업 없이 활성 폴더를 덮어써
     * 손으로 고친 파일이 사라졌다.
     *
     * @scenario case=direction_notice
     *
     * @effects reset_files_announces_direction_and_targets_before_replacing
     *
     * @return void 반환값 없음
     */
    public function test_text_output_announces_direction_and_targets_before_replacing(): void
    {
        $this->prepare('1.0.0', '1.0.0', 'old', 'new');

        $buffer = new BufferedOutput;
        $code = Artisan::call('extension:reset-files-from-bundled', ['identifier' => self::ID, '--type' => 'module'], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $code);
        $direction = strpos($output, __('extensions.commands.sync_files.direction'));
        $targets = strpos($output, __('extensions.commands.sync_files.targets', ['count' => 1]));
        $summary = strpos($output, __('extensions.commands.sync_files.summary', ['synced' => 1, 'failed' => 0]));
        $this->assertNotFalse($direction, '방향 안내가 있어야 한다');
        $this->assertNotFalse($targets, '교체 대상 수 안내가 있어야 한다');
        $this->assertNotFalse($summary);
        $this->assertLessThan($summary, $direction, '안내는 결과 요약보다 먼저 나온다');
    }

    /**
     * 종전 이름 `extension:sync-files` 도 같은 동작을 한다(하위 호환) — 사람이 읽는 출력에는 새 이름을 쓰라는 안내를, `--json` 에는
     * 아무것도 섞지 않는다(스크립트가 JSON 을 파싱한다).
     *
     * @scenario case=legacy_name
     *
     * @effects legacy_sync_files_name_keeps_working
     *
     * @return void 반환값 없음
     */
    public function test_legacy_sync_files_name_still_works(): void
    {
        $this->prepare('1.0.0', '1.0.0', 'old', 'new');

        $buffer = new BufferedOutput;
        $code = Artisan::call('extension:sync-files', ['identifier' => self::ID, '--type' => 'module', '--json' => true], $buffer);
        $json = json_decode(trim($buffer->fetch()), true);

        $this->assertSame(0, $code);
        $this->assertIsArray($json, '--json 출력은 그대로 JSON 이어야 한다');
        $this->assertSame(1, $json['synced']);
        $this->assertSame('new', File::get(base_path('modules/'.self::ID.'/src/Probe.php')));

        $buffer = new BufferedOutput;
        Artisan::call('extension:sync-files', ['identifier' => self::ID, '--type' => 'module', '--dry-run' => true], $buffer);
        $this->assertStringContainsString(__('extensions.commands.sync_files.legacy_name'), $buffer->fetch());
    }

    /**
     * 언어팩도 같은 판·다른 내용이면 활성 폴더(`lang-packs/<id>`)를 번들 내용으로 바꾼다 — 판 번호·DB 는 그대로, 미리보기는 무변경,
     * 교체 뒤 확장 캐시 버전을 올려 화면의 언어 파일을 새로 받게 한다.
     *
     * 검수 서버가 먼저 언어팩을 새 판으로 올리면 공유 DB 판이 새 판이 되어 운영 서버의 `language-pack:update` 는 「이미 최신」으로
     * 끝나고, 종전 이 명령은 module·plugin·template 만 받아 운영 서버가 새 언어팩 파일을 받을 길이 없었다.
     *
     * @scenario case=language_pack
     *
     * @effects language_pack_active_files_are_reset_from_bundled
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_same_version_files_are_replaced(): void
    {
        $this->preparePack('1.0.0', '1.0.0', 'old', 'new');
        $activeFile = base_path('lang-packs/'.self::PACK_ID.'/backend/zz/probe.php');

        [$code, $result] = $this->runPackSync(['--type' => 'language-pack', '--dry-run' => true]);
        $this->assertSame(0, $code, '언어팩 종류를 받아야 한다');
        $this->assertSame('differs', $result['items'][0]['status'] ?? null);
        $this->assertSame('language-pack', $result['items'][0]['type'] ?? null);
        $this->assertSame('old', File::get($activeFile), '미리보기는 파일을 바꾸지 않는다');

        $versionBefore = CacheDriverFactory::core()->get('ext.cache_version');
        [$code, $result] = $this->runPackSync(['--type' => 'language-pack']);

        $this->assertSame(0, $code);
        $this->assertSame(1, $result['synced'] ?? null);
        $this->assertSame('new', File::get($activeFile));
        $this->assertSame('1.0.0', LanguagePack::query()->where('identifier', self::PACK_ID)->value('version'), '판 번호는 그대로');
        $this->assertNotSame($versionBefore, CacheDriverFactory::core()->get('ext.cache_version'), '교체 뒤 확장 캐시 버전을 올린다');
    }

    /**
     * 종류를 정하지 않고 실행해도 설치된 언어팩을 판정한다 — 전 종류 실행이 언어팩을 빠뜨리지 않는다.
     *
     * @scenario case=language_pack
     *
     * @effects language_pack_active_files_are_reset_from_bundled
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_is_included_without_type(): void
    {
        $this->preparePack('1.0.0', '1.0.0', 'old', 'new');

        [$code, $result] = $this->runPackSync();

        $this->assertSame(0, $code);
        $this->assertSame(1, $result['synced'] ?? null);
        $this->assertSame('new', File::get(base_path('lang-packs/'.self::PACK_ID.'/backend/zz/probe.php')));
    }

    /**
     * 언어팩도 판이 다르면 대상이 아니고 `language-pack:update` 를 안내하며 1 이다.
     *
     * @scenario case=language_pack
     *
     * @effects version_mismatch_is_not_a_sync_target
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_with_different_version_is_not_a_target(): void
    {
        $this->preparePack('1.0.0', '1.0.1', 'old', 'new');

        $buffer = new BufferedOutput;
        $code = Artisan::call('extension:reset-files-from-bundled', ['identifier' => self::PACK_ID, '--type' => 'language-pack'], $buffer);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('php artisan language-pack:update '.self::PACK_ID, $buffer->fetch());
        $this->assertSame('old', File::get(base_path('lang-packs/'.self::PACK_ID.'/backend/zz/probe.php')));
    }
}
