<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 템플릿 레이아웃 첨부 파일 모델
 *
 * 레이아웃 편집 중 업로드되는 파일(배경 이미지 등)을 표현한다. 파일 자체는
 * 코어 StorageInterface 로 저장되고 본 모델은 저장 위치(disk/path)와 메타데이터를
 * 보관한다. 템플릿 삭제 시 행은 cascadeOnDelete 로 삭제되나, 스토리지 파일
 * 실삭제는 Service 가 명시적으로 수행한다(코어 규정: DB CASCADE 의존 삭제 금지).
 *
 * @property int $id 첨부 파일 ID
 * @property int $template_id 소속 템플릿 ID
 * @property string|null $layout_name 사용 출처 레이아웃 이름
 * @property string $disk 스토리지 디스크 이름
 * @property string $path 스토리지 내 파일 경로
 * @property string $original_name 업로드 원본 파일명
 * @property string $mime_type MIME 타입
 * @property int $size 파일 크기(바이트)
 * @property int|null $created_by 업로더 사용자 ID
 */
class TemplateLayoutAttachment extends Model
{
    use HasFactory;

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'template_id',
        'layout_name',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
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
            'template_id' => 'integer',
            'size' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * 소속 템플릿 관계
     *
     * @return BelongsTo<Template, TemplateLayoutAttachment>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
