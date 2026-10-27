<?php

use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\SitePolicyRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;

// 브라우저 검증용 회원·정책·IdP 설정을 만들고 되돌립니다 — 회원은 이메일 접두어(e2e798.)로만 다루고, 지울 때는 코어 회원 삭제 경로를 탑니다.
//   member <email> <password|-> [role…]   회원 (비밀번호 「-」 면 비밀번호 없는 회원), 역할 식별자 여러 개
//   link <email> <idp> <subject>          SSO 연결 행
//   state <email>                         회원·연결·동의 상태 (JSON 한 줄)
//   policy-get                            사이트 정책 (JSON 한 줄)
//   policy-put <json>                     사이트 정책 덮어쓰기 — 저장소에 바로 쓴다(PolicyService::update 의 비밀번호 토큰 회수를 타지 않아
//                                         개발 사이트의 다른 회원 로그인을 건드리지 않는다). 비상 계정은 이메일 목록 `emergency_emails` 로 받는다
//   idp-get <key>                         IdP 의 시험이 바꾸는 칸 (JSON 한 줄)
//   idp-put <key> <json>                  IdP 칸 덮어쓰기
//   purge                                 e2e798. 회원 전부 삭제
if (PHP_SAPI !== 'cli' || getenv('G7_PLAYWRIGHT_BYPASS') !== '1') {
    throw new RuntimeException('Explicit CLI test opt-in required');
}
$root = dirname(__DIR__, 6);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Browser fixtures require local or testing environment');
}

$prefix = 'e2e798.';
$args = array_slice($argv, 1);
$action = $args[0] ?? null;

/**
 * 접두어 회원만 다루게 막습니다.
 *
 * @param  string|null  $email  이메일
 * @param  string  $prefix  접두어
 * @return string 이메일
 */
$guard = static function (?string $email) use ($prefix): string {
    if ($email === null || ! str_starts_with($email, $prefix)) {
        throw new RuntimeException('Fixture emails must start with '.$prefix);
    }

    return $email;
};

/** 시험이 바꾸는 IdP 칸 */
$idpFields = ['enabled', 'enforce_owned_domains', 'allow_local_password', 'two_factor_mode', 'require_consent', 'provisioning_mode', 'idp_initiated', 'owned_domains', 'button_visible'];

switch ($action) {
    case 'member':
        $email = $guard($args[1] ?? null);
        $password = $args[2] ?? '-';
        $user = User::factory()->create([
            'email' => $email,
            'name' => 'e2e798 member',
            'password' => $password !== '-' ? Hash::make($password) : null,
        ]);
        $identifiers = array_slice($args, 3) ?: ['user'];
        $user->roles()->sync(Role::query()->whereIn('identifier', $identifiers)->pluck('id')->all());
        echo $user->id."\n";
        break;
    case 'link':
        $user = User::query()->where('email', $guard($args[1] ?? null))->firstOrFail();
        $idp = IdentityProvider::query()->where('key', $args[2] ?? '')->firstOrFail();
        SsoAccount::query()->create(['idp_id' => $idp->id, 'user_id' => $user->id, 'subject' => (string) ($args[3] ?? ''), 'email' => $user->email, 'linked_via' => 'admin', 'linked_at' => now()]);
        echo "linked\n";
        break;
    case 'state':
        $user = User::query()->where('email', $guard($args[1] ?? null))->first();
        echo json_encode($user === null ? ['exists' => false] : [
            'exists' => true,
            'has_password' => $user->password !== null,
            'links' => SsoAccount::query()->where('user_id', $user->id)->get()->map(fn (SsoAccount $a) => ['idp' => $a->idp?->key, 'linked_via' => $a->linked_via])->all(),
            'consents' => $user->consents()->count(),
            'tokens' => $user->tokens()->count(),
        ], JSON_UNESCAPED_UNICODE)."\n";
        break;
    case 'policy-get':
        echo json_encode(app(PolicyService::class)->current(), JSON_UNESCAPED_UNICODE)."\n";
        break;
    case 'policy-put':
        $policy = json_decode((string) ($args[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        if (array_key_exists('emergency_emails', $policy)) {
            $policy['emergency_user_ids'] = User::query()->whereIn('email', array_map($guard, (array) $policy['emergency_emails']))->pluck('id')->all();
            unset($policy['emergency_emails']);
        }
        app(SitePolicyRepositoryInterface::class)->save(array_merge(app(PolicyService::class)->current(), $policy), null);
        echo "saved\n";
        break;
    case 'idp-get':
        $idp = IdentityProvider::query()->where('key', $args[1] ?? '')->firstOrFail();
        echo json_encode($idp->only($idpFields), JSON_UNESCAPED_UNICODE)."\n";
        break;
    case 'idp-put':
        $idp = IdentityProvider::query()->where('key', $args[1] ?? '')->firstOrFail();
        $idp->forceFill(array_intersect_key(json_decode((string) ($args[2] ?? ''), true, flags: JSON_THROW_ON_ERROR), array_flip($idpFields)))->save();
        echo "saved\n";
        break;
    case 'purge':
        $count = 0;
        foreach (User::query()->where('email', 'like', $prefix.'%')->get() as $user) {
            // 시도 기록·연결은 회원 삭제로 지워지지 않을 수 있다 — 측정 흔적을 같이 지운다.
            SsoLoginAttempt::query()->where('user_id', $user->id)->delete();
            SsoAccount::query()->where('user_id', $user->id)->delete();
            app(UserService::class)->deleteUser($user);
            $count++;
        }
        echo "purged {$count}\n";
        break;
    default:
        throw new RuntimeException('Unknown action');
}
