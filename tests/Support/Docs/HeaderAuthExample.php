<?php

namespace Tests\Support\Docs;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\Auth\PasswordAuthDecision;
use App\Extension\HookManager;
use App\Services\Auth\LoginHandoffService;
use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * 공개 문서 예제 — 헤더 인증 확장 (docs/backend/authentication.md 「예제: 헤더 인증 확장」)
 *
 * 사내 인증 프록시가 본인을 확인한 뒤 `X-Remote-User` 헤더에 이메일을 실어 보내는 사이트를 가정한다. 이 클래스는 문서의 예제
 * 코드를 그대로 옮긴 것이고 `Tests\Feature\Docs\HeaderAuthExampleTest` 가 왕복을 돌린다 — 문서를 고치면 여기도 같이 고친다.
 */
final class HeaderAuthExample
{
    /** 로그인 수단 이름 — 로그인 완료 훅 `login_method` 와 활동 로그에 실린다 */
    public const LOGIN_METHOD = 'external:header';

    /** 회사 계정 도메인 — 이 도메인은 비밀번호 로그인을 막고 헤더 인증으로만 들어온다 */
    public const COMPANY_DOMAIN = '@corp.example';

    /** 진입 경로 — 인증 프록시가 이 주소만 보호한다. 확장 웹 라우트는 화면 catch-all 이 비켜 가는 `/plugins/{식별자}/…` 아래에 둔다 */
    public const ENTRY_PATH = '/plugins/header-auth/login';

    /**
     * 진입 라우트와 비밀번호 인증 정책 필터를 등록합니다 (확장의 서비스 프로바이더 boot 에 해당).
     *
     * @return void 반환값 없음
     */
    public static function register(): void
    {
        Route::middleware('web')->get(self::ENTRY_PATH, [self::class, 'login']);

        HookManager::addFilter('core.auth.password_auth_decision', [self::class, 'passwordAuthDecision'], 10, 2);
    }

    /**
     * 신뢰 프록시가 붙인 헤더로 회원을 확정하고 로그인 화면으로 인계합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  AuthService  $auth  인증 서비스
     * @param  LoginHandoffService  $handoff  로그인 인계 서비스
     * @return RedirectResponse `/login?login_handoff={코드}` 로 302
     */
    public function login(Request $request, UserRepositoryInterface $users, AuthService $auth, LoginHandoffService $handoff): RedirectResponse
    {
        // ① 헤더는 신뢰 프록시가 붙인 것만 믿는다 — 직접 들어온 요청의 헤더는 누구나 위조할 수 있다
        abort_unless($request->isFromTrustedProxy(), 403);

        $email = mb_strtolower(trim((string) $request->header('X-Remote-User', '')));
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 401);

        // ② 회원을 찾거나 만든다 — 회사가 만드는 계정이라 본인인증 정책·동의 기록을 건너뛴다(사유 필수)
        $user = $users->findByEmail($email) ?? $auth->register(
            ['name' => strstr($email, '@', true), 'email' => $email],
            [
                'issue_token' => false,
                'identity_policy' => 'skip',
                'identity_policy_reason' => self::LOGIN_METHOD,
                'record_consents' => false,
            ],
        )['user'];

        // ③ 토큰을 직접 만들지 않는다 — 인계 코드를 받아 로그인 화면이 교환한다(잠금·상태·2단계 인증·휴면은 코어가 처리)
        $code = $handoff->issue($user, ['login_method' => self::LOGIN_METHOD]);

        return redirect('/login?login_handoff='.urlencode($code));
    }

    /**
     * ④ 회사 계정 도메인의 비밀번호 로그인·재설정을 막고 헤더 인증 진입으로 안내합니다.
     *
     * @param  PasswordAuthDecision|null  $decision  앞선 확장의 판정 (있으면 그대로 둔다)
     * @param  array{identifier: string, user?: mixed, guard: string, purpose: string}  $subject  판정 대상
     * @return PasswordAuthDecision|null 거절 판정, 담당이 아니면 받은 값
     */
    public static function passwordAuthDecision(?PasswordAuthDecision $decision, array $subject): ?PasswordAuthDecision
    {
        if ($decision !== null || ! str_ends_with(mb_strtolower((string) ($subject['identifier'] ?? '')), self::COMPANY_DOMAIN)) {
            return $decision;
        }

        return PasswordAuthDecision::deny(
            basis: PasswordAuthDecision::BASIS_IDENTIFIER,
            methodLabel: ['ko' => '회사 계정', 'en' => 'Company account'],
            redirectUrl: self::ENTRY_PATH,
        );
    }
}
