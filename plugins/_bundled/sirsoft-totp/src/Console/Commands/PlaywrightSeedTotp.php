<?php

namespace Plugins\Sirsoft\Totp\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Support\Base32;
use Plugins\Sirsoft\Totp\Support\Totp;

/**
 * Playwright E2E 용 인증 앱(TOTP) 픽스처 커맨드.
 *
 * 두 가지 일을 한다.
 *   - `--enroll=<이메일>` : 그 계정에 «인증 앱이 이미 등록된 상태»(확정된 자격증명 + 고정 백업 코드)를
 *     만들고 평문 시크릿을 출력한다. 실측은 휴대폰을 들 수 없으므로 QR 스캔 단계를 서버에서 건너뛴다.
 *   - `--code=<시크릿>`   : 그 Base32 시크릿의 «지금 이 순간» 코드를 계산해 출력한다 (인증 앱 대역).
 *
 * 테스트 계정 준비·정리는 코어 `playwright:seed-two-factor` 가 맡는다. 종전에는 그 코어 커맨드가 이
 * 플러그인의 모델·시크릿 유틸을 직접 import 해 두 일을 했다 — 코어가 특정 확장을 아는 자리였으므로
 * 이 플러그인 소유로 옮겼다(2026-09-28 결정).
 *
 * 보안 가드 (3중, 코어 `playwright:issue-token` 과 동형):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② 명시 옵트인 — `G7_PLAYWRIGHT_BYPASS=1` 환경변수 필수
 *   ③ APP_DEBUG 강제 — production + debug=false 환경에서도 픽스처 조작이 가능하도록
 *
 * 호출 예시 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-totp --enroll=playwright_2fa_admin@example.test
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-totp --code=<BASE32>
 */
class PlaywrightSeedTotp extends Command
{
    protected $signature = 'playwright:seed-totp
        {--enroll= : 이 이메일 계정에 확정된 TOTP 자격증명을 심고 시크릿을 출력한다}
        {--code= : 이 Base32 시크릿의 현재 코드를 계산해 출력한다 (인증 앱 대역)}';

    protected $description = 'Playwright E2E 용 인증 앱 픽스처 (자격증명 심기 / 현재 코드 계산)';

    /**
     * 실측이 쓰는 고정 백업 코드 — 평문을 알아야 «백업 코드로 로그인» 축을 자동화할 수 있다.
     *
     * @var array<int, string>
     */
    private const TEST_BACKUP_CODES = ['pwtst-aaaa1', 'pwtst-bbbb2'];

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

        // ② 명시 옵트인 — 호출자가 그 자리에서 넘기는 프로세스 환경변수라 config:cache 영향이 없다.
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — $env:G7_PLAYWRIGHT_BYPASS=\'1\')');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제
        Config::set('app.debug', true);

        if ($email = $this->option('enroll')) {
            return $this->enroll((string) $email) ? self::SUCCESS : self::FAILURE;
        }

        if ($secret = $this->option('code')) {
            $this->line(Totp::codeAt((string) $secret, Totp::timestepAt()));

            return self::SUCCESS;
        }

        $this->error('--enroll · --code 중 하나는 지정해야 합니다.');

        return self::FAILURE;
    }

    /**
     * 대상 계정에 확정된 TOTP 자격증명을 심고 평문 시크릿을 출력합니다.
     *
     * 등록 API 를 그대로 태우면 QR 을 사람이 읽어야 해서 자동화가 불가능하다. 이 커맨드 전체가
     * `G7_PLAYWRIGHT_BYPASS` 가드 뒤에 있으므로 운영에서는 실행되지 않는다.
     *
     * @param  string  $email  대상 계정 이메일
     * @return bool 성공 여부
     */
    private function enroll(string $email): bool
    {
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("계정을 찾을 수 없습니다: {$email}");

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
}
