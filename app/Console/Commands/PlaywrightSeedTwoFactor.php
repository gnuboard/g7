<?php

namespace App\Console\Commands;

use App\Enums\ExtensionOwnerType;
use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Support\Base32;
use Plugins\Sirsoft\Totp\Support\Totp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Playwright E2E 용 2단계 인증 픽스처 커맨드.
 *
 * 두 가지 일을 한다.
 *   - `--ensure-user` : 알려진 비밀번호를 가진 Active 사용자를 만들거나 갱신한다.
 *     (`--admin` 이면 admin 역할을 함께 부여)
 *   - `--plant`       : 지정한 challenge 에 알려진 인증 코드의 해시를 심는다.
 *
 * 인증번호는 해시로만 저장되므로 브라우저 테스트가 되읽을 수 없다. 메일을 실제로 열어
 * 보는 대신 알려진 값을 심어 "코드가 맞을 때" 를 재현한다 — 검증 대상은 코드 생성이
 * 아니라 로그인 흐름(코드 확인 전 토큰 미발급 / 확인 후 발급)이다.
 * 심는 방식은 PHPUnit `TwoFactorAuthTest::issuedCode()` 와 동일하다.
 *
 * 보안 가드 (3중, `playwright:issue-token` 과 동형):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`. production 웹 요청에서 도달 불가
 *   ② 명시 옵트인 — `G7_PLAYWRIGHT_BYPASS=1` 환경변수 필수
 *   ③ APP_DEBUG 강제 — production + debug=false 환경에서도 픽스처 조작이 가능하도록
 *
 * 호출 예시 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-two-factor --ensure-user=user --password='Passw0rd!2fa'
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-two-factor --plant=<challenge_id> --code=135790
 */
class PlaywrightSeedTwoFactor extends Command
{
    protected $signature = 'playwright:seed-two-factor
        {--ensure-user= : 이 접미사로 Active 테스트 사용자를 생성/갱신하고 이메일을 출력한다}
        {--password= : --ensure-user 가 설정할 비밀번호 (기본값 Passw0rd!2fa)}
        {--admin : --ensure-user 계정에 admin 역할을 부여한다}
        {--role=* : --admin 과 함께, 확장이 만든 전용 역할 identifier 를 추가로 부여한다 (예: sirsoft-totp.credentials)}
        {--plant= : 이 challenge 에 알려진 인증 코드의 해시를 심는다 (challenge UUID)}
        {--code=135790 : --plant 가 심을 인증 코드}
        {--gc-hours=6 : 이 시간(시)보다 오래된 playwright 2FA 테스트 계정을 정리. 0 이면 정리 안 함}
        {--purge-users : 나이와 무관하게 playwright 2FA 테스트 계정을 전부 제거 (실측 종료 직후 호출)}
        {--purge-prefix= : --purge-users 대상을 이 접미사로 시작하는 계정으로 한정 (스펙별 정리)}
        {--enroll-totp= : 이 이메일 계정에 확정된 TOTP 자격증명을 심고 시크릿을 출력한다}
        {--totp-code= : 이 Base32 시크릿의 현재 코드를 계산해 출력한다 (인증 앱 대역)}';

    protected $description = 'Playwright E2E 용 2단계 인증 픽스처 (테스트 계정 준비 / 인증 코드 심기)';

    /** 테스트 전용 계정 이메일 접두사 */
    private const TEST_EMAIL_PREFIX = 'playwright_2fa_';

    /**
     * 실측이 쓰는 고정 백업 코드 — 평문을 알아야 «백업 코드로 로그인» 축을 자동화할 수 있다.
     *
     * @var array<int, string>
     */
    private const TEST_BACKUP_CODES = ['pwtst-aaaa1', 'pwtst-bbbb2'];

    /**
     * 커맨드를 실행합니다.
     *
     * 지정된 옵션에 따라 테스트 계정 준비·인증 코드 심기·TOTP 등록·계정 정리를 수행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // ① CLI 한정 — production 웹 요청에서 절대 도달 불가
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다. (현재 SAPI: '.php_sapi_name().')');

            return self::FAILURE;
        }

        // ② 명시 옵트인 — 환경변수 없이는 production 호출 실수 차단.
        // 여기의 `env()` 는 `.env` 유래 값이 아니라 호출자가 그 자리에서 넘기는 프로세스
        // 환경변수이므로 config:cache 의 영향을 받지 않는다.
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — $env:G7_PLAYWRIGHT_BYPASS=\'1\')');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제 — production + debug=false 환경에서도 픽스처 조작 허용
        Config::set('app.debug', true);

        $gcHours = (int) $this->option('gc-hours');
        if ($gcHours > 0) {
            $this->pruneStaleTestUsers($gcHours);
        }

        $did = false;

        // 알려진 비밀번호를 가진 계정(관리자 포함)을 실측 뒤에 남기지 않는다 —
        // 나이 기준 정리(gc-hours)는 그 사이의 창을 닫지 못한다.
        if ($this->option('purge-users')) {
            $this->purgeTestUsers();
            $did = true;
        }

        if ($suffix = $this->option('ensure-user')) {
            $this->ensureUser((string) $suffix);
            $did = true;
        }

        if ($challengeId = $this->option('plant')) {
            if (! $this->plantCode((string) $challengeId, (string) $this->option('code'))) {
                return self::FAILURE;
            }
            $did = true;
        }

        if ($email = $this->option('enroll-totp')) {
            if (! $this->enrollTotp((string) $email)) {
                return self::FAILURE;
            }
            $did = true;
        }

        if ($secret = $this->option('totp-code')) {
            $this->line(Totp::codeAt((string) $secret, Totp::timestepAt()));
            $did = true;
        }

        if (! $did) {
            $this->error('--ensure-user · --plant · --purge-users · --enroll-totp · --totp-code 중 하나는 지정해야 합니다.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 대상 계정에 확정된 TOTP 자격증명을 심고 평문 시크릿을 출력합니다.
     *
     * 실측은 휴대폰을 들 수 없으므로 «앱이 이미 등록된 상태»를 서버에서 만들어 준다.
     * 등록 API 를 그대로 태우면 QR 을 사람이 읽어야 해서 자동화가 불가능하다.
     *
     * 이 커맨드 전체가 `G7_PLAYWRIGHT_BYPASS` 가드 뒤에 있으므로 운영에서는 실행되지 않는다.
     *
     * @param  string  $email  대상 계정 이메일
     * @return bool 성공 여부
     */
    private function enrollTotp(string $email): bool
    {
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("계정을 찾을 수 없습니다: {$email}");

            return false;
        }

        if (! class_exists(TotpCredential::class)) {
            $this->error('sirsoft-totp 플러그인이 설치되어 있지 않습니다.');

            return false;
        }

        $secret = Base32::randomSecret();

        TotpCredential::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'secret' => $secret,
                'confirmed_at' => now(),
                // 방금 심은 자격증명으로 즉시 로그인해야 하므로 리플레이 기준을 비워 둔다.
                'last_used_timestep' => null,
                // 대조 시점(TotpCredential::matchBackupCode)이 하이픈을 걷어내고 소문자로
                // 맞추므로 저장도 같은 규칙을 거쳐야 한다 — 어긋나면 올바른 코드가 거절된다.
                'backup_codes' => array_map(
                    static fn (string $code): string => Hash::make(
                        strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $code))
                    ),
                    self::TEST_BACKUP_CODES,
                ),
            ]
        );

        $this->line($secret);

        return true;
    }

    /**
     * 알려진 비밀번호를 가진 Active 테스트 사용자를 만들거나 갱신하고 이메일을 출력합니다.
     *
     * @param  string  $suffix  계정 구분 접미사 (예: user / nonadmin)
     *
     * @return void 반환값 없음
     */
    private function ensureUser(string $suffix): void
    {
        $email = self::TEST_EMAIL_PREFIX.$suffix.'@example.test';
        $password = (string) ($this->option('password') ?: 'Passw0rd!2fa');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create([
                'email' => $email,
                'password' => Hash::make($password),
                'status' => UserStatus::Active->value,
            ]);
        } else {
            // 이전 실행이 남긴 계정을 재사용한다 — 매 실행마다 계정이 늘면 회원 목록이
            // 테스트 잔재로 뒤덮인다. 잠금·실패 카운트도 함께 초기화해 앞선 spec 의
            // 잠금 상태가 다음 실행으로 새지 않게 한다.
            $user->forceFill([
                'password' => Hash::make($password),
                'status' => UserStatus::Active->value,
                'locked_until' => null,
                'failed_login_attempts' => 0,
            ])->save();
        }

        if ($this->option('admin')) {
            $adminRole = Role::firstOrCreate(
                ['identifier' => 'admin'],
                [
                    'name' => ['ko' => '관리자', 'en' => 'Admin'],
                    'description' => ['ko' => '시스템 관리자', 'en' => 'System Admin'],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                    'is_active' => true,
                ]
            );

            if (! $user->roles()->where('roles.id', $adminRole->id)->exists()) {
                $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
            }

            // 확장이 만든 전용 역할(예: `sirsoft-totp.credentials`)은 admin 역할에 딸려오지
            // 않는다 — 그 권한으로만 보이는 화면을 측정하려면 따로 붙여야 한다. 이미 있는
            // 역할만 붙인다(없으면 그 확장이 비활성이라는 뜻이라 조용히 지나간다).
            foreach (array_filter((array) $this->option('role')) as $identifier) {
                $role = Role::where('identifier', $identifier)->first();

                if ($role === null) {
                    $this->warn("역할 '{$identifier}' 을 찾지 못해 건너뜁니다.");

                    continue;
                }

                if (! $user->roles()->where('roles.id', $role->id)->exists()) {
                    $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
                }
            }
        } else {
            // 관리자 거절 경로를 재현하려면 관리자 역할이 없어야 한다 — 재사용 계정에
            // 앞선 실행의 admin 역할이 남아 있으면 403 을 측정할 수 없다.
            $user->roles()->detach();
        }

        $this->line($email);
    }

    /**
     * challenge 에 알려진 인증 코드의 해시를 심습니다.
     *
     * @param  string  $challengeId  challenge UUID
     * @param  string  $code  심을 인증 코드
     * @return bool 성공 여부
     */
    private function plantCode(string $challengeId, string $code): bool
    {
        $log = IdentityVerificationLog::find($challengeId);

        if ($log === null) {
            $this->error("challenge 를 찾을 수 없습니다: {$challengeId}");

            return false;
        }

        $metadata = $log->metadata ?? [];
        $metadata['code_hash'] = Hash::make($code);

        $log->metadata = $metadata;
        $log->save();

        $this->line($code);

        return true;
    }

    /**
     * playwright 2FA 테스트 계정을 나이와 무관하게 전부 제거합니다.
     *
     * 이 계정들은 알려진 비밀번호를 갖고, `--admin` 으로 만든 것은 관리자 역할까지 갖는다.
     * 실측이 끝난 뒤에도 남아 있으면 그 자체가 열린 문이므로 즉시 지운다.
     *
     * @return void 반환값 없음
     */
    private function purgeTestUsers(): void
    {
        $removed = 0;

        // 스펙별 정리 — 접미사를 주면 그 스펙이 만든 계정만 지운다. 한 스위트 안에서 여러
        // 스펙이 같은 fixture 를 쓰면, 전체 정리는 아직 돌고 있는 형제 스펙의 계정까지
        // 지워 그쪽을 엉뚱한 실패로 떨어뜨린다(playwright.config.ts 의 «워커 1» 주석과 같은 사정).
        $prefix = self::TEST_EMAIL_PREFIX.(string) ($this->option('purge-prefix') ?? '');

        User::where('email', 'like', $prefix.'%')
            ->chunkById(100, function ($chunk) use (&$removed) {
                foreach ($chunk as $user) {
                    $user->tokens()->delete();
                    $user->roles()->detach();
                    $this->detachExtensionRows((int) $user->id);
                    $user->delete();
                    $removed++;
                }
            });

        $this->info("[purge] playwright 2FA 테스트 계정 제거: {$removed}건 (prefix={$prefix})");
    }

    /**
     * 사용자 삭제를 막는 확장 소유 행을 먼저 치웁니다.
     *
     * 이 커맨드는 `core.user.before_delete` 훅을 태우지 않고 모델을 직접 지우므로, 확장이
     * 그 훅에서 하던 정리가 일어나지 않아 외래키가 삭제를 막는다 — 정리 실패로 알려진
     * 비밀번호를 가진 관리자 계정이 DB 에 남는 것이 실제 위험이라 여기서 직접 치운다.
     *
     * 테이블이 없는 설치(플러그인 미설치)에서는 아무것도 하지 않는다.
     *
     * @param  int  $userId  대상 사용자 ID
     * @return void
     */
    private function detachExtensionRows(int $userId): void
    {
        foreach (['totp_credentials'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('user_id', $userId)->delete();
            }
        }
    }

    /**
     * 임계 시간보다 오래된 playwright 2FA 테스트 계정을 정리합니다.
     *
     * chunkById(키셋 순회) 필수 — 콜백이 순회 대상 행을 삭제하므로 OFFSET 기반
     * chunk()/each() 는 줄어든 결과 집합만큼 커서가 밀려 일부를 건너뛴다.
     *
     * @param  int  $hours  이 시간보다 오래된 계정만 정리
     *
     * @return void 반환값 없음
     */
    private function pruneStaleTestUsers(int $hours): void
    {
        $threshold = now()->subHours($hours);
        $removed = 0;

        User::where('email', 'like', self::TEST_EMAIL_PREFIX.'%')
            ->where('created_at', '<', $threshold)
            ->chunkById(100, function ($chunk) use (&$removed) {
                foreach ($chunk as $user) {
                    $user->tokens()->delete();
                    $user->roles()->detach();
                    $this->detachExtensionRows((int) $user->id);
                    $user->delete();
                    $removed++;
                }
            });

        if ($removed > 0) {
            $this->info("[gc] playwright 2FA 테스트 계정 정리: {$removed}건");
        }
    }
}
