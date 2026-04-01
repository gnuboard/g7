<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 비밀번호 재설정 토큰 모델
 *
 * @property string $email
 * @property string $token
 * @property \Carbon\Carbon|null $created_at
 */
class PasswordResetToken extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'password_reset_tokens';

    /**
     * 기본 키
     *
     * @var string
     */
    protected $primaryKey = 'email';

    /**
     * 기본 키 자동 증가 여부
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * 기본 키 타입
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * 타임스탬프 사용 여부 (created_at만 있고 updated_at 없음)
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<string>
     */
    protected $fillable = [
        'email',
        'token',
        'created_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
    ];
}