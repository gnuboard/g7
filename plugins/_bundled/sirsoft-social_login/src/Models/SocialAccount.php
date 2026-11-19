<?php

namespace Plugins\Sirsoft\SocialLogin\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\SocialLogin\Concerns\SavesOverUnreadableEncryptedValues;

/**
 * 회원과 제공자 계정의 연결 1행.
 *
 * 토큰과 원본 프로필은 encrypted 캐스트로 저장하고 직렬화에서 숨긴다 — 훅 인자나 API 응답에 실리지 않게.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider_key
 * @property string $provider_user_id
 * @property string|null $email
 * @property bool|null $email_verified
 * @property string|null $name
 * @property string|null $nickname
 * @property string|null $avatar_url
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property array<string, mixed>|null $profile_raw
 * @property Carbon|null $profile_raw_expires_at
 * @property Carbon $linked_at
 * @property Carbon|null $last_login_at
 *
 * @since 1.0.0
 */
class SocialAccount extends Model
{
    use SavesOverUnreadableEncryptedValues;

    /** @var string 테이블명 */
    protected $table = 'social_accounts';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = [
        'user_id', 'provider_key', 'provider_user_id', 'email', 'email_verified', 'name', 'nickname', 'avatar_url',
        'access_token', 'refresh_token', 'token_expires_at', 'profile_raw', 'profile_raw_expires_at',
        'linked_at', 'last_login_at',
    ];

    /** @var array<int, string> 직렬화에서 숨길 비밀 컬럼 */
    protected $hidden = ['access_token', 'refresh_token', 'profile_raw'];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'profile_raw' => 'encrypted:array',
            'profile_raw_expires_at' => 'datetime',
            'linked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * 연결된 회원.
     *
     * @return BelongsTo<User, $this> 회원 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
