<?php

namespace Tests\Feature\Security;

use App\Exceptions\CannotModifyProtectedRoleException;
use App\Exceptions\CannotModifySuperAdminException;
use App\Exceptions\PermissionEscalationException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 등급 상한 예외 3종의 전역 렌더러(bootstrap/app.php) 계약 테스트
 *
 * KVE-2026-1919 가 도입한 렌더러는 심층 방어다 — 현재 호출부(UserController /
 * RoleController)는 전부 로컬 catch 로 403 을 만들기 때문에, 렌더러 블록을 통째로
 * 지워도 기존 스위트는 전부 green 이 된다. 그러면 "새 호출부가 로컬 catch 없이
 * 던져도 조용한 500 대신 403" 이라는 렌더러의 존재 이유가 검증되지 않은 채 남는다.
 *
 * 그래서 로컬 catch 가 없는 경로를 테스트 안에서 만들어 렌더러 자신을 직접 건다.
 */
class SecurityExceptionRendererTest extends TestCase
{
    /**
     * 로컬 catch 없이 던져진 등급 상한 예외 3종이 500 이 아닌 403 으로 렌더된다.
     *
     * @param  string  $exceptionClass  던질 예외 클래스
     * @param  string  $langKey  기대 메시지의 lang 키
     *
     * @effects ceiling_exceptions_render_as_403_without_local_catch
     */
    #[DataProvider('ceilingExceptionProvider')]
    public function test_ceiling_exceptions_render_as_403_without_local_catch(string $exceptionClass, string $langKey): void
    {
        Route::middleware('api')->get('/api/_test/ceiling-render', function () use ($exceptionClass) {
            throw new $exceptionClass;
        });

        $response = $this->getJson('/api/_test/ceiling-render');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => __($langKey),
        ]);
    }

    /**
     * 예외 3종 × 대응 lang 키
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function ceilingExceptionProvider(): array
    {
        return [
            'super admin 보호' => [CannotModifySuperAdminException::class, 'exceptions.cannot_modify_super_admin'],
            '보호 역할' => [CannotModifyProtectedRoleException::class, 'exceptions.cannot_modify_protected_role'],
            '권한 상승' => [PermissionEscalationException::class, 'exceptions.cannot_grant_unheld_permission'],
        ];
    }
}
