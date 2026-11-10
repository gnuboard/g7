<?php

namespace Tests\Feature\Attachment;

use App\Enums\AttachmentSourceType;
use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Attachment;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 코어 첨부 다운로드의 판정 필터와 비활성 확장 규칙 (#887)
 *
 * 코어 공개 다운로드(`GET /api/attachment/{hash}` → `AttachmentService::getFileInfo`·`download`)는 확장을 모른다. 행을 만든 확장이
 * 필터 `core.attachment.filter_can_download` 로 원 리소스 열람 권한을 답한다 — `null`(의견 없음)=기존 흐름, `false`=403,
 * `true`=허용(기존 기능 권한·`before_download_action`·`before_download` 는 그대로 탄다). 코어 일반 규칙: 행을 만든 확장
 * (`source_type` module·plugin)이 비활성·미설치면 거부한다. 리스너가 없는 회원 아바타·사이트 로고·기본 OG 이미지·게시판 반응 아이콘
 * (게시판 켜짐)은 종전처럼 서빙된다. 코어 첨부 표에는 임시 업로드 키(`temp_key`)가 없다.
 *
 * @scenario-manifest tests/scenarios/attachment-download-gate.yaml
 */
class AttachmentDownloadGateTest extends TestCase
{
    use RefreshDatabase;

    /** 1x1 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * 가짜 첨부 디스크를 준비하고 판정 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        HookManager::clearFilter(AttachmentService::CAN_DOWNLOAD_FILTER);
    }

    /**
     * 시험이 더한 필터·액션을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(AttachmentService::CAN_DOWNLOAD_FILTER);

        parent::tearDown();
    }

    /**
     * 리스너가 없으면 종전처럼 서빙한다 — 회원 아바타·사이트 로고·기본 OG 이미지(코어 행), 게시판 반응 아이콘(켜진 게시판 모듈 행).
     *
     * @scenario case=no_listener
     *
     * @effects core_rows_served_without_listener, active_extension_row_served
     *
     * @return void 반환값 없음
     */
    public function test_rows_without_listener_are_served_as_before(): void
    {
        $this->recordModule('sirsoft-board', ExtensionStatus::Active);
        $user = User::factory()->create();

        $rows = [
            'avatar' => $this->storedAttachment(['collection' => 'avatar', 'attachmentable_type' => User::class, 'attachmentable_id' => $user->id]),
            'site_logo' => $this->storedAttachment(['collection' => 'site_logo']),
            'og_image_default' => $this->storedAttachment(['collection' => 'og_image_default']),
            'reaction_type_icon' => $this->storedAttachment([
                'collection' => 'reaction_type_icon',
                'source_type' => AttachmentSourceType::Module,
                'source_identifier' => 'sirsoft-board',
            ]),
        ];

        foreach ($rows as $label => $attachment) {
            $response = $this->get('/api/attachment/'.$attachment->hash);
            $response->assertStatus(200);
            $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'), $label.' 은 인라인 이미지로 서빙');
        }
    }

    /**
     * 리스너가 `false` 를 돌려주면 403 — 확장은 자기 행에만 답하고, 남의 행(`null`)은 그대로 서빙된다. 리스너는 행과 요청한 사람을 받는다.
     *
     * @scenario case=listener_deny
     *
     * @effects listener_false_forbidden, listener_scoped_to_own_rows
     *
     * @return void 반환값 없음
     */
    public function test_listener_false_forbids_only_its_rows(): void
    {
        $this->recordModule('vendor-module', ExtensionStatus::Active);
        $own = $this->storedAttachment(['source_type' => AttachmentSourceType::Module, 'source_identifier' => 'vendor-module', 'collection' => 'documents']);
        $other = $this->storedAttachment(['collection' => 'site_logo']);
        $seen = [];

        HookManager::addFilter(AttachmentService::CAN_DOWNLOAD_FILTER, function ($verdict, Attachment $attachment, $user) use (&$seen) {
            $seen[] = [$attachment->id, $user?->id];

            return $attachment->source_identifier === 'vendor-module' ? false : $verdict;
        });

        $this->get('/api/attachment/'.$own->hash)->assertStatus(403);
        $this->get('/api/attachment/'.$other->hash)->assertStatus(200);

        $viewer = User::factory()->create();
        $this->expectException(AuthorizationException::class);

        try {
            app(AttachmentService::class)->getFileInfo($own->hash, $viewer);
        } finally {
            $this->assertContains([$own->id, $viewer->id], $seen, '리스너는 행과 요청한 사람을 받는다');
        }
    }

    /**
     * 리스너가 `true` 를 돌려줘도 기존 흐름(본인인증 가드 `before_download_action` 등)은 그대로 탄다 — 허용이면 200.
     *
     * @scenario case=listener_allow
     *
     * @effects listener_true_allowed_existing_flow_kept
     *
     * @return void 반환값 없음
     */
    public function test_listener_true_allows_and_keeps_existing_flow(): void
    {
        $this->recordModule('vendor-module', ExtensionStatus::Active);
        $attachment = $this->storedAttachment(
            ['source_type' => AttachmentSourceType::Module, 'source_identifier' => 'vendor-module', 'collection' => 'documents'],
            'doc.pdf',
            'application/pdf',
        );
        $guarded = 0;

        HookManager::addFilter(AttachmentService::CAN_DOWNLOAD_FILTER, fn ($verdict, Attachment $row) => $row->source_identifier === 'vendor-module' ? true : $verdict);
        $guard = function () use (&$guarded) {
            $guarded++;
        };
        HookManager::addAction('core.attachment.before_download_action', $guard);

        try {
            $this->get('/api/attachment/'.$attachment->hash)->assertStatus(200);
            $this->assertSame(1, $guarded, '허용이어도 다운로드 경로의 기존 액션 훅을 탄다');
        } finally {
            // 코어가 부팅 때 건 같은 훅의 리스너(본인인증 가드)는 남기고 이 시험의 것만 뗀다
            HookManager::removeAction('core.attachment.before_download_action', $guard);
        }
    }

    /**
     * 행을 만든 확장이 꺼져 있거나 설치되지 않았으면 403 — 모듈·플러그인 모두. 리스너가 `true` 를 돌려줘도 뒤집지 못한다.
     * 같은 확장을 켜면 200.
     *
     * @scenario case=inactive_extension
     *
     * @effects inactive_extension_row_forbidden, active_extension_row_served
     *
     * @return void 반환값 없음
     */
    public function test_rows_of_inactive_or_missing_extension_are_forbidden(): void
    {
        $this->recordModule('vendor-module', ExtensionStatus::Inactive);
        $moduleRow = $this->storedAttachment(['source_type' => AttachmentSourceType::Module, 'source_identifier' => 'vendor-module', 'collection' => 'reaction_type_icon']);
        $pluginRow = $this->storedAttachment(['source_type' => AttachmentSourceType::Plugin, 'source_identifier' => 'vendor-plugin', 'collection' => 'images']);

        HookManager::addFilter(AttachmentService::CAN_DOWNLOAD_FILTER, fn () => true);

        $this->get('/api/attachment/'.$moduleRow->hash)->assertStatus(403);
        $this->get('/api/attachment/'.$pluginRow->hash)->assertStatus(403);

        Module::query()->where('identifier', 'vendor-module')->update(['status' => ExtensionStatus::Active->value]);
        ModuleManager::invalidateModuleStatusCache();
        $this->recordPlugin('vendor-plugin', ExtensionStatus::Active);

        $this->get('/api/attachment/'.$moduleRow->hash)->assertStatus(200);
        $this->get('/api/attachment/'.$pluginRow->hash)->assertStatus(200);
    }

    /**
     * 서비스의 두 서빙 경로(`getFileInfo` 인라인·`download` 내려받기)가 같은 판정을 쓴다 — 리스너 없음=허용, `false`=거부,
     * 비활성 확장=거부.
     *
     * @scenario case=service_paths
     *
     * @effects service_paths_share_gate
     *
     * @return void 반환값 없음
     */
    public function test_get_file_info_and_download_share_the_gate(): void
    {
        $this->recordModule('vendor-module', ExtensionStatus::Active);
        $service = app(AttachmentService::class);
        $row = $this->storedAttachment(['source_type' => AttachmentSourceType::Module, 'source_identifier' => 'vendor-module', 'collection' => 'documents'], 'doc.pdf', 'application/pdf');
        $orphan = $this->storedAttachment(['source_type' => AttachmentSourceType::Plugin, 'source_identifier' => 'vendor-plugin', 'collection' => 'images']);

        $this->assertNotNull($service->getFileInfo($row->hash));
        $this->assertNotNull($service->download($row->hash));

        foreach (['getFileInfo', 'download'] as $method) {
            $this->assertForbidden(fn () => $service->{$method}($orphan->hash), $method.' — 미설치 확장 행');
        }

        HookManager::addFilter(AttachmentService::CAN_DOWNLOAD_FILTER, fn ($verdict, Attachment $attachment) => $attachment->id === $row->id ? false : $verdict);

        foreach (['getFileInfo', 'download'] as $method) {
            $this->assertForbidden(fn () => $service->{$method}($row->hash), $method.' — 리스너 false');
        }
    }

    /**
     * 코어 첨부 표에는 임시 업로드 키 열이 없다 — 확장의 본문 미디어는 확장 자기 표에 둔다.
     *
     * @scenario case=core_table
     *
     * @effects core_table_has_no_temp_key
     *
     * @return void 반환값 없음
     */
    public function test_core_attachments_table_has_no_temp_key(): void
    {
        $this->assertFalse(Schema::hasColumn('attachments', 'temp_key'));
        $this->assertNotContains('temp_key', (new Attachment)->getFillable());
    }

    /**
     * 호출이 다운로드 거부(AuthorizationException)로 끝나는지 확인합니다.
     *
     * @param  \Closure  $call  호출
     * @param  string  $message  실패 메시지
     * @return void 반환값 없음
     */
    private function assertForbidden(\Closure $call, string $message): void
    {
        try {
            $call();
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message.': 거부되지 않았다');
    }

    /**
     * 모듈 행을 기록하고 활성 목록 캐시를 비웁니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  ExtensionStatus  $status  상태
     * @return void 반환값 없음
     */
    private function recordModule(string $identifier, ExtensionStatus $status): void
    {
        Module::create([
            'identifier' => $identifier,
            'vendor' => strtok($identifier, '-'),
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'status' => $status->value,
            'version' => '1.0.0',
            'config' => [],
        ]);

        ModuleManager::invalidateModuleStatusCache();
    }

    /**
     * 플러그인 행을 기록하고 활성 목록 캐시를 비웁니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  ExtensionStatus  $status  상태
     * @return void 반환값 없음
     */
    private function recordPlugin(string $identifier, ExtensionStatus $status): void
    {
        Plugin::create([
            'identifier' => $identifier,
            'vendor' => strtok($identifier, '-'),
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'status' => $status->value,
            'version' => '1.0.0',
        ]);

        PluginManager::invalidatePluginStatusCache();
    }

    /**
     * 첨부 디스크에 파일을 두고 행을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 열 값
     * @param  string  $name  원본 파일명
     * @param  string  $mime  파일 형식(MIME)
     * @return Attachment 만든 첨부파일 모델
     */
    private function storedAttachment(array $attributes, string $name = 'a.png', string $mime = 'image/png'): Attachment
    {
        $content = base64_decode(self::PNG);
        $path = 'download-gate/'.Str::random(8).'-'.$name;
        Storage::disk('attachments')->put($path, $content);

        return Attachment::factory()->create(array_merge([
            'disk' => 'attachments',
            'path' => $path,
            'original_filename' => $name,
            'mime_type' => $mime,
            'size' => strlen($content),
        ], $attributes));
    }
}
