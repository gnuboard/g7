<?php

namespace Tests\Unit\Services\Extension;

use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 모듈·플러그인 의존 버전 검사가 남긴 안내 결함 3건 (#152 F15·F16·F17)
 *
 * 1) 설치 미리보기가 설치됐지만 버전이 모자란 의존을 「함께 설치」로 고를 수 있게 보여, 골라도 건너뛴 뒤 본 설치가 실패했다.
 * 2) 연쇄 설치 실패 사유에 `(version_mismatch)` 원문이 그대로 나갔다.
 * 3) 설치 실패 문구가 「--force 로 우회하세요」였지만 `install --force` 는 파일 덮어쓰기라 의존 검사를 우회하지 않는다.
 *
 * 시나리오 매니페스트: tests/scenarios/template-dependency-version.yaml
 */
class InstallPreviewVersionBlockedTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 미리보기 의존 행을 만든다.
     *
     * @param  array<int, array<string, mixed>>  $enriched  DependencyEnricher::enrich 모양의 항목
     * @return array<int, array<string, mixed>> 미리보기 행
     */
    private function build(array $enriched): array
    {
        $builder = app(ExtensionInstallPreviewBuilder::class);

        return (new ReflectionMethod($builder, 'buildDependencies'))->invoke($builder, $enriched);
    }

    /**
     * 설치됐지만 판이 모자란 의존은 켜져 있든 꺼져 있든 고를 수 없고 이유를 싣는다. 미설치·꺼짐(판 충족)은 그대로 고를 수 있다.
     *
     * @scenario surface=preview_version_blocked
     *
     * @effects preview_blocks_version_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_installed_dependency_below_version_is_not_selectable(): void
    {
        $rows = $this->build([
            ['type' => 'module', 'identifier' => 'a', 'name' => 'A', 'required_version' => '>=1.3.0', 'installed_version' => '1.2.2', 'is_active' => true, 'is_met' => false, 'status' => 'version_mismatch'],
            ['type' => 'module', 'identifier' => 'b', 'name' => 'B', 'required_version' => '>=1.3.0', 'installed_version' => '1.2.2', 'is_active' => false, 'is_met' => false, 'status' => 'inactive'],
            ['type' => 'module', 'identifier' => 'c', 'name' => 'C', 'required_version' => '>=1.0.0', 'installed_version' => null, 'is_active' => false, 'is_met' => false, 'status' => 'not_installed'],
            ['type' => 'plugin', 'identifier' => 'd', 'name' => 'D', 'required_version' => '>=1.0.0', 'installed_version' => '1.1.0', 'is_active' => false, 'is_met' => false, 'status' => 'inactive'],
        ]);

        $this->assertSame([false, false, true, true], array_column($rows, 'available'));
        $this->assertSame(['version_mismatch', 'version_mismatch', null, null], array_column($rows, 'blocked_reason'));
        $this->assertFalse($rows[0]['default_selected']);
        $this->assertFalse($rows[1]['default_selected']);
        $this->assertSame('version_mismatch', $rows[0]['status']);
    }

    /**
     * 연쇄 설치 활성화 실패 사유가 사람이 읽는 문장이고 사유 원문을 내보내지 않는다.
     *
     * @scenario surface=cascade_failure_reason
     *
     * @effects cascade_reason_human_readable
     *
     * @return void 반환값 없음
     */
    public function test_cascade_activation_failure_reason_is_human_readable(): void
    {
        app()->setLocale('ko');
        $orchestrator = app(CascadeInstallOrchestrator::class);

        $reason = (new ReflectionMethod($orchestrator, 'activationFailureReason'))->invoke($orchestrator, [
            'missing_modules' => [['identifier' => 'sirsoft-board', 'name' => '게시판', 'status' => 'version_mismatch', 'required_version' => '>=1.3.0', 'installed_version' => '1.2.2']],
        ]);

        $this->assertStringContainsString('게시판(sirsoft-board) 버전 미달 1.2.2 → >=1.3.0', $reason);
        $this->assertStringNotContainsString('(version_mismatch)', $reason);
    }

    /**
     * 설치 거부 문구가 우회하지 못하는 --force 를 안내하지 않는다 (ko·en 원본 — ja 는 번들 언어팩 빌더가 원본을 따라 다시 번역한다).
     *
     * @scenario surface=install_unmet_message
     *
     * @effects install_message_no_force_bypass
     *
     * @return void 반환값 없음
     */
    public function test_install_rejection_message_does_not_suggest_force(): void
    {
        foreach (['ko', 'en'] as $locale) {
            foreach (['modules', 'plugins'] as $file) {
                $message = __($file.'.dependency_version_unmet', ['dependency' => 'x', 'required' => '>=1.0.0', 'installed' => '0.9.0'], $locale);

                $this->assertStringNotContainsString('--force', $message, "{$locale}/{$file}");
                $this->assertStringNotContainsString($file.'.dependency_version_unmet', $message, "{$locale}/{$file} 키 원문 노출");
            }
        }
    }
}
