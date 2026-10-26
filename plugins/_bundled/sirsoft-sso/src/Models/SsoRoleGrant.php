<?php

namespace Plugins\Sirsoft\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 역할 매핑이 실제로 붙인 역할 1행.
 *
 * 「매핑 역할만 회수」 모드는 이 기록이 있는 역할만 뗀다. 회원이 이미 수동으로 갖고 있던 역할은 기록하지 않는다.
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property int $idp_id
 * @property Carbon|null $granted_at
 *
 * @since 1.0.0
 */
class SsoRoleGrant extends Model
{
    /** @var bool created_at, updated_at 없음 (`granted_at` 만) */
    public $timestamps = false;

    /** @var string 테이블명 */
    protected $table = 'sso_role_grants';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = ['user_id', 'role_id', 'idp_id', 'granted_at'];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'role_id' => 'integer',
            'idp_id' => 'integer',
            'granted_at' => 'datetime',
        ];
    }
}
