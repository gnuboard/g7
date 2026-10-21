<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 언어팩 설치·재설치·업데이트가 활성 설치 폴더를 새 판으로 바꿀 때, 디렉터리 rename 이 막혀도 활성 폴더를 잃지 않고
 * DB 에 기록한 판과 폴더의 판이 같은지 검증합니다.
 *
 * 2026-10-14 20:03:28 실측: `g7-plugin-sirsoft-pay_nicepayments-ja` 를 번들에서 다시 설치하던 중 `finalizeInstall` 이
 * 활성 폴더를 먼저 지우고(`File::deleteDirectory`) 새 설치본을 옮겼는데(`File::moveDirectory`), 옮기기 결과를 보지
 * 않았다. 옮기기는 실패했고(Windows 에서 다른 프로그램이 폴더 안 파일을 잡고 있으면 지우기가 덜 끝나 같은 이름이
 * 남는다) 새 설치본은 `lang-packs/_pending/5d807708-…` 에 남았는데 설치는 성공으로 기록됐다 — DB 는 active, 활성
 * 폴더는 「파일 없음」 이 되어 일본어 화면의 나이스페이먼츠 문구가 영어로 떨어졌다(2026-10-17 관측).
 *
 * 이제 설치는 확장 폴더 교체와 같은 `ExtensionPendingHelper::copyToActive`(사본 → 옛 폴더 옆으로 rename → 새 폴더 rename,
 * rename 이 막히면 파일 단위 복사·제자리 동기화)로 놓는다. rename 실패는 파일 시스템 목으로 흉내 낸다(운영 체제와 무관하게 재현). 팩 식별자는 공식 팩과 겹치지 않는
 * 가짜 이름(`g7test-core-ja` — 시험 접두 `g7test-`)을 쓴다 — 실제 설치본을 읽지도 쓰지도 않는다.
 *
 * @scenario-manifest tests/scenarios/lang-pack-install-directory-replace.yaml
 */
class LanguagePackInstallDirectoryReplaceTest extends TestCase
{
    use RefreshDatabase;

    /** 시험 팩 식별자 — 공식 팩(`g7-…`)과 겹치지 않는 가짜 이름(코어 스코프 명명 공식 `{vendor}-core-{locale}`). */
    private const IDENTIFIER = 'g7test-core-ja';

    private LanguagePackService $service;

    /** 시험 전에 `lang-packs/_pending/` 에 있던 항목 — 시험이 남긴 것만 정리하기 위한 기준. */
    private array $pendingBefore = [];

    /**
     * 서비스를 준비하고 `_pending` 기준 목록을 기록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(LanguagePackService::class);
        $this->pendingBefore = $this->pendingEntries();
    }

    /**
     * 시험이 만든 번들 픽스처·설치 폴더·`_pending` 잔여를 지웁니다(시험 전부터 있던 `_pending` 항목은 건드리지 않음).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $fs = new Filesystem;
        $fs->deleteDirectory(base_path('lang-packs/_bundled/'.self::IDENTIFIER));
        $fs->deleteDirectory(base_path('lang-packs/'.self::IDENTIFIER));
        foreach ($this->leftoverPendingEntries() as $entry) {
            $fs->deleteDirectory(base_path('lang-packs/_pending/'.$entry));
        }

        parent::tearDown();
    }

    /**
     * 새 설치본을 활성 자리로 옮기는 rename 이 계속 막혀도(새 사본을 파일 감시기가 잡은 경우) 파일 단위 복사로 새 판을 놓는다.
     *
     * 수정 전: 옛 폴더를 먼저 지우고 옮기기 실패를 보지 않아, 예외 없이 DB 만 새 판(1.0.1)으로 바뀌고 활성 폴더는 사라졌다.
     *
     * @scenario case=reinstall_rename_into_active_blocked
     *
     * @effects install_directory_never_lost_on_blocked_rename
     *
     * @return void 반환값 없음
     */
    public function test_blocked_rename_into_active_still_places_new_version(): void
    {
        $this->writeBundledFixture('1.0.0');
        $this->service->installFromBundled(self::IDENTIFIER);
        $this->assertSame('1.0.0', $this->installedManifestVersion(), '전제 조건 — 첫 설치가 활성 폴더에 놓이지 않았다');

        $this->writeBundledFixture('1.0.1');
        $this->blockMoves(fn (string $from, string $to, string $active) => $to === $active);

        $pack = $this->service->installFromBundled(self::IDENTIFIER, force: true);

        $this->assertInstalledAndRecorded('1.0.1', $pack);
    }

