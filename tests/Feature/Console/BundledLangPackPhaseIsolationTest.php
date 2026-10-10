<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 번들 일괄 업데이트는 같은 프로세스에서 확장을 갱신했으면 언어팩 단계를 새 PHP 프로세스로 돌린다 (W3 리허설 실측).
 *
 * 일괄 업데이트 자식은 확장 갱신 **전에** 부팅해 각 모듈의 서비스 프로바이더(바인딩)를 옛 판으로 등록한다. 같은
 * 프로세스에서 이커머스를 1.3.0 으로 바꾼 뒤 일본어 팩을 갱신하면, 팩 활성화가 부르는 모듈 시더 재실행(`module:seed`)이
 * 새 판 클래스를 만들다 새 바인딩(`OrderExchangeRepositoryInterface`)이 없어 실패했다(68회, 경고만). 그래서 새 클레임
 * 사유 12종의 일본어 이름이 비었다. 새 프로세스는 갱신된 확장의 프로바이더로 부팅하므로 재시드가 성공한다.
 */
class BundledLangPackPhaseIsolationTest extends TestCase
{
    /**
     * 확장(모듈·플러그인·템플릿)을 같은 프로세스에서 갱신했고 언어팩이 있으면 새 프로세스 대상이다.
     *
     * @scenario phase=lang_packs, preceding=extensions_updated
     *
     * @effects lang_pack_phase_runs_in_fresh_process_after_extension_updates
     *
     * @return void 반환값 없음
     */
    public function test_lang_pack_phase_is_isolated_after_extension_updates(): void
    {
        $pack = [['identifier' => 'g7-module-acme-ja']];
        $module = [['identifier' => 'acme', 'strategy' => 'keep']];

        $this->assertTrue(ExecuteBundledUpdatesCommand::langPackPhaseNeedsFreshProcess($module, [], [], $pack));
        $this->assertTrue(ExecuteBundledUpdatesCommand::langPackPhaseNeedsFreshProcess([], $module, [], $pack));
        $this->assertTrue(ExecuteBundledUpdatesCommand::langPackPhaseNeedsFreshProcess([], [], $module, $pack));
    }

    /**
     * 언어팩만 있거나(새 프로세스의 언어팩 단계 자신 — 재귀 방지) 언어팩이 없으면 분리하지 않는다.
     *
     * @scenario phase=lang_packs, preceding=none
     *
     * @effects lang_pack_only_manifest_runs_in_process_without_recursion
     *
     * @return void 반환값 없음
     */
    public function test_lang_pack_only_or_empty_phase_is_not_isolated(): void
    {
        $pack = [['identifier' => 'g7-module-acme-ja']];
        $module = [['identifier' => 'acme', 'strategy' => 'keep']];

        $this->assertFalse(ExecuteBundledUpdatesCommand::langPackPhaseNeedsFreshProcess([], [], [], $pack));
        $this->assertFalse(ExecuteBundledUpdatesCommand::langPackPhaseNeedsFreshProcess($module, [], [], []));
    }

    /**
     * 처리 순서 계약 — 확장 갱신 반복이 끝난 뒤, 같은 프로세스 언어팩 반복보다 먼저 새 프로세스 위임을 시도하고, 새 프로세스를
     * 띄울 수 없을 때만 같은 프로세스로 돈다.
     *
     * @scenario phase=lang_packs, preceding=extensions_updated
     *
     * @effects lang_pack_phase_falls_back_in_process_when_spawn_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_handle_delegates_lang_pack_phase_before_in_process_loop(): void
    {
        $ref = new ReflectionMethod(ExecuteBundledUpdatesCommand::class, 'handle');
        $lines = file($ref->getFileName());
        $source = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        $templatesLoop = strpos($source, 'foreach ($templates as $entry)');
        $decision = strpos($source, 'self::langPackPhaseNeedsFreshProcess(');
        $spawn = strpos($source, '$this->runLangPackPhaseInFreshProcess(');
        $inProcessLoop = strpos($source, 'foreach ($langPacks as $entry)');

        $this->assertNotFalse($decision, '언어팩 단계 분리 판정이 없다');
        $this->assertNotFalse($spawn, '언어팩 단계를 새 프로세스로 돌리지 않는다');
        $this->assertTrue($templatesLoop < $decision && $decision < $spawn && $spawn < $inProcessLoop, '확장 갱신 → 분리 판정 → 새 프로세스 위임 → (위임 못 했을 때) 같은 프로세스 순서여야 한다');
    }
}
