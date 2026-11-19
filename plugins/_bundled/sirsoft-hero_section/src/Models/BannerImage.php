<?php

namespace Plugins\Sirsoft\HeroSection\Models;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\PluginStorageDriver;
use App\Support\AssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Sirsoft\HeroSection\Database\Factories\BannerImageFactory;

/**
 * 배너 파일 — 기기(PC·모바일)·종류(이미지·영상)마다 1개. 테이블 이름은 이미지만 받던 때 그대로다.
 *
 * 공개 주소는 무작위 해시(`hash`)로 만들고 원본 경로는 응답에 싣지 않는다. 행 disk 가 직접 URL 을 내는 디스크(공개 자산
 * 디스크 — public·CDN)면 원본 한 장은 그 주소로도 받을 수 있다(`directUrl()`, #878).
 */
class BannerImage extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'hero_section_banner_images';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'banner_id', 'temp_key', 'device', 'kind', 'hash', 'disk', 'path',
        'original_name', 'mime', 'size', 'width', 'height', 'created_by',
    ];

    /**
     * 속성 캐스팅을 반환합니다.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'banner_id' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * 팩토리 인스턴스를 반환합니다.
     *
     * @return BannerImageFactory 이미지 팩토리
     */
    protected static function newFactory(): BannerImageFactory
    {
        return BannerImageFactory::new();
    }

    /**
     * 이 이미지가 속한 배너를 반환합니다.
     *
     * @return BelongsTo 배너 관계
     */
    public function banner(): BelongsTo
    {
        return $this->belongsTo(Banner::class, 'banner_id');
    }

    /**
     * 공개 이미지 주소를 반환합니다 — 썸네일(`?size=`)의 기준 주소이기도 하다.
     *
     * 화면이 `<img src>`·`<video src>` 에 그대로 넣는 주소라 사이트 마운트 접두사를 붙인다 — 하위 폴더 설치(`/g7/`)에서
     * 접두사가 없으면 사이트 밖을 부른다(라우트는 접두사 없이 등록되고 접두사는 여기서 붙는다).
     *
     * @return string 공개 이미지 경로
     */
    public function publicUrl(): string
    {
        return AssetUrl::sitePath('/api/plugins/sirsoft-hero_section/images/'.$this->hash);
    }

    /**
     * 행 disk 별 스토리지 드라이버 캐시 (목록 직렬화 루프에서 행마다 드라이버를 새로 만들지 않게)
     *
     * @var array<string, StorageInterface>
     */
    private static array $storageByDisk = [];

    /**
     * 원본 파일의 직접 주소를 반환합니다 — 행 disk 가 직접 URL 을 내지 않으면 null (#878).
     *
     * 행에 기록된 disk 의 드라이버 `url()` 이 주소를 주면 그것을 쓴다. 공개 자산 디스크 설정을 나중에 꺼도 그 디스크에
     * 남은 행은 계속 직접 주소를 낸다(제품 결정 — 확장 선례와 같음). 이 주소는 **원본 한 장**에만 쓴다 — 썸네일(`?size=`)과
     * 같은 출처가 필요한 자리(캔버스 색 추출 등)는 `publicUrl()` 을 쓴다.
     *
     * disk·path 가 비었거나, 그 disk 가 설정에서 사라졌거나(그 디스크를 주던 확장 비활성화), `core.storage.filter_url` 훅이
     * 막으면 null 이다. 반환값은 드라이버가 만든 절대 주소라 사이트 마운트 접두사를 따로 붙이지 않는다.
     *
     * @return string|null 직접 주소 (불가하면 null)
     */
    public function directUrl(): ?string
    {
        $disk = (string) ($this->disk ?? '');
        $path = (string) ($this->path ?? '');
        if ($disk === '' || $path === '' || config("filesystems.disks.{$disk}") === null) {
            return null;
        }

        try {
            $storage = self::$storageByDisk[$disk] ??= new PluginStorageDriver('sirsoft-hero_section', $disk);

            return $storage->url('images', $path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 원본 한 장을 받을 주소를 반환합니다 — 직접 주소가 있으면 그것, 없으면 공개 API 주소.
     *
     * @return string 원본 주소
     */
    public function mediaUrl(): string
    {
        return $this->directUrl() ?? $this->publicUrl();
    }

    /**
     * 행 disk 드라이버 캐시를 비웁니다 (테스트 격리용).
     *
     * @return void 반환값 없음
     */
    public static function flushStorageCache(): void
    {
        self::$storageByDisk = [];
    }
}
