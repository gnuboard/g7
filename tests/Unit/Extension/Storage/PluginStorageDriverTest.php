<?php

namespace Tests\Unit\Extension\Storage;

use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PluginStorageDriver 단위 테스트
 */
class PluginStorageDriverTest extends TestCase
{
    use RefreshDatabase;

    private PluginStorageDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 스토리지 드라이버 생성
        $this->driver = new PluginStorageDriver('test-plugin', 'plugins');

        // 테스트 디렉토리 초기화
        Storage::disk('plugins')->deleteDirectory('test-plugin');
    }

    protected function tearDown(): void
    {
        // 테스트 데이터 정리
        Storage::disk('plugins')->deleteDirectory('test-plugin');

        parent::tearDown();
    }

    #[Test]
    public function it_can_put_and_get_file(): void
    {
        // Given: 파일 내용
        $content = 'test content';

        // When: 파일 저장
        $result = $this->driver->put('settings', 'config.json', $content);

        // Then: 저장 성공
        $this->assertTrue($result);

        // And: 파일 읽기 성공
        $retrieved = $this->driver->get('settings', 'config.json');
        $this->assertEquals($content, $retrieved);
    }

    #[Test]
    public function it_returns_null_for_non_existent_file(): void
    {
        // When: 존재하지 않는 파일 읽기
        $result = $this->driver->get('settings', 'non-existent.json');

        // Then: null 반환
        $this->assertNull($result);
    }

    #[Test]
    public function it_can_check_file_existence(): void
    {
        // Given: 파일 저장
        $this->driver->put('settings', 'config.json', 'test');

        // When & Then: 파일 존재 확인
        $this->assertTrue($this->driver->exists('settings', 'config.json'));
        $this->assertFalse($this->driver->exists('settings', 'non-existent.json'));
    }

    #[Test]
    public function it_can_delete_file(): void
    {
        // Given: 파일 저장
        $this->driver->put('settings', 'config.json', 'test');

        // When: 파일 삭제
        $result = $this->driver->delete('settings', 'config.json');

        // Then: 삭제 성공
        $this->assertTrue($result);
        $this->assertFalse($this->driver->exists('settings', 'config.json'));
    }

    #[Test]
    public function it_can_list_files_in_category(): void
    {
        // Given: 여러 파일 저장
        $this->driver->put('data', 'file1.txt', 'content1');
        $this->driver->put('data', 'file2.txt', 'content2');
        $this->driver->put('data', 'subdir/file3.txt', 'content3');

        // When: 파일 목록 조회
        $files = $this->driver->files('data');

        // Then: 파일 목록 확인 (재귀적으로 모든 파일 포함)
        $this->assertIsArray($files);
        $this->assertNotEmpty($files);
    }

    #[Test]
    public function it_can_delete_directory(): void
    {
        // Given: 디렉토리와 파일 생성
        $this->driver->put('temp', 'dir1/file1.txt', 'content1');
        $this->driver->put('temp', 'dir1/file2.txt', 'content2');

        // When: 디렉토리 삭제
        $result = $this->driver->deleteDirectory('temp', 'dir1');

        // Then: 삭제 성공
        $this->assertTrue($result);
        $this->assertFalse($this->driver->exists('temp', 'dir1/file1.txt'));
    }

    #[Test]
    public function it_can_delete_all_files_in_category(): void
    {
        // Given: 여러 파일 저장
        $this->driver->put('temp', 'file1.txt', 'content1');
        $this->driver->put('temp', 'file2.txt', 'content2');
        $this->driver->put('temp', 'subdir/file3.txt', 'content3');

        // When: 카테고리 전체 삭제
        $result = $this->driver->deleteAll('temp');

        // Then: 삭제 성공
        $this->assertTrue($result);
        $this->assertFalse($this->driver->exists('temp', 'file1.txt'));
        $this->assertFalse($this->driver->exists('temp', 'file2.txt'));
        $this->assertFalse($this->driver->exists('temp', 'subdir/file3.txt'));
    }

    #[Test]
    public function it_returns_correct_base_path(): void
    {
        // When: 기본 경로 조회
        $basePath = $this->driver->getBasePath('settings');

        // Then: 올바른 경로 반환
        $expectedPath = Storage::disk('plugins')->path('test-plugin/settings');
        $this->assertEquals($expectedPath, $basePath);
    }

    #[Test]
    public function it_returns_correct_disk_name(): void
    {
        // When: 디스크 이름 조회
        $disk = $this->driver->getDisk();

        // Then: 'plugins' 반환
        $this->assertEquals('plugins', $disk);
    }

    #[Test]
    public function it_returns_null_for_url_on_private_disk(): void
    {
        // Given: local disk (private)
        $this->driver->put('data', 'test.json', 'json content');

        // When: URL 조회
        $url = $this->driver->url('data', 'test.json');

        // Then: null 반환 (private disk)
        $this->assertNull($url);
    }

    #[Test]
    public function it_returns_url_for_public_disk(): void
    {
        // Given: public disk 사용
        $publicDriver = new PluginStorageDriver('test-plugin', 'public');
        $publicDriver->put('data', 'test.json', 'json content');

        // When: URL 조회
        $url = $publicDriver->url('data', 'test.json');

        // Then: URL 반환
        $this->assertNotNull($url);
        $this->assertStringContainsString('test-plugin/data/test.json', $url);

        // Cleanup
        Storage::disk('public')->deleteDirectory('test-plugin');
    }

    #[Test]
    public function it_uses_correct_path_pattern(): void
    {
        // Given: 파일 저장
        $this->driver->put('data', 'analytics/2024/01/report.json', 'content');

        // When: 파일 존재 확인
        $exists = $this->driver->exists('data', 'analytics/2024/01/report.json');

        // Then: 올바른 경로로 저장됨
        $this->assertTrue($exists);

        // And: Storage Facade로도 확인 가능
        $storagePath = 'test-plugin/data/analytics/2024/01/report.json';
        $this->assertTrue(Storage::disk('plugins')->exists($storagePath));
    }

    #[Test]
    public function it_returns_streamed_response_for_existing_file(): void
    {
        // Given: 파일 저장
        $this->driver->put('data', 'report.pdf', 'pdf content');

        // When: StreamedResponse 생성
        $response = $this->driver->response('data', 'report.pdf', 'download.pdf', [
            'Content-Type' => 'application/pdf',
        ]);

        // Then: StreamedResponse 반환
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);
    }

    #[Test]
    public function it_returns_null_for_response_on_non_existent_file(): void
    {
        // When: 존재하지 않는 파일로 StreamedResponse 생성 시도
        $response = $this->driver->response('data', 'non-existent.pdf', 'download.pdf');

        // Then: null 반환
        $this->assertNull($response);
    }
}
