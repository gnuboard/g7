<?php

namespace App\Privacy\Handlers;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\DTO\PrivacyRetentionTarget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * 탈퇴 회원 잔여 개인정보 2차 파기 핸들러
 *
 * `User::withdraw()` 는 이름·이메일·닉네임에 접미사를 붙여 **원값을 보존한** 채 탈퇴
 * 처리합니다 — 탈퇴 직후에는 분쟁·환불·부정거래 대응을 위해 식별이 필요하기 때문입니다.
 * 이 핸들러는 그 보존 기간(`privacy.withdrawn_retention_days`)이 지난 뒤 **남은 개인정보를
 * 실제로 지웁니다**. 즉 `withdraw()` 가 1차 처리, 이것이 2차 파기입니다.
 *
 * ## 무엇이 지워지나
 *
 * 연락처·주소·자기소개 등 직접 식별정보는 NULL 로, 이름은 고정 문구로, 이메일은
 * `withdrawn_{id}@removed.invalid` 로 바꿉니다. **본인인증 파생 컬럼도 함께 지웁니다** —
 * `identity_hash` 는 프로바이더가 바뀌어도 같은 사람을 찾아내는 동일인 식별자라서,
 * 연락처만 지우고 이것을 남기면 "지웠다" 는 말이 사실이 아니게 됩니다.
 *
 * ## 이메일을 NULL 로 두지 않는 이유
 *
 * `users.email` 은 NOT NULL + unique 입니다. id 를 넣은 고정 형식은 구조적으로 유일하므로
 * 충돌이 불가능하고, 동시에 "이 행은 2차 파기를 끝냈다" 는 **멱등 판정 표지**가 됩니다 —
 * 별도 플래그 컬럼을 만들지 않은 이유입니다.
 */
class WithdrawnUserRetentionHandler implements PrivacyRetentionHandlerInterface
{
    /** 파기 완료 이메일 도메인 — 멱등 판정의 표지이기도 하다 */
    public const ANONYMIZED_EMAIL_DOMAIN = '@removed.invalid';

    /** 파기 후 이름에 넣는 고정 문구 */
    public const ANONYMIZED_NAME = '탈퇴회원';

    /** 이 핸들러가 발행하는 prune 훅 접두사 */
    public const HOOK_PREFIX = 'core.privacy.withdrawn_user';

    /** 한 번에 읽어 처리할 행 수 — OFFSET 은 처리 중 행이 빠져 밀리므로 쓰지 않는다 */
    private const CHUNK_SIZE = 200;

