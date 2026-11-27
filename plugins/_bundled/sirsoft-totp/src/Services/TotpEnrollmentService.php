<?php

namespace Plugins\Sirsoft\Totp\Services;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Totp\Exceptions\AlreadyEnrolledException;
use Plugins\Sirsoft\Totp\Exceptions\EnrollmentConflictException;
use Plugins\Sirsoft\Totp\Exceptions\OwnershipThrottledException;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;
use Plugins\Sirsoft\Totp\Support\Base32;
use Plugins\Sirsoft\Totp\Support\Totp;

/**
 * 인증 앱 자격증명의 등록·확정·해제·백업 코드 재발급을 담당하는 서비스.
 *
 * 평문(시크릿·백업 코드)이 사용자에게 노출되는 지점은 이 서비스가 돌려주는 값 **한 번**뿐이며,
 * 저장은 암호화·해시 형태로만 이뤄집니다. 다시 볼 수 없으므로 화면이 그 1회 노출을
 * 책임집니다.
 *
 * @since 1.0.0
 */
class TotpEnrollmentService
{
    /** 발급할 백업 코드 개수 */
    public const BACKUP_CODE_COUNT = 10;

    /** 백업 코드 1개의 영숫자 길이 (하이픈 제외) */
    private const BACKUP_CODE_LENGTH = 10;

    /**
     * 본인 확인 시도 제한기 (미지정 시 컨테이너에서 해석 — 기존 수동 생성 호환)
     */
    private readonly TotpOwnershipThrottle $ownershipThrottle;

    /**
     * @param  TotpCredentialRepositoryInterface  $credentials  자격증명 저장소
     * @param  TotpOwnershipThrottle|null  $ownershipThrottle  해제·재발급 코드 확인 시도 제한 (1.0.3)
     */
    public function __construct(
        private readonly TotpCredentialRepositoryInterface $credentials,
        ?TotpOwnershipThrottle $ownershipThrottle = null,
    ) {
        $this->ownershipThrottle = $ownershipThrottle ?? app(TotpOwnershipThrottle::class);
    }

    /**
     * 등록을 시작해 새 시크릿을 발급합니다.
     *
     * 확정된 자격증명이 이미 있으면 발급하지 않는다 — 덮어쓰면 기존 앱이 조용히 무효가 되고,
     * 사용자는 그 사실을 다음 로그인에서야 안다. 바꾸려면 먼저 해제해야 한다.
     *
     * 미확정 자격증명은 덮어쓴다 — 등록을 하다 만 상태이므로 보호할 것이 없고, 남겨 두면
     * QR 을 다시 띄울 방법이 없어진다.
     *
     * @param  User  $user  대상 사용자
     * @param  string  $issuer  otpauth URI 에 실을 발급자 표기
     * @return array{secret: string, otpauth_uri: string} 1회 노출용 평문 시크릿과 URI
     *
     * @throws AlreadyEnrolledException 이미 확정된 자격증명이 있을 때
     */
    public function start(User $user, string $issuer): array
    {
        $existing = $this->findFor($user);

        if ($existing !== null && $existing->isConfirmed()) {
            throw new AlreadyEnrolledException((int) $user->id);
        }

        $secret = Base32::randomSecret();

        $this->credentials->issue((int) $user->id, $secret);

        return [
            'secret' => $secret,
            // 계정 표기는 인증 앱 목록에서 어느 계정인지 알아보는 용도다 — 이메일이 없는
            // 계정도 있으므로 닉네임·id 로 내려간다.
            'otpauth_uri' => Totp::otpauthUri($secret, $this->accountLabel($user), $issuer),
        ];
    }

    /**
     * 인증 앱 목록에 표시될 계정 라벨을 만듭니다.
     *
     * @param  User  $user  대상 사용자
     * @return string 계정 표기
     */
    protected function accountLabel(User $user): string
    {
        return (string) ($user->email ?: $user->nickname ?: ('user-'.$user->id));
    }

