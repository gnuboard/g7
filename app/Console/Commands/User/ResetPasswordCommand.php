<?php

namespace App\Console\Commands\User;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\PasswordChangeSource;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Rules\PasswordNotReused;
use App\Rules\PasswordPolicy;
use App\Services\PasswordResetService;
use App\Support\ExecutionEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * 서버 터미널에서 회원(관리자) 비밀번호를 재설정한다 — 메일을 보낼 수 없는 서버의 복구 수단.
 *
 * 메일 링크 재설정과 같은 서비스·같은 규칙을 쓴다: 비밀번호 정책 + 재사용 금지(켜져 있으면) 검증 → 잠금 해제 →
 * 모든 기기 로그아웃 → 비밀번호 변경 알림. 활동 로그는 행위자 없는 「시스템(CLI)」 재설정으로 남는다.
 *
 * 비밀번호는 명령줄 인자로 받지 않는다(프로세스 목록·셸 기록에 남는다) — 환경변수 G7_RESET_PASSWORD,
 * --password-file, 숨김 입력, --generate 만. 셸 접근이 곧 권한이다(totp:reset 과 같은 관례).
 *
 * 이메일을 생략하면 최고관리자가 대상이다 — 비밀번호를 잊은 최고관리자는 설치 때 넣은 이메일도
 * 기억하지 못하는 일이 많다. 한 명이면 그 계정, 여럿이면 터미널에서 고르게 하고(답할 수 없는 실행은 목록을
 * 보여 주고 실패), 답할 수 있는 실행은 대상을 보여 준 뒤 확인을 받는다.
 *
 * 확장이 덧붙일 안내(예: 2단계 인증 초기화 명령)는 filter 훅 `core.auth.console_password_reset_notices`
 * 로 받는다 — 코어는 어느 확장이 설치돼 있는지 묻지 않는다.
 */
class ResetPasswordCommand extends Command
{
    use HasUnifiedConfirm;
    use LocalizesDescription;

    /**
     * 명령 시그니처.
     *
     * @var string
     */
    protected $signature = 'user:reset-password
        {email? : 비밀번호를 재설정할 회원 이메일 (생략하면 최고관리자)}
        {--password-file= : 새 비밀번호를 담은 파일 (끝 개행 하나만 제거)}
        {--generate : 정책을 만족하는 무작위 비밀번호를 만들어 한 번만 출력 (다음 로그인 때 변경 강제)}
        {--require-change : 다음 로그인 때 비밀번호를 바꾸도록 강제}';

    /**
     * 비밀번호를 정하고 검증한 뒤 재설정한다.
     *
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  PasswordResetService  $resetService  재설정 실행 (메일 재설정과 공통)
     * @return int 종료 코드 (성공 0, 실패 1)
     */
    public function handle(UserRepositoryInterface $users, PasswordResetService $resetService): int
    {
        $email = trim((string) $this->argument('email'));
        if ($email === '') {
            $picked = $this->pickSuperAdmin($users);
            if (! $picked instanceof User) {
                return $picked;
            }
            $user = $picked;
            $email = (string) $user->email;
        } else {
            $user = $users->findByEmail($email);
            if (! $user) {
                $this->error('❌ '.__('user.commands.reset_password.not_found', ['email' => $email]));

                return self::FAILURE;
            }
        }

        $generated = (bool) $this->option('generate');
        $password = $this->resolvePassword($email, $generated);
        if ($password === null) {
            return self::FAILURE;
        }

        $rules = [...PasswordPolicy::rules($email), new PasswordNotReused($user)];
        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', ...$rules]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->get('password') as $message) {
                $this->error('❌ '.$message);
            }

