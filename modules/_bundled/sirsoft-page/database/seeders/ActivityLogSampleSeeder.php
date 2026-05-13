<?php

namespace Modules\Sirsoft\Page\Database\Seeders;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;

/**
 * 페이지 활동 로그 샘플 시더
 *
 * 리소스별 rand(1,50)건의 랜덤 활동 로그를 생성합니다.
 * 실제 DB 레코드를 참조하며, 데이터가 없는 카테고리는 건너뜁니다.
 */
class ActivityLogSampleSeeder extends Seeder
{
    /** @var string 다국어 키 접두사 */
    private const PREFIX = 'sirsoft-page::activity_log.description.';

    /** @var array<string> 샘플 IP 목록 */
    private const IPS = ['192.168.1.10', '10.0.0.5', '172.16.0.1', '192.168.0.100', '10.10.10.1'];

    /** @var array<string> 샘플 User-Agent 목록 */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/125.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) Firefox/126.0',
    ];

    /**
     * 시더를 실행합니다.
     */
    public function run(): void
    {
        $admins = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->get();
        if ($admins->isEmpty()) {
            $this->command->warn('관리자 사용자가 없어 페이지 활동 로그 시더를 건너뜁니다.');

            return;
        }

        // 기존 페이지 활동 로그 삭제
        $deleted = ActivityLog::where('description_key', 'like', 'sirsoft-page::%')->delete();
        if ($deleted > 0) {
            $this->command->info("기존 페이지 활동 로그 {$deleted}건 삭제.");
        }

        $count = 0;

        $count += $this->seedPageLogs($admins);
        $count += $this->seedPageAttachmentLogs($admins);

        $this->command->info("페이지 활동 로그 {$count}건 생성 완료.");
    }

    /**
     * 페이지 활동 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedPageLogs(Collection $admins): int
    {
        $pages = Page::get();
        if ($pages->isEmpty()) {
            $this->command->warn('페이지 데이터가 없어 페이지 활동 로그를 건너뜁니다.');

            return 0;
        }

        $morphType = (new Page)->getMorphClass();

        $actions = [
            [
                'action' => 'page.create',
                'key' => 'page.create',
                'loggable' => true,
                'params' => fn ($page) => ['title' => $this->getLocalizedName($page->title), 'slug' => $page->slug],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'page.update',
                'key' => 'page.update',
                'loggable' => true,
                'params' => fn ($page) => ['title' => $this->getLocalizedName($page->title)],
                'changes' => fn ($page) => [
                    ['field' => 'title', 'label_key' => 'sirsoft-page::activity_log.fields.title', 'old' => $this->getLocalizedName($page->title).' (수정 전)', 'new' => $this->getLocalizedName($page->title), 'type' => 'text'],
                    ['field' => 'content', 'label_key' => 'sirsoft-page::activity_log.fields.content', 'old' => '이전 페이지 내용...', 'new' => '수정된 페이지 내용', 'type' => 'text'],
                ],
                'properties' => null,
            ],
            [
                'action' => 'page.delete',
                'key' => 'page.delete',
                'loggable' => false,
                'params' => fn ($page) => ['title' => $this->getLocalizedName($page->title)],
                'changes' => null,
                'properties' => fn ($page) => ['deleted_id' => $page->id, 'slug' => $page->slug, 'title' => $this->getLocalizedName($page->title)],
            ],
            [
                'action' => 'page.publish',
                'key' => 'page.publish',
                'loggable' => true,
                'params' => fn ($page) => ['title' => $this->getLocalizedName($page->title), 'published' => $page->published ? 'true' : 'false'],
                'changes' => fn ($page) => [
                    ['field' => 'published', 'label_key' => 'sirsoft-page::activity_log.fields.published', 'old' => ! $page->published, 'new' => $page->published, 'type' => 'boolean'],
                ],
                'properties' => null,
            ],
            [
                'action' => 'page.restore',
                'key' => 'page.restore',
                'loggable' => true,
                'params' => fn ($page) => ['title' => $this->getLocalizedName($page->title)],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'page.show',
                'key' => 'page.show',
                'loggable' => true,
                'params' => fn ($page) => ['title' => $this->getLocalizedName($page->title)],
                'changes' => null,
                'properties' => null,
            ],
        ];

        $count = $this->generateResourceLogs($pages, $admins, ActivityLogType::Admin, $morphType, $actions);

        // index (비리소스 액션, rand 1-5)
        $indexCount = rand(1, 5);
        for ($i = 0; $i < $indexCount; $i++) {
            $this->createLog(ActivityLogType::Admin, null, null, $admins->random()->id, 'page.index', self::PREFIX.'page.index', []);
        }
        $count += $indexCount;

        return $count;
    }

    /**
     * 페이지 첨부파일 활동 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedPageAttachmentLogs(Collection $admins): int
    {
        $attachments = PageAttachment::with('page')->get();
        if ($attachments->isEmpty()) {
            $this->command->warn('페이지 첨부파일 데이터가 없어 첨부파일 활동 로그를 건너뜁니다.');

            return 0;
        }

        $morphType = (new PageAttachment)->getMorphClass();

        $actions = [
            [
                'action' => 'page_attachment.upload',
                'key' => 'page_attachment.upload',
                'loggable' => true,
                'params' => fn ($attachment) => [
                    'filename' => $attachment->original_filename ?? $attachment->stored_filename ?? 'file',
                    'page_title' => $attachment->page ? $this->getLocalizedName($attachment->page->title) : '삭제된 페이지',
                    'size' => $this->formatBytes($attachment->size ?? 0),
                ],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'page_attachment.delete',
                'key' => 'page_attachment.delete',
                'loggable' => false,
                'params' => fn ($attachment) => [
                    'filename' => $attachment->original_filename ?? $attachment->stored_filename ?? 'file',
                    'page_title' => $attachment->page ? $this->getLocalizedName($attachment->page->title) : '삭제된 페이지',
                ],
                'changes' => null,
                'properties' => fn ($attachment) => [
                    'deleted_id' => $attachment->id,
                    'page_id' => $attachment->page_id,
                    'mime_type' => $attachment->mime_type ?? 'unknown',
                    'size' => $attachment->size,
                ],
            ],
        ];

        return $this->generateResourceLogs($attachments, $admins, ActivityLogType::Admin, $morphType, $actions);
    }

    /**
     * 리소스별 랜덤 활동 로그를 생성합니다.
     *
     * 각 리소스에 대해 rand(1, 50)개의 랜덤 액션을 선택(중복 허용)하여 로그를 생성합니다.
     *
     * @param  Collection  $resources  리소스 컬렉션
     * @param  Collection  $actors  액터(관리자) 컬렉션
     * @param  ActivityLogType  $logType  로그 유형
     * @param  string  $morphType  모델 morph 타입
     * @param  array  $actions  액션 템플릿 배열
     * @return int 생성된 로그 수
     */
    private function generateResourceLogs(
        Collection $resources,
        Collection $actors,
        ActivityLogType $logType,
        string $morphType,
        array $actions,
    ): int {
        $count = 0;

        foreach ($resources as $resource) {
            $logCount = rand(1, 50);

            for ($i = 0; $i < $logCount; $i++) {
                $action = $actions[array_rand($actions)];

                $loggableType = $action['loggable'] ? $morphType : null;
                $loggableId = $action['loggable'] ? $resource->id : null;
                $params = ($action['params'])($resource);
                $changes = $action['changes'] ? ($action['changes'])($resource) : null;
                $properties = $action['properties'] ? ($action['properties'])($resource) : null;

                $this->createLog(
                    $logType,
                    $loggableType,
                    $loggableId,
                    $actors->random()->id,
                    $action['action'],
                    self::PREFIX.$action['key'],
                    $params,
                    $changes,
                    $properties,
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * ActivityLog 레코드를 생성합니다.
     *
     * @param  ActivityLogType  $logType  로그 유형
     * @param  string|null  $loggableType  모델 morph 타입
     * @param  int|null  $loggableId  모델 ID
     * @param  int  $userId  사용자 ID
     * @param  string  $action  액션명
     * @param  string  $descriptionKey  다국어 키
     * @param  array  $descriptionParams  다국어 파라미터
     * @param  array|null  $changes  변경 이력
     * @param  array|null  $properties  추가 속성
     * @return ActivityLog 생성된 로그
     */
    private function createLog(
        ActivityLogType $logType,
        ?string $loggableType,
        ?int $loggableId,
        int $userId,
        string $action,
        string $descriptionKey,
        array $descriptionParams,
        ?array $changes = null,
        ?array $properties = null,
    ): ActivityLog {
        return ActivityLog::create([
            'log_type' => $logType,
            'loggable_type' => $loggableType,
            'loggable_id' => $loggableId,
            'user_id' => $userId,
            'action' => $action,
            'description_key' => $descriptionKey,
            'description_params' => $descriptionParams,
            'changes' => $changes,
            'properties' => $properties,
            'ip_address' => self::IPS[array_rand(self::IPS)],
            'user_agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'created_at' => Carbon::now()->subDays(rand(1, 30))->subHours(rand(0, 23))->subMinutes(rand(0, 59)),
        ]);
    }

    /**
     * 다국어 name 배열에서 현재 로케일 값을 가져옵니다.
     *
     * @param  mixed  $name  name 속성 (배열 또는 문자열)
     * @return string 로케일에 맞는 이름
     */
    private function getLocalizedName(mixed $name): string
    {
        if (is_string($name)) {
            return $name;
        }

        if (is_array($name)) {
            $locale = app()->getLocale();

            return $name[$locale] ?? $name['ko'] ?? $name[array_key_first($name)] ?? '';
        }

        return '';
    }

    /**
     * 바이트 수를 사람이 읽기 쉬운 형식으로 변환합니다.
     *
     * @param  int  $bytes  바이트 수
     * @return string 포맷된 파일 크기
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
