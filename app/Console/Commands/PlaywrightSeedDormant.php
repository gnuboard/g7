<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Playwright·Chrome MCP 실측용 휴면 계정 픽스처 커맨드 (이슈 #636 S4).
 *
 * 휴면 축은 **시간이 흘러야** 재현되는 상태다. 730일을 기다릴 수 없으므로 마지막 활동
 * 시각과 통지 표지를 직접 심어 각 단계의 화면을 즉시 만들어 낸다. 검증 대상은 날짜 계산이
 * 아니라 화면·전환 동작이다.
 *
 * 만들 수 있는 상태 (`--stage`):
 *   - `pre_notice` : 사전 안내 대상 (활성 · 전환 임박 · 미통지)
 *   - `convertible`: 전환 대상 (활성 · 안내 완료 · 통지 기간 경과)
 *   - `dormant`    : 이미 휴면 (해제 축 실측용)
 *   - `withdraw_notice`: 탈퇴 예고 대상 (휴면 · 탈퇴 예정일 임박 · 미통지)
 *   - `withdraw`   : 탈퇴 대상 (휴면 · 예고 완료 · 휴면 기간 경과)
 *   - `withdrawn`  : 탈퇴 회원 잔여정보 파기 대상 (탈퇴 후 보존기간 경과)
 *
 * 보안 가드 (3중, `playwright:seed-two-factor` 와 동형):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`. production 웹 요청에서 도달 불가
 *   ② 명시 옵트인 — `G7_PLAYWRIGHT_BYPASS=1` 환경변수 필수
 *   ③ APP_DEBUG 강제 — production + debug=false 환경에서도 픽스처 조작 허용
 *
 * 호출 예시 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-dormant --stage=dormant
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-dormant --stage=convertible --count=25
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-dormant --purge-users
 */
class PlaywrightSeedDormant extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-dormant
        {--stage=dormant : 만들 상태 (pre_notice|convertible|dormant|withdraw_notice|withdraw|withdrawn)}
        {--password= : 생성 계정의 비밀번호 (기본값 Passw0rd!dorm)}
        {--count=1 : 같은 단계 계정을 몇 개 만들지 (대상 목록 창의 무한 스크롤 실측용, 최대 200)}
        {--purge-users : 이 커맨드가 만든 테스트 계정을 전부 제거 (실측 종료 직후 호출)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright·Chrome MCP 실측용 휴면 계정 픽스처를 만듭니다.';

    /** 테스트 전용 계정 이메일 접두사 — 정리 대상 판정에도 쓴다 */
    private const TEST_EMAIL_PREFIX = 'playwright_dormant_';

    /**
     * 관리자 메모에 남기는 시드 표지 — 이메일이 바뀐 뒤에도 정리 대상을 찾는 두 번째 표지다.
     *
     * `withdrawn` 단계 계정은 「지금 실행」이 이메일을 `withdrawn_{id}@removed.invalid` 로 바꾸라고
     * 심는다. 잔여정보 파기는 관리자 메모를 지우지 않으므로 파기 뒤에도 이 값으로 찾는다.
     */
    private const TEST_MEMO_MARKER = 'playwright:seed-dormant fixture';

    /**
     * 만들 수 있는 단계 — 개인정보 탭의 「대상 보기」 다섯 개가 각각 한 단계를 연다.
     *
     * @var list<string>
     */
    public const STAGES = ['pre_notice', 'convertible', 'dormant', 'withdraw_notice', 'withdraw', 'withdrawn'];

    /** 기본 비밀번호 */
    private const DEFAULT_PASSWORD = 'Passw0rd!dorm';

    /**
     * 커맨드를 실행합니다.
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
        // 여기의 `env()` 는 호출자가 그 자리에서 넘기는 프로세스 환경변수라 config:cache 무관.
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — $env:G7_PLAYWRIGHT_BYPASS=\'1\')');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제 — production + debug=false 환경에서도 픽스처 조작 허용
        Config::set('app.debug', true);

        if ($this->option('purge-users')) {
            $this->purgeTestUsers();

            return self::SUCCESS;
        }

        $stage = (string) $this->option('stage');

        if (! in_array($stage, self::STAGES, true)) {
            $this->error("알 수 없는 단계입니다: {$stage} (".implode('|', self::STAGES).')');

            return self::FAILURE;
        }

        // 대상 목록 창은 한 쪽 20건이라, 다음 쪽 이어 붙이기를 재려면 한 쪽을 넘는 대상이 필요하다.
        $count = max(1, min(200, (int) $this->option('count')));

        // 실측 스크립트가 파싱할 수 있도록 이메일을 한 줄씩, 마지막에 비밀번호를 그대로 출력한다.
        for ($i = 0; $i < $count; $i++) {
            $this->line($this->createUserForStage($stage)->email);
        }
        $this->line((string) ($this->option('password') ?: self::DEFAULT_PASSWORD));

        return self::SUCCESS;
    }

    /**
     * 단계에 맞는 테스트 계정을 만듭니다.
     *
     * 기간 설정을 읽어 그 설정에 맞는 시각을 심는다 — 리터럴 일수를 박으면 운영자가 기간을
     * 바꾼 사이트에서 픽스처가 대상에서 빠져 "화면이 비었다" 로 오판하게 된다.
     *
     * @param  string  $stage  만들 단계
     * @return User 생성된 회원
     */
    private function createUserForStage(string $stage): User
    {
        $dormantDays = max(1, (int) g7_core_settings('privacy.dormant_days', 730));
        $noticeDays = max(1, (int) g7_core_settings('privacy.dormant_notice_days', 30));
        $withdrawDays = max(1, (int) g7_core_settings('privacy.dormant_withdraw_days', 365));
        $withdrawnRetentionDays = max(1, (int) g7_core_settings('privacy.withdrawn_retention_days', 365));

        $attributes = [
            'uuid' => Str::orderedUuid()->toString(),
            'name' => '휴면 실측 회원',
            'email' => self::TEST_EMAIL_PREFIX.$stage.'_'.Str::lower(Str::random(6)).'@example.test',
            'password' => Hash::make((string) ($this->option('password') ?: self::DEFAULT_PASSWORD)),
            'email_verified_at' => now(),
            'status' => UserStatus::Active->value,
            'created_at' => now()->subDays($dormantDays + 60),
            'admin_memo' => self::TEST_MEMO_MARKER,
        ];

        // `+=` 가 아니라 array_merge 다 — `+=` 는 **이미 있는 키를 덮지 않으므로** 기본값으로 둔
        // `status` 가 그대로 남아 `--stage=dormant` 가 활성 계정을 만든다(실측으로 잡은 결함).
        $attributes = array_merge($attributes, match ($stage) {
            // 전환 예정일이 코앞이지만 아직 안내를 받지 않은 상태.
            'pre_notice' => [
                'last_login_at' => now()->subDays($dormantDays - $noticeDays + 1),
                'dormant_notified_at' => null,
            ],
            // 안내를 받았고 통지 기간까지 지난 상태 — 다음 실행에서 전환된다.
            'convertible' => [
                'last_login_at' => now()->subDays($dormantDays + 1),
                'dormant_notified_at' => now()->subDays($noticeDays + 1),
            ],
            // 이미 휴면 — 로그인 해제 축 실측용.
            'dormant' => [
                'last_login_at' => now()->subDays($dormantDays + 1),
                'status' => UserStatus::Dormant->value,
                'dormant_at' => now()->subDays(1),
                'dormant_notified_at' => null,
            ],
            // 휴면 전환 뒤 탈퇴 예정일이 통지 기간 안으로 들어왔지만 아직 예고를 받지 않은 상태.
            'withdraw_notice' => [
                'last_login_at' => now()->subDays($dormantDays + $withdrawDays),
                'status' => UserStatus::Dormant->value,
                'dormant_at' => now()->subDays(max(1, $withdrawDays - $noticeDays) + 1),
                'dormant_notified_at' => null,
            ],
            // 탈퇴 예고를 받았고 통지 기간과 휴면 기간이 모두 지난 상태 — 다음 실행에서 탈퇴된다.
            'withdraw' => [
                'last_login_at' => now()->subDays($dormantDays + $withdrawDays + 2),
                'status' => UserStatus::Dormant->value,
                'dormant_at' => now()->subDays($withdrawDays + 1),
                'dormant_notified_at' => now()->subDays($noticeDays + 1),
            ],
            // 탈퇴 후 보존기간이 지나 잔여 개인정보 파기를 기다리는 상태.
            'withdrawn' => [
                'last_login_at' => now()->subDays($withdrawnRetentionDays + 30),
                'status' => UserStatus::Withdrawn->value,
                'withdrawn_at' => now()->subDays($withdrawnRetentionDays + 1),
            ],
            default => [],
        });

        // forceFill — `created_at` 은 대량 할당 대상이 아니라 create() 가 **조용히 버리고** 지금 시각을 넣는다.
        // 그러면 「가입한 지 오래된 계정」이 방금 가입한 계정이 되어, 가입 경과를 보는 대상(연간 통지 등)에서
        // 빠진다(#636 §1-E 실측으로 잡은 결함).
        $user = new User;
        $user->forceFill($attributes)->save();

        return $user;
    }

    /**
     * 이 커맨드가 만든 테스트 계정을 제거합니다.
     *
     * 알려진 비밀번호를 가진 계정을 실측 뒤에 남기지 않는다.
     *
     * @return void 반환값 없음
     */
    private function purgeTestUsers(): void
    {
        // 이메일 접두사만 보면 실측이 잔여정보를 파기한 계정(이메일이 바뀐 계정)이 개발 DB 에 남는다.
        $deleted = User::query()
            ->where('email', 'like', self::TEST_EMAIL_PREFIX.'%')
            ->orWhere('admin_memo', self::TEST_MEMO_MARKER)
            ->delete();

        $this->info("휴면 실측 테스트 계정 {$deleted}건을 제거했습니다.");
    }
}
