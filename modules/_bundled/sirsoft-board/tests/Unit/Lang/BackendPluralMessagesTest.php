<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Lang;

use App\Helpers\ResponseHelper;
use Modules\Sirsoft\Board\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Board\Exceptions\BoardTypeOperationException;
use Modules\Sirsoft\Board\Exceptions\CategoryInUseException;
use Modules\Sirsoft\Board\Exceptions\CommentDepthExceededException;
use Modules\Sirsoft\Board\Exceptions\ReactionTypeInUseException;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 백엔드 영어 메시지의 단·복수 테스트.
 *
 * 종전 영어 문구는 `:count post(s)` 처럼 수와 상관없이 적었다. 프런트 사전과 같게 1 이면 단수,
 * 0·2 이상이면 복수로 고르고(`단수|복수` + trans_choice), 한국어 문구는 그대로다.
 */
class BackendPluralMessagesTest extends ModuleTestCase
{
    /**
     * 사전을 작업 원본(_bundled)에서 읽도록 네임스페이스를 다시 잡습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-board', base_path('modules/_bundled/sirsoft-board/src/lang'));
    }

    /**
     * 사전 키별 [치환값, 수 자리표시자 이름, 1일 때 영어, 2일 때 영어].
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string}> 데이터 이름 => [다국어 키, 수 외 치환값, 수 자리표시자, 1일 때 영어, 2일 때 영어]
     */
    public static function boardKeys(): array
    {
        $p = 'sirsoft-board::';

        return [
            'boards.bulk_members_success' => [$p.'messages.boards.bulk_members_success', ['skipped' => 0, 'unchanged' => 0], 'count',
                'Updated member lists of 1 board. (0 skipped, 0 unchanged)', 'Updated member lists of 2 boards. (0 skipped, 0 unchanged)'],
            'reaction.bulk_removed' => [$p.'messages.reaction.bulk_removed', [], 'count',
                '1 reaction has been removed.', '2 reactions have been removed.'],
            'reaction.admin.bulk_removed' => [$p.'messages.reaction.admin.bulk_removed', [], 'count',
                '1 reaction has been deleted.', '2 reactions have been deleted.'],
            'reaction_type.in_use' => [$p.'messages.reaction_type.in_use', ['name' => 'Like'], 'count',
                'This type has 1 reaction and cannot be deleted (Like). You can only deactivate it.',
                'This type has 2 reactions and cannot be deleted (Like). You can only deactivate it.'],
            'errors.category_in_use' => [$p.'messages.errors.category_in_use', ['category' => 'News'], 'count',
                'Category News is in use by 1 post.', 'Category News is in use by 2 posts.'],
            'errors.attachment_limit_exceeded' => [$p.'messages.errors.attachment_limit_exceeded', ['attempted' => 3], 'limit',
                'You can attach up to 1 file. (requested: 3)', 'You can attach up to 2 files. (requested: 3)'],
            'reports.bulk_status_updated_with_count' => [$p.'messages.reports.bulk_status_updated_with_count', ['status' => 'Rejected'], 'count',
                'Changed 1 report to Rejected.', 'Changed 2 reports to Rejected.'],
            'reports.manual_blind_restored_notice' => [$p.'messages.reports.manual_blind_restored_notice', [], 'count',
                '1 manually blinded item was also restored.', '2 manually blinded items were also restored.'],
            'scrap.bulk_removed' => [$p.'messages.scrap.bulk_removed', [], 'count',
                '1 scrap removed.', '2 scraps removed.'],
            'scrap.admin.bulk_removed' => [$p.'messages.scrap.admin.bulk_removed', [], 'count',
                '1 scrap deleted.', '2 scraps deleted.'],
            'posts_bulk.delete_success' => [$p.'messages.posts_bulk.delete_success', ['skipped' => 0], 'count',
                '1 post deleted. (0 skipped)', '2 posts deleted. (0 skipped)'],
            'posts_bulk.blind_success' => [$p.'messages.posts_bulk.blind_success', ['skipped' => 0], 'count',
                '1 post blinded. (0 skipped)', '2 posts blinded. (0 skipped)'],
            'posts_bulk.restore_success' => [$p.'messages.posts_bulk.restore_success', ['skipped' => 0], 'count',
                '1 post restored. (0 skipped)', '2 posts restored. (0 skipped)'],
            'board_type.delete_in_use' => [$p.'messages.board_type.delete_in_use', [], 'count',
                'Cannot delete board type in use by 1 board.', 'Cannot delete board type in use by 2 boards.'],
            'validation.post.parent_id.depth_exceeded' => [$p.'validation.post.parent_id.depth_exceeded', [], 'max',
                'This board allows replies up to 1 level only.', 'This board allows replies up to 2 levels only.'],
            'validation.comment.depth.exceeded' => [$p.'validation.comment.depth.exceeded', [], 'max',
                'This board allows replies up to 1 level only.', 'This board allows replies up to 2 levels only.'],
            'validation.category_in_use' => [$p.'validation.category_in_use', ['category' => 'News'], 'count',
                'The "News" category is currently in use by 1 post.', 'The "News" category is currently in use by 2 posts.'],
        ];
    }

