<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuPermission extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'menu_permissions';

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
        'menu_id',
        'role_id',
        'user_id',
        'permission_type',
        'is_allowed',
        'granted_at',
        'granted_by'
    ];

    protected $casts = [
        'is_allowed' => 'boolean',
        'granted_at' => 'datetime'
    ];

    /**
     * 메뉴와의 관계를 정의합니다.
     *
     * @return BelongsTo
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * 역할과의 관계를 정의합니다.
     *
     * @return BelongsTo
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * 사용자와의 관계를 정의합니다.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 권한을 부여한 사용자와의 관계를 정의합니다.
     *
     * @return BelongsTo
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * 역할 기반 권한만 조회하는 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRoleBased($query)
    {
        return $query->whereNotNull('role_id')->whereNull('user_id');
    }

    /**
     * 사용자 기반 권한만 조회하는 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUserBased($query)
    {
        return $query->whereNotNull('user_id')->whereNull('role_id');
    }

    /**
     * 허용된 권한만 조회하는 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAllowed($query)
    {
        return $query->where('is_allowed', true);
    }

    /**
     * 거부된 권한만 조회하는 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDenied($query)
    {
        return $query->where('is_allowed', false);
    }
}
