<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHPUnit Annotation 변환 커맨드 테스트
 *
 * @package Tests\Feature\Console
 * @author sirsoft
 */
class ConvertPHPUnitAnnotationsTest extends TestCase
{
    /**
     * 테스트용 임시 디렉토리
     *
     * @var string
     */
    private string $tempDir;

    /**
     * 각 테스트 전 실행
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 임시 디렉토리 생성
        $this->tempDir = base_path('tests/temp_phpunit_convert');
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * 각 테스트 후 실행
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // 임시 디렉토리 정리
        if (is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * Test annotation을 #[Test] attribute로 변환하는지 테스트합니다.
     *     * @return void
     */
    #[Test]
    public function it_converts_test_annotation(): void
    {
        // Given: @test annotation이 있는 임시 테스트 파일 생성
        $testFile = $this->tempDir . '/SampleTest.php';
        $content = <<<'PHP'
<?php

namespace Tests\Unit;

use Tests\TestCase;

class SampleTest extends TestCase
{
    /**
     * 샘플 테스트입니다.
     *
     * @test
     */
    public function it_can_do_something(): void
    {
        $this->assertTrue(true);
    }
}
PHP;
        file_put_contents($testFile, $content);

        // When: 변환 커맨드 실행 (dry-run)
        $this->artisan('phpunit:convert-annotations', [
            '--path' => 'tests/temp_phpunit_convert',
            '--dry-run' => true,
        ])->assertSuccessful();

        // 실제 변환 실행
        $this->artisan('phpunit:convert-annotations', [
            '--path' => 'tests/temp_phpunit_convert',
        ])->assertSuccessful();

        // Then: #[Test] attribute로 변환되었는지 확인
        $convertedContent = file_get_contents($testFile);
        $this->assertStringContainsString('#[Test]', $convertedContent);
        $this->assertStringContainsString('use PHPUnit\Framework\Attributes\Test;', $convertedContent);
        $this->assertStringNotContainsString('@test', $convertedContent);
        $this->assertStringContainsString('샘플 테스트입니다.', $convertedContent); // 주석 보존
    }

    /**
     * use 구문 중복 없이 추가하는지 테스트합니다.
     *     * @return void
     */
    #[Test]
    public function it_adds_use_statements_without_duplication(): void
    {
        // Given: 이미 일부 use 구문이 있는 파일
        $testFile = $this->tempDir . '/DuplicateTest.php';
        $content = <<<'PHP'
<?php

namespace Tests\Unit;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DuplicateTest extends TestCase
{
    /**
     * @test
     */
    public function it_has_existing_use_statement(): void
    {
        $this->assertTrue(true);
    }
}
PHP;
        file_put_contents($testFile, $content);

        // When: 변환 커맨드 실행
        $this->artisan('phpunit:convert-annotations', [
            '--path' => 'tests/temp_phpunit_convert',
        ])->assertSuccessful();

        // Then: use 구문이 중복되지 않았는지 확인
        $convertedContent = file_get_contents($testFile);
        $useCount = substr_count($convertedContent, 'use PHPUnit\Framework\Attributes\Test;');
        $this->assertEquals(1, $useCount, 'use 구문이 중복되지 않아야 합니다');
    }

    /**
     * 메서드 설명과 주석을 보존하는지 테스트합니다.
     *     * @return void
     */
    #[Test]
    public function it_preserves_method_documentation(): void
    {
        // Given: 메서드 설명, @param, @return이 포함된 파일
        $testFile = $this->tempDir . '/DocumentationTest.php';
        $content = <<<'PHP'
<?php

namespace Tests\Unit;

use Tests\TestCase;

class DocumentationTest extends TestCase
{
    /**
     * 사용자가 생성될 수 있는지 테스트합니다.
     *
     * @test
     */
    public function it_can_create_user(): void
    {
        $this->assertTrue(true);
    }
}
PHP;
        file_put_contents($testFile, $content);

        // When: 변환 커맨드 실행
        $this->artisan('phpunit:convert-annotations', [
            '--path' => 'tests/temp_phpunit_convert',
        ])->assertSuccessful();

        // Then: 메서드 설명이 보존되었는지 확인
        $convertedContent = file_get_contents($testFile);
        $this->assertStringContainsString('사용자가 생성될 수 있는지 테스트합니다.', $convertedContent);
        $this->assertStringContainsString('#[Test]', $convertedContent);
    }

