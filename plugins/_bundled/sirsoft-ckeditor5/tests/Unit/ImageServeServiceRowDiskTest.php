<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Extension\PluginManager;
use App\Extension\Storage\PluginStorageDriver;
use App\Services\PluginSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Plugin;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Services\ImageServeService;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ImageServeService 행 disk 기준 서빙 테스트
 *
 * 공개 자산 디스크 전환 이후에도 전환 이전 행(구 disk)의 서빙이 404 가 되지 않고,
 * CDN 디스크로 기록된 행의 스트리밍 요청도 그 행의 실제 저장 위치를 향하는지
 * (혼재 운용 정합) 검증합니다.
 *
 * ImageServeControllerTest 는 모든 행이 주입 디스크('plugins')라서
 * withDisk 분기가 실행되지 않으므로 별도 파일로 둡니다.
 */
class ImageServeServiceRowDiskTest extends PluginTestCase
{
    private ImageServeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        Ckeditor5ImageUpload::flushStorageCache();

        $this->service = app(ImageServeService::class);
    }

    protected function tearDown(): void
    {
        Ckeditor5ImageUpload::flushStorageCache();

        parent::tearDown();
    }

    /**
     * 지정 disk 에 파일과 업로드 행을 함께 생성합니다.
     *
     * @param  string  $disk  행 storage_disk
     * @param  string  $filePath  "카테고리/상대경로" 형태의 file_path
     * @return Ckeditor5ImageUpload 생성된 행
     */
    private function makeRowOnDisk(string $disk, string $filePath): Ckeditor5ImageUpload
    {
        [$category, $relativePath] = explode('/', $filePath, 2);

        (new PluginStorageDriver('sirsoft-ckeditor5', $disk))->put($category, $relativePath, 'image-bytes');

        return Ckeditor5ImageUpload::create([
            'original_name' => basename($filePath),
            'file_path' => $filePath,
            'storage_disk' => $disk,
            'file_size' => 11,
            'mime_type' => 'image/jpeg',
            'uploaded_by' => null,
        ]);
    }

    /**
     * @effects serve_and_delete_follow_row_disk, mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function serve_streams_row_from_its_recorded_disk(): void
    {
        // 혼재 운용 — 주입 스토리지 disk('plugins')와 다른 disk 의 행도 서빙 가능해야 한다
        $cdnRow = $this->makeRowOnDisk('fake_cdn', 'images/row-disk-test/cdn.jpg');
        $localRow = $this->makeRowOnDisk('plugins', 'images/row-disk-test/local.jpg');

        $this->assertInstanceOf(StreamedResponse::class, $this->service->serve($cdnRow));
        $this->assertInstanceOf(StreamedResponse::class, $this->service->serve($localRow));
    }

    /**
     * 외부 생성 행(첫 세그먼트가 'images' 가 아닌 경우)도 카테고리 일반형 분해로
     * 행 disk 에서 서빙된다 — ApiDoc 샘플(`ckeditor5/apidoc-sample.png`) 형태.
     *
     * @effects serve_and_delete_follow_row_disk
     */
    #[Test]
    public function serve_resolves_non_images_prefix_on_row_disk(): void
    {
        $row = $this->makeRowOnDisk('fake_cdn', 'ckeditor5/row-disk-sample.png');

        $this->assertInstanceOf(StreamedResponse::class, $this->service->serve($row));
    }

    /**
     * 행 disk 에 파일이 없으면 주입 디스크로 몰래 폴백하지 않고 null 을 반환한다
     * (다른 디스크의 동명 파일을 잘못 서빙하는 회귀 차단).
     *
     * @effects serve_and_delete_follow_row_disk
     */
    #[Test]
    public function serve_returns_null_when_file_absent_on_row_disk(): void
    {
        // 파일은 주입 디스크에만 두고, 행은 CDN 디스크로 기록
        (new PluginStorageDriver('sirsoft-ckeditor5', 'plugins'))
            ->put('images', 'row-disk-test/only-local.jpg', 'image-bytes');

        $row = Ckeditor5ImageUpload::create([
            'original_name' => 'only-local.jpg',
            'file_path' => 'images/row-disk-test/only-local.jpg',
            'storage_disk' => 'fake_cdn',
            'file_size' => 11,
            'mime_type' => 'image/jpeg',
            'uploaded_by' => null,
        ]);

        $this->assertNull($this->service->serve($row));
    }

    /**
     * 고아 disk 행의 서빙은 500 이 아니라 스트리밍 폴백이어야 한다.
     *
     * 디스크를 제공하던 플러그인이 비활성화되면 행 storage_disk 가 config 에서 사라진다.
     * 이때 미등록 disk 로 withDisk 를 만들면 response 가 InvalidArgumentException 을
     * 던져 에디터 이미지 서빙이 500 이 된다.
     *
     * @effects orphan_disk_falls_back_to_streaming
     */
    #[Test]
    public function serve_falls_back_when_row_disk_is_orphaned(): void
    {
        $row = $this->makeRowOnDisk('fake_cdn', 'images/row-disk-test/orphan.jpg');

        Config::set('filesystems.disks.fake_cdn', null);
        Storage::forgetDisk('fake_cdn');
        Ckeditor5ImageUpload::flushStorageCache();

        // 예외 없이 폴백 — 파일은 도달 불가이므로 null(404) 이 정상 degradation
        $this->assertNull($this->service->serve($row));
    }

    /**
     * SP 배선(`$storageCategoryServices`)이 주입하는 것과 동일한 스토리지로 서비스를 만듭니다.
     *
     * PluginTestCase 는 ImageUploadService 의 StorageInterface 를 'plugins' 고정으로
     * 하드바인딩하므로, 컨테이너 해석으로는 카테고리 디스크 배선을 검증할 수 없습니다.
     * 여기서는 실제 플러그인 인스턴스의 getStorageFor('images') 를 직접 주입해
     * 배선이 주는 것과 같은 조건을 재현합니다.
     */
    private function makeUploadServiceWithCategoryStorage(): ImageUploadService
    {
        $plugin = app(PluginManager::class)->getPlugin('sirsoft-ckeditor5')
            ?? new Plugin(base_path('plugins/sirsoft-ckeditor5'));

        return new ImageUploadService(
            app(ImageUploadRepositoryInterface::class),
            $plugin->getStorageFor('images')
        );
    }

    /**
     * 플러그인 개별 오버라이드를 지정 값으로 고정합니다.
     *
     * PluginTestCase 는 플러그인 설정을 격리하지 않아, 고정하지 않으면
     * `plugin_setting()` 이 개발 머신의 실제 저장값(storage/app/plugins/…)을 읽는다.
     * 오버라이드는 코어 전역보다 우선하므로 그 값이 남아 있으면 전역 설정을 세우는
     * 이 테스트가 개발자 환경에 따라 통과/실패한다 — 두 입력을 모두 통제한다.
     *
     * @param  string  $override  플러그인 설정 public_asset_disk 값 ('' = 코어 따름)
     */
    private function fixPluginOverride(string $override): void
    {
        $stub = new class($override) extends PluginSettingsService
        {
            public function __construct(private string $override) {}

            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                if ($key === 'public_asset_disk') {
                    return $this->override;
                }

                return $default;
            }
        };

        $this->app->instance(PluginSettingsService::class, $stub);
    }

    /**
     * put 종단 — 공개 자산 디스크 설정값이 신규 행의 storage_disk 에 기록되고,
     * 그 행이 다시 자기 disk 로 서빙되는지 단언합니다(배선 회귀 검출).
     *
     * @effects mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function upload_records_configured_public_asset_disk_on_row(): void
    {
        $this->fixPluginOverride('');
        Config::set('core.storage.public_asset_disk', 'fake_cdn');

        $record = $this->makeUploadServiceWithCategoryStorage()
            ->upload(UploadedFile::fake()->image('cdn-editor.jpg', 40, 40), null);

        $this->assertSame('fake_cdn', $record->storage_disk);
        $this->assertInstanceOf(StreamedResponse::class, $this->service->serve($record));
    }

    /**
     * put 종단 — 공개 자산 디스크 미설정이면 신규 행이 기본 디스크로 기록(현행 보존).
     *
     * @effects mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function upload_records_base_disk_when_public_asset_disk_unset(): void
    {
        $this->fixPluginOverride('');
        Config::set('core.storage.public_asset_disk', '');

        $record = $this->makeUploadServiceWithCategoryStorage()
            ->upload(UploadedFile::fake()->image('local-editor.jpg', 40, 40), null);

        $this->assertSame('plugins', $record->storage_disk);
    }
}