    /**
     * NULL 로 지우는 컬럼 목록
     *
     * @var array<int, string>
     */
    private const NULLABLE_PII_COLUMNS = [
        'mobile',
        'phone',
        'zipcode',
        'address',
        'address_detail',
        'homepage',
        'bio',
        'signature',
        'ip_address',
        'nickname',
        // 본인인증 파생 — 동일인 식별자를 남기면 이 기능의 존재 이유가 무너진다.
        'identity_hash',
        'identity_verified_at',
        'identity_verified_provider',
        'identity_verified_purpose_last',
        'mobile_verified_at',
    ];

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'user-withdrawn';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('privacy.handlers.user_withdrawn');
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return (bool) g7_core_settings('privacy.withdrawn_retention_enabled', false);
    }

    /**
     * 보존기간(일)을 설정에서 읽습니다.
     *
     * @return int 보존기간(일)
     */
    public function retentionDays(): int
    {
        // 바닥값 1 — 형제 getter 셋(`DormantAccountService::dormantDays()`·`noticeDays()`·
        // `withdrawDays()`)과 같은 어휘다. 저장 검증은 `min:1`(SaveSettingsRequest)이지만
        // 그 검증을 거치지 않는 쓰기(단건 설정 API·JSON 직접 편집·확장 훅)가 `0`·음수·빈
        // 문자열을 남기면 `(int)` 가 0 이 되고, 임계가 `now()` 로 올라붙어 **탈퇴 당일 회원까지
        // 전원** 되돌릴 수 없는 2차 파기 대상이 된다. 판독부에서 막는다.
        return max(1, (int) g7_core_settings('privacy.withdrawn_retention_days', 365));
    }

    /**
     * {@inheritDoc}
     */
    public function preview(): int
    {
        return $this->targetQuery()->count();
    }

    /**
     * {@inheritDoc}
     */
    public function targets(int $page, int $perPage): LengthAwarePaginator
    {
        return $this->targetQuery()
            ->orderBy('withdrawn_at')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'uuid', 'name', 'email', 'withdrawn_at'], 'page', $page)
            ->through(fn (User $user) => new PrivacyRetentionTarget(
                id: $user->id,
                title: (string) $user->name,
                subtitle: $user->email,
                date: $user->withdrawn_at,
                url: '/admin/users/'.$user->uuid,
            ));
    }

    /**
     * {@inheritDoc}
     */
    public function targetColumns(): array
    {
        return [
            'title' => __('privacy.targets.columns.name'),
            'subtitle' => __('privacy.targets.columns.email'),
            'date' => __('privacy.targets.columns.withdrawn_at'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function targetPermissions(): array
    {
        return ['core.settings.read', 'core.users.read'];
    }

    /**
     * {@inheritDoc}
     */
    public function runPermissions(): array
    {
        return ['core.settings.update', 'core.users.update'];
    }

    /**
     * {@inheritDoc}
     */
    public function process(bool $dryRun, bool $force = false): array
    {
        // 토글을 스스로 다시 본다 — 수집기(Manager)가 이미 걸러 주지만, 이 메서드는
        // 되돌릴 수 없는 파기라 호출자를 믿지 않는다. 다른 경로(커맨드의 --handler,
        // 확장 코드)가 직접 부르면 가드 없이 지워지던 자리다. 관리자 직권 실행(`$force`)만
        // 이 가드를 넘는다 — 권한 검사는 직권 실행 API 가 이미 마쳤다.
        if (! $force && ! $this->isEnabled()) {
            return ['count' => 0, 'mode' => 'disabled'];
        }

        $days = $this->retentionDays();

        if ($dryRun) {
            return ['count' => $this->preview(), 'mode' => 'anonymize'];
        }

        HookManager::doAction(self::HOOK_PREFIX.'.before_prune', $days);

        $processed = 0;

        // chunkById — 처리하면서 조건에서 빠지는 행을 다루므로 OFFSET 순회는 건을 건너뛴다.
        $this->targetQuery()->chunkById(self::CHUNK_SIZE, function ($users) use (&$processed) {
            foreach ($users as $user) {
                if ($this->anonymize($user)) {
                    $processed++;
                }
            }
        });

        HookManager::doAction(self::HOOK_PREFIX.'.after_prune', $days, $processed);

        if ($processed > 0) {
            Log::info('탈퇴 회원 잔여 개인정보 2차 파기 완료', [
                'days' => $days,
                'count' => $processed,
            ]);
        }

        return ['count' => $processed, 'mode' => 'anonymize'];
    }

    /**
     * 보존기간이 지난 미처리 탈퇴 회원 쿼리를 만듭니다.
     *
     * 미리보기와 실행이 **같은 이 쿼리**를 씁니다 — 두 곳에 조건을 따로 적으면 화면 건수와
     * 실제 처리 건수가 어긋납니다.
     *
     * @return Builder<User> 대상 쿼리
     */
    public function targetQuery()
    {
        $days = $this->retentionDays();

        return User::query()
            ->where('status', UserStatus::Withdrawn->value)
            ->whereNotNull('withdrawn_at')
            ->where('withdrawn_at', '<=', now()->subDays(max(0, $days)))
            // 이미 2차 파기된 행 제외 — 완전 익명 이메일 형식이 그 표지다.
            ->where('email', 'not like', '%'.self::ANONYMIZED_EMAIL_DOMAIN);
    }

    /**
     * 회원 한 명의 잔여 개인정보를 지웁니다.
     *
     * @param  User  $user  대상 회원
     * @return bool 저장 성공 여부
     */
    private function anonymize(User $user): bool
    {
        $updates = [];

        foreach (self::NULLABLE_PII_COLUMNS as $column) {
            $updates[$column] = null;
        }

        $updates['name'] = self::ANONYMIZED_NAME;
        $updates['email'] = 'withdrawn_'.$user->id.self::ANONYMIZED_EMAIL_DOMAIN;

        // 모델 이벤트를 타지 않는 직접 갱신 — 파기는 감사 로그·검색 색인 재작성이 필요한
        // 도메인 변경이 아니고, 대량 순회에서 이벤트 연쇄가 붙으면 배치가 중간에 죽는다.
        return User::query()->whereKey($user->getKey())->update($updates) > 0;
    }
}
