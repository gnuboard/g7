<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\ChangelogParser;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ChangelogParserTest extends TestCase
{
    private string $testDir;

    /**
     * 테스트마다 임시 CHANGELOG 디렉터리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = base_path('tests/_temp_changelog_test');
        File::ensureDirectoryExists($this->testDir);
    }

    /**
     * 테스트가 만든 임시 CHANGELOG 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }

    /**
     * 표준 Keep a Changelog 형식을 파싱합니다.
     *
     * @scenario case=parse_versions
     *
     * @effects changelog_versions_and_categories_parsed
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=parse_versions
     *
     * @effects changelog_versions_and_categories_parsed
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=parse_versions
     *
     * @effects changelog_versions_and_categories_parsed
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=empty_input
     *
     * @effects changelog_empty_inputs_return_empty
     *
     * @return void 반환값 없음
     */
    public function test_parse_returns_empty_for_nonexistent_file(): void
    {
        $result = ChangelogParser::parse($this->testDir.'/nonexistent.md');

        $this->assertSame([], $result);
    }

    /**
     * 빈 파일은 빈 배열을 반환합니다.
     *
     * @scenario case=empty_input
     *
     * @effects changelog_empty_inputs_return_empty
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=empty_input
     *
     * @effects changelog_empty_inputs_return_empty
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=parse_versions
     *
     * @effects changelog_versions_and_categories_parsed
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=version_range
     *
     * @effects changelog_version_range_filtered
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=version_range
     *
     * @effects changelog_version_range_filtered
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=version_range
     *
     * @effects changelog_version_range_filtered
     *
     * @return void 반환값 없음
     */
    public function test_get_version_range_returns_empty_for_nonexistent_file(): void
    {
        $result = ChangelogParser::getVersionRange($this->testDir.'/nonexistent.md', '0.1.0', '0.1.1');

        $this->assertSame([], $result);
    }

    /**
     * active 소스에서 CHANGELOG.md 경로를 올바르게 결정합니다.
     *
     * @scenario case=resolve_path
     *
     * @effects changelog_path_resolved_by_source
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=resolve_path
     *
     * @effects changelog_path_resolved_by_source
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=resolve_path
     *
     * @effects changelog_path_resolved_by_source
     *
     * @return void 반환값 없음
     */
    public function test_resolve_changelog_path_returns_null_for_missing_file(): void
    {
        $result = ChangelogParser::resolveChangelogPath($this->testDir, 'nonexistent-module');

        $this->assertNull($result);
    }

    /**
     * source가 null이면 active로 동작합니다.
     *
     * @scenario case=resolve_path
     *
     * @effects changelog_path_resolved_by_source
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=resolve_path
     *
     * @effects changelog_path_resolved_by_source
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=resolve_path
     *
     * @effects changelog_path_resolved_by_source
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=parse_versions
     *
     * @effects changelog_versions_and_categories_parsed
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=parse_versions
     *
     * @effects changelog_versions_and_categories_parsed
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=version_range
     *
     * @effects changelog_version_range_filtered
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=resolve_path
     *
     * @effects changelog_path_resolved_by_source
     *
     * @return void 반환값 없음
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

    /**
     * 들여쓰기로 이어 쓴 줄은 앞 항목에 공백 하나로 이어 붙입니다.
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_indented_continuation_lines_join_previous_item(): void
    {
        $content = <<<'MD'
## [1.0.0] - 2026-10-08

### Added
- 첫 줄 문장이 길어서
  둘째 줄로 이어지고
  셋째 줄로 끝납니다.
- 한 줄 항목
MD;

        $result = ChangelogParser::parseFromString($content);

        $this->assertSame(
            ['첫 줄 문장이 길어서 둘째 줄로 이어지고 셋째 줄로 끝납니다.', '한 줄 항목'],
            $result[0]['categories'][0]['items']
        );
    }

    /**
     * 중첩 bullet 은 따로 항목으로 두고, 그 아래 이어 쓴 줄은 중첩 항목에 붙입니다.
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_nested_bullet_keeps_own_item_with_its_continuation(): void
    {
        $content = <<<'MD'
## [1.0.0]

### Fixed
- 보안: 여러 문제를
  함께 고쳤습니다.
  - 첫 번째 하위 문제를
    고쳤습니다.
  - 두 번째 하위 문제
- 다음 항목
MD;

        $result = ChangelogParser::parseFromString($content);

        $this->assertSame([
            '보안: 여러 문제를 함께 고쳤습니다.',
            '첫 번째 하위 문제를 고쳤습니다.',
            '두 번째 하위 문제',
            '다음 항목',
        ], $result[0]['categories'][0]['items']);
    }

    /**
     * 빈 줄 뒤 들여쓴 문단은 같은 항목(느슨한 목록)으로, 들여쓰지 않은 문단은 항목 밖으로 봅니다.
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_blank_line_boundary_between_item_paragraph_and_plain_paragraph(): void
    {
        $content = <<<'MD'
## [1.0.0]

### Fixed
- **파일을 바꾸면 수정이 사라지던 문제를 고쳤습니다.**
  편집 내용이 한 벌만 유지되었습니다.

  이제 편집 내용은 파일마다 따로 남습니다.

- 다음 항목

### Notes

들여쓰지 않은 안내 문단은 항목이 아닙니다.
- 문단 다음 항목
MD;

        $result = ChangelogParser::parseFromString($content);

        $this->assertSame([
            '**파일을 바꾸면 수정이 사라지던 문제를 고쳤습니다.** 편집 내용이 한 벌만 유지되었습니다. 이제 편집 내용은 파일마다 따로 남습니다.',
            '다음 항목',
        ], $result[0]['categories'][0]['items']);
        $this->assertSame(['문단 다음 항목'], $result[0]['categories'][1]['items']);
    }

    /**
     * 빈 줄 뒤 들여쓰지 않은 문단이 오면 항목이 끝나 그 뒤 들여쓴 줄도 앞 항목에 붙지 않습니다.
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_plain_paragraph_closes_item_for_following_indented_lines(): void
    {
        $content = <<<'MD'
## [1.0.0]

### Upgrade Notice

- 손실 항목 1

들여쓰지 않은 문단입니다.
  들여쓴 줄이지만 항목에 속하지 않습니다.
MD;

        $result = ChangelogParser::parseFromString($content);

        $this->assertSame(['손실 항목 1'], $result[0]['categories'][0]['items']);
    }

    /**
     * 항목 안 코드 블록은 울타리 줄을 빼고 내용을 이어 붙이고, 항목 밖 코드 블록 안의 `- ` 줄과 `##` 줄은 무시합니다.
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_code_fences_inside_and_outside_items(): void
    {
        $content = <<<'MD'
## [1.0.0]

### Upgrade Notice

- 다음 단계적 업그레이드를 권장합니다:

  ```text
  beta.1 사용자: 4 회
  ```

  각 단계마다 버전이 갱신됩니다.

**매뉴얼 회복**:

```bash
- 이 줄은 항목이 아닙니다
## [9.9.9]
php artisan storage:link
```

- 코드 블록 다음 항목
MD;

        $result = ChangelogParser::parseFromString($content);

        $this->assertCount(1, $result);
        $this->assertSame([
            '다음 단계적 업그레이드를 권장합니다: beta.1 사용자: 4 회 각 단계마다 버전이 갱신됩니다.',
            '코드 블록 다음 항목',
        ], $result[0]['categories'][0]['items']);
    }

    /**
     * 카테고리 아래 `####` 소제목은 앞 항목에 붙지 않고 항목을 끝냅니다.
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_sub_heading_closes_item(): void
    {
        $content = <<<'MD'
## [1.0.0]

### Added

- 첫 항목
#### 소제목
  들여쓴 줄
- 둘째 항목
MD;

        $result = ChangelogParser::parseFromString($content);

        $this->assertSame(['첫 항목', '둘째 항목'], $result[0]['categories'][0]['items']);
    }

    /**
     * CRLF 줄바꿈 파일도 이어 쓴 줄을 묶습니다.
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_crlf_continuation_lines(): void
    {
        $content = "## [1.0.0]\r\n\r\n### Added\r\n- 첫 줄\r\n  둘째 줄\r\n- 다음\r\n";

        $result = ChangelogParser::parseFromString($content);

        $this->assertSame(['첫 줄 둘째 줄', '다음'], $result[0]['categories'][0]['items']);
    }

    /**
     * 실제 번들 CHANGELOG 의 이어 쓴 줄이 버려지지 않습니다(Hello 모듈 0.2.0 Added 두 번째 항목).
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_bundled_hello_module_changelog_keeps_second_line(): void
    {
        $path = base_path('modules/_bundled/gnuboard7-hello_module/CHANGELOG.md');
        if (! File::exists($path)) {
            $this->markTestSkipped('Hello 모듈 번들 원본이 없습니다.');
        }

        $result = ChangelogParser::parse($path);
        $items = $result[0]['categories'][0]['items'];

        $this->assertStringContainsString('레이아웃에서는 모듈 식별자와 점을 앞에 붙인 태그로 씁니다', $items[1]);
    }

    /**
     * 모든 번들 CHANGELOG 에서 항목 바로 밑에 들여써 이어 쓴 줄의 내용이 어느 항목에든 남습니다.
     *
     * @scenario case=continuation_lines
     *
     * @effects changelog_continuation_lines_kept
     *
     * @return void 반환값 없음
     */
    public function test_all_bundled_changelogs_keep_indented_continuation_text(): void
    {
        $files = array_merge(
            [base_path('CHANGELOG.md')],
            glob(base_path('modules/_bundled/*/CHANGELOG.md')) ?: [],
            glob(base_path('plugins/_bundled/*/CHANGELOG.md')) ?: [],
            glob(base_path('templates/_bundled/*/CHANGELOG.md')) ?: [],
            glob(base_path('lang-packs/_bundled/*/CHANGELOG.md')) ?: [],
        );

        $checked = 0;
        $missing = [];

        foreach ($files as $file) {
            $content = File::get($file);
            $joined = [];
            foreach (ChangelogParser::parseFromString($content) as $entry) {
                foreach ($entry['categories'] as $category) {
                    foreach ($category['items'] as $item) {
                        $joined[] = $item;
                    }
                }
            }
            $haystack = implode("\n", $joined);

            $inItem = false;
            $inFence = false;
            foreach (preg_split('/\r?\n/', $content) as $no => $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '```')) {
                    $inFence = ! $inFence;

                    continue;
                }
                if ($inFence) {
                    continue;
                }
                if ($trimmed === '' || preg_match('/^#{1,6}\s/', $trimmed)) {
                    $inItem = false;

                    continue;
                }
                if (preg_match('/^\s*-\s+/', $line)) {
                    $inItem = true;

                    continue;
                }
                if ($inItem && preg_match('/^\s+\S/', $line)) {
                    $checked++;
                    if (! str_contains($haystack, $trimmed)) {
                        $missing[] = basename(dirname($file)).':'.($no + 1);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertSame([], array_slice($missing, 0, 20), '이어 쓴 줄이 항목에서 빠졌습니다 (총 '.count($missing).'건)');
    }
}