    /**
     * 첫 코드를 확인해 등록을 확정하고 백업 코드를 발급합니다.
     *
     * 확정 시점의 timestep 을 `last_used_timestep` 에 새겨 둔다 — 확정에 쓴 코드를 곧바로
     * 로그인에 재사용하지 못하게 한다.
     *
     * 확정은 「미확정이고 시크릿이 그대로일 때만」 저장한다 — 다른 화면이 먼저 확정했거나 그 사이 시크릿이 새로 발급되면
     * `EnrollmentConflictException` 이다(화면의 백업 코드와 저장 코드가 어긋나지 않게 — KVE 동형 L3).
     * 확정되면 `sirsoft-totp.credential.enrolled` 훅을 발화한다(본인 알림).
     *
     * @param  User  $user  대상 사용자
     * @param  string  $code  인증 앱이 만든 6자리 코드
     * @param  array<string, mixed>  $context  요청 문맥 (ip_address·user_agent)
     * @return array<int, string>|null 평문 백업 코드 10개, 코드가 틀리면 null
     *
     * @throws EnrollmentConflictException 다른 확정·재발급과 겹쳤을 때
     */
    public function confirm(User $user, string $code, array $context = []): ?array
    {
        $credential = $this->findFor($user);

        if ($credential === null || $credential->isConfirmed()) {
            return null;
        }

        try {
            $secret = (string) $credential->secret;
        } catch (DecryptException) {
            // 미확정 행의 시크릿이 지금 키로 풀리지 않는다(사이트 암호화 키가 바뀐 뒤 남은 행) — 맞출 수 있는 코드가
            // 없으므로 코드 불일치로 답한다. 등록을 다시 시작하면 새 시크릿이 발급된다.
            return null;
        }

        $step = Totp::verify($secret, $code);

        if ($step === null) {
            return null;
        }

        $plain = $this->generateBackupCodes();

        if (! $this->credentials->confirmIfPending($credential, $step, $this->hashBackupCodes($plain))) {
            throw new EnrollmentConflictException;
        }

        HookManager::doAction('sirsoft-totp.credential.enrolled', $user, $this->hookContext($context));

        return $plain;
    }

    /**
     * 자격증명을 해제합니다.
     *
     * 해제에도 현재 코드를 요구한다 — 잠깐 자리를 비운 사이 열린 세션으로 2단계 인증을
     * 꺼 버리는 경로를 막는다.
     *
     * 코드 확인은 회원 단위로 시도를 제한한다(KVE 동형 A1). 해제되면 `sirsoft-totp.credential.disabled` 훅(본인 알림)을 발화한다.
     *
     * @param  User  $user  대상 사용자
     * @param  string  $code  현재 코드 또는 백업 코드
     * @param  array<string, mixed>  $context  요청 문맥 (ip_address·user_agent)
     * @return bool 해제 성공 여부
     *
     * @throws OwnershipThrottledException 시도 기준을 넘었을 때
     */
    public function disable(User $user, string $code, array $context = []): bool
    {
        $credential = $this->findFor($user);

        if ($credential === null || ! $credential->isConfirmed()) {
            return false;
        }

        if (! $this->verifyOwnership($user, $credential, $code)) {
            return false;
        }

        $this->credentials->deleteByUserId((int) $user->id);

        HookManager::doAction('sirsoft-totp.credential.disabled', $user, $this->hookContext($context) + ['by_admin' => false]);

        return true;
    }

    /**
     * 백업 코드를 새로 발급합니다 (기존 코드는 전부 무효).
     *
     * 코드 확인은 회원 단위로 시도를 제한한다(KVE 동형 A1). 재발급되면 `sirsoft-totp.backup_codes.regenerated` 훅(본인 알림)을 발화한다.
     *
     * @param  User  $user  대상 사용자
     * @param  string  $code  현재 코드 또는 남은 백업 코드
     * @param  array<string, mixed>  $context  요청 문맥 (ip_address·user_agent)
     * @return array<int, string>|null 평문 백업 코드 10개, 확인에 실패하면 null
     *
     * @throws OwnershipThrottledException 시도 기준을 넘었을 때
     */
    public function regenerateBackupCodes(User $user, string $code, array $context = []): ?array
    {
        $credential = $this->findFor($user);

        if ($credential === null || ! $credential->isConfirmed()) {
            return null;
        }

        if (! $this->verifyOwnership($user, $credential, $code)) {
            return null;
        }

        $plain = $this->generateBackupCodes();

        $this->credentials->replaceBackupCodes((int) $credential->getKey(), $this->hashBackupCodes($plain));

        HookManager::doAction('sirsoft-totp.backup_codes.regenerated', $user, $this->hookContext($context));

        return $plain;
    }

    /**
     * 관리자·artisan 이 사용하는 무조건 초기화.
     *
     * 사용자가 단말과 백업 코드를 모두 잃었을 때의 최후 구제 경로다 — 코드를 요구하지
     * 않으므로 호출부가 권한을 책임진다.
     *
     * @param  User  $user  대상 사용자
     * @return bool 삭제한 자격증명이 있었으면 true
     */
    public function reset(User $user): bool
    {
        return $this->credentials->deleteByUserId((int) $user->id) > 0;
    }

