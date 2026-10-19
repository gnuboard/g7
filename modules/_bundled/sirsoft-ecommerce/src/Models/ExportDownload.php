<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 내보내기 파일 다운로드 이력 (원장)
 *
 * 파일 스트림을 시작한 시점에 1행. 작업 행의 `download_count` 는 이 원장의 캐시다.
 */
class ExportDownload extends Model
{
    protected $table = 'ecommerce_export_downloads';

    protected $fillable = [
        'export_id',
        'user_id',
        'user_name_snapshot',
        'ip_address',
        'user_agent',
        'downloaded_at',
    ];

    /**
     * 캐스팅 정의를 반환합니다.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * 내보내기 작업
     *
     * @return BelongsTo 작업 관계 (삭제된 작업 포함)
     */
    public function export(): BelongsTo
    {
        return $this->belongsTo(Export::class, 'export_id')->withTrashed();
    }

    /**
     * 내려받은 회원
     *
     * @return BelongsTo 회원 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
