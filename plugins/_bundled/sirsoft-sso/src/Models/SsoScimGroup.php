<?php

namespace Plugins\Sirsoft\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * IdP 가 SCIM 으로 보낸 그룹 1행 — 역할 매핑의 입력(그룹 출처 `scim`)으로만 쓴다.
 *
 * @property int $id
 * @property int $idp_id
 * @property string|null $external_id
 * @property string $display_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @since 1.0.0
 */
class SsoScimGroup extends Model
{
    /** 소속 표 */
    public const MEMBERS_TABLE = 'sso_scim_group_members';

    /** @var string 테이블명 */
    protected $table = 'sso_scim_groups';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = ['idp_id', 'external_id', 'display_name'];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'idp_id' => 'integer',
        ];
    }

    /**
     * 이 그룹에 속한 회원 연결.
     *
     * @return BelongsToMany<SsoAccount, $this> 소속 관계
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(SsoAccount::class, self::MEMBERS_TABLE, 'group_id', 'sso_account_id');
    }
}
