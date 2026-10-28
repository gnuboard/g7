<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\StuckUpdateRecovery;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 역방향 의존 안내의 연결 지점 — 재설치(`install --force`)·강제 비활성화·멈춘 업데이트 정리·업데이트 성공 경로 (#152 D6)
 *
 * 판정과 안내 수준은 DependencyVersionWarningsDependentsTest 가 잠근다. 여기서는 매니저 메서드가 안내를 부르는지 잠근다.
 * 실제로 비활성화·재설치를 돌리면 라우트 캐시 재굽기·훅 캐시 재생성이 따라와 시험 중 실행할 수 없으므로(테스트 중 캐시 재굽기 금지)
 * 메서드 본문에서 호출과 그 조건을 읽는다. 호출 줄이나 조건을 지우면 실패한다.
 *
 * 시나리오 매니페스트: tests/scenarios/template-dependency-version.yaml
 */
class DependentsNoticeWiringTest extends TestCase
{
    /**
     * 메서드 본문 소스를 읽는다.
     *
     * @param  class-string  $class  클래스
     * @param  string  $method  메서드 이름
     * @return string 본문 소스
     */
    private function body(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    /**
     * 재설치·강제 비활성화·업데이트 성공 경로가 모듈·플러그인 모두 역방향 안내를 부른다.
     *
     * @scenario surface=dependents_wiring
     *
     * @effects dependents_notice_wired_into_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_manager_lifecycle_paths_call_dependents_notice(): void
    {
        foreach ([[ModuleManager::class, 'module', 'Module'], [PluginManager::class, 'plugin', 'Plugin']] as [$class, $type, $suffix]) {
            $install = $this->body($class, 'install'.$suffix);
            $this->assertMatchesRegularExpression(
                '/if \(\$existingRecord !== null\) \{\s*DependencyVersionWarnings::noticeDependents\(\''.$type.'\',/',
                $install,
                "{$class}::install{$suffix} — 기존 레코드 위 재설치 뒤 안내"
            );

            $deactivate = $this->body($class, 'deactivate'.$suffix);
            $this->assertMatchesRegularExpression(
                '/if \(\$hasDependents\) \{\s*DependencyVersionWarnings::noticeDependents\(\''.$type.'\',/',
                $deactivate,
                "{$class}::deactivate{$suffix} — 의존자 경고를 건너뛴 강제 비활성화 뒤 안내"
            );

            $update = $this->body($class, 'update'.$suffix);
            $this->assertStringContainsString(
                "DependencyVersionWarnings::forDependents('{$type}', \$identifier)",
                $update,
                "{$class}::update{$suffix} — 업데이트 결과에 역방향 안내 합침"
            );
        }
    }

    /**
     * 멈춘 업데이트 정리는 판을 되돌렸을 때만, 판 기준으로 안내한다.
     *
     * @scenario surface=dependents_wiring
     *
     * @effects dependents_notice_wired_into_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_stuck_update_recovery_notices_only_when_version_reverted(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \(\$unfinished !== null\) \{\s*DependencyVersionWarnings::noticeDependents\(\$type, \$identifier, versionOnly: true\);/',
            $this->body(StuckUpdateRecovery::class, 'recover')
        );
    }
}
