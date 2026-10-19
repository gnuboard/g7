<?php

namespace Plugins\Sirsoft\SocialLogin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 소셜 로그인 시도 1건 (통계·진단 원천).
 *
 * 생성 시각만 있다 — `created_at` 은 DB 기본값(useCurrent)이 채운다.
 *
 * @property int $id
 * @property string $provider_key
 * @property int|null $user_id
 * @property string $intent
 * @property string $outcome
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @since 1.0.0
 */
class SocialLoginAttempt extends Model
{
    /** @var string 테이블명 */
    protected $table = 'social_login_attempts';

    /** @var bool updated_at 이 없는 기록 테이블 */
    public $timestamps = false;

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = [
        'provider_key', 'user_id', 'intent', 'outcome', 'error_code', 'error_message', 'ip', 'user_agent', 'created_at',
    ];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
