<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 공개 레이아웃 서빙 — 화면 조각(partial)의 개발자용 설명 제거.
 */
class LayoutServingPartialDescriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 레이아웃 서빙 경로가 GDPR 미들웨어를 거치므로 해당 플러그인 마이그레이션을 테스트 DB 에 포함시킨다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    /**
     * 화면 조각(partial)의 개발자용 설명(`meta.description`)도 공개 응답에서 제거되어야 합니다.
     *
     * 마크업 `<partial description="…">` 은 조각 루트 노드의 `meta: {is_partial: true, description}` 으로 컴파일된다.
     * 이 설명은 편집기와 개발자를 위한 글(예: 「본인인증 공통 모달 — 428 응답을 가로채는 … launcher」)이고 런타임
     * 렌더러는 읽지 않는데, `comment` 키만 지우던 탓에 7.1.0 실측(영역 I2)에서 사용자 404 화면 구성 데이터에
     * 그대로 실려 방문자에게 전달됐다. 레이아웃 최상단 `meta.description` 은 화면 설명(SEO)이므로 보존한다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects partial_developer_descriptions_never_reach_the_public_serving_response
     *
     * @return void 반환값 없음
     */
    public function test_strips_partial_developer_descriptions_from_public_serving(): void
    {
        $template = Template::create([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 사용자 템플릿', 'en' => 'Basic User Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 사용자 템플릿', 'en' => 'Basic User Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'partial_described',
            'content' => [
                'meta' => ['title' => '$t:error.404.title', 'description' => '$t:error.404.description'],
                'modals' => [
                    [
                        'meta' => [
                            'is_partial' => true,
                            'description' => '본인인증 공통 모달 — 428 응답을 가로채는 개발자용 설명',
                            'editor_label' => '$t:user.modal_label.identity_challenge_modal',
                        ],
                        'id' => 'identity-challenge-modal',
                        'type' => 'composite',
                        'name' => 'Modal',
                        'props' => ['title' => '본인 확인'],
                    ],
                ],
                'components' => [
                    [
                        'meta' => ['is_partial' => true, 'description' => '푸터 조각 개발자용 설명'],
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => [],
                        'children' => [],
                    ],
                ],
            ],
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringNotContainsString('개발자용 설명', $body);

        $data = $response->json('data');
        // 화면 설명(SEO)은 보존
        $this->assertSame('$t:error.404.description', $data['meta']['description']);
        // 조각 설명만 제거 — 조각 표식·편집기 이름·노드 구조는 그대로
        $this->assertArrayNotHasKey('description', $data['modals'][0]['meta']);
        $this->assertTrue($data['modals'][0]['meta']['is_partial']);
        $this->assertSame('identity-challenge-modal', $data['modals'][0]['id']);
        $this->assertArrayNotHasKey('description', $data['components'][0]['meta']);
        $this->assertSame('Div', $data['components'][0]['name']);
    }
}
