<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\ChangelogParser;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ChangelogParserTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = base_path('tests/_temp_changelog_test');
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }

    /**
     * 표준 Keep a Changelog 형식을 파싱합니다.
     */
    public function test_parse_standard_format(): void
    {
        $content = <<<'MD'
# Changelog

## [0.1.2] - 2026-02-25

### Added
- 새 기능 A
- 새 기능 B

### Fixed
- 버그 C 수정

## [0.1.1] - 2026-02-20

### Changed
- 기존 기능 D 개선
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        $result = ChangelogParser::parse($filePath);

        $this->assertCount(2, $result);

        // 첫 번째 버전 (0.1.2)
        $this->assertSame('0.1.2', $result[0]['version']);
        $this->assertSame('2026-02-25', $result[0]['date']);
        $this->assertCount(2, $result[0]['categories']);

        $this->assertSame('Added', $result[0]['categories'][0]['name']);
        $this->assertSame(['새 기능 A', '새 기능 B'], $result[0]['categories'][0]['items']);

        $this->assertSame('Fixed', $result[0]['categories'][1]['name']);
        $this->assertSame(['버그 C 수정'], $result[0]['categories'][1]['items']);

        // 두 번째 버전 (0.1.1)
        $this->assertSame('0.1.1', $result[1]['version']);
        $this->assertSame('2026-02-20', $result[1]['date']);
        $this->assertCount(1, $result[1]['categories']);
        $this->assertSame('Changed', $result[1]['categories'][0]['name']);
        $this->assertSame(['기존 기능 D 개선'], $result[1]['categories'][0]['items']);
    }

    /**
     * 대괄호 없는 버전 형식도 파싱합니다.
     */
    public function test_parse_version_without_brackets(): void
    {
        $content = <<<'MD'
# Changelog

## 1.0.0 - 2026-01-01

### Added
- 초기 릴리스
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        $result = ChangelogParser::parse($filePath);

        $this->assertCount(1, $result);
        $this->assertSame('1.0.0', $result[0]['version']);
        $this->assertSame('2026-01-01', $result[0]['date']);
    }

    /**
     * 날짜가 없는 버전도 파싱합니다.
     */
    public function test_parse_version_without_date(): void
    {
        $content = <<<'MD'
# Changelog

## [0.1.0]

### Added
- 초기 기능
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        $result = ChangelogParser::parse($filePath);

        $this->assertCount(1, $result);
        $this->assertSame('0.1.0', $result[0]['version']);
        $this->assertNull($result[0]['date']);
    }

    /**
     * 파일이 존재하지 않으면 빈 배열을 반환합니다.
     */
    public function test_parse_returns_empty_for_nonexistent_file(): void
    {
        $result = ChangelogParser::parse($this->testDir.'/nonexistent.md');

        $this->assertSame([], $result);
    }

    /**
     * 빈 파일은 빈 배열을 반환합니다.
     */
    public function test_parse_returns_empty_for_empty_file(): void
    {
        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, '');

        $result = ChangelogParser::parse($filePath);

        $this->assertSame([], $result);
    }

    /**
     * 버전 헤더가 없는 파일은 빈 배열을 반환합니다.
     */
    public function test_parse_returns_empty_for_no_version_headers(): void
    {
        $content = <<<'MD'
# Changelog

이 프로젝트의 모든 변경사항을 기록합니다.
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        $result = ChangelogParser::parse($filePath);

        $this->assertSame([], $result);
    }

    /**
     * 여러 카테고리를 올바르게 파싱합니다.
     */
    public function test_parse_multiple_categories(): void
    {
        $content = <<<'MD'
# Changelog

## [0.2.0] - 2026-03-01

### Added
- 새 기능

### Changed
- 변경 사항

### Deprecated
- 더 이상 사용하지 않는 기능

### Removed
- 제거된 기능

### Fixed
- 수정된 버그

### Security
- 보안 패치
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        $result = ChangelogParser::parse($filePath);

        $this->assertCount(1, $result);
        $this->assertCount(6, $result[0]['categories']);
        $this->assertSame('Added', $result[0]['categories'][0]['name']);
        $this->assertSame('Changed', $result[0]['categories'][1]['name']);
        $this->assertSame('Deprecated', $result[0]['categories'][2]['name']);
        $this->assertSame('Removed', $result[0]['categories'][3]['name']);
        $this->assertSame('Fixed', $result[0]['categories'][4]['name']);
        $this->assertSame('Security', $result[0]['categories'][5]['name']);
    }

    /**
     * 버전 범위 필터링이 올바르게 동작합니다 (from 초과, to 이하).
     */
    public function test_get_version_range(): void
    {
        $content = <<<'MD'
# Changelog

## [0.1.3] - 2026-02-28

### Added
- 기능 C

## [0.1.2] - 2026-02-25

### Added
- 기능 B

## [0.1.1] - 2026-02-20

### Added
- 기능 A

## [0.1.0] - 2026-02-15

### Added
- 초기 릴리스
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        // from=0.1.1 초과, to=0.1.3 이하 → 0.1.2, 0.1.3
        $result = ChangelogParser::getVersionRange($filePath, '0.1.1', '0.1.3');

        $this->assertCount(2, $result);
        $this->assertSame('0.1.3', $result[0]['version']);
        $this->assertSame('0.1.2', $result[1]['version']);
    }

    /**
     * 버전 범위에 해당하는 엔트리가 없으면 빈 배열을 반환합니다.
     */
    public function test_get_version_range_returns_empty_for_no_match(): void
    {
        $content = <<<'MD'
# Changelog

## [0.1.0] - 2026-02-15

### Added
- 초기 릴리스
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        $result = ChangelogParser::getVersionRange($filePath, '0.1.0', '0.1.0');

        $this->assertSame([], $result);
    }

    /**
     * 파일 미존재 시 버전 범위도 빈 배열을 반환합니다.
     */
    public function test_get_version_range_returns_empty_for_nonexistent_file(): void
    {
        $result = ChangelogParser::getVersionRange($this->testDir.'/nonexistent.md', '0.1.0', '0.1.1');

        $this->assertSame([], $result);
    }

    /**
     * active 소스에서 CHANGELOG.md 경로를 올바르게 결정합니다.
     */
    public function test_resolve_changelog_path_active_source(): void
    {
        $basePath = $this->testDir;
        $identifier = 'test-module';

        File::ensureDirectoryExists($basePath.'/'.$identifier);
        File::put($basePath.'/'.$identifier.'/CHANGELOG.md', '# Changelog');

        $result = ChangelogParser::resolveChangelogPath($basePath, $identifier);

        $this->assertNotNull($result);
        $this->assertStringContainsString($identifier.DIRECTORY_SEPARATOR.'CHANGELOG.md', $result);
    }

    /**
     * bundled 소스에서 CHANGELOG.md 경로를 올바르게 결정합니다.
     */
    public function test_resolve_changelog_path_bundled_source(): void
    {
        $basePath = $this->testDir;
        $identifier = 'test-module';

        File::ensureDirectoryExists($basePath.'/_bundled/'.$identifier);
        File::put($basePath.'/_bundled/'.$identifier.'/CHANGELOG.md', '# Changelog');

        $result = ChangelogParser::resolveChangelogPath($basePath, $identifier, 'bundled');

        $this->assertNotNull($result);
        $this->assertStringContainsString('_bundled'.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.'CHANGELOG.md', $result);
    }

    /**
     * 파일이 존재하지 않으면 null을 반환합니다.
     */
    public function test_resolve_changelog_path_returns_null_for_missing_file(): void
    {
        $result = ChangelogParser::resolveChangelogPath($this->testDir, 'nonexistent-module');

        $this->assertNull($result);
    }

    /**
     * source가 null이면 active로 동작합니다.
     */
    public function test_resolve_changelog_path_defaults_to_active(): void
    {
        $basePath = $this->testDir;
        $identifier = 'test-module';

        File::ensureDirectoryExists($basePath.'/'.$identifier);
        File::put($basePath.'/'.$identifier.'/CHANGELOG.md', '# Changelog');

        $result = ChangelogParser::resolveChangelogPath($basePath, $identifier, null);

        $this->assertNotNull($result);
        $this->assertStringContainsString($identifier.DIRECTORY_SEPARATOR.'CHANGELOG.md', $result);
    }

    /**
     * active에 파일이 없으면 _bundled로 폴백합니다.
     */
    public function test_resolve_changelog_path_fallback_to_bundled(): void
    {
        $basePath = $this->testDir;
        $identifier = 'test-module';

        // active 디렉토리에는 CHANGELOG.md 없음
        // _bundled에만 존재
        File::ensureDirectoryExists($basePath.'/_bundled/'.$identifier);
        File::put($basePath.'/_bundled/'.$identifier.'/CHANGELOG.md', '# Changelog');

        $result = ChangelogParser::resolveChangelogPath($basePath, $identifier);

        $this->assertNotNull($result);
        $this->assertStringContainsString('_bundled'.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.'CHANGELOG.md', $result);
    }

    /**
     * active와 _bundled 모두에 파일이 있으면 active를 우선합니다.
     */
    public function test_resolve_changelog_path_active_takes_priority_over_bundled(): void
    {
        $basePath = $this->testDir;
        $identifier = 'test-module';

        File::ensureDirectoryExists($basePath.'/'.$identifier);
        File::put($basePath.'/'.$identifier.'/CHANGELOG.md', '# Active Changelog');

        File::ensureDirectoryExists($basePath.'/_bundled/'.$identifier);
        File::put($basePath.'/_bundled/'.$identifier.'/CHANGELOG.md', '# Bundled Changelog');

        $result = ChangelogParser::resolveChangelogPath($basePath, $identifier);

        $this->assertNotNull($result);
        // active가 우선이므로 _bundled가 포함되지 않아야 함
        $this->assertStringNotContainsString('_bundled', $result);
    }

    /**
     * 프리릴리스 버전(7.0.0-alpha.1)을 올바르게 파싱합니다.
     */
    public function test_parse_prerelease_version(): void
    {
        $content = <<<'MD'
# Changelog

## [7.0.0-beta.1] - 2026-03-07

### Added
- 코어 업데이트 시스템

### Fixed
- 확장 의존성 체크 오류 수정

## [7.0.0-alpha.2] - 2026-02-15

### Added
- 초기 알파 기능
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        $result = ChangelogParser::parse($filePath);

        $this->assertCount(2, $result);

        // 7.0.0-beta.1
        $this->assertSame('7.0.0-beta.1', $result[0]['version']);
        $this->assertSame('2026-03-07', $result[0]['date']);
        $this->assertCount(2, $result[0]['categories']);

        // 7.0.0-alpha.2
        $this->assertSame('7.0.0-alpha.2', $result[1]['version']);
        $this->assertSame('2026-02-15', $result[1]['date']);
    }

    /**
     * 대괄호 없는 프리릴리스 버전도 파싱합니다.
     */
    public function test_parse_prerelease_version_without_brackets(): void
    {
        $content = <<<'MD'
# Changelog

## 7.0.0-rc.1 - 2026-04-01

### Added
- RC 기능
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        $result = ChangelogParser::parse($filePath);

        $this->assertCount(1, $result);
        $this->assertSame('7.0.0-rc.1', $result[0]['version']);
        $this->assertSame('2026-04-01', $result[0]['date']);
    }

    /**
     * 프리릴리스 버전 범위 필터링이 올바르게 동작합니다.
     */
    public function test_get_version_range_with_prerelease(): void
    {
        $content = <<<'MD'
# Changelog

## [7.0.0-beta.2] - 2026-03-15

### Added
- 베타2 기능

## [7.0.0-beta.1] - 2026-03-07

### Added
- 베타1 기능

## [7.0.0-alpha.1] - 2026-02-01

### Added
- 알파1 기능
MD;

        $filePath = $this->testDir.'/CHANGELOG.md';
        File::put($filePath, $content);

        // alpha.1 초과 ~ beta.2 이하
        $result = ChangelogParser::getVersionRange($filePath, '7.0.0-alpha.1', '7.0.0-beta.2');

        $this->assertCount(2, $result);
        $this->assertSame('7.0.0-beta.2', $result[0]['version']);
        $this->assertSame('7.0.0-beta.1', $result[1]['version']);
    }

    /**
     * bundled 소스 지정 시에는 폴백하지 않습니다.
     */
    public function test_resolve_changelog_path_bundled_source_no_fallback(): void
    {
        $basePath = $this->testDir;
        $identifier = 'test-module';

        // active에만 존재, _bundled에는 없음
        File::ensureDirectoryExists($basePath.'/'.$identifier);
        File::put($basePath.'/'.$identifier.'/CHANGELOG.md', '# Changelog');

        $result = ChangelogParser::resolveChangelogPath($basePath, $identifier, 'bundled');

        $this->assertNull($result);
    }
}
