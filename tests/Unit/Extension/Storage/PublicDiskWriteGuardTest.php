<?php

namespace Tests\Unit\Extension\Storage;

use App\Contracts\Extension\StreamingStorageInterface;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 저장 드라이버의 최후 관문 — 공개 디스크에는 내보낼 확장자만 쓴다.
 *
 * 서비스 계층이 디스크를 잘못 골라도, 그대로 열리는 파일(`.html` 등)이 직접 주소로 나가는 디스크에 놓이지
 * 않게 저장 드라이버가 쓰기를 거부하고 경고 로그를 남긴다. 객체 저장소(S3)에는 형식 정보를 함께 실어,
 * 이미지가 아닌 파일이 직접 주소에서도 내려받기로만 열리게 한다.
 *
 * @scenario-manifest tests/scenarios/public-disk-exportable-extensions.yaml
 */
class PublicDiskWriteGuardTest extends TestCase
{
    /**
     * 세 드라이버를 `public` 디스크로 만드는 함수를 돌려줍니다.
     *
     * @return array<string, array{0: callable(string): StreamingStorageInterface}> 드라이버 이름 => 생성 함수
     */
    public static function drivers(): array
    {
        return [
            'core' => [fn (string $disk) => new CoreStorageDriver($disk)],
            'module' => [fn (string $disk) => new ModuleStorageDriver('guard-mod', $disk)],
            'plugin' => [fn (string $disk) => new PluginStorageDriver('guard-plugin', $disk)],
        ];
    }

