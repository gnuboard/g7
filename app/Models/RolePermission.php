<?php

namespace App\Models;

use App\Enums\ScopeType;
use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'role_permissions';

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
        'role_id',
        'permission_id',
        'scope_type',
        'granted_at',
        'granted_by',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'scope_type' => ScopeType::class,
    ];
}
