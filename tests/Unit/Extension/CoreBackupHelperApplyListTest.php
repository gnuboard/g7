<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\CoreBackupHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CoreBackupHelper::computeApplyList() 단위 테스트 (공개 #64)
 *
 * `core:update` 기본(증분) 모드의 3-way 판정을 검증합니다:
 *  - base == theirs (코어 미변경) → 적용 목록 제외 (사용자 상태 보존)
 *  - base 없음 (신규)             → added 로 목록 포함
 *  - size 다름 / md5 다름 (변경)   → changed 로 목록 포함
 *  - symlink / excludes / protected → 목록 제외
 */
class CoreBackupHelperApplyListTest extends TestCase
{
    private string $basePath;   // 백업 스냅샷 (base = 구버전 원본)

    private string $theirsPath; // _pending (theirs = 신 버전)

    protected function setUp(): void
    {
        parent::setUp();

        $root = storage_path('app/test_apply_list_'.uniqid());
        $this->basePath = $root.DIRECTORY_SEPARATOR.'base';
        $this->theirsPath = $root.DIRECTORY_SEPARATOR.'theirs';

        File::ensureDirectoryExists($this->basePath);
        File::ensureDirectoryExists($this->theirsPath);
    }

    protected function tearDown(): void
    {
        $root = dirname($this->basePath);
        if (File::isDirectory($root)) {
            File::deleteDirectory($root);
        }

        parent::tearDown();
    }

    /**
     * base == theirs 인 파일은 적용 목록에서 제외됩니다 (코어 미변경 → 보존).
     */
    public function test_unchanged_file_is_excluded_from_apply_list(): void
    {
        $this->writeBoth('app/Same.php', '<?php // identical', '<?php // identical');

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['app'],
            [],
            [],
        );

        $this->assertNotContains('app/Same.php', $result['apply']);
        $this->assertSame(0, $result['added_count']);
        $this->assertSame(0, $result['changed_count']);
    }

    /**
     * base 에 없고 theirs 에만 있는 파일은 added 로 포함됩니다 (신규).
     */
    public function test_new_file_is_added_to_apply_list(): void
    {
        File::ensureDirectoryExists($this->theirsPath.DIRECTORY_SEPARATOR.'app');
        File::put($this->theirsPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'New.php', '<?php // new');

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['app'],
            [],
            [],
        );

        $this->assertContains('app/New.php', $result['apply']);
        $this->assertSame(1, $result['added_count']);
        $this->assertSame(0, $result['changed_count']);
    }

    /**
     * size 가 다른 파일은 md5 계산 없이 changed 로 포함됩니다.
     */
    public function test_size_differing_file_is_changed(): void
    {
        $this->writeBoth('app/Grown.php', '<?php // small', '<?php // this content is clearly longer than base');

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['app'],
            [],
            [],
        );

        $this->assertContains('app/Grown.php', $result['apply']);
        $this->assertSame(1, $result['changed_count']);
        $this->assertSame(0, $result['added_count']);
    }

    /**
     * size 는 같지만 내용(md5)이 다른 파일은 changed 로 포함됩니다.
     */
    public function test_same_size_different_content_file_is_changed(): void
    {
        // 동일 길이(12), 내용만 다름
        $this->writeBoth('app/Edited.php', 'AAAAAAAAAAAA', 'BBBBBBBBBBBB');

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['app'],
            [],
            [],
        );

        $this->assertContains('app/Edited.php', $result['apply']);
        $this->assertSame(1, $result['changed_count']);
    }

    /**
     * excludes 패턴에 매칭되는 파일은 목록에서 제외됩니다.
     */
    public function test_excluded_file_is_not_in_apply_list(): void
    {
        // theirs 에만 존재하지만 node_modules 하위 → 제외
        File::ensureDirectoryExists($this->theirsPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'node_modules');
        File::put($this->theirsPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR.'x.js', 'x');

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['app'],
            [],
            ['node_modules'],
        );

        $this->assertSame([], $result['apply']);
    }

    /**
     * protected_paths 하위 항목은 목록에서 제외됩니다.
     */
    public function test_protected_path_file_is_not_in_apply_list(): void
    {
        File::ensureDirectoryExists($this->theirsPath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');
        File::put($this->theirsPath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'runtime.txt', 'data');

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['storage'],
            ['storage'],
            [],
        );

        $this->assertSame([], $result['apply']);
    }

    /**
     * targets 에 명시된 `{domain}/_bundled` 경로는 상위 protected(`{domain}`)보다
     * 우선하여 apply 목록에 포함됩니다 (공개 #64 / 내부 #452 회귀).
     *
     * 배경: `config/app.php` 의 update.targets 에는 `modules/_bundled`,
     * `plugins/_bundled`, `templates/_bundled`, `lang-packs/_bundled` 가 있고,
     * protected_paths 에는 부모 `modules`, `plugins`, `templates`, `lang-packs` 가
     * 있다. protected 는 자동 발견 폴백이 활성 서브디렉토리(`modules/sirsoft-*`)를
     * 통째로 삭제하는 회귀(#347)를 막기 위한 것인데, 정상 증분 흐름의 apply 산출에서
     * `{domain}/_bundled/...` 파일이 `{domain}/` 접두사에 걸려 통째로 제외되면
     * 코어 업데이트 시 번들 확장의 새 파일(composer.json·vendor-bundle.*)이
     * 반영되지 않는다. targets 에 더 구체적으로 명시된 경로는 상위 protected 를
     * 오버라이드해야 한다. 4종 확장(모듈/플러그인/템플릿/언어팩) 전수 검증.
     *
     * @dataProvider bundledDomainProvider
     */
    public function test_bundled_target_overrides_parent_protected_path(string $domain): void
    {
        $bundledTarget = "{$domain}/_bundled";

        // theirs 에만 존재하는 신규 번들 파일 (added)
        $newRel = "{$bundledTarget}/vendor-ext/composer.json";
        $newAbs = $this->theirsPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $newRel);
        File::ensureDirectoryExists(dirname($newAbs));
        File::put($newAbs, '{"version":"1.0.1"}');

        // base·theirs 양쪽에 있지만 내용이 다른 번들 파일 (changed)
        $this->writeBoth(
            "{$bundledTarget}/vendor-ext/vendor-bundle.json",
            '{"composer_json_sha256":"old"}',
            '{"composer_json_sha256":"new-value-differs"}',
        );

        // 활성 서브디렉토리(=targets 밖, protected 로 보존되어야 함)
        $activeRel = "{$domain}/vendor-ext-active";
        File::ensureDirectoryExists($this->theirsPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $activeRel));
        File::put(
            $this->theirsPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, "{$activeRel}/active.php"),
            'active',
        );

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            [$bundledTarget],                 // targets: _bundled 만 명시
            [$domain, 'storage', 'vendor'],   // protected: 부모 도메인 포함 (실제 config 반영)
            ['node_modules', '.git'],
        );

        // _bundled 하위 신규/변경 파일은 apply 목록에 포함되어야 한다
        $this->assertContains("{$bundledTarget}/vendor-ext/composer.json", $result['apply']);
        $this->assertContains("{$bundledTarget}/vendor-ext/vendor-bundle.json", $result['apply']);
        $this->assertSame(1, $result['added_count']);
        $this->assertSame(1, $result['changed_count']);

        // 활성 서브디렉토리(targets 밖)는 순회 대상이 아니므로 목록에 없어야 한다
        $this->assertNotContains("{$activeRel}/active.php", $result['apply']);
    }

    /**
     * 4종 번들 확장 도메인 (config/app.php 의 targets/protected_paths 실제 조합).
     *
     * @return array<string, array{string}>
     */
    public static function bundledDomainProvider(): array
    {
        return [
            'modules' => ['modules'],
            'plugins' => ['plugins'],
            'templates' => ['templates'],
            'lang-packs' => ['lang-packs'],
        ];
    }

    /**
     * target 과 protected 가 정확히 동일한 경로면 여전히 제외됩니다 (기존 방어 유지).
     *
     * `_bundled` 오버라이드는 "targets 가 protected 보다 더 구체적(하위)일 때"만
     * 적용된다. target == protected (예: storage) 는 제외가 정상.
     */
    public function test_target_equal_to_protected_is_still_excluded(): void
    {
        File::ensureDirectoryExists($this->theirsPath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');
        File::put($this->theirsPath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'runtime.txt', 'data');

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['storage'],
            ['storage'],
            [],
        );

        $this->assertSame([], $result['apply']);
    }

    /**
     * added 와 changed 가 섞여 있을 때 apply 목록이 정렬되어 둘 다 포함합니다.
     */
    public function test_apply_list_merges_added_and_changed_sorted(): void
    {
        $this->writeBoth('app/Zeta.php', 'old', 'new-different');            // changed
        File::put($this->theirsPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Alpha.php', 'brand-new'); // added
        $this->writeBoth('app/Keep.php', 'same', 'same');                    // unchanged → 제외

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['app'],
            [],
            [],
        );

        $this->assertSame(['app/Alpha.php', 'app/Zeta.php'], $result['apply']);
        $this->assertSame(1, $result['added_count']);
        $this->assertSame(1, $result['changed_count']);
    }

    /**
     * 단일 파일 target 도 3-way 판정을 받습니다 (변경 시 changed).
     */
    public function test_single_file_target_is_classified(): void
    {
        File::put($this->basePath.DIRECTORY_SEPARATOR.'artisan', 'v1');
        File::put($this->theirsPath.DIRECTORY_SEPARATOR.'artisan', 'v2-changed');

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['artisan'],
            [],
            [],
        );

        $this->assertContains('artisan', $result['apply']);
        $this->assertSame(1, $result['changed_count']);
    }

    /**
     * theirs 의 symlink 는 적용 목록에서 제외되고 has_symlink 플래그가 켜집니다.
     * (링크 자체는 copyDirectory 의 별도 링크 처리 경로가 담당 — 목록 산출 대상 아님)
     */
    public function test_symlink_is_excluded_and_flagged(): void
    {
        // theirs/app/real.php + theirs/app/link.php(→ real.php) 구성
        File::ensureDirectoryExists($this->theirsPath.DIRECTORY_SEPARATOR.'app');
        $realTarget = $this->theirsPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'real.php';
        File::put($realTarget, '<?php // real');

        $linkPath = $this->theirsPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'link.php';
        if (! @symlink($realTarget, $linkPath) || ! is_link($linkPath)) {
            // Windows SeCreateSymbolicLink 권한 부족 등 — 링크 생성 불가 환경은 skip
            $this->markTestSkipped('symlink 생성 불가 환경 (권한 부족)');
        }

        $result = CoreBackupHelper::computeApplyList(
            $this->basePath,
            $this->theirsPath,
            ['app'],
            [],
            [],
        );

        // real.php 는 added 로 포함되지만 link.php 는 목록에서 제외
        $this->assertContains('app/real.php', $result['apply']);
        $this->assertNotContains('app/link.php', $result['apply']);
        $this->assertTrue($result['has_symlink'], 'symlink 발견 시 has_symlink 플래그가 켜져야 한다');
    }

    /**
     * base·theirs 양쪽에 동일 파일을 씁니다.
     */
    private function writeBoth(string $relative, string $baseContent, string $theirsContent): void
    {
        $rel = str_replace('/', DIRECTORY_SEPARATOR, $relative);

        $baseFile = $this->basePath.DIRECTORY_SEPARATOR.$rel;
        File::ensureDirectoryExists(dirname($baseFile));
        File::put($baseFile, $baseContent);

        $theirsFile = $this->theirsPath.DIRECTORY_SEPARATOR.$rel;
        File::ensureDirectoryExists(dirname($theirsFile));
        File::put($theirsFile, $theirsContent);
    }
}
