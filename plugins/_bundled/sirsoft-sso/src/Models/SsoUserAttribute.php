<?php

namespace Plugins\Sirsoft\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 회원의 IdP 추가 속성 1행 (부서, 직급, 사번 등 — 코어 `users` 스키마 밖의 값).
 *
 * @property int $id
 * @property int $user_id
 * @property int $idp_id
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $synced_at
 *
 * @since 1.0.0
 */
class SsoUserAttribute extends Model
{
    /** @var bool created_at, updated_at 없음 (`synced_at` 만) */
    public $timestamps = false;

    /** @var string 테이블명 */
    protected $table = 'sso_user_attributes';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = ['user_id', 'idp_id', 'key', 'value', 'synced_at'];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'idp_id' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