    /**
     * 활성 폴더 하위에 열린 핸들이 있어 그 폴더를 옮기는 rename 이 모두 막히면(2026-10-14 실측 조건) 제자리 동기화로 새 판을 놓는다.
     *
     * @scenario case=reinstall_active_tree_locked
     *
     * @effects install_directory_never_lost_on_blocked_rename
     *
     * @return void 반환값 없음
     */
    public function test_locked_active_tree_is_synced_in_place(): void
    {
        $this->writeBundledFixture('1.0.0');
        $this->service->installFromBundled(self::IDENTIFIER);

        $this->writeBundledFixture('1.0.1');
        $this->blockMoves(fn (string $from, string $to, string $active) => $to === $active || $from === $active);

        $pack = $this->service->installFromBundled(self::IDENTIFIER, force: true);

        $this->assertInstalledAndRecorded('1.0.1', $pack);
    }

    /**
     * 처음 설치(옛 설치본 없음)에서도 rename 이 막히면 복사로 놓고, DB 행과 폴더가 함께 생긴다.
     *
     * @scenario case=fresh_install_rename_blocked
     *
     * @effects install_directory_never_lost_on_blocked_rename
     *
     * @return void 반환값 없음
     */
    public function test_fresh_install_with_blocked_rename_places_files(): void
    {
        $this->writeBundledFixture('1.0.0');
        $this->blockMoves(fn (string $from, string $to, string $active) => $to === $active);

        $pack = $this->service->installFromBundled(self::IDENTIFIER);

        $this->assertInstalledAndRecorded('1.0.0', $pack);
    }

    /**
     * 강제 업데이트(`performUpdate(force)`)도 rename 이 막힌 채 새 판을 놓고 활성 상태를 유지한다.
     *
     * 수정 전: 안쪽 설치가 예외 없이 끝나 업데이트는 「성공」 으로 보고 롤백 백업까지 지웠다 — 폴더를 되살릴 길이 없었다.
     *
     * @scenario case=forced_update_active_tree_locked
     *
     * @effects install_directory_never_lost_on_blocked_rename
     *
     * @return void 반환값 없음
     */
    public function test_forced_update_with_locked_active_tree_keeps_files_and_status(): void
    {
        $this->writeBundledFixture('1.0.0');
        $installed = $this->service->installFromBundled(self::IDENTIFIER, autoActivate: true);
        $this->assertSame('active', $installed->status, '전제 조건 — 첫 설치가 활성화되지 않았다');

        $this->writeBundledFixture('1.0.1');
        $this->blockMoves(fn (string $from, string $to, string $active) => $to === $active || $from === $active);

        $updated = $this->service->performUpdate($installed->fresh(), force: true);

        $this->assertInstalledAndRecorded('1.0.1', $updated);
        $this->assertSame('active', LanguagePack::query()->where('identifier', self::IDENTIFIER)->value('status'));
    }

