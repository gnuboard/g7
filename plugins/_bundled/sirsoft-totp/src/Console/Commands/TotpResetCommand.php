<?php

namespace Plugins\Sirsoft\Totp\Console\Commands;

use App\Contracts\Repositories\UserRepositoryInterface;
use Illuminate\Console\Command;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;

/**
 * 회원의 인증 앱 등록을 서버에서 강제 해제하는 최후 구제 커맨드.
 *
 * 관리자 본인이 단말과 백업 코드를 모두 잃으면 관리자 화면에 들어갈 수 없어 UI 경로
 * (`DELETE admin/credentials/{user}`)를 쓸 수 없다. 그 유일한 탈출구다 — 서버 셸 접근
 * 자체가 권한이므로 추가 확인을 요구하지 않는다.
 *
 * @since 1.0.0
 */
class TotpResetCommand extends Command
{
    /**
     * 커맨드 시그니처.
     *
     * @var string
     */
    protected $signature = 'totp:reset {email : 등록을 해제할 회원의 이메일}';

    /**
     * 커맨드 설명.
     *
     * @var string
     */
    protected $description = '회원의 인증 앱(TOTP) 등록을 강제 해제합니다 (단말·백업 코드 분실 시 최후 구제)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  UserRepositoryInterface  $userRepository  사용자 저장소
     * @param  TotpEnrollmentService  $enrollmentService  등록 서비스
     * @return int 종료 코드
     */
    public function handle(
        UserRepositoryInterface $userRepository,
        TotpEnrollmentService $enrollmentService,
    ): int {
        $email = (string) $this->argument('email');
        $user = $userRepository->findByEmail($email);

        if ($user === null) {
            $this->error("회원을 찾을 수 없습니다: {$email}");

            return self::FAILURE;
        }

        // 관리자 화면과 같은 감사 기록을 남긴다 — 셸에는 요청 사용자·IP 가 없다
        if (! $enrollmentService->adminReset($user, null, null)) {
            $this->warn("이 회원에게는 등록된 인증 앱이 없습니다: {$email}");

            return self::SUCCESS;
        }

        $this->info("인증 앱 등록을 해제했습니다: {$email}");
        $this->line('다음 로그인부터 이 회원은 인증 앱 코드를 요구받지 않습니다 (메일 폴백이 켜져 있으면 이메일로 받습니다).');

        return self::SUCCESS;
    }
}