    /**
     * 각 영어 메시지는 1 이면 단수, 0·2 이면 복수로 나온다.
     *
     * @scenario surface=dictionary
     *
     * @effects english_message_singular_for_one_plural_otherwise
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $params  수 외 치환값
     * @param  string  $numberParam  수 자리표시자 이름
     * @param  string  $one  1 일 때 기대 문구
     * @param  string  $two  2 일 때 기대 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('boardKeys')]
    public function english_message_is_singular_for_one_and_plural_otherwise(string $key, array $params, string $numberParam, string $one, string $two): void
    {
        $this->app->setLocale('en');

        $this->assertSame($one, trans_choice($key, 1, $params + [$numberParam => 1]));
        $this->assertSame($two, trans_choice($key, 2, $params + [$numberParam => 2]));
        $this->assertSame(preg_replace('/(?<!\d)2(?!\d)/', '0', $two, 1), trans_choice($key, 0, $params + [$numberParam => 0]), "{$key}: 0 은 복수");
    }

    /**
     * 한국어 문구는 복수 구분 기호가 없고, 복수 고르기 호출로 바꿔도 결과가 `__()` 와 같다.
     *
     * @scenario surface=dictionary
     *
     * @effects korean_message_unchanged
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $params  수 외 치환값
     * @param  string  $numberParam  수 자리표시자 이름
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('boardKeys')]
    public function korean_message_is_unchanged(string $key, array $params, string $numberParam): void
    {
        $this->app->setLocale('ko');

        foreach ([1, 2] as $n) {
            $replace = $params + [$numberParam => $n];
            $legacy = __($key, $replace);

            $this->assertStringNotContainsString('|', $legacy, "{$key}: 한국어 값에 복수 구분 기호가 있다");
            $this->assertSame($legacy, trans_choice($key, $n, $replace));
        }
    }

    /**
     * 신고 권한 역할 최소 선택 문구는 규칙이 `min:1` 고정이라 단수로 적는다.
     *
     * @scenario surface=fixed_minimum
     *
     * @effects fixed_minimum_message_is_singular
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function report_permission_minimum_is_singular(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('Please select at least :min role for report view permission.', __('sirsoft-board::validation.report_permissions.view_roles.min'));
        $this->assertSame('Please select at least :min role for report manage permission.', __('sirsoft-board::validation.report_permissions.manage_roles.min'));
    }

    /**
     * 예외 메시지와 그 예외로 만든 API 응답 문구가 수 1 에서 단수다.
     *
     * @scenario surface=exception_response
     *
     * @effects exception_and_response_message_follow_number
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function exception_messages_and_responses_follow_the_number(): void
    {
        $this->app->setLocale('en');

        $attachment = new AttachmentLimitExceededException(1, 2);
        $this->assertSame('You can attach up to 1 file. (requested: 2)', $attachment->getMessage());
        $this->assertSame(
            'You can attach up to 1 file. (requested: 2)',
            ResponseHelper::error($attachment->getMessageKey(), 422, null, $attachment->getMessageParams())->getData(true)['message']
        );

        $reaction = new ReactionTypeInUseException('Like', 1);
        $this->assertSame('This type has 1 reaction and cannot be deleted (Like). You can only deactivate it.', $reaction->getMessage());

        $depth = new CommentDepthExceededException(1, 2);
        $this->assertSame('This board allows replies up to 1 level only.', $depth->getMessage());
        $this->assertSame(
            'This board allows replies up to 1 level only.',
            ResponseHelper::error($depth->getMessageKey(), 422, null, $depth->getMessageParams())->getData(true)['message']
        );

        $category = new CategoryInUseException('News', 1);
        $this->assertSame('Category News is in use by 1 post.', $category->getMessage());

        $type = new BoardTypeOperationException('sirsoft-board::messages.board_type.delete_in_use', ['count' => 1]);
        $this->assertSame('Cannot delete board type in use by 1 board.', $type->getMessage());
        $this->assertSame(
            'Cannot delete board type in use by 2 boards.',
            ResponseHelper::error('sirsoft-board::messages.board_type.delete_in_use', 422, null, ['count' => 2])->getData(true)['message']
        );

        $this->app->setLocale('ko');
        $this->assertSame(__('sirsoft-board::messages.errors.attachment_limit_exceeded', ['limit' => 1, 'attempted' => 2]), (new AttachmentLimitExceededException(1, 2))->getMessage());
    }
}
