<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Template;

use App\Models\NotificationDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 비즈뿌리오 알림 템플릿 라이프사이클 엔드포인트 테스트 (#597 §3.2).
 *
 * 목록/맵/상세/생성/수정/발송설정/검수신청/취소/동기화/삭제 전 경로의 권한 게이트·
 * FormRequest 매트릭스(유형별 조건부 필수)·상태 가드(422)·kapi 사유 표면화를 검증한다.
 */
class BizppurioTemplateControllerTest extends PluginTestCase
{
    private const BASE = '/api/plugins/sirsoft-message_bizppurio/admin/templates';

    private const VIEW = 'sirsoft-message_bizppurio.messaging.view';

    private const MANAGE = 'sirsoft-message_bizppurio.messaging.manage';

    /**
     * 지정 권한을 가진 admin 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissionIds  부여할 권한 식별자
     */
    private function adminWith(array $permissionIds): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $permIds = [];
        foreach ($permissionIds as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => json_encode(['ko' => $identifier, 'en' => $identifier]), 'type' => 'admin']
            );
            $permIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'bizppurio_tpl_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync($permIds);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 사용자 토큰 헤더를 만듭니다.
     */
    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * 알림 정의 1건을 만듭니다 (notification_type 존재 검증 대상).
     */
    private function definition(string $type = 'welcome'): NotificationDefinition
    {
        return NotificationDefinition::create([
            'type' => $type,
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
            'variables' => [['key' => 'name', 'description' => '이름']],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
        ]);
    }

    /**
     * kapi 자격증명·발신프로필 키를 플러그인 설정에 저장합니다 (검수 신청 경로용).
     */
    private function seedKakaoSettings(): void
    {
        app(PluginSettingsService::class)->save('sirsoft-message_bizppurio', [
            'bizppurio_id' => 'biz1',
            'api_key' => 'key1',
            'sender_key' => 'SK_TEST',
        ]);
    }

    /**
     * 유효한 BA×NONE content 를 만듭니다.
     *
     * @return array<string, mixed>
     */
    private function baseContent(): array
    {
        return [
            'templateName' => '환영 알림톡',
            'templateMessageType' => 'BA',
            'templateEmphasizeType' => 'NONE',
            'templateContent' => '#{name}님 가입을 환영합니다.',
            'categoryCode' => '001001',
        ];
    }

    public function test_권한_없는_관리자는_목록을_볼_수_없다(): void
    {
        $user = $this->adminWith([]);

        $this->getJson(self::BASE, $this->authHeaders($user))->assertStatus(403);
    }

    public function test_view_권한으로_목록을_조회하면_정의_라벨이_조인된다(): void
    {
        $this->definition();
        BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => BizppurioTemplateStatus::Draft->value,
        ]);
        $user = $this->adminWith([self::VIEW]);

        $response = $this->getJson(self::BASE, $this->authHeaders($user))->assertOk();

        $row = $response->json('data.templates.0');
        $this->assertSame('welcome', $row['notification_type']);
        $this->assertSame('회원가입 환영', $row['definition_name']['ko'] ?? null, '알림 정의 라벨이 조인돼야 한다.');
        $this->assertSame('core', $row['definition_extension_type']);
        $this->assertArrayHasKey('last_page', $response->json('data.pagination'), '페이지네이션 계약(last_page 노출).');
    }

    public function test_목록은_상태_필터와_검색을_지원한다(): void
    {
        $this->definition('welcome');
        $this->definition('order_confirmed');
        BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'approved']);
        BizppurioTemplate::create(['notification_type' => 'order_confirmed', 'status' => 'draft']);
        $user = $this->adminWith([self::VIEW]);

        $this->getJson(self::BASE.'?status=approved', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(1, 'data.templates')
            ->assertJsonPath('data.templates.0.notification_type', 'welcome');

        $this->getJson(self::BASE.'?search=order', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(1, 'data.templates')
            ->assertJsonPath('data.templates.0.notification_type', 'order_confirmed');
    }

    public function test_목록_검색은_정의_표시명_한글로도_매칭된다(): void
    {
        // 회귀 배경(§6.3 실측): nd.name 은 translatable JSON 컬럼이라 raw LIKE 는
        // 유니코드 이스케이프 저장값과 비교되어 비ASCII 검색이 항상 0건이었다.
        // 운영자가 화면에서 보는 표시명("회원가입 환영")으로 검색이 돼야 한다.
        $this->definition('welcome');
        $this->definition('order_confirmed')
            ->update(['name' => ['ko' => '주문 완료', 'en' => 'Order confirmed']]);
        BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft']);
        BizppurioTemplate::create(['notification_type' => 'order_confirmed', 'status' => 'draft']);
        $user = $this->adminWith([self::VIEW]);

        $this->getJson(self::BASE.'?search='.urlencode('회원'), $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(1, 'data.templates')
            ->assertJsonPath('data.templates.0.notification_type', 'welcome');
    }

    public function test_알_수_없는_상태_필터는_422다(): void
    {
        $user = $this->adminWith([self::VIEW]);

        $this->getJson(self::BASE.'?status=nope', $this->authHeaders($user))->assertStatus(422);
    }

    public function test_map은_notification_type_키_요약을_내려준다(): void
    {
        $this->definition();
        BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'approved',
            'content' => $this->baseContent(),
            'sms_body' => ['ko' => '[샵] 환영', 'en' => '[Shop] Welcome'],
            'fallback_sms_enabled' => true,
        ]);
        $user = $this->adminWith([self::VIEW]);

        $response = $this->getJson(self::BASE.'/map', $this->authHeaders($user))->assertOk();

        $entry = $response->json('data.templates.welcome');
        $this->assertNotNull($entry, 'notification_type 을 키로 하는 맵이어야 한다.');
        $this->assertSame('approved', $entry['status']);
        $this->assertTrue($entry['has_content'], 'content 존재 플래그(미작성/작성중 분기)가 실려야 한다.');
        // MySQL json 은 키를 (길이, 사전순)으로 정규화해 저장하므로 순서 비의존 단언을 쓴다
        // (§12.2 T1 과 같은 원인 — assertSame 은 키 순서까지 비교해 항상 실패한다).
        $this->assertEqualsCanonicalizing(
            ['ko' => '[샵] 환영', 'en' => '[Shop] Welcome'],
            $entry['sms_body'],
            'sms_body 는 로케일 맵 그대로 실려야 한다(§14.3).'
        );
    }

    public function test_생성은_manage_권한이_필요하다(): void
    {
        $this->definition();
        $user = $this->adminWith([self::VIEW]);

        $this->postJson(self::BASE, ['notification_type' => 'welcome'], $this->authHeaders($user))
            ->assertStatus(403);
    }

    public function test_생성하면_draft_상태다(): void
    {
        $this->definition();
        $user = $this->adminWith([self::MANAGE]);

        $this->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'content' => $this->baseContent(),
        ], $this->authHeaders($user))
            ->assertStatus(201)
            ->assertJsonPath('data.template.status', 'draft');

        $this->assertDatabaseHas('bizppurio_templates', ['notification_type' => 'welcome']);
    }

    public function test_존재하지_않는_알림_정의로는_생성할_수_없다(): void
    {
        $user = $this->adminWith([self::MANAGE]);

        $this->postJson(self::BASE, ['notification_type' => 'ghost'], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('notification_type');
    }

    /**
     * @effects notification_type_unique_one_row_per_definition
     */
    public function test_알림당_1행_unique가_강제된다(): void
    {
        $this->definition();
        BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft']);
        $user = $this->adminWith([self::MANAGE]);

        $this->postJson(self::BASE, ['notification_type' => 'welcome'], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('notification_type');
    }

    /**
     * FormRequest 유형 매트릭스 (§3.2 표 그대로).
     *
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function contentMatrixProvider(): array
    {
        $base = [
            'templateName' => '이름',
            'templateMessageType' => 'BA',
            'templateEmphasizeType' => 'NONE',
            'templateContent' => '본문',
            'categoryCode' => '001001',
        ];

        return [
            '공통_templateName_누락' => [array_diff_key($base, ['templateName' => 1]), 'content.templateName'],
            '공통_messageType_허용값_외' => [array_merge($base, ['templateMessageType' => 'ZZ']), 'content.templateMessageType'],
            '공통_본문_1000자_초과' => [array_merge($base, ['templateContent' => str_repeat('가', 1001)]), 'content.templateContent'],
            '공통_본문_1000자_경계는_통과' => [array_merge($base, ['templateContent' => str_repeat('가', 1000)]), null],
            'EX는_부가정보_필수' => [array_merge($base, ['templateMessageType' => 'EX']), 'content.templateExtra'],
            'MI는_부가정보_필수' => [array_merge($base, ['templateMessageType' => 'MI']), 'content.templateExtra'],
            'EX_부가정보_있으면_통과' => [array_merge($base, ['templateMessageType' => 'EX', 'templateExtra' => '부가']), null],
            'TEXT는_타이틀_필수' => [array_merge($base, ['templateEmphasizeType' => 'TEXT', 'templateSubtitle' => '보조']), 'content.templateTitle'],
            'TEXT는_서브타이틀_필수' => [array_merge($base, ['templateEmphasizeType' => 'TEXT', 'templateTitle' => '강조']), 'content.templateSubtitle'],
            'TEXT_쌍이_있으면_통과' => [array_merge($base, ['templateEmphasizeType' => 'TEXT', 'templateTitle' => '강조', 'templateSubtitle' => '보조']), null],
            'IMAGE는_이미지_url_필수' => [array_merge($base, ['templateEmphasizeType' => 'IMAGE', 'templateImageName' => 'img.png']), 'content.templateImageUrl'],
            'ITEM_LIST는_아이템_필수' => [array_merge($base, ['templateEmphasizeType' => 'ITEM_LIST']), 'content.templateItem'],
            'ITEM_LIST_아이템_1개는_min2_위반' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [['title' => '품목', 'description' => '설명']]],
            ]), 'content.templateItem.list'],
            'ITEM_LIST_아이템_제목_6자_초과' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [
                    ['title' => '일곱글자제목임', 'description' => '설명'],
                    ['title' => '품목', 'description' => '설명'],
                ]],
            ]), 'content.templateItem.list.0.title'],
            'ITEM_LIST_2개면_통과' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [
                    ['title' => '품목1', 'description' => '설명1'],
                    ['title' => '품목2', 'description' => '설명2'],
                ]],
            ]), null],
            '버튼_6개는_max5_위반' => [array_merge($base, [
                'buttons' => array_fill(0, 6, ['name' => '버튼', 'linkType' => 'WL', 'linkMo' => 'https://m.example.com']),
            ]), 'content.buttons'],
            'WL버튼은_linkMo_필수' => [array_merge($base, [
                'buttons' => [['name' => '버튼', 'linkType' => 'WL']],
            ]), 'content.buttons.0.linkMo'],
            'AL버튼은_양_스킴_필수' => [array_merge($base, [
                'buttons' => [['name' => '버튼', 'linkType' => 'AL', 'linkAnd' => 'app://x']],
            ]), 'content.buttons.0.linkIos'],
            'TN버튼은_전화번호_필수' => [array_merge($base, [
                'buttons' => [['name' => '전화', 'linkType' => 'TN']],
            ]), 'content.buttons.0.telNumber'],
            'P1버튼은_pluginId_필수' => [array_merge($base, [
                'buttons' => [['name' => '보안', 'linkType' => 'P1']],
            ]), 'content.buttons.0.pluginId'],
            '버튼명_14자_초과' => [array_merge($base, [
                'buttons' => [['name' => str_repeat('가', 15), 'linkType' => 'WL', 'linkMo' => 'https://m.example.com']],
            ]), 'content.buttons.0.name'],
            '바로연결은_TN_불가' => [array_merge($base, [
                'quickReplies' => [['name' => '전화', 'linkType' => 'TN', 'telNumber' => '021234']],
            ]), 'content.quickReplies.0.linkType'],
            '바로연결_WL은_통과' => [array_merge($base, [
                'quickReplies' => [['name' => '바로', 'linkType' => 'WL', 'linkMo' => 'https://m.example.com']],
            ]), null],
            '하이라이트_썸네일_있으면_타이틀_21자_제한' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [
                    ['title' => '품목1', 'description' => '설명1'],
                    ['title' => '품목2', 'description' => '설명2'],
                ]],
                'templateItemHighlight' => [
                    'title' => str_repeat('가', 22),
                    'imageUrl' => 'https://mud-kage.kakaocdn.net/img.png',
                ],
            ]), 'content.templateItemHighlight.title'],
            '하이라이트_썸네일_없으면_30자까지_허용' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [
                    ['title' => '품목1', 'description' => '설명1'],
                    ['title' => '품목2', 'description' => '설명2'],
                ]],
                'templateItemHighlight' => ['title' => str_repeat('가', 30)],
            ]), null],

            // --- 공통 필수·길이 ---
            '공통_emphasizeType_허용값_외' => [array_merge($base, ['templateEmphasizeType' => 'ZZ']), 'content.templateEmphasizeType'],
            '공통_templateContent_누락' => [array_diff_key($base, ['templateContent' => 1]), 'content.templateContent'],
            '공통_categoryCode_누락' => [array_diff_key($base, ['categoryCode' => 1]), 'content.categoryCode'],
            '공통_templateName_200자_초과' => [array_merge($base, ['templateName' => str_repeat('가', 201)]), 'content.templateName'],
            '공통_previewMessage_40자_초과' => [array_merge($base, ['templatePreviewMessage' => str_repeat('가', 41)]), 'content.templatePreviewMessage'],
            '공통_header_16자_초과' => [array_merge($base, ['templateHeader' => str_repeat('가', 17)]), 'content.templateHeader'],
            '공통_securityFlag_boolean_아님' => [array_merge($base, ['securityFlag' => 'yes']), 'content.securityFlag'],
            'AD는_추가_필수필드_없이_통과' => [array_merge($base, ['templateMessageType' => 'AD']), null],

            // --- IMAGE ---
            'IMAGE는_이미지명_필수' => [array_merge($base, [
                'templateEmphasizeType' => 'IMAGE',
                'templateImageUrl' => 'https://mud-kage.kakaocdn.net/img.png',
            ]), 'content.templateImageName'],

            // --- ITEM_LIST ---
            'ITEM_LIST_아이템_11개는_max10_위반' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => array_fill(0, 11, ['title' => '품목', 'description' => '설명'])],
            ]), 'content.templateItem.list'],
            'ITEM_LIST_아이템_설명_23자_초과' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [
                    ['title' => '품목1', 'description' => str_repeat('가', 24)],
                    ['title' => '품목2', 'description' => '설명2'],
                ]],
            ]), 'content.templateItem.list.0.description'],
            'ITEM_LIST_요약_제목_6자_초과' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => [
                    'list' => [
                        ['title' => '품목1', 'description' => '설명1'],
                        ['title' => '품목2', 'description' => '설명2'],
                    ],
                    'summary' => ['title' => str_repeat('가', 7), 'description' => '1,000원'],
                ],
            ]), 'content.templateItem.summary.title'],
            'ITEM_LIST_요약_설명_14자_초과' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => [
                    'list' => [
                        ['title' => '품목1', 'description' => '설명1'],
                        ['title' => '품목2', 'description' => '설명2'],
                    ],
                    'summary' => ['title' => '합계', 'description' => str_repeat('가', 15)],
                ],
            ]), 'content.templateItem.summary.description'],

            // --- 하이라이트 (설명 축·이미지 URL) ---
            '하이라이트_썸네일_있으면_설명_13자_제한' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [
                    ['title' => '품목1', 'description' => '설명1'],
                    ['title' => '품목2', 'description' => '설명2'],
                ]],
                'templateItemHighlight' => [
                    'description' => str_repeat('가', 14),
                    'imageUrl' => 'https://mud-kage.kakaocdn.net/img.png',
                ],
            ]), 'content.templateItemHighlight.description'],
            '하이라이트_썸네일_없으면_설명_19자까지_허용' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [
                    ['title' => '품목1', 'description' => '설명1'],
                    ['title' => '품목2', 'description' => '설명2'],
                ]],
                'templateItemHighlight' => ['description' => str_repeat('가', 19)],
            ]), null],
            '하이라이트_이미지url_500자_초과' => [array_merge($base, [
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateItem' => ['list' => [
                    ['title' => '품목1', 'description' => '설명1'],
                    ['title' => '품목2', 'description' => '설명2'],
                ]],
                'templateItemHighlight' => ['imageUrl' => 'https://x/'.str_repeat('a', 500)],
            ]), 'content.templateItemHighlight.imageUrl'],

            // --- 버튼 ---
            '버튼_linkType_허용값_외' => [array_merge($base, [
                'buttons' => [['name' => '버튼', 'linkType' => 'ZZ']],
            ]), 'content.buttons.0.linkType'],
            'AL버튼은_안드로이드_스킴도_필수' => [array_merge($base, [
                'buttons' => [['name' => '버튼', 'linkType' => 'AL', 'linkIos' => 'app://x']],
            ]), 'content.buttons.0.linkAnd'],
            'P2버튼은_pluginId_필수' => [array_merge($base, [
                'buttons' => [['name' => '개인정보', 'linkType' => 'P2']],
            ]), 'content.buttons.0.pluginId'],
            'P3버튼은_pluginId_필수' => [array_merge($base, [
                'buttons' => [['name' => '결제', 'linkType' => 'P3']],
            ]), 'content.buttons.0.pluginId'],
            '버튼_링크_500자_초과' => [array_merge($base, [
                'buttons' => [['name' => '버튼', 'linkType' => 'WL', 'linkMo' => 'https://m/'.str_repeat('a', 500)]],
            ]), 'content.buttons.0.linkMo'],

            // --- 바로연결 ---
            '바로연결_11개는_max10_위반' => [array_merge($base, [
                'quickReplies' => array_fill(0, 11, ['name' => '바로', 'linkType' => 'BK']),
            ]), 'content.quickReplies'],
            '바로연결_이름_누락' => [array_merge($base, [
                'quickReplies' => [['linkType' => 'BK']],
            ]), 'content.quickReplies.0.name'],
            '바로연결_BK_MD_BC_BT는_통과' => [array_merge($base, [
                'quickReplies' => [
                    ['name' => '봇키워드', 'linkType' => 'BK'],
                    ['name' => '메시지전달', 'linkType' => 'MD'],
                    ['name' => '상담톡', 'linkType' => 'BC'],
                    ['name' => '봇전환', 'linkType' => 'BT'],
                ],
            ]), null],

            // --- 대표링크 ---
            '대표링크_500자_초과' => [array_merge($base, [
                'templateRepresentLink' => ['linkMo' => 'https://m/'.str_repeat('a', 500)],
            ]), 'content.templateRepresentLink.linkMo'],
        ];
    }

    /**
     * FormRequest 유형 매트릭스 전수 실행 (§3.2 표 그대로 — EX/TEXT 변형 대표 조합 포함).
     *
     * @scenario content_variant=ex_text, transition=draft_to_requested
     *
     * @effects ex_mi_require_template_extra, text_requires_title_and_subtitle, image_requires_image_url, item_list_requires_two_to_ten_items, buttons_max_five_with_linktype_conditional_fields, quick_replies_restricted_linktypes, highlight_length_depends_on_thumbnail_presence
     *
     * @param  array<string, mixed>  $content  content 페이로드
     * @param  string|null  $errorField  기대 검증 오류 필드 (null=통과)
     */
    #[DataProvider('contentMatrixProvider')]
    public function test_content_유형_매트릭스(array $content, ?string $errorField): void
    {
        $this->definition();
        $user = $this->adminWith([self::MANAGE]);

        $response = $this->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'content' => $content,
        ], $this->authHeaders($user));

        if ($errorField === null) {
            $response->assertStatus(201);
        } else {
            $response->assertStatus(422)->assertJsonValidationErrors($errorField);
        }
    }

    public function test_수정_매트릭스도_store와_대칭이다(): void
    {
        $this->definition();
        $template = BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft']);
        $user = $this->adminWith([self::MANAGE]);

        // EX 인데 부가정보 누락 — Update 에서도 같은 조건부 필수가 걸려야 한다(대칭).
        $this->putJson(self::BASE.'/'.$template->id, [
            'content' => array_merge($this->baseContent(), ['templateMessageType' => 'EX']),
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('content.templateExtra');
    }

    /**
     * @effects server_prunes_irrelevant_type_fields_before_save
     */
    public function test_유형_전환_잔여_필드는_서버가_정돈해_저장한다(): void
    {
        $this->definition();
        $user = $this->adminWith([self::MANAGE]);

        // 화면이 폼 상태 전체를 보내는 상황 — NONE 인데 TEXT/IMAGE/ITEM_LIST 잔여값 동봉.
        $response = $this->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'content' => array_merge($this->baseContent(), [
                'templateTitle' => '잔여 타이틀',
                'templateImageUrl' => 'https://stale.example.com/x.png',
                'templateItem' => ['list' => [['title' => '잔여', 'description' => '잔여']]],
                'buttons' => [],
                'templateHeader' => '',
            ]),
        ], $this->authHeaders($user))->assertStatus(201);

        $content = $response->json('data.template.content');
        $this->assertArrayNotHasKey('templateTitle', $content, 'NONE 유형에서 TEXT 잔여 필드는 정돈돼야 한다.');
        $this->assertArrayNotHasKey('templateImageUrl', $content);
        $this->assertArrayNotHasKey('templateItem', $content);
        $this->assertArrayNotHasKey('buttons', $content, '빈 배열 버튼은 kapi 페이로드에서 제거돼야 한다.');
        $this->assertArrayNotHasKey('templateHeader', $content, '빈 문자열 선택 필드는 제거돼야 한다.');
    }

    public function test_requested_상태의_content_수정은_422다(): void
    {
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'requested',
            'content' => $this->baseContent(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $this->putJson(self::BASE.'/'.$template->id, [
            'content' => array_merge($this->baseContent(), ['templateContent' => '다른 본문']),
        ], $this->authHeaders($user))->assertStatus(422);
    }

    public function test_없는_행은_404다(): void
    {
        $user = $this->adminWith([self::VIEW, self::MANAGE]);

        $this->getJson(self::BASE.'/999999', $this->authHeaders($user))->assertStatus(404);
        $this->putJson(self::BASE.'/999999', [], $this->authHeaders($user))->assertStatus(404);
        $this->postJson(self::BASE.'/999999/request', [], $this->authHeaders($user))->assertStatus(404);
        $this->deleteJson(self::BASE.'/999999', [], $this->authHeaders($user))->assertStatus(404);
    }

    public function test_delivery_upsert는_행이_없으면_draft로_만든다(): void
    {
        $this->definition();
        $user = $this->adminWith([self::MANAGE]);

        $this->putJson(self::BASE.'/delivery/welcome', [
            'sms_only' => true,
            'sms_body' => ['ko' => '[샵] #{name} 님'],
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.template.status', 'draft')
            ->assertJsonPath('data.template.sms_only', true);
    }

    public function test_delivery_upsert는_없는_알림_정의를_거부한다(): void
    {
        $user = $this->adminWith([self::MANAGE]);

        $this->putJson(self::BASE.'/delivery/ghost', ['sms_only' => true], $this->authHeaders($user))
            ->assertStatus(422);
    }

    public function test_검수_신청_성공시_requested가_되고_kapi_시퀀스를_수행한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $this->seedKakaoSettings();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'draft',
            'content' => $this->baseContent(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $this->postJson(self::BASE.'/'.$template->id.'/request', [], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.template.status', 'requested');

        $this->assertNotNull($template->fresh()->template_code);
        Http::assertSentCount(3); // codeCheck → add → request
    }

    /**
     * 검수자 전달 의견(comment)은 선택 입력이며 kapi request 로만 전달된다 (#597 §18.7 — PO 결정 2026-08-23).
     *
     * 500자 초과는 FormRequest 가 kapi 호출 전에 422 로 끊고(행 상태 불변), 유효한 의견은
     * `template/request` 의 comment 로 원문 그대로 실린다.
     *
     * @effects inspection_request_carries_reviewer_comment
     */
    public function test_검수_신청의_comment는_kapi_request에_실리고_500자를_넘으면_422다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $this->seedKakaoSettings();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'draft',
            'content' => $this->baseContent(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $this->postJson(self::BASE.'/'.$template->id.'/request', ['comment' => str_repeat('가', 501)], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('comment');
        $this->assertSame(BizppurioTemplateStatus::Draft, $template->fresh()->status, '검증 실패는 kapi 호출 전에 끝나야 한다(선점 없음).');
        Http::assertNothingSent();

        $this->postJson(self::BASE.'/'.$template->id.'/request', ['comment' => '변수 예시: #{name}=홍길동'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.template.status', 'requested');

        Http::assertSent(function ($request) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/v3/kakao/template/request') {
                return false;
            }
            $this->assertSame('변수 예시: #{name}=홍길동', $request->data()['comment'] ?? null);

            return true;
        });
    }

    /**
     * @scenario content_variant=ad_image, transition=draft_to_requested
     *
     * @effects image_requires_image_url, first_request_calls_add_then_request_with_full_content_payload
     */
    public function test_이미지형_content가_검수_신청_페이로드에_그대로_실린다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $this->seedKakaoSettings();
        $this->definition();
        $user = $this->adminWith([self::MANAGE]);

        $content = array_merge($this->baseContent(), [
            'templateMessageType' => 'AD',
            'templateEmphasizeType' => 'IMAGE',
            'templateImageName' => 'promo.png',
            'templateImageUrl' => 'https://mud-kage.kakaocdn.net/promo.png',
        ]);

        $id = $this->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'content' => $content,
        ], $this->authHeaders($user))->assertStatus(201)->json('data.template.id');

        $this->postJson(self::BASE.'/'.$id.'/request', [], $this->authHeaders($user))->assertOk();

        Http::assertSent(function ($request) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/v3/kakao/template/add') {
                return false;
            }
            $body = $request->data();
            $this->assertSame('AD', $body['templateMessageType'] ?? null);
            $this->assertSame('IMAGE', $body['templateEmphasizeType'] ?? null);
            $this->assertSame('https://mud-kage.kakaocdn.net/promo.png', $body['templateImageUrl'] ?? null);

            return true;
        });
    }

    /**
     * @scenario content_variant=mi_item_list, transition=draft_to_requested
     *
     * @effects item_list_requires_two_to_ten_items, ex_mi_require_template_extra, first_request_calls_add_then_request_with_full_content_payload
     */
    public function test_복합_아이템리스트형_content가_검수_신청_페이로드에_그대로_실린다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $this->seedKakaoSettings();
        $this->definition();
        $user = $this->adminWith([self::MANAGE]);

        $content = array_merge($this->baseContent(), [
            'templateMessageType' => 'MI',
            'templateEmphasizeType' => 'ITEM_LIST',
            'templateExtra' => '부가정보',
            'templateItem' => [
                'list' => [
                    ['title' => '품목', 'description' => '연필'],
                    ['title' => '수량', 'description' => '2'],
                ],
                'summary' => ['title' => '합계', 'description' => '1,000원'],
            ],
        ]);

        $id = $this->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'content' => $content,
        ], $this->authHeaders($user))->assertStatus(201)->json('data.template.id');

        $this->postJson(self::BASE.'/'.$id.'/request', [], $this->authHeaders($user))->assertOk();

        Http::assertSent(function ($request) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/v3/kakao/template/add') {
                return false;
            }
            $body = $request->data();
            $this->assertSame('MI', $body['templateMessageType'] ?? null);
            $this->assertSame('부가정보', $body['templateExtra'] ?? null);
            $this->assertCount(2, $body['templateItem']['list'] ?? []);

            return true;
        });
    }

    /**
     * @effects kapi_failure_reason_surfaced_in_errors_bizppurio_message
     */
    public function test_검수_신청시_kapi_실패_사유가_errors로_표면화된다(): void
    {
        Http::fake([
            '*codeCheck*' => Http::response(['code' => '200', 'message' => 'ok']),
            'kapi.ppurio.com/*' => Http::response(['code' => '507', 'message' => '유효하지 않은 발신프로필']),
        ]);
        $this->seedKakaoSettings();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'draft',
            'content' => $this->baseContent(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $response = $this->postJson(self::BASE.'/'.$template->id.'/request', [], $this->authHeaders($user))
            ->assertStatus(422);

        $this->assertSame('유효하지 않은 발신프로필', $response->json('errors.bizppurio_message'), 'kapi 사유 원문이 관리자에게 표면화돼야 한다.');
        $this->assertSame('507', $response->json('errors.result_code'));
    }

    /**
     * @effects request_without_content_rejected_422
     */
    public function test_content_없는_행의_검수_신청은_422_상태_가드다(): void
    {
        Http::fake();
        $this->definition();
        $template = BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft']);
        $user = $this->adminWith([self::MANAGE]);

        $this->postJson(self::BASE.'/'.$template->id.'/request', [], $this->authHeaders($user))
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_검수중_행의_신청_취소는_draft로_복귀한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $this->seedKakaoSettings();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'requested',
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_TEST',
            'content' => $this->baseContent(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $this->postJson(self::BASE.'/'.$template->id.'/cancel-request', [], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.template.status', 'draft');
    }

    public function test_승인_취소는_스냅샷을_유지한채_draft로_복귀한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $this->seedKakaoSettings();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'approved',
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_TEST',
            'content' => $this->baseContent(),
            'approved_content' => $this->baseContent(),
            'approved_at' => now(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $response = $this->postJson(self::BASE.'/'.$template->id.'/cancel-approval', [], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.template.status', 'draft');

        $this->assertNotNull($response->json('data.template.approved_content'), '승인 스냅샷은 유지된다.');
    }

    public function test_sync는_카카오_상태를_반영한다(): void
    {
        Http::fake([
            '*template/detail*' => Http::response([
                'code' => '200', 'message' => 'ok',
                'data' => ['templateCode' => 'g7_deadbeef_1', 'serviceStatus' => 'ACT'],
            ]),
        ]);
        $this->seedKakaoSettings();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'requested',
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_TEST',
            'content' => $this->baseContent(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $this->postJson(self::BASE.'/'.$template->id.'/sync', [], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.template.status', 'approved')
            ->assertJsonPath('data.template.is_approved', true);
    }

    public function test_삭제는_카카오측_동반_삭제_여부를_응답에_명시한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $this->seedKakaoSettings();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'draft',
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_TEST',
            'content' => $this->baseContent(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $this->deleteJson(self::BASE.'/'.$template->id, [], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.kakao_deleted', true);

        $this->assertDatabaseCount('bizppurio_templates', 0);
    }

    public function test_삭제_불가_상태면_db만_삭제하고_사유를_명시한다(): void
    {
        Http::fake();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'approved',
            'template_code' => 'g7_deadbeef_1',
            'content' => $this->baseContent(),
        ]);
        $user = $this->adminWith([self::MANAGE]);

        $this->deleteJson(self::BASE.'/'.$template->id, [], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.kakao_deleted', false)
            ->assertJsonPath('data.kakao_skip_reason', 'state_not_deletable');

        $this->assertDatabaseCount('bizppurio_templates', 0);
        Http::assertNothingSent();
    }

    public function test_view_권한만으로는_변경_경로에_접근할_수_없다(): void
    {
        $this->definition();
        $template = BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft']);
        $user = $this->adminWith([self::VIEW]);
        $headers = $this->authHeaders($user);

        $this->postJson(self::BASE, ['notification_type' => 'welcome'], $headers)->assertStatus(403);
        $this->putJson(self::BASE.'/'.$template->id, [], $headers)->assertStatus(403);
        $this->postJson(self::BASE.'/'.$template->id.'/request', [], $headers)->assertStatus(403);
        $this->postJson(self::BASE.'/'.$template->id.'/cancel-request', [], $headers)->assertStatus(403);
        $this->postJson(self::BASE.'/'.$template->id.'/cancel-approval', [], $headers)->assertStatus(403);
        $this->postJson(self::BASE.'/'.$template->id.'/release', [], $headers)->assertStatus(403);
        $this->postJson(self::BASE.'/'.$template->id.'/sync', [], $headers)->assertStatus(403);
        $this->deleteJson(self::BASE.'/'.$template->id, [], $headers)->assertStatus(403);
        $this->putJson(self::BASE.'/delivery/welcome', [], $headers)->assertStatus(403);
        $this->postJson(self::BASE.'/image', [], $headers)->assertStatus(403);
    }

    public function test_상세는_content와_반려사유를_함께_반환한다(): void
    {
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'rejected',
            'template_code' => 'g7_abcd1234_1',
            'content' => ['templateName' => '환영', 'templateContent' => '본문'],
            'inspection_detail' => [['status' => 'REJ', 'content' => '변수 표기가 규격과 다릅니다.']],
        ]);

        $this->getJson(self::BASE.'/'.$template->id, $this->authHeaders($this->adminWith([self::VIEW])))
            ->assertStatus(200)
            ->assertJsonPath('data.template.id', $template->id)
            ->assertJsonPath('data.template.status', 'rejected')
            ->assertJsonPath('data.template.content.templateName', '환영')
            ->assertJsonPath('data.template.inspection_detail.0.content', '변수 표기가 규격과 다릅니다.');
    }

    public function test_검증_오류_문구는_영문_필드경로_대신_한글_라벨을_쓴다(): void
    {
        $this->definition();

        $response = $this->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'content' => [
                'templateName' => '이름',
                'templateMessageType' => 'BA',
                'templateEmphasizeType' => 'ITEM_LIST',
                'templateContent' => '본문',
                'categoryCode' => '001001',
                'templateItem' => ['list' => [['title' => '일곱글자제목임', 'description' => '설명']]],
            ],
        ], $this->authHeaders($this->adminWith([self::MANAGE])))->assertStatus(422);

        $messages = collect($response->json('errors'))->flatten()->implode(' ');

        $this->assertStringNotContainsString('content.templateItem', $messages, '운영자에게 내부 필드 경로를 노출하면 안 된다.');
        $this->assertStringContainsString('아이템', $messages, '한글 라벨(attributes)이 적용되어야 한다.');
    }

    public function test_없는_상세는_404다(): void
    {
        $this->getJson(self::BASE.'/999999', $this->authHeaders($this->adminWith([self::VIEW])))
            ->assertStatus(404);
    }

    public function test_휴면_해제는_kapi_release를_호출하고_상태를_되돌린다(): void
    {
        // 휴면 해제는 kapi release 후 detail 을 다시 읽어 카카오측 실제 상태를 반영한다 —
        // "해제했으니 승인" 이라고 우리가 단정하지 않는다.
        Http::fake([
            'kapi.ppurio.com/v3/kakao/template/release' => Http::response(['code' => '200', 'message' => 'ok']),
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'message' => 'ok',
                'data' => ['templateCode' => 'g7_abcd1234_1', 'serviceStatus' => 'ACT'],
            ]),
        ]);
        $this->definition();
        $this->seedKakaoSettings();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'dormant',
            'template_code' => 'g7_abcd1234_1',
            'content' => ['templateName' => '환영', 'templateContent' => '본문'],
        ]);

        $this->postJson(self::BASE.'/'.$template->id.'/release', [], $this->authHeaders($this->adminWith([self::MANAGE])))
            ->assertStatus(200);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/kakao/template/release')
            && $request['templateCode'] === 'g7_abcd1234_1');

        $this->assertSame('approved', $template->fresh()->status->value, '카카오가 ACT 로 응답하면 승인 상태로 반영한다.');
    }

    public function test_휴면이_아닌_행의_휴면_해제는_422다(): void
    {
        Http::fake();
        $this->definition();
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'draft',
            'template_code' => 'g7_abcd1234_1',
        ]);

        $this->postJson(self::BASE.'/'.$template->id.'/release', [], $this->authHeaders($this->adminWith([self::MANAGE])))
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * 지정 크기의 가짜 이미지 업로드 파일을 만듭니다.
     *
     * @param  int  $width  가로 픽셀
     * @param  int  $height  세로 픽셀
     * @param  string  $extension  확장자(jpg/png/gif)
     */
    private function fakeImage(int $width, int $height, string $extension = 'png'): UploadedFile
    {
        return UploadedFile::fake()->image('promo.'.$extension, $width, $height);
    }

    /**
     * @scenario content_variant=ad_image, transition=draft_to_requested
     *
     * @effects image_upload_proxies_to_kapi_and_returns_url
     */
    public function test_이미지_업로드는_kapi로_프록시되고_url을_반환한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'message' => 'ok',
                'image' => 'https://mud-kage.kakaocdn.net/promo.png',
            ]),
        ]);
        $this->seedKakaoSettings();
        $user = $this->adminWith([self::MANAGE]);

        $this->post(self::BASE.'/image', ['image' => $this->fakeImage(1000, 500)], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.url', 'https://mud-kage.kakaocdn.net/promo.png');

        // 부록 A-7: multipart + 최상위 image 파트, senderKey 는 전송하지 않는다.
        Http::assertSent(function ($request) {
            $this->assertTrue($request->isMultipart());
            $this->assertStringContainsString('/v3/kakao/image/alimtalk/template', $request->url());
            $this->assertTrue($request->hasFile('image'));

            $names = array_column($request->data(), 'name');
            $this->assertContains('bizId', $names);
            $this->assertContains('apiKey', $names);
            $this->assertNotContains('senderKey', $names);

            return true;
        });
    }

    /**
     * @scenario content_variant=ad_image, transition=draft_to_requested
     *
     * @effects image_upload_rejects_out_of_spec_file_before_kapi
     */
    #[DataProvider('invalidImageProvider')]
    public function test_규격을_벗어난_이미지는_kapi_호출_없이_422가_된다(string $case): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $this->seedKakaoSettings();
        $user = $this->adminWith([self::MANAGE]);

        $file = match ($case) {
            // 부록 A-7: jpg/png 만 허용
            'mime' => $this->fakeImage(1000, 500, 'gif'),
            // 가로 ≥ 500px
            'width' => $this->fakeImage(400, 200),
            // 가로:세로 = 2:1
            'ratio' => $this->fakeImage(1000, 1000),
            // ≤ 500KB
            'size' => $this->fakeImage(1000, 500)->size(600 * 1024),
        };

        $this->post(self::BASE.'/image', ['image' => $file], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        Http::assertNothingSent();
    }

    /**
     * 규격 위반 케이스 목록.
     *
     * @return array<string, array<int, string>>
     */
    public static function invalidImageProvider(): array
    {
        return [
            '허용되지 않는 확장자' => ['mime'],
            '가로 500px 미만' => ['width'],
            '2:1 비율 아님' => ['ratio'],
            '500KB 초과' => ['size'],
        ];
    }

    public function test_이미지_업로드는_manage_권한을_요구한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'message' => 'ok'])]);
        $user = $this->adminWith([self::VIEW]);

        $this->post(self::BASE.'/image', ['image' => $this->fakeImage(1000, 500)], $this->authHeaders($user))
            ->assertStatus(403);

        Http::assertNothingSent();
    }
}
