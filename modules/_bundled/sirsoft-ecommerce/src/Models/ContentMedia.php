<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;

/**
 * 이커머스 본문 미디어 모델 — 상품 설명·공통 정보 편집기 본문의 이미지·동영상·오디오 (#883 4단계)
 *
 * @property int $id
 * @property string $hash
 * @property ContentMediaOwnerType $owner_type
 * @property int|null $owner_id
 * @property string|null $temp_key
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $stored_filename
 * @property string $mime_type
 * @property int $size
 * @property string $kind
 * @property array|null $meta
 * @property int|null $created_by
 */
class ContentMedia extends Model
{
    use SoftDeletes;

    /**
     * 테이블명
     */
    protected $table = 'ecommerce_content_media';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'hash', 'owner_type', 'owner_id', 'temp_key', 'disk', 'path', 'original_filename', 'stored_filename',
        'mime_type', 'size', 'kind', 'meta', 'created_by',
    ];

    /**
     * 직렬화에서 숨길 속성 — 임시 키는 연결 권한이라 응답에 싣지 않는다
     *
     * @var array<int, string>
     */
    protected $hidden = ['temp_key'];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_type' => ContentMediaOwnerType::class,
            'owner_id' => 'integer',
            'size' => 'integer',
            'meta' => 'array',
            'created_by' => 'integer',
        ];
    }

    /**
     * 만들 때 고유 해시를 붙입니다.
     *
     * @return void 반환값 없음
     */
    protected static function booted(): void
    {
        static::creating(function (ContentMedia $media) {
            if (empty($media->hash)) {
                do {
                    $hash = Str::random(12);
                } while (self::withTrashed()->where('hash', $hash)->exists());
                $media->hash = $hash;
            }
        });
    }
}
