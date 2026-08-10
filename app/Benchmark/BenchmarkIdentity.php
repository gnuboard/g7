<?php

namespace App\Benchmark;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 화면 계측용 인증 주체 해석기
 *
 * 관리자 화면의 응답 시간은 로그인 상태에서만 잴 수 있습니다. 두 방식을 지원합니다.
 *
 *   기본  — 프로파일이 선언한 권한만 가진 임시 관리자를 즉석 생성해 Sanctum 토큰 발급
 *   `--as` — 기존 계정을 지정 (그 계정의 실제 역할/권한으로 재측정)
 *
 * 두 경로 모두 계정/역할/토큰 생성이라는 **쓰기**를 동반하므로, 호출자가 열어둔 트랜잭션
 * 안에서 수행하고 계측 후 롤백해 흔적을 남기지 않습니다(`withRolledBackTransaction`).
 * 트랜잭션이 열려 있으면 Laravel 이 읽기 쿼리도 write PDO 로 보내므로(`Connection::getReadPdo`
 * 의 `transactions > 0` 분기), 읽기/쓰기 분리 환경에서도 계측 요청이 이 계정을 인증할 수
 * 있습니다. 운영 DB 에서도 잔여 계정이 생기지 않는 이유가 이 롤백입니다.
 */
class BenchmarkIdentity
{
    /**
     * 롤백되는 트랜잭션 안에서 콜백을 실행합니다.
     *
     * 계측이 만든 계정·토큰과, 계측 대상이 만든 행까지 함께 되돌립니다. 쓰기 축의 결과를
     * 되돌리는 것도 같은 장치를 씁니다.
     *
     * @param  \Closure  $callback  트랜잭션 안에서 실행할 작업
     * @return mixed 콜백 반환값
     */
    public function withRolledBackTransaction(\Closure $callback): mixed
    {
        DB::beginTransaction();

        try {
            return $callback();
        } finally {
            // 계측 결과 산출 여부와 무관하게 되돌린다 — 예외로 빠져나가도 잔여 데이터 없음
            DB::rollBack();
        }
    }

    /**
     * 계측에 사용할 Sanctum 토큰을 발급합니다.
     *
     * 호출 전에 `--as` 계정 존재를 `findExistingUser()` 로 확인해야 합니다 — 계정 부재는
     * 계측 실패가 아니라 실행 전 판정 대상이라 이 메서드는 그 경우를 다루지 않습니다.
     *
     * @param  array<int, string>  $permissions  임시 계정에 부여할 권한 식별자 목록
     * @param  User|null  $actor  `--as` 로 확인된 기존 계정 (null 이면 임시 계정 생성)
     * @return array{token: string, user: User, ephemeral: bool} 토큰과 인증 주체
     */
    public function issueToken(array $permissions = [], ?User $actor = null): array
    {
        $user = $actor ?? $this->makeEphemeralAdmin($permissions);

        return [
            'token' => $user->createToken('g7-bench-'.Str::random(8))->plainTextToken,
            'user' => $user,
            'ephemeral' => $actor === null,
        ];
    }

    /**
     * 지정된 기존 계정을 찾습니다.
     *
     * @param  string  $identifier  계정 ID 또는 이메일
     * @return User|null 찾은 계정 (없으면 null)
     */
    public function findExistingUser(string $identifier): ?User
    {
        return ctype_digit($identifier)
            ? User::find((int) $identifier)
            : User::where('email', $identifier)->first();
    }

    /**
     * 선언된 권한만 가진 임시 관리자를 생성합니다.
     *
     * 권한을 프로파일 선언에서 받는 이유는, 계측 대상 화면이 요구하는 권한만 주어야
     * 미들웨어 통과 여부까지 실제와 같아지기 때문입니다(전권 계정으로 재면 권한 검사
     * 비용과 분기가 달라집니다).
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 생성된 임시 관리자
     */
    private function makeEphemeralAdmin(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];

        foreach ($permissions as $identifier) {
            $identifier = (string) $identifier;

            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );

            $permissionIds[] = $permission->id;
        }

        $benchRole = Role::create([
            'identifier' => 'g7_bench_'.Str::random(8),
            'name' => json_encode(['ko' => '성능 계측 전용', 'en' => 'Benchmark Only']),
            'description' => json_encode(['ko' => '성능 계측 임시 역할', 'en' => 'Temporary benchmark role']),
            'is_active' => true,
        ]);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Admin']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Admin']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
                'is_active' => true,
            ]
        );

        if ($permissionIds !== []) {
            $benchRole->permissions()->sync($permissionIds);
        }

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($benchRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
