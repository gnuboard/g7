<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * [case:backend-34] overlay 확장이 주입한 노드의 `__source` 에 `injectionIndex` 가 실린다
 *
 * 편집기 확장 편집 모드는 호스트 병합 트리(`with_source_meta=1`)에서 현재 확장의 노드를
 * 추출해 `injections[].components` 로 되돌려 저장한다. 어느 injection 에서 왔는지가
 * 메타에 없으면 재조립이 모든 노드를 버려 저장본의 injections 가 통째로 비워진다
 * (실측: 이커머스 → `_user_base` 헤더 통화 선택기 18,405B → 148B). 백엔드가 주입 시점에
 * injection 순번을 메타로 싣는 것이 SSoT 다.
 */
class LayoutOverlayInjectionIndexMetaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    #[Test]
    public function overlay_injected_nodes_carry_injection_index_in_source_meta(): void
    {
        $template = Template::factory()->create([
            'identifier' => 'sirsoft-basic',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);

        $extension = LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => '_user_base',
            // 출처를 템플릿 자신으로 두어 모듈 활성 여부 게이트(isExtensionSourceActive)에 걸리지 않게 한다.
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'sirsoft-basic',
            'content' => [
                'target_layout' => '_user_base',
                'injections' => [
                    // 0 — inject_props (components 없음). 순번은 이 injection 도 센다.
                    ['target_id' => 'anchor_a', 'position' => 'inject_props', 'props' => ['className' => 'x']],
                    // 1 — 컴포넌트 주입
                    [
                        'target_id' => 'anchor_b',
                        'position' => 'append_child',
                        'components' => [
                            [
                                'type' => 'basic',
                                'name' => 'Span',
                                'id' => 'ext_b',
                                'children' => [
                                    ['type' => 'basic', 'name' => 'Span', 'id' => 'ext_b_child'],
                                ],
                            ],
                        ],
                    ],
                ],
                'priority' => 320,
            ],
            'priority' => 320,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => '_user_base',
            'meta' => ['title' => 'Base'],
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'id' => 'anchor_a'],
                ['type' => 'basic', 'name' => 'Div', 'id' => 'anchor_b'],
            ],
        ];

        // 편집 모드(with_source_meta) 병합 — PublicLayoutController::serve 가 같은 인자로 호출한다.
        $merged = $this->app->make(LayoutExtensionService::class)
            ->applyExtensions($layout, $template->id, true);

        $injected = $this->findNodeById($merged['components'], 'ext_b');
        $this->assertNotNull($injected, '주입 노드 ext_b 가 병합 결과에 있어야 한다');
        $this->assertSame('extension', $injected['__source']['kind'] ?? null);
        $this->assertSame($extension->id, $injected['__source']['extensionId'] ?? null);
        $this->assertSame(
            1,
            $injected['__source']['injectionIndex'] ?? null,
            '주입 노드 진입점의 __source 에 원래 injection 순번(1)이 실려야 편집기가 저장 시 되돌릴 수 있다'
        );

        // 자식도 같은 순번을 상속(진입점 판정은 자식으로 내려가지 않지만 메타는 일관).
        $child = $this->findNodeById($merged['components'], 'ext_b_child');
        $this->assertSame(1, $child['__source']['injectionIndex'] ?? null);

        // 운영 렌더(with_source_meta=false)에는 메타가 전혀 없다 — 비파괴.
        $plain = $this->app->make(LayoutExtensionService::class)
            ->applyExtensions($layout, $template->id, false);
        $plainInjected = $this->findNodeById($plain['components'], 'ext_b');
        $this->assertNotNull($plainInjected);
        $this->assertArrayNotHasKey('__source', $plainInjected);
    }

    /**
     * 트리에서 id 로 노드를 찾는다.
     *
     * @param  array<int, mixed>|null  $nodes  컴포넌트 배열
     * @param  string  $id  찾을 id
     * @return array<string, mixed>|null 노드
     */
    private function findNodeById(?array $nodes, string $id): ?array
    {
        foreach ($nodes ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) === $id) {
                return $node;
            }
            $found = $this->findNodeById($node['children'] ?? null, $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