    /**
     * 언어팩 서비스 코드는 `moveDirectory` 결과를 버리지 않는다 — 결과를 보지 않는 한 줄짜리 호출이 다시 생기면 실패.
     *
     * @scenario case=static_source_lock
     *
     * @effects lang_pack_code_checks_move_directory_result
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_code_never_discards_move_directory_result(): void
    {
        $files = array_merge(
            [base_path('app/Services/LanguagePackService.php')],
            glob(base_path('app/Services/LanguagePack/*.php')) ?: [],
        );
        $this->assertGreaterThan(5, count($files), '검사 대상 파일 해석이 어긋났다');

        $violations = [];
        foreach ($files as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $no => $line) {
                if (preg_match('/^\s*(?:File::|\$this->files->|\$fs->|\$filesystem->)moveDirectory\s*\(/', $line)) {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.($no + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $violations, "결과를 버리는 moveDirectory 호출 — 실패하면 설치 폴더가 사라진 채 성공으로 기록된다:\n".implode("\n", $violations));
    }

    /**
     * 번들 픽스처(`lang-packs/_bundled/g7test-core-ja`)를 주어진 판으로 씁니다.
     *
     * @param  string  $version  manifest 판
     * @return void 반환값 없음
     */
    private function writeBundledFixture(string $version): void
    {
        $dir = base_path('lang-packs/_bundled/'.self::IDENTIFIER);
        File::ensureDirectoryExists($dir.'/backend/ja');
        File::put($dir.'/backend/ja/messages.php', "<?php\n\nreturn ['version' => '{$version}'];\n");
        File::put($dir.'/language-pack.json', json_encode([
            'identifier' => self::IDENTIFIER,
            'namespace' => 'g7test',
            'vendor' => 'g7test',
            'name' => ['ko' => self::IDENTIFIER, 'en' => self::IDENTIFIER, 'ja' => self::IDENTIFIER],
            'description' => ['ko' => '시험', 'en' => 'Test'],
            'version' => $version,
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'JA',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'g7_version' => '>=7.0.0-beta.4',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 조건에 맞는 디렉터리 rename(`File::moveDirectory`)을 늘 실패시킵니다 — Windows 에서 다른 프로그램이 트리 안 핸들을
     * 잡고 있을 때의 모습. 그 밖의 파일 연산은 실제로 수행한다.
     *
     * @param  Closure(string, string, string): bool  $shouldBlock  (정규화한 from, to, 활성 폴더) → 막을지 여부
     * @return void 반환값 없음
     */
    private function blockMoves(Closure $shouldBlock): void
    {
        $real = new Filesystem;
        $active = $this->normalize(base_path('lang-packs/'.self::IDENTIFIER));

        File::partialMock()
            ->shouldReceive('moveDirectory')
            ->andReturnUsing(function ($from, $to, $overwrite = false) use ($real, $active, $shouldBlock) {
                if ($shouldBlock($this->normalize($from), $this->normalize($to), $active)) {
                    return false;
                }

                return $real->moveDirectory($from, $to, $overwrite);
            });
    }

    /**
     * 활성 폴더 manifest 판·DB 판·반환 판이 모두 기대 판이고, 이 팩의 `_pending` 잔여가 없는지 단언합니다.
     *
     * @param  string  $version  기대 판
     * @param  LanguagePack  $pack  서비스가 돌려준 팩
     * @return void 반환값 없음
     */
    private function assertInstalledAndRecorded(string $version, LanguagePack $pack): void
    {
        $this->assertSame(
            $version,
            $this->installedManifestVersion(),
            'DB 에는 설치로 기록됐는데 활성 폴더에 그 판이 없다 — 2026-10-14 nicepayments-ja 「파일 없음」 의 모양'
        );
        $this->assertSame($version, $pack->version);
        $this->assertSame($version, LanguagePack::query()->where('identifier', self::IDENTIFIER)->value('version'));
        $this->assertFileExists(base_path('lang-packs/'.self::IDENTIFIER.'/backend/ja/messages.php'));
        $this->assertSame([], $this->leftoverPendingEntries(), '설치가 lang-packs/_pending 에 이 팩의 사본을 남겼다');
    }

    /**
     * 활성 설치 폴더의 manifest 판을 돌려줍니다(폴더·manifest 가 없으면 null).
     *
     * @return string|null manifest 판
     */
    private function installedManifestVersion(): ?string
    {
        $manifest = base_path('lang-packs/'.self::IDENTIFIER.'/language-pack.json');
        if (! is_file($manifest)) {
            return null;
        }

        return json_decode((string) file_get_contents($manifest), true)['version'] ?? null;
    }

    /**
     * `lang-packs/_pending/` 의 하위 항목 이름 목록을 돌려줍니다(숨김 파일 제외).
     *
     * @return list<string> 항목 이름
     */
    private function pendingEntries(): array
    {
        $dir = base_path('lang-packs/_pending');
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(scandir($dir) ?: [], fn (string $name) => $name[0] !== '.'));
    }

    /**
     * 시험 중에 새로 생긴 `_pending` 항목 가운데 이 시험 팩의 것(설치본 사본·옛 판 사본)만 돌려줍니다.
     *
     * 다른 시험·세션이 동시에 만든 `_pending` 항목은 건드리지 않도록 manifest 식별자로 거른다.
     *
     * @return list<string> 항목 이름
     */
    private function leftoverPendingEntries(): array
    {
        $new = array_diff($this->pendingEntries(), $this->pendingBefore);

        return array_values(array_filter($new, function (string $name) {
            $dir = base_path('lang-packs/_pending/'.$name);
            $manifests = array_merge(glob($dir.'/language-pack.json') ?: [], glob($dir.'/*/language-pack.json') ?: []);
            foreach ($manifests as $manifest) {
                if ((json_decode((string) file_get_contents($manifest), true)['identifier'] ?? null) === self::IDENTIFIER) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * 경로 구분자를 `/` 로 맞추고 끝 구분자를 뗍니다.
     *
     * @param  string  $path  경로
     * @return string 정규화한 경로
     */
    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