    /**
     * 가짜 디스크와 기본 확장자 목록을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Config::set('core.storage.public_asset_disk', '');
        Config::set('core.storage.public_asset_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);
    }

    /**
     * 공개 디스크에 목록 밖 확장자를 쓰면 거부(false)하고 경고 로그를 남기며 파일을 만들지 않는다.
     *
     * @param  callable(string): StreamingStorageInterface  $make  드라이버 생성
     * @return void 반환값 없음
     *
     * @scenario case=write_guard__not_exportable
     *
     * @effects write_guard_refuses_and_logs
     */
    #[DataProvider('drivers')]
    public function test_put_to_public_disk_with_non_exportable_extension_is_refused(callable $make): void
    {
        Log::spy();
        $driver = $make('public');

        $this->assertFalse($driver->put('assets', 'x/evil.html', '<script></script>'));
        $this->assertFalse($driver->put('assets', 'x/shell.php', '<?php'));

        $this->assertSame([], array_values(array_filter(
            Storage::disk('public')->allFiles(),
            fn (string $file) => str_ends_with($file, '.html') || str_ends_with($file, '.php')
        )));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => ($context['disk'] ?? null) === 'public'
                && str_contains((string) ($context['path'] ?? ''), 'evil.html'))
            ->once();
    }

    /**
     * 스트림 쓰기도 같은 관문을 거친다.
     *
     * @param  callable(string): StreamingStorageInterface  $make  드라이버 생성
     * @return void 반환값 없음
     *
     * @scenario case=write_guard__not_exportable
     *
     * @effects write_guard_refuses_and_logs
     */
    #[DataProvider('drivers')]
    public function test_stream_write_to_public_disk_is_guarded_too(callable $make): void
    {
        Log::spy();
        $driver = $make('public');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, '<script></script>');
        rewind($stream);

        $this->assertFalse($driver->writeStream('assets', 'x/evil.svg', $stream));
        fclose($stream);

        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * 내보낼 확장자는 공개 디스크에 그대로 쓰이고, 공개 디스크가 아니면 어떤 확장자도 막지 않는다.
     *
     * @param  callable(string): StreamingStorageInterface  $make  드라이버 생성
     * @return void 반환값 없음
     *
     * @scenario case=write_guard__exportable
     *
     * @effects exportable_and_private_disk_writes_unaffected
     */
    #[DataProvider('drivers')]
    public function test_exportable_extension_and_private_disk_are_unaffected(callable $make): void
    {
        $this->assertTrue($make('public')->put('assets', 'x/ok.png', 'PNGDATA'));
        $this->assertTrue($make('local')->put('assets', 'x/doc.html', '<p>ok</p>'));
        $this->assertTrue($make('local')->put('assets', 'x/noext', 'data'));
    }

    /**
     * 운영자가 목록에 넣은 확장자는 공개 디스크에 쓸 수 있다.
     *
     * @param  callable(string): StreamingStorageInterface  $make  드라이버 생성
     * @return void 반환값 없음
     *
     * @scenario case=write_guard__operator_added
     *
     * @effects operator_added_extension_is_exported
     */
    #[DataProvider('drivers')]
    public function test_operator_added_extension_is_written_to_public_disk(callable $make): void
    {
        Config::set('core.storage.public_asset_extensions', ['png', 'pdf']);

        $this->assertTrue($make('public')->put('assets', 'x/guide.pdf', '%PDF-1.4'));
    }

    /**
     * 확장이 자기 기본 저장소로 쓰는 디스크가 공개 자산 디스크와 같은 저장 공간이면 관문이 막지 않는다.
     *
     * 게시판처럼 첨부 저장소를 객체 저장소에 두고 공개 자산 디스크도 같은 저장소로 고른 구성에서, 그 디스크는
     * 확장의 비공개 파일이 원래 놓이는 자리다. 관문이 막으면 pdf 같은 일반 첨부가 저장되지 않는다.
     * 다른 디스크를 기본으로 쓰는 확장이 공개 자산 디스크로 갈아 끼운 저장소와 Laravel `public` 디스크는 그대로 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=write_guard__shared_with_attachment_disk
     *
     * @effects write_guard_allows_attachment_disk_shared_with_public_asset_disk
     */
    public function test_extension_home_disk_shared_with_public_asset_disk_is_not_refused(): void
    {
        Storage::fake('fake_cdn');
        Config::set('filesystems.disks.fake_cdn', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/fake_cdn')]);
        Storage::fake('modules');
        Storage::fake('plugins');
        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        Config::set('attachment.disk', 'attachments');

        // 확장의 기본 저장소가 공개 자산 디스크와 같다 — 일반 첨부를 받는다.
        $this->assertTrue((new ModuleStorageDriver('guard-mod', 'fake_cdn'))->put('attachments', 'x/report.pdf', '%PDF-1.4'));
        $this->assertTrue((new PluginStorageDriver('guard-plugin', 'fake_cdn'))->put('data', 'x/report.pdf', '%PDF-1.4'));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'PK');
        rewind($stream);
        $this->assertTrue((new ModuleStorageDriver('guard-mod', 'fake_cdn'))->writeStream('attachments', 'x/files.zip', $stream));
        fclose($stream);

        // 다른 디스크가 기본인 확장이 공개 자산 디스크로 갈아 끼운 저장소는 계속 막는다.
        $this->assertFalse((new ModuleStorageDriver('guard-mod', 'modules'))->withDisk('fake_cdn')->put('images', 'x/evil.html', '<script></script>'));
        $this->assertFalse((new PluginStorageDriver('guard-plugin', 'plugins'))->withDisk('fake_cdn')->put('images', 'x/evil.html', '<script></script>'));

        // 기본 저장소가 Laravel public 디스크여도 public 은 언제나 관문 대상이다.
        $this->assertFalse((new ModuleStorageDriver('guard-mod', 'public'))->put('attachments', 'x/evil.html', '<script></script>'));
    }

    /**
     * 객체 저장소(S3)에는 내용 기준 Content-Type 을 싣고, 이미지가 아니면 내려받기 지시도 싣는다.
     *
     * @param  callable(string): StreamingStorageInterface  $make  드라이버 생성
     * @return void 반환값 없음
     *
     * @scenario case=object_storage_metadata__operator_added
     *
     * @effects object_storage_put_carries_content_type_and_attachment
     */
    #[DataProvider('drivers')]
    public function test_s3_put_carries_content_type_and_attachment_for_non_raster(callable $make): void
    {
        Config::set('filesystems.disks.fake_s3', ['driver' => 's3', 'bucket' => 'b', 'region' => 'r']);

        $captured = [];
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->andReturn(true);
        $filesystem->shouldReceive('put')->andReturnUsing(function (string $path, $content, $options = []) use (&$captured) {
            $captured[basename($path)] = $options;

            return true;
        });
        Storage::shouldReceive('disk')->with('fake_s3')->andReturn($filesystem);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $driver = $make('fake_s3');

        $this->assertTrue($driver->put('assets', 'x/ok.png', $png));
        $this->assertTrue($driver->put('assets', 'x/guide.pdf', "%PDF-1.4\n%%EOF\n"));

        $this->assertSame('image/png', $captured['ok.png']['ContentType'] ?? null);
        $this->assertArrayNotHasKey('ContentDisposition', $captured['ok.png']);

        $this->assertSame('application/pdf', $captured['guide.pdf']['ContentType'] ?? null);
        $this->assertSame('attachment', $captured['guide.pdf']['ContentDisposition'] ?? null);
    }

    /**
     * 객체 저장소가 아닌 디스크에는 옵션을 싣지 않는다(기존 호출 모양 유지).
     *
     * @param  callable(string): StreamingStorageInterface  $make  드라이버 생성
     * @return void 반환값 없음
     *
     * @scenario case=object_storage_metadata__exportable
     *
     * @effects exportable_and_private_disk_writes_unaffected
     */
    #[DataProvider('drivers')]
    public function test_local_disk_put_carries_no_options(callable $make): void
    {
        Config::set('filesystems.disks.fake_local', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/fake_local')]);

        $calls = [];
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->andReturn(true);
        $filesystem->shouldReceive('put')->andReturnUsing(function (...$args) use (&$calls) {
            $calls[] = $args;

            return true;
        });
        Storage::shouldReceive('disk')->with('fake_local')->andReturn($filesystem);

        $this->assertTrue($make('fake_local')->put('assets', 'x/guide.pdf', '%PDF-1.4'));
        $this->assertSame([], $calls[0][2] ?? []);
    }
}
