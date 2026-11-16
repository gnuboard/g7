<?php

namespace Tests\Unit\Extension\Integrity;

use App\Exceptions\PackageIntegrityException;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageVerification;
use App\Extension\Integrity\PackageVerificationStatus;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Http\Requests\LanguagePack\PerformLanguagePackUpdateRequest;
use App\Http\Requests\Module\PerformModuleUpdateRequest;
use App\Http\Requests\Plugin\PerformPluginUpdateRequest;
use App\Http\Requests\Template\PerformTemplateUpdateRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 옛 화면(7.1.x 관리자 템플릿 복사본) 호환 — 무결성 표식이 없는 요청의 미서명 허용 판정
 *
 * 새 관리자 화면은 설치·업데이트 요청에 표식 `integrity_aware=1` 을 늘 싣는다. 표식이 없는 요청은 「미서명으로 계속」을 그릴 수 없는
 * 옛 화면이므로 7.1 동작대로 미서명(검증 불가 포함) 패키지를 허용하고 경고 로그를 남긴다. 차단 스위치·서명 불일치·미신뢰 키는 그대로 막는다.
 * 판정은 `UnsignedPackagePolicy::decide()` 한 곳이고, 표식은 FormRequest 가 `PackageIntegrityOptions::legacyClient` 로 넘긴다.
 */
class LegacyScreenIntegrityCompatTest extends TestCase
{
    /**
     * 차단 스위치를 끕니다 (개발 설정 값과 무관하게).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, false);
    }

    /**
     * 정책 — 옛 화면은 미서명·검증 불가를 통과시키고 경고 로그를 남기며, 서명 불일치·미신뢰·차단 스위치는 막는다. 새 화면은 종전대로 409.
     *
     * @scenario case=install_legacy_screen_compat
     *
     * @effects legacy_screen_without_marker_installs_unsigned_but_keeps_hard_blocks
     *
     * @return void 반환값 없음
     */
    public function test_policy_allows_unsigned_only_for_legacy_screen(): void
    {
        $policy = app(UnsignedPackagePolicy::class);
        $legacy = new PackageIntegrityOptions(legacyClient: true);
        $aware = PackageIntegrityOptions::none();

        Log::spy();
        foreach ([PackageVerification::unsigned(), new PackageVerification(PackageVerificationStatus::Unverifiable, 'sodium_missing')] as $verification) {
            $this->assertSame($verification, $policy->decide($verification, $legacy));
        }
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, '옛 화면 호환'))->twice();

        $this->assertRejected(fn () => $policy->decide(PackageVerification::unsigned(), $aware), 409, canContinue: true);
        $this->assertRejected(fn () => $policy->decide(new PackageVerification(PackageVerificationStatus::Invalid, 'digest_mismatch'), $legacy), 422);
        $this->assertRejected(fn () => $policy->decide(new PackageVerification(PackageVerificationStatus::Untrusted, 'key_unknown'), $legacy), 422);

        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, true);
        $this->assertRejected(fn () => $policy->decide(PackageVerification::unsigned(), $legacy), 409, canContinue: false);
    }

    /**
     * 업데이트 요청(모듈·플러그인·템플릿·언어팩) — 표식이 없으면 옛 화면, `integrity_aware=1` 이면 새 화면이다.
     *
     * @scenario case=install_legacy_screen_compat
     *
     * @effects legacy_screen_without_marker_installs_unsigned_but_keeps_hard_blocks
     *
     * @return void 반환값 없음
     */
    public function test_update_requests_mark_legacy_screen_by_missing_marker(): void
    {
        foreach ([PerformModuleUpdateRequest::class, PerformPluginUpdateRequest::class, PerformTemplateUpdateRequest::class, PerformLanguagePackUpdateRequest::class] as $class) {
            $this->assertTrue($this->optionsFor($class, [])->legacyClient, "{$class}: 표식 없는 요청을 새 화면으로 봤다");
            $this->assertFalse($this->optionsFor($class, ['integrity_aware' => '1'])->legacyClient, "{$class}: 표식 있는 요청을 옛 화면으로 봤다");
            $this->assertArrayHasKey('integrity_aware', app($class)->rules(), "{$class}: 표식 검증 규칙이 없다");
        }
    }

    /**
     * 요청 본문으로 FormRequest 를 만들어 무결성 선택을 돌려줍니다.
     *
     * @param  class-string<FormRequest>  $class  요청 클래스
     * @param  array<string, mixed>  $input  본문
     * @return PackageIntegrityOptions 무결성 선택
     */
    private function optionsFor(string $class, array $input): PackageIntegrityOptions
    {
        /** @var FormRequest $request */
        $request = $class::create('/', 'POST', $input);

        return $request->packageIntegrityOptions();
    }

    /**
     * 판정이 거절(예외)인지와 그 상태 코드를 확인합니다.
     *
     * @param  \Closure  $decide  판정
     * @param  int  $status  기대 상태 코드
     * @param  bool|null  $canContinue  기대 계속 가능 여부 (null 이면 보지 않음)
     * @return void 반환값 없음
     */
    private function assertRejected(\Closure $decide, int $status, ?bool $canContinue = null): void
    {
        try {
            $decide();
            $this->fail("거절({$status})이어야 하는데 통과했다");
        } catch (PackageIntegrityException $e) {
            $this->assertSame($status, $e->getStatusCode());
            if ($canContinue !== null) {
                $this->assertSame($canContinue, $e->canContinue);
            }
        }
    }
}