    /**
     * 관리자 강제 해제 — 초기화하고, 실제로 풀린 경우 감사 훅을 발화합니다.
     *
     * 관리자 화면과 서버 셸(`totp:reset`)이 같은 이 메서드를 부른다. 예전에는 컨트롤러가 훅을
     * 발화해 셸로 풀면 감사 기록이 남지 않았다(공개 #138 후속). 셸에는 요청 사용자·IP 가 없으므로
     * 호출부가 null 을 넘기고, 진입점(CLI)은 활동 로그가 따로 표시한다.
     *
     * @param  User  $user  대상 사용자
     * @param  int|null  $actorId  해제한 관리자 ID (셸이면 null)
     * @param  string|null  $ipAddress  요청 IP (셸이면 null)
     * @return bool 삭제한 자격증명이 있었으면 true
     */
    public function adminReset(User $user, ?int $actorId, ?string $ipAddress): bool
    {
        $removed = $this->reset($user);

        if ($removed) {
            HookManager::doAction('sirsoft-totp.credential.admin_reset', $user, [
                'actor_id' => $actorId,
                'ip_address' => $ipAddress,
            ]);
        }

        return $removed;
    }

    /**
     * 사용자의 자격증명을 조회합니다.
     *
     * @param  User  $user  대상 사용자
     * @return TotpCredential|null 자격증명, 없으면 null
     */
    public function findFor(User $user): ?TotpCredential
    {
        return $this->credentials->findByUserId((int) $user->id);
    }

    /**
     * 현재 코드 또는 백업 코드로 본인 확인을 수행합니다.
     *
     * 백업 코드가 맞으면 그 코드는 소진된다 — 해제·재발급도 1회용 규칙을 따른다.
     *
     * 회원 단위로 시도를 제한하고(기준 초과면 계산 전에 거부), 코드 소진은 조건부 갱신·행 잠금으로 한다 —
     * 같은 코드를 동시에 두 번 쓰지 못한다(KVE-2026-3193·동형 A1).
     *
     * @param  User  $user  대상 회원
     * @param  TotpCredential  $credential  대상 자격증명
     * @param  string  $code  입력 코드
     * @return bool 확인 성공 여부
     *
     * @throws OwnershipThrottledException 시도 기준을 넘었을 때
     */
    protected function verifyOwnership(User $user, TotpCredential $credential, string $code): bool
    {
        // 시도 1회를 먼저 세어 자리를 잡는다 — 동시에 보낸 요청이 같은 남은 횟수를 함께 통과하지 못하게(성공하면 아래에서 지운다)
        $this->ownershipThrottle->reserve($user);

        $alnum = preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '';
        $ok = false;

        if (strlen($alnum) === Totp::DIGITS && ctype_digit($alnum)) {
            $step = Totp::verify(
                secret: (string) $credential->secret,
                code: $alnum,
                window: 1,
                notBeforeTimestep: $credential->last_used_timestep,
            );

            $ok = $step !== null && $this->credentials->advanceTimestep((int) $credential->getKey(), $step);
        } else {
            $ok = $this->credentials->consumeBackupCode((int) $credential->getKey(), $code);
        }

        if ($ok) {
            $this->ownershipThrottle->clear($user);
        }

        return $ok;
    }

    /**
     * 알림 훅 문맥을 만듭니다.
     *
     * @param  array<string, mixed>  $context  호출자가 넘긴 요청 문맥
     * @return array<string, mixed> 훅 문맥 (ip_address·user_agent·occurred_at)
     */
    private function hookContext(array $context): array
    {
        return [
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'occurred_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * 평문 백업 코드를 저장용 해시 목록으로 바꿉니다.
     *
     * 표기의 하이픈은 대조 시점에도 제거되므로 저장 전에 같은 규칙으로 걷어낸다.
     *
     * @param  array<int, string>  $plain  평문 백업 코드
     * @return array<int, string> bcrypt 해시 목록
     */
    protected function hashBackupCodes(array $plain): array
    {
        return array_map(
            static fn (string $code): string => Hash::make(str_replace('-', '', $code)),
            $plain,
        );
    }

    /**
     * 사람이 옮겨 적기 쉬운 백업 코드를 만듭니다.
     *
     * 표기는 `xxxxx-xxxxx` 이고 저장·대조는 하이픈을 뺀 소문자 영숫자로 한다.
     *
     * @return array<int, string> 평문 백업 코드 목록
     */
    protected function generateBackupCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $raw = strtolower(Str::random(self::BACKUP_CODE_LENGTH));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return $codes;
    }
}
