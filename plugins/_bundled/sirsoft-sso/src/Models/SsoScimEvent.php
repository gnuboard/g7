<?php

namespace Plugins\Sirsoft\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SCIM 수신 이력 1행 — 요청 본문은 저장하지 않는다(개인정보).
 *
 * @property int $id
 * @property int|null $idp_id
 * @property string $method
 * @property string $resource
 * @property string|null $target_id
 * @property int $status
 * @property string|null $error_code
 * @property Carbon|null $created_at
 *
 * @since 1.0.0
 */
class SsoScimEvent extends Model
{
    /** updated_at 없음 */
    public const UPDATED_AT = null;

    /** @var string 테이블명 */
    protected $table = 'sso_scim_events';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = ['idp_id', 'method', 'resource', 'target_id', 'status', 'error_code'];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'idp_id' => 'integer',
            'status' => 'integer',
        ];
    }
}
