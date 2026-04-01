<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Theme extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'themes';

    /**
     * 기본키
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 타임스탬프 사용 여부
     *
     * @var bool
     */
    public $timestamps = true;

    protected $fillable = [
        'name',
        'type',
        'target',
        'module_name',
        'version',
        'status',
        'config',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    /**
     * 테마가 활성화되어 있는지 확인합니다.
     *
     * @return bool 활성화 상태 여부
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * 코어 테마인지 확인합니다.
     *
     * @return bool 코어 테마 여부
     */
    public function isCore(): bool
    {
        return $this->type === 'core';
    }

    /**
     * 모듈 테마인지 확인합니다.
     *
     * @return bool 모듈 테마 여부
     */
    public function isModule(): bool
    {
        return $this->type === 'module';
    }

    /**
     * 관리자용 테마인지 확인합니다.
     *
     * @return bool 관리자용 테마 여부
     */
    public function isAdmin(): bool
    {
        return $this->target === 'admin';
    }

    /**
     * 사용자용 테마인지 확인합니다.
     *
     * @return bool 사용자용 테마 여부
     */
    public function isUser(): bool
    {
        return $this->target === 'user';
    }

    /**
     * 테마의 파일 시스템 경로를 반환합니다.
     *
     * @return string 테마 파일 경로
     */
    public function getPath(): string
    {
        $basePath = resource_path('themes');
        
        if ($this->isCore()) {
            return $basePath . '/core/' . $this->target . '/' . $this->name;
        }
        
        return $basePath . '/modules/' . $this->module_name . '/' . $this->target . '/' . $this->name;
    }

    /**
     * 테마를 생성한 사용자와의 관계를 정의합니다.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
