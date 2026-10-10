<?php

namespace App\Console\Commands;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserPasswordHistoryRepositoryInterface;
use App\Enums\UserStatus;
use App\Extension\Storage\CoreStorageDriver;
use App\Models\User;
use App\Services\PasswordExpiryService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Playwright·Chrome MCP 실측용 비밀번호 만료 픽스처 커맨드 (이슈 #736 S1).
 *
 * 만료 축은 **시간이 흘러야** 재현되는 상태다. 90일을 기다릴 수 없으므로 변경일·표지·연기를 직접
 * 심어 각 단계의 화면을 즉시 만든다. 보안 설정도 스냅샷으로 남겼다가 저장 경로(`SettingsService`)로
 * 되돌린다 — 설정 파일을 직접 복사하면 캐시 무효화를 건너뛰어 큐 워커·스케줄러가 옛 값을 읽는다.
 *
 * 만들 수 있는 상태 (`--stage`):
 *   - `fresh`          : 방금 바꾼 비밀번호 (만료 아님 — 휴면 축·정상 로그인 실측용)
 *   - `notice`         : 사전 안내 대상 (안내 창 안 · 미안내)
 *   - `expired`        : 만료 (만료일 처리 전)
 *   - `postponed`      : 만료 + '다음에 변경' 연기 중
 *   - `admin_assigned` : 관리자가 정해 준 비밀번호 (다음 로그인 때 변경 필수)
 *
 * 보안 가드 (3중, `playwright:seed-dormant` 와 동형):
 *   ① CLI 한정 ② `G7_PLAYWRIGHT_BYPASS=1` 필수 ③ APP_DEBUG 강제
 *
 * 호출 예시 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-password-expiry --snapshot-settings
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-password-expiry --enable-policy
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-password-expiry --stage=expired --admin --issue-token --with-history
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-password-expiry --purge-users
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-password-expiry --restore-settings
 *
 * @since 7.1.0
 */
class PlaywrightSeedPasswordExpiry extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-password-expiry
        {--stage=expired : 만들 상태 (fresh|notice|expired|postponed|admin_assigned)}
        {--admin : 관리자 역할을 붙인다}
        {--dormant : 계정을 휴면 상태로 만든다 (status·dormant_at)}
        {--count=1 : 같은 조합 계정을 몇 개 만들지 (최대 200)}
        {--password= : 생성 계정의 비밀번호 (기본값 Passw0rd!pwexp)}
        {--with-history : 이전 비밀번호 OldPassw0rd!1~3 을 이력으로 심는다}
        {--snapshot-settings : 현재 보안 설정을 스냅샷으로 저장한다 (이미 있으면 덮지 않는다)}
        {--enable-policy : 만료 켜기·범위 전체·변경해야 로그인·시작일 (기간+1)일 전 (나머지 값은 유지)}
        {--issue-token : 시드 계정마다 "다른 기기" 로그인 토큰을 하나씩 발급해 출력한다}
        {--restore-settings : 스냅샷으로 보안 설정을 되돌리고 스냅샷을 지운다}
        {--purge-users : 이 커맨드가 만든 테스트 계정을 전부 제거한다}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright·Chrome MCP 실측용 비밀번호 만료 픽스처를 만들고 보안 설정을 스냅샷·복원합니다.';

    /** 만들 수 있는 상태 */
    public const STAGES = ['fresh', 'notice', 'expired', 'postponed', 'admin_assigned'];

    /** 테스트 계정 이메일 접두사·도메인 — 정리 대상 판정에도 쓴다 */
    private const EMAIL_PREFIX = 'pwexp-';

    private const EMAIL_DOMAIN = '@playwright.local';

    /** 기본 비밀번호 */
    private const DEFAULT_PASSWORD = 'Passw0rd!pwexp';

    /** 보안 설정 스냅샷 저장 위치 (local 디스크의 코어 저장소 카테고리 · 파일명) */
    private const SNAPSHOT_CATEGORY = 'playwright';

    private const SNAPSHOT_PATH = 'password-expiry-security-snapshot.json';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다. (현재 SAPI: '.php_sapi_name().')');

            return self::FAILURE;
        }

        // 여기의 `env()` 는 호출자가 그 자리에서 넘기는 프로세스 환경변수라 config:cache 무관.
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — $env:G7_PLAYWRIGHT_BYPASS=\'1\')');

            return self::FAILURE;
        }

        Config::set('app.debug', true);

        if ($this->option('snapshot-settings')) {
            return $this->snapshotSettings();
        }

        if ($this->option('restore-settings')) {
            return $this->restoreSettings();
        }

        if ($this->option('purge-users')) {
            $this->purgeTestUsers();

            return self::SUCCESS;
        }

        if ($this->option('enable-policy')) {
            $this->enablePolicy();

            return self::SUCCESS;
        }

        $stage = (string) $this->option('stage');

        if (! in_array($stage, self::STAGES, true)) {
            $this->error("알 수 없는 상태입니다: {$stage} (".implode('|', self::STAGES).')');

            return self::FAILURE;
        }

        $count = max(1, min(200, (int) $this->option('count')));
        $password = (string) ($this->option('password') ?: self::DEFAULT_PASSWORD);

        for ($i = 1; $i <= $count; $i++) {
            $user = $this->seedUser($stage, $i, $password);
            $this->line($user->email);

            if ($this->option('issue-token')) {
                $this->line('other-device-token: '.$user->createToken('playwright-other-device')->plainTextToken);
            }
        }

        $this->line($password);

        return self::SUCCESS;
    }

    /**
     * 상태에 맞는 테스트 계정을 만들거나(같은 조합이면) 그 상태로 덮어씁니다.
     *
     * 기간 설정을 읽어 그 설정에 맞는 변경일을 심는다 — 리터럴 일수를 박으면 운영자가 기간을 바꾼
     * 사이트에서 픽스처가 대상에서 빠진다.
     *
     * @param  string  $stage  상태
     * @param  int  $n  같은 조합 안의 순번
     * @param  string  $password  비밀번호 평문
     * @return User 회원
     */
    private function seedUser(string $stage, int $n, string $password): User
    {
        $expiry = app(PasswordExpiryService::class);
        $days = $expiry->days();
        $noticeDays = $expiry->noticeDays();

        $suffix = ($this->option('admin') ? '-admin' : '').($this->option('dormant') ? '-dormant' : '');
        $email = self::EMAIL_PREFIX.$stage.$suffix.'-'.$n.self::EMAIL_DOMAIN;

        $attributes = [
            'name' => '비밀번호 만료 실측 '.$stage.$suffix.' '.$n,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'status' => $this->option('dormant') ? UserStatus::Dormant->value : UserStatus::Active->value,
            'dormant_at' => $this->option('dormant') ? now()->subDay() : null,
            'dormant_notified_at' => null,
            'password_expiry_policy' => 'inherit',
            'password_change_required' => $stage === 'admin_assigned',
            'password_expiry_notified_at' => null,
            'password_expired_processed_at' => null,
            'password_change_postponed_until' => $stage === 'postponed' ? now()->addDays($expiry->remindDays()) : null,
            'password_changed_at' => match ($stage) {
                'notice' => now()->subDays($days - $noticeDays + 1),
                'expired', 'postponed' => now()->subDays($days + 1),
                default => now(),
            },
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'locked_permanently' => false,
        ];

        $user = User::query()->where('email', $email)->first() ?? new User(['uuid' => Str::orderedUuid()->toString(), 'email' => $email]);
        $user->forceFill($attributes)->save();
        $user->tokens()->delete();

        $roleIdentifiers = $this->option('admin') ? ['admin', 'user'] : ['user'];
        $roleIds = collect($roleIdentifiers)
            ->map(fn (string $identifier) => app(RoleRepositoryInterface::class)->findByIdentifier($identifier)?->id)
            ->filter()
            ->values()
            ->all();
        $user->roles()->sync($roleIds);
        $user->flushPermissionCaches();

        $history = app(UserPasswordHistoryRepositoryInterface::class);
        $history->deleteByUser((int) $user->id);

        if ($this->option('with-history')) {
            foreach ([3, 2, 1] as $i) {
                $history->push((int) $user->id, Hash::make('OldPassw0rd!'.$i));
            }
        }

        return $user;
    }

    /**
     * 현재 보안 설정을 스냅샷으로 남깁니다 (이미 있으면 덮지 않는다 — 실측 도중 재실행으로 원본을 잃지 않게).
     *
     * @return int 종료 코드
     */
    private function snapshotSettings(): int
    {
        if ($this->storage()->exists(self::SNAPSHOT_CATEGORY, self::SNAPSHOT_PATH)) {
            $this->warn('보안 설정 스냅샷이 이미 있어 덮지 않았습니다: '.self::SNAPSHOT_PATH);

            return self::SUCCESS;
        }

        $security = app(ConfigRepositoryInterface::class)->getCategory('security');

        $this->storage()->put(self::SNAPSHOT_CATEGORY, self::SNAPSHOT_PATH, json_encode($security, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info('보안 설정 스냅샷을 저장했습니다: '.self::SNAPSHOT_PATH);

        return self::SUCCESS;
    }

    /**
     * 스냅샷으로 보안 설정을 되돌리고 스냅샷을 지웁니다 (저장 경로 — 캐시 무효화 포함).
     *
     * @return int 종료 코드
     */
    private function restoreSettings(): int
    {
        if (! $this->storage()->exists(self::SNAPSHOT_CATEGORY, self::SNAPSHOT_PATH)) {
            $this->error('되돌릴 보안 설정 스냅샷이 없습니다: '.self::SNAPSHOT_PATH);

            return self::FAILURE;
        }

        $snapshot = json_decode((string) $this->storage()->get(self::SNAPSHOT_CATEGORY, self::SNAPSHOT_PATH), true);

        if (! is_array($snapshot)) {
            $this->error('보안 설정 스냅샷을 읽지 못했습니다.');

            return self::FAILURE;
        }

        app(SettingsService::class)->saveSettings(['_tab' => 'security', 'security' => $snapshot]);
        $this->storage()->delete(self::SNAPSHOT_CATEGORY, self::SNAPSHOT_PATH);

        $this->info('보안 설정을 스냅샷으로 되돌렸습니다.');

        return self::SUCCESS;
    }

    /**
     * 만료 기능을 켜고(범위 전체·변경해야 로그인) 시작일을 (기간+1)일 전으로 둡니다. 나머지 값은 유지합니다.
     *
     * @return void
     */
    private function enablePolicy(): void
    {
        $settings = app(SettingsService::class);

        $settings->saveSettings(['_tab' => 'security', 'security' => [
            'password_expiry_enabled' => true,
            'password_expiry_scope' => 'all',
            'password_expiry_action' => 'force',
        ]]);

        $days = app(PasswordExpiryService::class)->days();
        $settings->setSetting(PasswordExpiryService::STARTED_AT_KEY, now()->subDays($days + 1)->toIso8601String());

        $this->info('비밀번호 만료를 켰습니다 (범위 전체·변경해야 로그인·시작일 '.($days + 1).'일 전).');
    }

    /**
     * 이 커맨드가 만든 테스트 계정을 제거합니다 (알려진 비밀번호를 가진 계정을 남기지 않는다).
     *
     * @return void
     */
    private function purgeTestUsers(): void
    {
        $history = app(UserPasswordHistoryRepositoryInterface::class);
        $deleted = 0;

        User::query()
            ->where('email', 'like', self::EMAIL_PREFIX.'%'.self::EMAIL_DOMAIN)
            ->eachById(function (User $user) use ($history, &$deleted) {
                $history->deleteByUser((int) $user->id);
                $user->tokens()->delete();
                $user->roles()->detach();
                $user->delete();
                $deleted++;
            });

        $this->info("비밀번호 만료 실측 테스트 계정 {$deleted}건을 제거했습니다.");
    }

    /**
     * 스냅샷을 둘 코어 저장소(local 디스크)를 반환합니다.
     *
     * @return StorageInterface 저장소
     */
    private function storage(): StorageInterface
    {
        return new CoreStorageDriver('local');
    }
}