    /**
     * --backup 옵션으로 백업 파일을 생성하는지 테스트합니다.
     *     * @return void
     */
    #[Test]
    public function it_creates_backup_when_requested(): void
    {
        // Given: 테스트 파일
        $testFile = $this->tempDir . '/BackupTest.php';
        $content = <<<'PHP'
<?php

namespace Tests\Unit;

use Tests\TestCase;

class BackupTest extends TestCase
{
    /**
     * @test
     */
    public function it_should_be_backed_up(): void
    {
        $this->assertTrue(true);
    }
}
PHP;
        file_put_contents($testFile, $content);

        // When: --backup 옵션으로 실행
        $this->artisan('phpunit:convert-annotations', [
            '--path' => 'tests/temp_phpunit_convert',
            '--backup' => true,
        ])->assertSuccessful();

        // Then: .bak 파일이 생성되었는지 확인
        $backupFile = $testFile . '.bak';
        $this->assertFileExists($backupFile);

        // 백업 파일이 원본과 동일한지 확인
        $backupContent = file_get_contents($backupFile);
        $this->assertStringContainsString('@test', $backupContent);
    }

    /**
     * DataProvider annotation을 변환하는지 테스트합니다.
     *     * @return void
     */
    #[Test]
    public function it_converts_data_provider_annotation(): void
    {
        // Given: @dataProvider annotation이 있는 파일
        $testFile = $this->tempDir . '/DataProviderTest.php';
        $content = <<<'PHP'
<?php

namespace Tests\Unit;

use Tests\TestCase;

class DataProviderTest extends TestCase
{
    /**
     * @dataProvider versionProvider
     */
    public function test_version_validation(string $version, bool $valid): void
    {
        $this->assertTrue($valid);
    }

    public function versionProvider(): array
    {
        return [
            ['1.0.0', true],
            ['invalid', false],
        ];
    }
}
PHP;
        file_put_contents($testFile, $content);

        // When: 변환 커맨드 실행
        $this->artisan('phpunit:convert-annotations', [
            '--path' => 'tests/temp_phpunit_convert',
        ])->assertSuccessful();

        // Then: #[DataProvider] attribute로 변환되었는지 확인
        $convertedContent = file_get_contents($testFile);
        $this->assertStringContainsString("#[DataProvider('versionProvider')]", $convertedContent);
        $this->assertStringContainsString('use PHPUnit\Framework\Attributes\DataProvider;', $convertedContent);
        $this->assertStringNotContainsString('@dataProvider', $convertedContent);
    }

    /**
     * Group annotation을 변환하는지 테스트합니다.
     *     * @return void
     */
    #[Test]
    public function it_converts_group_annotation(): void
    {
        // Given: @group annotation이 있는 파일
        $testFile = $this->tempDir . '/GroupTest.php';
        $content = <<<'PHP'
<?php

namespace Tests\Unit;

use Tests\TestCase;

class GroupTest extends TestCase
{
    /**
     * @test
     * @group slow
     */
    public function it_is_a_slow_test(): void
    {
        $this->assertTrue(true);
    }
}
PHP;
        file_put_contents($testFile, $content);

        // When: 변환 커맨드 실행
        $this->artisan('phpunit:convert-annotations', [
            '--path' => 'tests/temp_phpunit_convert',
        ])->assertSuccessful();

        // Then: #[Group] attribute로 변환되었는지 확인
        $convertedContent = file_get_contents($testFile);
        $this->assertStringContainsString("#[Group('slow')]", $convertedContent);
        $this->assertStringContainsString('use PHPUnit\Framework\Attributes\Group;', $convertedContent);
        $this->assertStringNotContainsString('@group', $convertedContent);
    }

    /**
     * --stats-only 옵션이 통계만 출력하는지 테스트합니다.
     *     * @return void
     */
    #[Test]
    public function it_shows_stats_only_without_converting(): void
    {
        // Given: @test annotation이 있는 파일
        $testFile = $this->tempDir . '/StatsTest.php';
        $content = <<<'PHP'
<?php

namespace Tests\Unit;

use Tests\TestCase;

class StatsTest extends TestCase
{
    /**
     * @test
     */
    public function it_should_not_be_converted(): void
    {
        $this->assertTrue(true);
    }
}
PHP;
        file_put_contents($testFile, $content);

        // When: --stats-only 옵션으로 실행
        $this->artisan('phpunit:convert-annotations', [
            '--path' => 'tests/temp_phpunit_convert',
            '--stats-only' => true,
        ])->assertSuccessful();

        // Then: 파일이 변환되지 않았는지 확인
        $unchangedContent = file_get_contents($testFile);
        $this->assertStringContainsString('@test', $unchangedContent);
        $this->assertStringNotContainsString('#[Test]', $unchangedContent);
    }
}
