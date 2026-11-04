<?php

namespace Plugins\Sirsoft\HeroSection\Models;

use App\Support\AssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Sirsoft\HeroSection\Database\Factories\BannerImageFactory;

/**
 * 배너 파일 — 기기(PC·모바일)·종류(이미지·영상)마다 1개. 테이블 이름은 이미지만 받던 때 그대로다.
 *
 * 공개 주소는 무작위 해시(`hash`)로 만들고 원본 경로는 응답에 싣지 않는다.
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
     * 공개 이미지 주소를 반환합니다.
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
}
