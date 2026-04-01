<?php

namespace Tests\Unit\Listeners;

use App\Contracts\Repositories\MailTemplateRepositoryInterface;
use App\Listeners\MailTemplateUserOverridesListener;
use App\Models\MailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * MailTemplateUserOverridesListener 단위 테스트
 *
 * 메일 템플릿 수정 시 사용자가 변경한 필드를 user_overrides에 기록하는 리스너를 검증합니다.
 */
class MailTemplateUserOverridesListenerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var MailTemplateRepositoryInterface&\Mockery\MockInterface 리포지토리 목
     */
    private MailTemplateRepositoryInterface $repository;

    /**
     * @var MailTemplateUserOverridesListener 테스트 대상 리스너
     */
    private MailTemplateUserOverridesListener $listener;

    /**
     * 테스트 사전 설정
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(MailTemplateRepositoryInterface::class);
        $this->listener = new MailTemplateUserOverridesListener($this->repository);
    }

    /**
     * subject 변경 시 user_overrides에 'subject'가 기록되는지 확인합니다.
     *
     * @return void
     */
    public function test_records_subject_change_in_user_overrides(): void
    {
        $template = MailTemplate::factory()->create([
            'subject' => ['ko' => '기존 제목', 'en' => 'Old Subject'],
            'user_overrides' => [],
        ]);

        $newData = [
            'subject' => ['ko' => '변경된 제목', 'en' => 'New Subject'],
        ];

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function (MailTemplate $t, array $data) use ($template) {
                return $t->id === $template->id
                    && isset($data['user_overrides'])
                    && in_array('subject', $data['user_overrides'], true);
            })
            ->andReturn(true);

        $this->listener->handleBeforeUpdate($template, $newData);
    }

    /**
     * body 변경 시 user_overrides에 'body'가 기록되는지 확인합니다.
     *
     * @return void
     */
    public function test_records_body_change_in_user_overrides(): void
    {
        $template = MailTemplate::factory()->create([
            'body' => ['ko' => '<p>기존 본문</p>', 'en' => '<p>Old Body</p>'],
            'user_overrides' => [],
        ]);

        $newData = [
            'body' => ['ko' => '<p>변경된 본문</p>', 'en' => '<p>New Body</p>'],
        ];

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function (MailTemplate $t, array $data) use ($template) {
                return $t->id === $template->id
                    && isset($data['user_overrides'])
                    && in_array('body', $data['user_overrides'], true);
            })
            ->andReturn(true);

        $this->listener->handleBeforeUpdate($template, $newData);
    }

    /**
     * is_active 변경 시 user_overrides에 'is_active'가 기록되는지 확인합니다.
     *
     * @return void
     */
    public function test_records_is_active_change_in_user_overrides(): void
    {
        $template = MailTemplate::factory()->create([
            'is_active' => true,
            'user_overrides' => [],
        ]);

        $newData = [
            'is_active' => false,
        ];

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function (MailTemplate $t, array $data) use ($template) {
                return $t->id === $template->id
                    && isset($data['user_overrides'])
                    && in_array('is_active', $data['user_overrides'], true);
            })
            ->andReturn(true);

        $this->listener->handleBeforeUpdate($template, $newData);
    }

    /**
     * 이미 user_overrides에 기록된 필드는 중복 추가하지 않는지 확인합니다.
     *
     * @return void
     */
    public function test_does_not_duplicate_existing_overrides(): void
    {
        $template = MailTemplate::factory()->create([
            'subject' => ['ko' => '기존 제목', 'en' => 'Old Subject'],
            'user_overrides' => ['subject'],
        ]);

        $newData = [
            'subject' => ['ko' => '다시 변경된 제목', 'en' => 'Changed Again'],
        ];

        $this->repository
            ->shouldNotReceive('update');

        $this->listener->handleBeforeUpdate($template, $newData);
    }

    /**
     * 동일한 값을 전달하면 user_overrides가 변경되지 않는지 확인합니다.
     *
     * @return void
     */
    public function test_does_not_record_unchanged_fields(): void
    {
        $subject = ['ko' => '동일 제목', 'en' => 'Same Subject'];
        $body = ['ko' => '<p>동일 본문</p>', 'en' => '<p>Same Body</p>'];

        $template = MailTemplate::factory()->create([
            'subject' => $subject,
            'body' => $body,
            'is_active' => true,
            'user_overrides' => [],
        ]);

        $newData = [
            'subject' => $subject,
            'body' => $body,
            'is_active' => true,
        ];

        $this->repository
            ->shouldNotReceive('update');

        $this->listener->handleBeforeUpdate($template, $newData);
    }
}
