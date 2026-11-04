<?php

namespace Plugins\Sirsoft\Popup\Models;

use App\Support\AssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Sirsoft\Popup\Database\Factories\PopupImageFactory;

/**
 * 팝업 이미지 (PC·모바일 각 1장)
 *
 * 공개 주소는 무작위 해시(`hash`)로 만들고 원본 경로는 응답에 싣지 않는다.
 */
class PopupImage extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'popup_images';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'popup_id', 'temp_key', 'device', 'hash', 'disk', 'path',
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
            'popup_id' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * 팩토리 인스턴스를 반환합니다.
     *
     * @return PopupImageFactory 이미지 팩토리
     */
    protected static function newFactory(): PopupImageFactory
    {
        return PopupImageFactory::new();
    }

    /**
     * 이 이미지가 속한 팝업을 반환합니다.
     *
     * @return BelongsTo 팝업 관계
     */
    public function popup(): BelongsTo
    {
        return $this->belongsTo(Popup::class, 'popup_id');
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
        return AssetUrl::sitePath('/api/plugins/sirsoft-popup/images/'.$this->hash);
    }
}
