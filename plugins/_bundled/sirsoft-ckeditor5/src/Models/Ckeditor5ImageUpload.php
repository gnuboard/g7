<?php

namespace Plugins\Sirsoft\Ckeditor5\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

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
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read string $download_url 이미지 서빙 URL
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
     * 이미지 서빙 URL 반환 (API 라우트 경유)
     *
     * @return string 이미지 서빙 URL
     */
    public function getDownloadUrlAttribute(): string
    {
        return '/api/plugins/sirsoft-ckeditor5/images/'.$this->hash;
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
