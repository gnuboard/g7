<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 회원 이전 비밀번호 해시 이력 모델
 *
 * 재사용 금지 판정에만 쓴다. 현재 비밀번호는 users.password 와 직접 비교하므로
 * 여기에는 이전 해시만 있고, 회원당 최대 9건을 유지한다.
 *
 * @since 7.1.0
 */
class UserPasswordHistory extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'user_password_histories';

    /**
     * created_at 만 쓴다 (그 비밀번호를 그만 쓴 일시).
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'password',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * 이력의 회원
     *
     * @return BelongsTo 회원 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
