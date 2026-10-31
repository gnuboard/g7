<?php

namespace Plugins\Sirsoft\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SSO 사이트 정책 1행.
 *
 * @property int $id
 * @property array<string, mixed> $policy
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @since 1.0.0
 */
class SsoSitePolicy extends Model
{
    /** @var string 테이블명 */
    protected $table = 'sso_site_policies';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = ['policy', 'updated_by'];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'policy' => 'array',
            'updated_by' => 'integer',
        ];
    }
}
