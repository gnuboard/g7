<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 이커머스 사용자 프로필 모델 (A3)
 *
 * 유저별 결제 통화·배송국가 등 커머스 전용 사용자 설정을 보관합니다. 코어 users 테이블을
 * 건드리지 않고 user_id FK 로 연결됩니다(ecommerce_user_addresses 선례). 모듈 미설치 시
 * 테이블이 부재하므로 "유저별 통화/배송국가 = 커머스 책임" 설치 게이트를 구조적으로 충족합니다(A5).
 */
class EcommerceUserProfile extends Model
{
    protected $table = 'ecommerce_user_profiles';

    protected $fillable = [
        'user_id',
        'preferred_currency',
        'preferred_shipping_country',
    ];

    /**
     * 소유 사용자 관계 (코어 User)
     *
     * @return BelongsTo 코어 User 모델과의 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
