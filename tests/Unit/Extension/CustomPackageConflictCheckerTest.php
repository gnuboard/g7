<?php

namespace Tests\Unit\Extension;

use App\Extension\Custom\CustomPackageConflictChecker;
use PHPUnit\Framework\TestCase;

/**
 * 개발자 패키지(custom/composer.lock) ↔ 코어 패키지 정적 충돌 판정 단위 테스트 (#792 W18)
 *
 * composer 를 실행하지 않고 lock 두 개만으로 「요구 제약 불만족」과 「같은 패키지 다른 버전」을 잡는지 잠근다.
 */
class CustomPackageConflictCheckerTest extends TestCase
{
    /**
     * 코어 lock — symfony/console 7.1.0, twig 없음.
     *
     * @return array<string, mixed> lock 내용
     */
    private function coreLock(): array
    {
        return ['packages' => [
            ['name' => 'symfony/console', 'version' => 'v7.1.0'],
            ['name' => 'symfony/polyfill-mbstring', 'version' => 'v1.31.0'],
            ['name' => 'psr/log', 'version' => '3.0.2'],
        ]];
    }

    /**
     * 제약을 만족하는 패키지·플랫폼 요구는 충돌이 아닙니다.
     *
     * @scenario case=no_conflict
     *
     * @effects compatible_packages_pass
     *
     * @return void 반환값 없음
     */
    public function test_compatible_packages_have_no_conflict(): void
    {
        $custom = ['packages' => [
            ['name' => 'twig/twig', 'version' => 'v3.14.0', 'require' => [
                'php' => '>=8.1',
                'ext-mbstring' => '*',
                'symfony/polyfill-mbstring' => '^1.3',
            ]],
        ]];

        $this->assertSame([], (new CustomPackageConflictChecker)->check($this->coreLock(), $custom));
    }

    /**
     * custom 패키지가 요구하는 코어 패키지 버전 제약을 코어가 만족하지 않으면 constraint 충돌입니다.
     *
     * @scenario case=constraint_unsatisfied
     *
     * @effects constraint_conflict_detected
     *
     * @return void 반환값 없음
     */
    public function test_unsatisfied_constraint_is_conflict(): void
    {
        $custom = ['packages' => [
            ['name' => 'acme/cli-kit', 'version' => '2.0.0', 'require' => ['symfony/console' => '^6.4']],
        ]];

        $conflicts = (new CustomPackageConflictChecker)->check($this->coreLock(), $custom);

        $this->assertCount(1, $conflicts);
        $this->assertSame(CustomPackageConflictChecker::TYPE_CONSTRAINT, $conflicts[0]['type']);
        $this->assertSame('acme/cli-kit', $conflicts[0]['package']);
        $this->assertSame('symfony/console', $conflicts[0]['requires']);
        $this->assertSame('^6.4', $conflicts[0]['constraint']);
        $this->assertSame('v7.1.0', $conflicts[0]['core_version']);
    }

    /**
     * 같은 패키지가 코어와 다른 버전으로 custom 에 있으면 duplicate 충돌, 같은 버전(앞 v 무시)이면 충돌 아님.
     *
     * @scenario case=duplicate
     *
     * @effects duplicate_conflict_detected, same_version_duplicate_allowed
     *
     * @return void 반환값 없음
     */
    public function test_same_package_different_version_is_conflict(): void
    {
        $checker = new CustomPackageConflictChecker;

        $different = $checker->check($this->coreLock(), ['packages' => [['name' => 'psr/log', 'version' => '2.0.0']]]);
        $this->assertCount(1, $different);
        $this->assertSame(CustomPackageConflictChecker::TYPE_DUPLICATE, $different[0]['type']);
        $this->assertSame('3.0.2', $different[0]['core_version']);

        $same = $checker->check($this->coreLock(), ['packages' => [['name' => 'psr/log', 'version' => 'v3.0.2']]]);
        $this->assertSame([], $same);
    }

    /**
     * 판정할 수 없는 dev 버전은 막지 않고, lock 파일이 없으면 빈 결과입니다.
     *
     * @scenario case=unparseable_or_missing
     *
     * @effects unparseable_version_not_blocking, missing_lock_is_empty
     *
     * @return void 반환값 없음
     */
    public function test_unparseable_and_missing_lock(): void
    {
        $checker = new CustomPackageConflictChecker;
        $core = ['packages' => [['name' => 'symfony/console', 'version' => 'dev-main']]];
        $custom = ['packages' => [['name' => 'acme/x', 'version' => '1.0.0', 'require' => ['symfony/console' => '^7.0']]]];

        $this->assertSame([], $checker->check($core, $custom));
        $this->assertSame([], $checker->checkFiles(sys_get_temp_dir().'/no-such-core.lock', sys_get_temp_dir().'/no-such-custom.lock'));
    }
}
