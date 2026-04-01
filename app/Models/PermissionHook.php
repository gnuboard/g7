<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 권한-훅 매핑 모델
 *
 * 특정 훅 실행 시 필요한 권한을 정의합니다.
 * 훅에 권한이 매핑되어 있으면 해당 권한을 가진 사용자만 훅을 실행할 수 있습니다.
 * 훅에 권한이 매핑되어 있지 않으면 모든 사용자가 훅을 실행할 수 있습니다.
 */
class PermissionHook extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'permission_id',
        'hook_name',
    ];

    /**
     * 권한 관계
     *
     * @return BelongsTo<Permission, PermissionHook>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * 특정 훅에 매핑된 권한 목록 조회
     *
     * @param string $hookName 훅 이름
     * @return Collection<int, Permission>
     */
    public static function getPermissionsForHook(string $hookName): Collection
    {
        return Permission::whereHas('hooks', function ($query) use ($hookName) {
            $query->where('hook_name', $hookName);
        })->get();
    }

    /**
     * 특정 훅에 권한이 매핑되어 있는지 확인
     *
     * @param string $hookName 훅 이름
     * @return bool
     */
    public static function hasPermissionMapping(string $hookName): bool
    {
        return static::where('hook_name', $hookName)->exists();
    }
}
