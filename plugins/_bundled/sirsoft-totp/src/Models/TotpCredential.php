<?php

namespace Plugins\Sirsoft\Totp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * 사용자별 인증 앱(TOTP) 자격증명 (1:1).
 *
 * - `secret` 은 `encrypted` cast 로 저장되어 DB 덤프만으로는 코드를 만들 수 없다.
 * - `backup_codes` 는 bcrypt 해시 배열이다 — 평문은 확정·재발급 응답에서 한 번만 보여 준다.
 * - 사용자 탈퇴/삭제 시 `CleanTotpCredentialOnUser*` listener 가 명시 삭제한다.
 *
 * @since 1.0.0
 */
class TotpCredential extends Model
{
    /**
     * 모델과 연결되는 테이블명.
     */
    protected $table = 'totp_credentials';

    /**
     * 대량 할당 가능한 속성.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'secret',
        'confirmed_at',
        'last_used_timestep',
        'backup_codes',
    ];

    /**
     * 직렬화에서 제외할 속성.
     *
     * 시크릿과 백업 코드 해시는 어떤 API 응답에도 실리면 안 된다 — 모델을 그대로
     * 돌려주는 경로가 생겨도 여기서 막힌다.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'secret',
        'backup_codes',
    ];

    /**
     * 속성 캐스팅.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'backup_codes' => 'array',
            'confirmed_at' => 'datetime',
            'last_used_timestep' => 'integer',
        ];
    }

    /**
     * 자격증명 소유자.
     *
     * @return BelongsTo<User, TotpCredential> 사용자 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 등록이 확정된 자격증명인지 여부.
     *
     * 발급만 하고 첫 코드 확인을 마치지 않은 자격증명으로는 로그인할 수 없다 —
     * 확정하지 않으면 사용자는 앱에 시크릿을 넣지 않았을 수 있고, 그 상태로 2단계
     * 인증을 요구하면 계정이 잠긴다.
     *
     * @return bool 확정 여부
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * 입력이 이 자격증명의 백업 코드와 맞는지 대조하고, 맞으면 남는 목록을 돌려줍니다.
     *
     * 백업 코드는 1회용이므로 맞은 항목을 뺀 목록이 곧 다음 상태다. **저장은 하지 않는다**
     * — 영속은 Repository 소관이고, 모델이 스스로 저장하면 그 경로가 감사 대상 밖으로 샌다.
     *
     * 해시 비교라 어느 항목이 맞았는지는 순회로만 알 수 있다.
     *
     * @param  string  $code  사용자가 입력한 백업 코드
     * @return array<int, string>|null 맞았으면 소진 후 남는 해시 목록, 틀렸으면 null
     */
    public function matchBackupCode(string $code): ?array
    {
        $codes = $this->backup_codes ?? [];
        $normalized = $this->normalizeBackupCode($code);

        if ($normalized === '') {
            return null;
        }

        foreach ($codes as $index => $hash) {
            if (! is_string($hash) || ! Hash::check($normalized, $hash)) {
                continue;
            }

            unset($codes[$index]);

            return array_values($codes);
        }

        return null;
    }

    /**
     * 백업 코드 표기를 대조 가능한 형태로 정규화합니다.
     *
     * 사용자는 화면에 보인 `abcd-efgh` 를 하이픈 없이 또는 대문자로 옮겨 적는다 —
     * 저장 시점과 대조 시점이 같은 규칙을 거쳐야 한다.
     *
     * @param  string  $code  원본 입력
     * @return string 정규화된 코드
     */
    public function normalizeBackupCode(string $code): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}