            return self::FAILURE;
        }

        $requireChange = $generated || (bool) $this->option('require-change');
        try {
            $resetService->resetByOperator($user, $password, PasswordChangeSource::Console, $requireChange);
        } catch (Throwable $e) {
            // 트랜잭션이 되돌린 상태다 — 스택 대신 사유 한 줄로 끝낸다.
            $this->error('❌ '.__('user.commands.reset_password.failed', ['reason' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->info('✓ '.__('user.commands.reset_password.done', ['email' => $email]));
        $this->line('   - '.__('user.commands.reset_password.unlocked'));
        $this->line('   - '.__('user.commands.reset_password.sessions_revoked'));
        $this->line('   - '.__('user.commands.reset_password.notified'));
        if ($requireChange) {
            $this->line('   - '.__('user.commands.reset_password.change_required'));
        }
        if ($generated) {
            $this->warn(__('user.commands.reset_password.generated_once'));
            $this->line($password);
        }
        foreach ((array) HookManager::applyFilters('core.auth.console_password_reset_notices', [], $user) as $notice) {
            if (is_string($notice) && $notice !== '') {
                $this->line('   '.$notice);
            }
        }
        $this->warnIfLoginStillBlocked($user);

        return self::SUCCESS;
    }

    /**
     * 이메일 없이 실행했을 때 대상 최고관리자를 고른다.
     *
     * 한 명이면 그 계정, 여럿이면 선택을 받는다. 바꿀 계정을 보여 준 뒤 확인을 받는다 — 확인에 답할 수 없는 실행
     * (`--no-interaction`, 콘솔 없는 원격 명령)은 대상을 추측하지 않고 최고관리자 이메일 목록과 다시 실행할 명령을
     * 보여 준 뒤 실패로 끝낸다.
     *
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @return User|int 대상 회원, 또는 진행하지 않을 때의 종료 코드
     */
    private function pickSuperAdmin(UserRepositoryInterface $users): User|int
    {
        $supers = $users->getSuperAdmins();
        if ($supers->isEmpty()) {
            $this->error('❌ '.__('user.commands.reset_password.no_super_admin'));

            return self::FAILURE;
        }

        if (! $this->canAnswerConfirmation()) {
            $this->error('❌ '.__('user.commands.reset_password.super_admin_unconfirmable'));
            foreach ($supers as $super) {
                $this->line('   - '.$super->email);
            }
            $this->line(__('user.commands.reset_password.super_admin_rerun_hint', [
                'command' => 'php artisan user:reset-password '.$supers->first()->email,
            ]));

            return self::FAILURE;
        }

        if ($supers->count() === 1) {
            $user = $supers->first();
        } else {
            $chosen = (string) $this->choice(
                __('user.commands.reset_password.choose_super_admin'),
                $supers->pluck('email')->all(),
            );
            $user = $supers->firstWhere('email', $chosen);
        }

        $this->line(__('user.commands.reset_password.super_admin_target', ['email' => $user->email]));
        if (! $this->unifiedConfirm(__('user.commands.reset_password.confirm_super_admin'), false)) {
            $this->info(__('user.commands.reset_password.aborted'));

            return $this->confirmAbortExitCode(); // 대화형에서 운영자가 직접 답한 「아니오」는 의도한 취소라 0
        }

        return $user;
    }

    /**
     * 비밀번호를 바꿔도 회원 상태 때문에 로그인이 막히면 알린다 (사용 중지·차단·인증 대기·탈퇴).
     *
     * 상태는 이 명령이 바꾸지 않는다 — 재설정은 비밀번호만 다룬다. 운영자가 「바꿨는데 왜 안 되지」에서 멈추지 않게 이유만 보인다.
     * 판정은 로그인 경로와 같은 `UserStatus::allowsLogin()` 이다.
     *
     * @param  User  $user  재설정한 회원
     * @return void 반환값 없음
     */
    private function warnIfLoginStillBlocked(User $user): void
    {
        $status = $user->status instanceof UserStatus ? $user->status->value : (string) $user->status;
        if (UserStatus::allowsLogin($status)) {
            return;
        }

        $label = UserStatus::tryFrom($status)?->label() ?? $status;
        $this->warn(__('user.commands.reset_password.status_blocks_login', ['status' => $label]));
    }

    /**
     * 새 비밀번호 — 환경변수 → --password-file → --generate → 숨김 입력 2회.
     *
     * @param  string  $email  대상 이메일 (생성 비밀번호의 정책 확인용)
     * @param  bool  $generate  무작위 생성 여부
     * @return string|null 비밀번호 (얻지 못하면 null — 사유 출력)
     */
    private function resolvePassword(string $email, bool $generate): ?string
    {
        // getenv 가 막힌 PHP 에서는 호출 자체가 Error 다 — CLI 가 $_SERVER 에 심어 둔 환경변수로 대신 읽는다.
        $fromEnv = ExecutionEnvironment::hasFunction('getenv')
            ? getenv('G7_RESET_PASSWORD')
            : ($_SERVER['G7_RESET_PASSWORD'] ?? false);
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $file = $this->option('password-file');
        if (is_string($file) && $file !== '') {
            $content = @file_get_contents($file);
            if (! is_string($content)) {
                $this->error('❌ '.__('user.commands.reset_password.file_unreadable', ['path' => $file]));

                return null;
            }

            return (string) preg_replace('/\r?\n\z/', '', $content);
        }

        if ($generate) {
            return $this->generatePassword($email);
        }

        // 콘솔 없는 실행은 isInteractive() 가 참으로 남는 환경(Windows)이 있어 비밀값 입력을 무한히 기다린다 — 같은 판정을 쓴다
        if (! $this->canAnswerConfirmation()) {
            $this->error('❌ '.__('user.commands.reset_password.no_source'));

            return null;
        }

        $first = (string) $this->secret(__('user.commands.reset_password.ask'));
        $second = (string) $this->secret(__('user.commands.reset_password.ask_confirm'));
        if ($first !== $second) {
            $this->error('❌ '.__('user.commands.reset_password.mismatch'));

            return null;
        }

        return $first;
    }

    /**
     * 현재 정책을 만족하는 무작위 비밀번호 (대소문자·숫자·특수문자 포함, 정책 최소 길이 이상).
     *
     * @param  string  $email  아이디 포함 검사용 이메일
     * @return string 비밀번호
     */
    private function generatePassword(string $email): string
    {
        $minLength = max(16, (int) g7_core_settings('security.password_min_length', PasswordPolicy::DEFAULT_MIN_LENGTH));
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*-_=+'];
        $all = implode('', $sets);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $chars = array_map(static fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);
            while (count($chars) < $minLength) {
                $chars[] = $all[random_int(0, strlen($all) - 1)];
            }
            // 섞기도 암호학적 난수로 (shuffle() 은 예측 가능한 난수를 쓴다)
            for ($i = count($chars) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
            }
            $candidate = implode('', $chars);

            if (! Validator::make(['password' => $candidate], ['password' => PasswordPolicy::rules($email)])->fails()) {
                return $candidate;
            }
        }

        return $candidate;
    }
}
