<?php

namespace App\Models;

use App\Enums\AttachmentSourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 첨부파일 모델
 *
 * 다형성 구조로 여러 엔티티에서 공용으로 사용할 수 있는 첨부파일 모델입니다.
 */
class Attachment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'attachmentable_type',
        'attachmentable_id',
        'source_type',
        'source_identifier',
        'hash',
        'original_filename',
        'stored_filename',
        'disk',
        'path',
        'mime_type',
        'size',
        'collection',
        'order',
        'meta',
        'created_by',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'source_type' => AttachmentSourceType::class,
            'size' => 'integer',
            'order' => 'integer',
            'attachmentable_id' => 'integer',
        ];
    }

    /**
     * 모델 부팅 시 해시 자동 생성
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Attachment $attachment) {
            if (empty($attachment->hash)) {
                $attachment->hash = self::generateUniqueHash();
            }
        });
    }

    /**
     * 고유한 12자 해시 생성
     *
     * @return string 고유 해시
     */
    public static function generateUniqueHash(): string
    {
        do {
            $hash = Str::random(12);
        } while (self::where('hash', $hash)->exists());

        return $hash;
    }

    /**
     * 다형성 관계 - 첨부 대상
     */
    public function attachmentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 업로더 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 파일의 전체 경로 반환
     *
     * @return string 전체 파일 경로
     */
    public function getFullPathAttribute(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    /**
     * 다운로드 URL 반환 (해시 기반)
     *
     * @return string 다운로드 URL
     */
    public function getDownloadUrlAttribute(): string
    {
        return '/api/attachment/'.$this->hash;
    }

    /**
     * 이미지 여부 확인
     *
     * @return bool 이미지 여부
     */
    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * 파일 크기 포맷팅 (KB, MB 등)
     *
     * @return string 포맷된 파일 크기
     */
    public function getSizeFormattedAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * 특정 컬렉션으로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $collection  컬렉션명
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /**
     * 특정 소스 타입으로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  AttachmentSourceType  $sourceType  소스 타입
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeBySourceType(Builder $query, AttachmentSourceType $sourceType): Builder
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * 특정 소스 식별자로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $identifier  소스 식별자
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeBySourceIdentifier(Builder $query, string $identifier): Builder
    {
        return $query->where('source_identifier', $identifier);
    }

    /**
     * 이미지만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    /**
     * 정렬된 상태로 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 정렬된 쿼리 빌더
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order')->orderBy('id');
    }
}
