<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;

/**
 * 내보내기 작업
 *
 * 목록 화면에서 요청한 파일 생성 1건. 요청 조건(필터·필드·옵션)과 실행 컨텍스트를 함께 들고 있어
 * 큐 작업이 이 행 하나만 읽고 파일을 만든다. 저장 경로(`file_path`)는 화면·API 에 내지 않는다.
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property string $format
 * @property ExportStatusEnum $status
 * @property int|null $requested_by
 * @property array $options
 * @property array|null $filters
 * @property array|null $selected_ids
 * @property array|null $context
 * @property bool $contains_personal_data
 * @property array|null $warnings
 */
class Export extends Model
{
    use SoftDeletes;

    protected $table = 'ecommerce_exports';

    protected $fillable = [
        'uuid',
        'type',
        'format',
        'status',
        'requested_by',
        'requester_name_snapshot',
        'options',
        'filters',
        'selected_ids',
        'context',
        'contains_personal_data',
        'reason',
        'idempotency_key',
        'warnings',
        'locale',
        'timezone',
        'format_version',
        'file_name',
        'file_path',
        'file_size',
        'checksum_sha256',
        'total_rows',
        'processed_rows',
        'download_count',
        'error_code',
        'requested_at',
        'cutoff_at',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    /**
     * 화면·API 응답에 실리면 안 되는 컬럼 — 저장 경로와 실행 컨텍스트(IP 등)
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'file_path',
        'context',
        'idempotency_key',
    ];

    /**
     * 캐스팅 정의를 반환합니다.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'status' => ExportStatusEnum::class,
            'options' => 'array',
            'filters' => 'array',
            'selected_ids' => 'array',
            'context' => 'array',
            'warnings' => 'array',
            'contains_personal_data' => 'boolean',
            'file_size' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'download_count' => 'integer',
            'requested_at' => 'datetime',
            'cutoff_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * 요청자
     *
     * @return BelongsTo 회원 관계
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * 다운로드 이력
     *
     * @return HasMany 다운로드 이력 관계
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(ExportDownload::class, 'export_id');
    }

    /**
     * 활동 로그 설명의 이름 자리에 쓰는 표시명 — 파일명이 곧 이 작업의 이름이다.
     *
     * @return string|null 파일명
     */
    public function getNameAttribute(): ?string
    {
        return $this->file_name;
    }

    /**
     * 파일을 내려받을 수 있는 상태인지 반환합니다.
     *
     * 보관 기간이 지나면 정리 스케줄이 돌기 전이라도 받을 수 없다 — 정리는 만료 뒤 유예를 두고 파일을 지우므로,
     * 만료 직전에 시작한 다운로드가 전송 중에 파일을 잃지 않는다.
     *
     * @return bool 완료 상태이고 파일 경로가 있으며 보관 기간 안이면 true
     */
    public function isDownloadable(): bool
    {
        return $this->status === ExportStatusEnum::COMPLETED
            && ! empty($this->file_path)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * 보관 기간이 끝났는지 반환합니다(정리 전 포함).
     *
     * @return bool 만료 상태이거나 만료 시각이 지났으면 true
     */
    public function isPastRetention(): bool
    {
        return $this->status === ExportStatusEnum::EXPIRED || ($this->expires_at !== null && ! $this->expires_at->isFuture());
    }
}
