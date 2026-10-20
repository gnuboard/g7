<?php

use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

// 브라우저 검증용 회원을 만들고 지웁니다 — 이메일 접두어(e2e40.)로만 다루고, 지울 때는 코어 회원 삭제 경로를 탑니다(연결 정리 리스너 포함).
//   member <email> [password]            비밀번호 없으면 비밀번호 없는 회원
//   link <email> <provider> <uid>        소셜 연결 행
//   token <email>                        로그인 토큰 (마지막 줄)
//   purge                                e2e40. 회원 전부 삭제
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

$prefix = 'e2e40.';
[$action, $email, $third, $fourth] = array_pad(array_slice($argv, 1), 4, null);

if ($email !== null && ! str_starts_with($email, $prefix) && ! str_contains($email, '_e2e40')) {
    throw new RuntimeException('Fixture emails must start with '.$prefix);
}

switch ($action) {
    case 'member':
        $user = User::factory()->create([
            'email' => $email,
            'name' => 'e2e40 member',
            'password' => $third !== null ? Hash::make($third) : null,
        ]);
        $role = Role::query()->where('identifier', 'user')->first();
        if ($role) {
            $user->roles()->sync([$role->id]);
        }
        echo $user->id."\n";
        break;
    case 'link':
        $user = User::query()->where('email', $email)->firstOrFail();
        SocialAccount::query()->create(['user_id' => $user->id, 'provider_key' => $third, 'provider_user_id' => $fourth, 'linked_at' => now()]);
        echo "linked\n";
        break;
    case 'token':
        echo User::query()->where('email', $email)->firstOrFail()->createToken('auth-token')->plainTextToken."\n";
        break;
    case 'purge':
        $count = 0;
        $users = User::query()->where('email', 'like', $prefix.'%')->orWhere('email', 'like', '%_e2e40%@social.invalid')->get();
        foreach ($users as $user) {
            app(UserService::class)->deleteUser($user);
            $count++;
        }
        echo "purged {$count}\n";
        break;
    default:
        throw new RuntimeException('Unknown action');
}
