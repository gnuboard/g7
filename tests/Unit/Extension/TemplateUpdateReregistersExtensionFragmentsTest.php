<?php

namespace Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 활성 템플릿 업데이트는 자기 레이아웃을 갱신한 뒤 활성 확장 조각 전체를 다시 등록한다 (7.1.0 출시 점검 PU-1).
 *
 * 번들 일괄 업데이트는 모듈 → 플러그인 → 템플릿 순서다. 플러그인 갱신이 조각을 다시 등록하는 시점에는 템플릿이 아직
 * 옛 판이라 새 확장점(예: 결제 플러그인의 `mypage_order_payment_provider_slot`)이 없어 조각이 건너뛰어지고(C10),
 * 템플릿 갱신은 자기 레이아웃만 갱신했다. 그래서 일괄 업데이트를 마친 사이트에서 주문 상세의 가상계좌 입금 안내·
 * 영수증·에스크로 표시가 다른 확장을 켜거나 갱신하기 전까지 사라졌다.
 *
 * 늦게 생긴 자리를 재등록이 메우는 동작은 FragmentRegistrationPolicyTest
 * (`test_late_host_is_filled_on_reregistration_and_orphan_rows_are_removed`)가 잠그고, 본 시험은 업데이트 경로가
 * 그 재등록을 자기 레이아웃 갱신 **뒤에** 부른다는 호출 계약을 소스 구조로 고정한다
 * (선례: TemplateUpdateConfigCacheInvalidationTest — updateTemplate 통합 실행은 업데이트 소스 픽스처 비용이 크다).
 */
class TemplateUpdateReregistersExtensionFragmentsTest extends TestCase
{
    // 활성 분기 안에서만 부르는지(비활성 업데이트는 재등록하지 않음)도 함께 잠근다.
    // @scenario lifecycle_trigger=template_update,template_status=inactive

    /**
     * 활성 경로에서 refreshTemplateLayouts() 다음에 registerAllActiveExtensionsToTemplate() 를 부른다.
     *
     * @scenario lifecycle_trigger=template_update,template_status=active
     *
     * @effects template_update_reregisters_active_extension_fragments
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function active_template_update_reregisters_extension_fragments_after_layout_refresh(): void
    {
        $body = $this->methodSource(base_path('app/Extension/TemplateManager.php'), 'updateTemplate');

        $refreshAt = strpos($body, '$this->refreshTemplateLayouts($identifier');
        $registerAt = strpos($body, '->registerAllActiveExtensionsToTemplate(');

        $this->assertNotFalse($refreshAt, 'updateTemplate() 의 활성 경로 레이아웃 갱신을 찾지 못했습니다.');
        $this->assertNotFalse(
            $registerAt,
            'updateTemplate() 가 활성 확장 조각을 다시 등록하지 않습니다 — 일괄 업데이트 뒤 새 확장점에 붙는 조각(가상계좌 입금 안내 등)이 사라집니다.'
        );
        $this->assertGreaterThan($refreshAt, $registerAt, '조각 재등록은 템플릿 자기 레이아웃 갱신 뒤여야 새 확장점에 붙습니다.');

        // 꺼 둔 채 업데이트(stash) 분기보다 앞 — 활성 경로 안에서만 부른다(비활성 템플릿은 켤 때 activateTemplate 이 등록).
        $inactiveBranchAt = strpos($body, 'stashBaseForInactiveUpdate(');
        $this->assertNotFalse($inactiveBranchAt);
        $this->assertLessThan($inactiveBranchAt, $registerAt, '조각 재등록은 활성 경로(if 분기) 안에 있어야 합니다.');
    }

    /**
     * 조각 재등록 실패는 업데이트를 되돌리지 않는다 — 파일·버전은 이미 새 판이고, 재등록은 다른 확장 활성화·갱신으로도 다시 돈다.
     *
     * @scenario lifecycle_trigger=template_update,template_status=active
     *
     * @effects template_update_fragment_reregistration_failure_is_logged_not_rolled_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function fragment_reregistration_failure_does_not_roll_back_update(): void
    {
        $body = $this->methodSource(base_path('app/Extension/TemplateManager.php'), 'updateTemplate');

        $registerAt = strpos($body, '->registerAllActiveExtensionsToTemplate(');
        $this->assertNotFalse($registerAt);

        $tryAt = strrpos(substr($body, 0, $registerAt), 'try {');
        $catchAt = strpos($body, 'catch (\Throwable', $registerAt);
        $this->assertNotFalse($tryAt);
        $this->assertNotFalse($catchAt);

        $catchBody = substr($body, $catchAt, 400);
        $this->assertStringContainsString('Log::', $catchBody, '조각 재등록 실패는 기록하고 삼켜야 합니다.');
        $this->assertStringNotContainsString('throw', $catchBody, '조각 재등록 실패가 업데이트 전체를 되돌리면 안 됩니다.');
    }

    /**
     * 대상 메서드 본문을 주석 제거 후 반환합니다.
     *
     * @param  string  $path  대상 파일 절대 경로
     * @param  string  $method  대상 메서드명
     * @return string 주석이 제거된 메서드 본문
     */
    private function methodSource(string $path, string $method): string
    {
        $this->assertFileExists($path);

        $stripped = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }

        $start = strpos($stripped, "public function {$method}(");
        $this->assertNotFalse($start, "{$path} 에서 {$method}() 메서드를 찾지 못했습니다.");

        $open = strpos($stripped, '{', $start);
        $depth = 0;
        $length = strlen($stripped);

        for ($i = $open; $i < $length; $i++) {
            if ($stripped[$i] === '{') {
                $depth++;
            } elseif ($stripped[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($stripped, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("{$path} 의 {$method}() 본문 끝을 찾지 못했습니다.");
    }
}
