<?php

namespace Plugins\Sirsoft\Ckeditor5\Models;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\PluginStorageDriver;
use App\Models\User;
use App\Support\AssetUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CKEditor5 이미지 업로드 기록 모델
 *
 * @property int $id
 * @property string $hash URL용 고유 해시 (12자)
 * @property string $original_name 원본 파일명
 * @property string $file_path 저장 파일 경로
 * @property string $storage_disk 스토리지 디스크
 * @property int $file_size 파일 크기 (bytes)
 * @property string $mime_type MIME 타입
 * @property int|null $uploaded_by 업로드 사용자 ID
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string $download_url 원본 이미지 URL (직접 URL 우선, 불가 시 API 서빙 경로)
 * @property-read string $preview_url 썸네일 기준 URL (항상 API 서빙 경로)
 */
class Ckeditor5ImageUpload extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ckeditor5_image_uploads';

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'hash',
        'original_name',
        'file_path',
        'storage_disk',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    /**
     * 모델 부트 메서드
     *
     * @return void 반환값 없음
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->hash)) {
                $model->hash = self::generateHash();
            }
        });
    }

    /**
     * 고유 해시를 생성합니다.
     *
     * @return string 12자리 고유 해시
     */
    public static function generateHash(): string
    {
        do {
            $hash = substr(bin2hex(random_bytes(6)), 0, 12);
        } while (self::where('hash', $hash)->exists());

        return $hash;
    }

    /**
     * 행 disk 별 스토리지 드라이버 캐시 (직렬화 루프 대응 memoize)
     *
     * @var array<string, StorageInterface>
     */
    private static array $storageByDisk = [];

    /**
     * 원본 이미지 URL 반환 — 직접 URL(CDN) 우선, 불가 시 API 라우트 폴백
     *
     * 행에 기록된 storage_disk 로 직접 URL 생성을 시도하고, 불가하면(로컬 디스크,
     * url 미설정 디스크, 훅 차단 등) 기존 API 서빙 경로로 폴백합니다.
     *
     * 직접 URL 은 썸네일 파이프(`?size=`)의 대상이 아니다 — 썸네일은 {@see getPreviewUrlAttribute()}
     * (항상 API 서빙 경로)에 건다.
     *
     * @return string 이미지 URL
     */
    public function getDownloadUrlAttribute(): string
    {
        return $this->resolveDirectUrl() ?? $this->apiUrl();
    }

    /**
     * 썸네일 기준 URL 반환 — 항상 API 서빙 경로
     *
     * 관리 화면 미리보기·본문 srcset 후보처럼 `?size=` 를 붙일 자리용. API 경로에 `?size=` 를
     * 붙이면 서버가 썸네일을 만들고, 공개 썸네일 디스크가 있으면 그 주소로 302 한다.
     * 직접 URL 이 없는 행은 `download_url` 과 같다.
     *
     * @return string API 서빙 URL
     */
    public function getPreviewUrlAttribute(): string
    {
        return $this->apiUrl();
    }

    /**
     * hash 기반 API 서빙 경로를 반환합니다.
     *
     * 마운트 접두사를 붙인다 — 직접 URL 은 스토리지 드라이버가 만들어 이미 접두사를 갖지만
     * 이 손조립은 갖지 않았다. 그 값이 본문 HTML 에 굳어 저장되므로 하위 폴더 배치에서는
     * 나중에 고쳐도 옛 글이 계속 깨진 주소를 갖는다.
     *
     * @return string `{접두사}/api/plugins/sirsoft-ckeditor5/images/{hash}`
     */
    public function apiUrl(): string
    {
        return AssetUrl::basePrefix().'/api/plugins/sirsoft-ckeditor5/images/'.$this->hash;
    }

    /**
     * 행 storage_disk 기준 직접 URL 해석을 시도합니다.
     *
     * file_path 는 카테고리 prefix 를 포함하므로("images/..." 또는 "ckeditor5/..." 등
     * 외부 생성 행 포함) 첫 세그먼트를 카테고리로 분해하는 일반형으로 처리합니다.
     *
     * @return string|null 직접 URL (불가하면 null)
     */
    protected function resolveDirectUrl(): ?string
    {
        $disk = (string) ($this->storage_disk ?? '');
        $filePath = (string) ($this->file_path ?? '');

        if ($disk === '' || $filePath === '' || ! str_contains($filePath, '/')) {
            return null;
        }

        [$category, $relativePath] = explode('/', $filePath, 2);

        if ($category === '' || $relativePath === '') {
            return null;
        }

        $storage = self::$storageByDisk[$disk] ??= new PluginStorageDriver('sirsoft-ckeditor5', $disk);

        return $storage->url($category, $relativePath);
    }

    /**
     * 행 disk 드라이버 memoize 캐시를 비웁니다 (테스트 격리용).
     *
     * @return void 반환값 없음
     */
    public static function flushStorageCache(): void
    {
        self::$storageByDisk = [];
    }

    /**
     * 업로드한 사용자
     *
     * @return BelongsTo<User, Ckeditor5ImageUpload>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
