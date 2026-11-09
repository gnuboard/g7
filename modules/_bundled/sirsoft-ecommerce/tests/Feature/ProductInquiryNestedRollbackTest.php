<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품문의 — 바깥 트랜잭션 안에서 게시판 글 저장이 먼저 커밋된 뒤 바깥이 롤백되는 경로 (#883 3단계 F1)
 *
 * `ProductInquiryService::createInquiry` 는 자기 트랜잭션 안에서 게시판 훅으로 글을 만든다(게시판 `createPost` 의 트랜잭션은
 * savepoint). 글 저장이 첨부를 옮기고 커밋한 뒤 문의 쪽이 실패하면 DB 는 모두 되돌아가는데, `DB::afterRollBack()` 은 이미
 * 커밋된 안쪽 콜백을 버려 파일만 본경로에 남는다. 코드 변경 없이 StorageTransaction 저널의 깊이 비교가 보상하는지 잠근다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/product-save-transaction-hooks.yaml
 */
class ProductInquiryNestedRollbackTest extends ModuleTestCase
{
    /**
     * 게시판 모듈을 함께 띄운다 — 문의 글은 게시판 훅이 만든다.
     *
     * @var array<int, string>
     */
    protected array $requiredExtensions = [
        'modules/sirsoft-board',
    ];

    /**
     * 문의 처리가 글 저장 뒤 실패하면 문의·글이 없고 첨부 파일이 임시 자리에 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=temp_image_link_rollback
     *
     * @effects temp_image_file_restored_on_rollback
     */
    public function test_inquiry_failure_after_inner_post_commit_restores_attachment_file(): void
    {
        Storage::fake('local');
        Storage::fake('modules');
        $boardStorage = $this->serviceStorage(app(\Modules\Sirsoft\Board\Services\AttachmentService::class));
        $slug = 'inq-nested-'.Str::lower(Str::random(4));
        Board::create(['slug' => $slug, 'name' => ['ko' => '문의', 'en' => 'Inquiry'], 'is_active' => true, 'use_file_upload' => true]);
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', $slug);

        $user = User::factory()->create();
        $this->actingAs($user);
        $tempKey = (string) Str::uuid();
        $tempPath = "{$slug}/temp/{$tempKey}/q.png";
        $boardStorage->put('attachments', $tempPath, 'png');
        $attachment = Attachment::create([
            'board_id' => 0, 'post_id' => null, 'temp_key' => $tempKey,
            'original_filename' => 'q.png', 'stored_filename' => 'q.png', 'path' => $tempPath,
            'mime_type' => 'image/png', 'size' => 3, 'collection' => 'attachments', 'order' => 1, 'created_by' => $user->id,
            'disk' => $boardStorage->getDisk(),
        ]);

        // 게시판 문의 리스너를 실제 등록 경로로 붙인다 — 다른 모듈의 리스너는 시험에서 자동 등록되지 않는다
        HookListenerRegistrar::register(\Modules\Sirsoft\Board\Listeners\EcommerceInquiryHookListener::class, 'sirsoft-board');

        // 게시판 리스너(글 생성) 뒤에 실패를 만든다 — 글 저장 트랜잭션은 이미 커밋된 뒤다
        HookManager::addFilter('sirsoft-ecommerce.inquiry.create', function ($result) {
            if (is_array($result) && ! empty($result['post_id'])) {
                throw new \RuntimeException('inquiry failed after post commit');
            }

            return $result;
        }, 99);

        $product = Product::factory()->create();
        $postsBefore = Post::query()->count();

        try {
            app(ProductInquiryService::class)->createInquiry($product->id, [
                'title' => '중첩 롤백 문의',
                'content' => '바깥 트랜잭션 롤백 확인',
                'temp_key' => $tempKey,
            ]);
            $this->fail('문의 처리 예외가 올라와야 한다');
        } catch (\RuntimeException $e) {
            $this->assertSame('inquiry failed after post commit', $e->getMessage());
        }

        $this->assertSame($postsBefore, Post::query()->count());
        $this->assertSame(0, ProductInquiry::query()->count());
        $attachment->refresh();
        $this->assertNull($attachment->post_id);
        $this->assertSame($tempPath, $attachment->path);
        $this->assertTrue($boardStorage->exists('attachments', $tempPath));
        $this->assertSame([], $boardStorage->files('attachments', "{$slug}/".date('Y/m/d')), '본경로 사본이 남지 않는다');
    }

    /**
     * 서비스가 주입받은 저장소를 돌려줍니다 — 모듈이 쓰는 디스크(구성에 따라 local·modules)를 그대로 쓰기 위해.
     *
     * @param  object  $service  저장소를 주입받은 서비스
     * @return \App\Contracts\Extension\StorageInterface 저장소
     */
    private function serviceStorage(object $service): \App\Contracts\Extension\StorageInterface
    {
        return (new \ReflectionProperty($service, 'storage'))->getValue($service);
    }
}
