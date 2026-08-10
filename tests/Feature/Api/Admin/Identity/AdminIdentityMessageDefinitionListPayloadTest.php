<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 관리자 — IDV 메시지 정의 목록 페이로드 프루닝 (#518 / 공개 #76).
 *
 * 정의 목록 화면이 그리는 것은 대표 템플릿 1건의 제목/본문뿐인데, 종전에는 정의마다 모든 채널의
 * 템플릿 본문이 통째로 응답에 실렸다. 목록은 대표 1건만 싣고 전체는 단건 조회가 공급한다.
 *
 */
class AdminIdentityMessageDefinitionListPayloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }
        $this->admin = $this->admin->fresh();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 인증 헤더가 붙은 요청 빌더를 반환합니다.
     *
     * @return static
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 채널 여러 개를 가진 메시지 정의를 만듭니다.
     *
     * @param  string  $key  정의 식별 키 (scope_value)
     * @param  array<int, string>  $channels  만들 채널 목록 (첫 번째가 대표)
     * @return IdentityMessageDefinition 생성된 정의
     */
    private function makeDefinitionWithChannels(string $key, array $channels): IdentityMessageDefinition
    {
        $definition = IdentityMessageDefinition::create([
            'provider_id' => 'test-provider',
            'scope_type' => 'purpose',
            'scope_value' => $key,
            'name' => ['ko' => '정의 '.$key],
            'description' => ['ko' => '설명'],
            'channels' => $channels,
            'variables' => [],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
            'is_default' => true,
        ]);

        foreach ($channels as $channel) {
            IdentityMessageTemplate::create([
                'definition_id' => $definition->id,
                'channel' => $channel,
                'subject' => ['ko' => "제목-{$key}-{$channel}"],
                'body' => ['ko' => str_repeat("본문-{$channel} ", 50)],
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        return $definition;
    }

    /**
     * 목록은 정의마다 대표 템플릿 1건만 싣는다.
     *
     * @return void
     *
     * @scenario resource=identity_message_definition,endpoint=list,observation=response_payload
     * @effects identity_definition_list_carries_representative_template_only
     */
    public function test_definition_list_carries_only_the_representative_template(): void
    {
        $this->makeDefinitionWithChannels('first', ['mail', 'sms', 'push']);
        $this->makeDefinitionWithChannels('second', ['mail', 'sms']);

        $response = $this->authRequest()->getJson('/api/admin/identity/messages/definitions');
        $response->assertStatus(200);

        $rows = $response->json('data.data');
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('templates', $row, '화면 계약은 templates 배열이다 — 키 자체는 유지해야 한다');
            $this->assertCount(
                1,
                $row['templates'],
                '목록에 채널별 템플릿이 전부 실리면 한 페이지를 여는 것만으로 모든 채널 본문이 전송된다'
            );
        }
    }

    /**
     * 대표 1건만 싣는 대신, 템플릿이 더 있다는 사실은 집계로 알린다.
     *
     * @return void
     *
     * @scenario resource=identity_message_definition,endpoint=list,observation=response_payload
     * @effects identity_definition_list_reports_total_template_count
     */
    public function test_definition_list_reports_total_template_count(): void
    {
        $this->makeDefinitionWithChannels('first', ['mail', 'sms', 'push']);

        $row = $this->authRequest()
            ->getJson('/api/admin/identity/messages/definitions')
            ->json('data.data.0');

        $this->assertSame(3, $row['templates_count'], '배열 길이로는 알 수 없으므로 집계가 필요하다');
        $this->assertCount(1, $row['templates']);
    }

    /**
     * 단건 조회는 전체 템플릿을 그대로 공급한다 (대체 경로 보존 — 기능 축소가 아니다).
     *
     * @return void
     *
     * @scenario resource=identity_message_definition,endpoint=detail,observation=response_payload
     * @effects identity_definition_detail_returns_every_template, detail_still_returns_full_payload
     */
    public function test_definition_detail_still_returns_every_template(): void
    {
        $definition = $this->makeDefinitionWithChannels('first', ['mail', 'sms', 'push']);

        $row = $this->authRequest()
            ->getJson("/api/admin/identity/messages/definitions/{$definition->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(3, $row['templates']);
        $this->assertSame(
            ['mail', 'push', 'sms'],
            collect($row['templates'])->pluck('channel')->sort()->values()->all()
        );
    }

    /**
     * 목록의 대표 템플릿은 가장 먼저 등록된 것이다 (정의별 1건 보장).
     *
     * eager load 의 limit(1) 은 부모별이 아니라 배치 전체에 걸리므로, 잘못 구현하면 첫 정의만
     * 템플릿을 받고 나머지는 빈 배열이 된다.
     *
     * @return void
     *
     * @scenario resource=identity_message_definition,endpoint=list,observation=response_payload
     * @effects identity_definition_every_row_gets_its_own_representative
     */
    public function test_every_definition_gets_its_own_representative_template(): void
    {
        $this->makeDefinitionWithChannels('first', ['mail', 'sms']);
        $this->makeDefinitionWithChannels('second', ['sms', 'mail']);
        $this->makeDefinitionWithChannels('third', ['push', 'mail']);

        $rows = $this->authRequest()
            ->getJson('/api/admin/identity/messages/definitions')
            ->json('data.data');

        foreach ($rows as $row) {
            $this->assertCount(
                1,
                $row['templates'],
                '정의마다 대표 1건씩 — 배치 전체에 limit 이 걸리면 첫 행만 채워진다'
            );
        }

        // 대표는 정의별 "가장 먼저 등록된" 채널이어야 한다 (생성 순서 = 위 배열 순서)
        $byScope = collect($rows)->keyBy('scope_value');
        $this->assertSame('mail', $byScope['first']['templates'][0]['channel']);
        $this->assertSame('sms', $byScope['second']['templates'][0]['channel']);
        $this->assertSame('push', $byScope['third']['templates'][0]['channel']);
    }

    /**
     * 템플릿이 0건인 정의도 `templates` 는 빈 배열이다 (null 금지).
     *
     * 화면은 `(def.templates ?? []).find(...)` 로 읽으므로 null 이어도 즉시 깨지지는 않는다.
     * 그러나 응답 계약상 null 은 "관계를 로드하지 않았다" 를 뜻하고 빈 배열은 "로드했더니 0건"
     * 을 뜻한다. 두 상황이 같은 값으로 나오면 소비자가 둘을 구분할 수 없다.
     *
     * @return void
     *
     * @scenario resource=identity_message_definition,endpoint=list,templates=zero,observation=response_payload
     * @effects identity_definition_zero_template_serializes_as_empty_array
     */
    public function test_definition_without_any_template_serializes_templates_as_empty_array(): void
    {
        $this->makeDefinitionWithChannels('empty', []);

        $row = $this->authRequest()
            ->getJson('/api/admin/identity/messages/definitions')
            ->assertStatus(200)
            ->json('data.data.0');

        $this->assertArrayHasKey('templates', $row);
        $this->assertSame(
            [],
            $row['templates'],
            'null 은 "로드하지 않음" 을 뜻한다 — 0건은 빈 배열로 구분되어야 한다'
        );
        $this->assertSame(0, $row['templates_count']);
    }

    /**
     * 목록의 대표 템플릿과 단건 조회의 첫 템플릿은 같은 행이다.
     *
     * 목록은 `firstTemplate`(MIN(id)) 로 대표를 고정하는데 단건의 `templates` 에 정렬이 없으면
     * 첫 항목이 DB 반환 순서(채널 유니크 인덱스 순)를 탄다. 두 응답을 같은 `templates[0]` 계약으로
     * 읽는 소비자는 목록에서 본 것과 다른 템플릿을 열게 된다.
     *
     * @return void
     *
     * @scenario resource=identity_message_definition,endpoint=list+detail,observation=response_payload
     * @effects identity_definition_list_and_detail_agree_on_representative
     */
    public function test_list_representative_matches_detail_first_template(): void
    {
        // 등록 순서(id)와 채널 사전순이 어긋나도록 만든다 — 정렬이 없으면 두 응답이 갈린다
        $definition = $this->makeDefinitionWithChannels('order', ['sms', 'kakao']);

        $listRow = collect(
            $this->authRequest()
                ->getJson('/api/admin/identity/messages/definitions')
                ->json('data.data')
        )->firstWhere('id', $definition->id);

        $detail = $this->authRequest()
            ->getJson("/api/admin/identity/messages/definitions/{$definition->id}")
            ->assertStatus(200)
            ->json('data');

        $detailIds = collect($detail['templates'])->pluck('id')->all();
        $this->assertSame(
            collect($detailIds)->sort()->values()->all(),
            $detailIds,
            '단건의 templates 는 등록 순서(id)로 정렬되어야 한다'
        );

        $this->assertSame(
            $listRow['templates'][0]['id'],
            $detail['templates'][0]['id'],
            '목록에서 본 대표 템플릿과 단건의 첫 템플릿이 다르면 같은 계약을 읽는 소비자가 갈린다'
        );
    }

    /**
     * 템플릿 조회 쿼리 수가 정의 수에 비례하지 않는다 (N+1 부재).
     *
     * @return void
     *
     * @scenario resource=identity_message_definition,endpoint=list,observation=response_payload
     * @effects query_count_does_not_scale_with_row_count
     */
    public function test_template_query_count_does_not_grow_with_definition_count(): void
    {
        $this->makeDefinitionWithChannels('a', ['mail', 'sms']);

        // 권한/설정 캐시를 채워 측정에서 제외한다
        $this->authRequest()->getJson('/api/admin/identity/messages/definitions');

        $countTemplateQueries = function (): int {
            $count = 0;
            DB::listen(function ($query) use (&$count) {
                if (str_contains($query->sql, 'identity_message_templates')) {
                    $count++;
                }
            });

            $this->authRequest()->getJson('/api/admin/identity/messages/definitions');

            return $count;
        };

        $withOne = $countTemplateQueries();

        foreach (['b', 'c', 'd', 'e', 'f'] as $key) {
            $this->makeDefinitionWithChannels($key, ['mail', 'sms']);
        }

        $withSix = $countTemplateQueries();

        $this->assertSame(
            $withOne,
            $withSix,
            "정의 1건일 때 {$withOne}회, 6건일 때 {$withSix}회 — 정의 수에 비례하면 N+1 이다"
        );
    }
}
