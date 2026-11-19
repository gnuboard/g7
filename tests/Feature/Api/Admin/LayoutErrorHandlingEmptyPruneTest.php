<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use stdClass;
use Tests\TestCase;

/**
 * 레이아웃 저장과 버전 복원의 빈 오류 처리 항목 정리 테스트 (#869)
 *
 * 편집기 [덮기]로 시작한 빈 동작(`{}`)을 고르지 않고 저장하면 런타임 findHandler 가 그 코드를 「처리됨」으로 보아
 * 같은 맵 default, 데이터 소스 onError, 템플릿 기본 처리를 가린다. 편집기 저장 직렬화가 빈 항목을 지우도록
 * 고쳤지만(설계 확정 2026-11-09), 버전 복원은 버전 행 원문을 그대로 본 행에 쓰므로 고치기 전에 저장한 버전을
 * 복원하면 빈 항목이 되살아난다. 서버 저장은 클라이언트 마스킹과 같은 정책을 최종 방어선으로 다시 적용한다
 * (UpdateLayoutContentRequest::stripInheritedFromLayoutContent). 두 경로 모두 같은 정리를 거치는지 잰다.
 * PHP 는 errorHandling 항목의 `{}` 를 `[]` 로 읽는데, 화면에서는 빈 배열도 참이라 똑같이 가린다.
 *
 * @see tests/scenarios/layout-error-handling-empty-prune.yaml
 */
class LayoutErrorHandlingEmptyPruneTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 레이아웃 서빙 경로가 GDPR 미들웨어를 거치므로 그 마이그레이션을 포함한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    private string $token;

    /**
     * 편집 권한 관리자 토큰과 활성 사용자 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit'])
            ->createToken('editor')->plainTextToken;

        $this->template = Template::factory()->create([
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 빈 동작이 저장된 옛 버전을 복원하면 본 행과 새 버전 행에서 그 코드가 빠지고, 동작이 있는 코드와 다른 빈 객체는 남는다.
     *
     * @scenario path=restore
     *
     * @effects restore_prunes_empty_error_handling_entries
     *
     * @return void 반환값 없음
     */
    public function test_version_restore_prunes_empty_error_handling_entries(): void
    {
        $layout = $this->makeLayout('probe_page', $this->layoutContent('현재'));
        $version = TemplateLayoutVersion::create([
            'layout_id' => $layout->id,
            'version' => 1,
            'content' => $this->contentWithEmptyErrorEntries('옛 버전'),
            'changes_summary' => ['added' => 0, 'removed' => 0, 'char_diff' => 0],
        ]);

        $this->authRequest()
            ->postJson($this->layoutUrl('probe_page')."/versions/{$version->id}/restore")
            ->assertOk();

        $this->assertPruned($layout->fresh(), '복원 본 행');
        $restored = TemplateLayoutVersion::where('layout_id', $layout->id)->where('version', 2)->firstOrFail();
        $this->assertPruned($restored, '복원 버전 행');
    }

    /**
     * 편집기를 거치지 않은 저장 요청(클라이언트 정리 누락)도 서버가 같은 정리를 적용한다.
     *
     * @scenario path=save
     *
     * @effects save_request_prunes_empty_error_handling_entries
     *
     * @return void 반환값 없음
     */
    public function test_save_request_prunes_empty_error_handling_entries(): void
    {
        $layout = $this->makeLayout('probe_page', $this->layoutContent('원본'));

        $this->authRequest()->putJson($this->layoutUrl('probe_page'), [
            'expected_lock_version' => 0,
            'content' => json_encode($this->contentWithEmptyErrorEntries('저장본')),
        ])->assertOk();

        $this->assertPruned($layout->fresh(), '저장 본 행');
    }

    /**
     * 정리 결과를 DB 원문으로 검증합니다 — 빈 코드는 빠지고, 동작이 있는 코드와 오류 처리 밖의 빈 객체는 남는다.
     *
     * @param  mixed  $model  레이아웃 또는 버전 모델
     * @param  string  $label  실패 메시지 머리
     * @return void 반환값 없음
     */
    private function assertPruned($model, string $label): void
    {
        $raw = (string) $model->getRawOriginal('content');
        $content = json_decode($raw, true);

        $this->assertSame(['default' => ['handler' => 'toast']], $content['errorHandling'] ?? null, "{$label}: 최상위 errorHandling — {$raw}");
        $this->assertSame(['500' => ['handler' => 'navigate']], $content['data_sources'][0]['errorHandling'] ?? null, "{$label}: 데이터 소스 errorHandling");
        $this->assertArrayNotHasKey('errorHandling', $content['data_sources'][1], "{$label}: 코드가 모두 빈 데이터 소스는 errorHandling 키를 뺀다");
        $this->assertSame(['handler' => 'toast'], $content['data_sources'][1]['onError'] ?? null, "{$label}: onError 는 그대로");
        $this->assertStringContainsString('"filters":{}', $raw, "{$label}: 오류 처리 밖의 빈 객체는 지키지 않으면 안 된다");
    }

    /**
     * 빈 동작 항목을 담은 레이아웃 본문을 만듭니다(최상위와 데이터 소스, `{}` 와 `[]` 둘 다).
     *
     * @param  string  $text  본문 텍스트
     * @return array<string, mixed> 레이아웃 본문
     */
    private function contentWithEmptyErrorEntries(string $text): array
    {
        $content = $this->layoutContent($text);
        $content['errorHandling'] = ['401' => new stdClass, '403' => [], 'default' => ['handler' => 'toast']];
        $content['data_sources'] = [
            ['id' => 'products', 'type' => 'api', 'endpoint' => '/api/products', 'errorHandling' => ['401' => new stdClass, '500' => ['handler' => 'navigate']]],
            ['id' => 'orders', 'type' => 'api', 'endpoint' => '/api/orders', 'errorHandling' => ['401' => new stdClass], 'onError' => ['handler' => 'toast']],
        ];

        return $content;
    }

    /**
     * 오류 처리 밖에 빈 객체(initLocal.filters)를 둔 기본 레이아웃 본문을 만듭니다.
     *
     * @param  string  $text  본문 텍스트(저장마다 바꿔 변경을 만든다)
     * @return array<string, mixed> 레이아웃 본문
     */
    private function layoutContent(string $text): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => 'probe_page',
            'initLocal' => ['filters' => new stdClass],
            'components' => [[
                'id' => 'root',
                'type' => 'basic',
                'name' => 'Div',
                'text' => $text,
            ]],
        ];
    }

    /**
     * 템플릿 출처 레이아웃을 만듭니다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  array<string, mixed>  $content  레이아웃 본문
     * @return TemplateLayout 생성된 레이아웃
     */
    private function makeLayout(string $name, array $content): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => $name,
            'source_type' => LayoutSourceType::Template->value,
            'source_identifier' => $this->template->identifier,
            'lock_version' => 0,
            'content' => $content,
        ]);
    }

    /**
     * 레이아웃 편집 API 주소를 만듭니다.
     *
     * @param  string  $name  레이아웃 이름
     * @return string API 주소
     */
    private function layoutUrl(string $name): string
    {
        return "/api/admin/templates/{$this->template->identifier}/layouts/{$name}";
    }

    /**
     * 관리자 토큰을 실은 JSON 요청 헤더를 설정합니다.
     *
     * @return $this 헤더가 설정된 테스트 케이스
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 지정 권한을 붙인 관리자 역할을 가진 회원을 생성합니다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 관리자 회원
     */
    private function createAdminUser(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                ]
            )->id;
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'is_system' => true,
                'priority' => 0,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching($permissionIds);
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        return $user;
    }
}
