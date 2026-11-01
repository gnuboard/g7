<?php

namespace Tests\Unit\Support\ExtensionDoc;

use App\Support\ExtensionDoc\DependencyGraphCollector;
use App\Support\ExtensionDoc\ExtensionInventory;
use Tests\TestCase;

/**
 * 확장 문서 생성기의 선택 연동 절 — 정방향(이 확장이 연동하는 확장)과 역방향(이 확장과 연동하는 확장) (#639 ⑥)
 *
 * 실제 번들 선언을 읽는다: 비즈뿌리오는 게시판·쇼핑몰 알림 설정 화면에 탭을 끼우고, 쇼핑몰은 게시판이 있으면 상품
 * 문의를 게시판으로 받는다. 게시판 문서에는 두 확장이 「이 확장과 연동하는 확장」으로 실려야, 게시판의 화면 이름을
 * 바꾸는 사람이 무엇이 끊기는지 안다.
 */
class DependencyGraphCollectorIntegrationsTest extends TestCase
{
    /**
     * 정방향·역방향 연동을 번들 전수에서 모은다.
     *
     * @scenario shape=doc_graph
     *
     * @effects extension_docs_list_integrations_both_ways
     *
     * @return void 반환값 없음
     */
    public function test_collects_integrations_in_both_directions(): void
    {
        $inventory = app(ExtensionInventory::class);
        $collector = new DependencyGraphCollector($inventory);

        $bizppurio = $collector->collect($inventory->find(ExtensionInventory::TYPE_PLUGIN, 'sirsoft-message_bizppurio'));
        // 목록형 선언은 버전 무관(`*`)으로 싣는다 (7.1.4 — 연동 표의 버전 열)
        $this->assertSame(
            [['type' => 'modules', 'id' => 'sirsoft-board', 'constraint' => '*', 'bundled' => true], ['type' => 'modules', 'id' => 'sirsoft-ecommerce', 'constraint' => '*', 'bundled' => true]],
            $bizppurio['integrates'],
        );
        $this->assertSame([], $bizppurio['requires'], '연동은 의존이 아니다');

        $board = $collector->collect($inventory->find(ExtensionInventory::TYPE_MODULE, 'sirsoft-board'));
        // 번들 manifest 가 바뀌어도(템플릿·다른 모듈이 게시판을 연동으로 선언) 깨지지 않게 알려진 두 확장이 실리는지만 본다
        $integratedBy = collect($board['integratedBy'])->keyBy(fn (array $row) => $row['type'].':'.$row['id']);
        $this->assertArrayHasKey('module:sirsoft-ecommerce', $integratedBy->all());
        $this->assertIsString($integratedBy['module:sirsoft-ecommerce']['constraint'], '역방향에도 선언한 쪽의 버전 제약을 싣는다');
        $this->assertArrayHasKey('plugin:sirsoft-message_bizppurio', $integratedBy->all());
        $this->assertNotContains('sirsoft-board', array_column($board['integrates'], 'id'), '자기 자신을 연동하지 않는다');
    }
}
