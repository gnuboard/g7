<?php

namespace Modules\Sirsoft\Page\Database\Seeders;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Models\PageVersion;

/**
 * 페이지 활동 로그 샘플 시더
 *
 * 실제 페이지·버전·첨부를 따라, 리스너(PageActivityLogListener)가 남기는 것과 같은
 * 키(`page_create`·`page_update`·`page_publish`·`page_unpublish`·`page_restore`·
 * `page_attachment_upload`)와 속성 모양으로 활동 로그를 만듭니다. 페이지·첨부는 영구 삭제라
 * 남아 있는 데이터와 맞지 않는 삭제 로그(`page_delete`·`page_attachment_delete`)는 만들지 않는다.
 *
 * 불변식:
 * - 이번 실행에서 시더가 새로 만든 페이지·첨부(PageSampleRunRegistry)에만 로그를 만든다. 운영자 페이지나
 *   이미 있던 기본 페이지에는 만들지 않는다 — 다시 실행해도 새로 만든 페이지가 없으면 로그도 없어 중복되지 않는다.
 * - 활동 로그는 지우지 않는다(B-5) — 이전 실행이 만든 샘플 로그(properties.seeded_by='sirsoft-page:sample')도
 *   그 실행이 만든 페이지의 기록이라 남긴다. 행위자는 실재 회원만.
 * - description 키는 밑줄 형식이다 — 점 형식(`page.create`)은 중첩 키로 해석돼 번역이 없어
 *   관리자 화면에 원시 키가 보였다(B-6). 조회(show·index) 로그는 리스너가 남기지 않으므로 없다(B-9).
 * - 시각은 페이지 생성 → 발행 → 수정(버전) 순서, 모두 현재 이전.
 */
class ActivityLogSampleSeeder extends Seeder
{
    /** @var string 다국어 키 접두사 */
    private const PREFIX = 'sirsoft-page::activity_log.description.';

    /** @var string 활동 로그 출처 확장 식별자 (리스너의 extension_origin 과 같다) */
    private const ORIGIN = 'sirsoft-page';

    /** @var string 이 시더가 만든 행의 표식 (properties.seeded_by) */
    public const SEEDED_BY = 'sirsoft-page:sample';

    /** @var array<string> 샘플 IP 목록 */
    private const IPS = ['192.168.1.10', '10.0.0.5', '172.16.0.1', '192.168.0.100', '10.10.10.1'];

    /** @var array<string> 샘플 User-Agent 목록 */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/125.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) Firefox/126.0',
    ];

    /** @var array<int, array<string, mixed>> insert 대기 행 */
    private array $rows = [];

    /** @var array<int, int> 관리자 ID 목록 */
    private array $adminIds = [];

    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->adminIds = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->pluck('id')->all();
        if ($this->adminIds === []) {
            $this->command->warn('관리자 사용자가 없어 페이지 활동 로그 시더를 건너뜁니다.');

            return;
        }

        $unmarked = ActivityLog::where('description_key', 'like', 'sirsoft-page::%')
            ->where(fn ($q) => $q->whereNull('properties')
                ->orWhere('properties', 'not like', '%"seeded_by":"'.self::SEEDED_BY.'"%'))
            ->count();
        if ($unmarked > 0) {
            $this->command->warn("  ⚠ 표식 없는 페이지 활동 로그 {$unmarked}건은 운영 로그로 보고 남깁니다 (이전 버전 시더가 만든 샘플도 표식이 없어 정리되지 않습니다).");
        }

        // 이번 실행에서 시더가 새로 만든 페이지만 — 운영자 페이지·이미 있던 기본 페이지의 이력은 지어내지 않는다
        $pages = Page::whereIn('id', PageSampleRunRegistry::pageIds())->get();
        if ($pages->isEmpty()) {
            $this->command->warn('이번 실행에서 새로 만든 페이지가 없어 페이지 활동 로그를 건너뜁니다.');

            return;
        }

        foreach ($pages as $page) {
            $this->seedPageLifecycle($page);
        }

        $this->seedAttachmentLogs();

        foreach (array_chunk($this->rows, 500) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }

        $this->command->info('페이지 활동 로그 '.count($this->rows).'건 생성 완료.');
    }

    /**
     * 페이지 1개의 생성 → 발행(또는 발행 취소) → 수정·복원 로그.
     *
     * @param  Page  $page  페이지
     * @return void 반환값 없음
     */
    private function seedPageLifecycle(Page $page): void
    {
        $createdAt = Carbon::parse($page->created_at);
        $actor = $page->created_by ?: $this->randomAdmin();

        $this->push(Page::class, $page->id, $actor, 'page.create', 'page_create',
            ['title' => $page->title], ['title' => $page->title, 'slug' => $page->slug], null, $createdAt);

        $versions = PageVersion::where('page_id', $page->id)->orderBy('version')->get();
        $cursor = $createdAt;

        foreach ($versions->skip(1) as $version) {
            $at = Carbon::parse($version->created_at);
            $this->push(Page::class, $page->id, $version->created_by ?: $actor, 'page.update', 'page_update',
                ['title' => $page->title], null, [[
                    'field' => 'content',
                    'label_key' => 'sirsoft-page::activity_log.fields.content',
                    'old' => '이전 페이지 내용...',
                    'new' => '수정된 페이지 내용',
                    'type' => 'text',
                ]], $at);
            $cursor = $at;
        }

        if ($page->published && $page->published_at) {
            $this->push(Page::class, $page->id, $actor, 'page.publish', 'page_publish',
                ['title' => $page->title], ['published' => true], null, Carbon::parse($page->published_at));
        } elseif (! $page->published) {
            // 한 번 발행했다가 내린 페이지로 시연 — 발행 → 발행 취소
            $publishedAt = $this->timeAfter($createdAt, 24);
            $this->push(Page::class, $page->id, $actor, 'page.publish', 'page_publish',
                ['title' => $page->title], ['published' => true], null, $publishedAt);
            $this->push(Page::class, $page->id, $actor, 'page.unpublish', 'page_unpublish',
                ['title' => $page->title], ['published' => false], null, $this->timeAfter($publishedAt, 48));
        }

        // 현재 버전이 마지막 버전보다 작으면 이전 버전으로 복원한 페이지다
        $latest = $versions->last();
        if ($latest && (int) $page->current_version < (int) $latest->version) {
            $restored = $versions->firstWhere('version', $page->current_version);
            if ($restored) {
                $this->push(Page::class, $page->id, $actor, 'page.restore', 'page_restore',
                    ['title' => $page->title, 'version_id' => $restored->id],
                    ['version_id' => $restored->id, 'version_number' => $restored->version], null, $this->timeAfter($cursor, 24));
            }
        }
    }

    /**
     * 첨부 업로드 로그 (페이지에 붙은 첨부만 — 첨부는 소프트 삭제가 없어 남아 있는 첨부는 업로드 이력만 있다).
     *
     * @return void 반환값 없음
     */
    private function seedAttachmentLogs(): void
    {
        $attachments = PageAttachment::whereIn('id', PageSampleRunRegistry::attachmentIds())->with('page')->get();

        foreach ($attachments as $attachment) {
            $title = $attachment->page?->title ?? '';
            $props = [
                // 리스너와 같은 속성 키 — 실제 칸 이름 original_filename (게시판 첨부 로그와 같다)
                'original_filename' => $attachment->original_filename,
                'size' => $attachment->size,
                'page_id' => $attachment->page_id,
                'title' => $title,
            ];

            $this->push(PageAttachment::class, $attachment->id, $attachment->created_by ?: $this->randomAdmin(),
                'page_attachment.upload', 'page_attachment_upload', ['title' => $title], $props, null, Carbon::parse($attachment->created_at));
        }
    }

    /**
     * 활동 로그 1행을 쌓습니다 (관리자 로그).
     *
     * @param  string  $loggableType  대상 모델 클래스
     * @param  int  $loggableId  대상 ID
     * @param  int|null  $userId  행위자 ID
     * @param  string  $action  액션명 (리스너와 같은 값)
     * @param  string  $key  description 키 꼬리 (밑줄 형식)
     * @param  array<string, mixed>  $params  다국어 파라미터
     * @param  array<string, mixed>|null  $properties  추가 속성 (리스너 모양)
     * @param  array<int, array<string, mixed>>|null  $changes  변경 이력
     * @param  Carbon  $at  기록 시각
     * @return void 반환값 없음
     */
    private function push(string $loggableType, int $loggableId, ?int $userId, string $action, string $key, array $params, ?array $properties, ?array $changes, Carbon $at): void
    {
        // 행위자는 실재 회원만 (activity_logs.user_id FK)
        if ($userId !== null && ! User::whereKey($userId)->exists()) {
            $userId = null;
        }

        $properties = array_merge($properties ?? [], [
            'extension_origin' => self::ORIGIN,
            'seeded_by' => self::SEEDED_BY,
        ]);
        $now = now();

        $this->rows[] = [
            'log_type' => ActivityLogType::Admin->value,
            'loggable_type' => $loggableType,
            'loggable_id' => $loggableId,
            'user_id' => $userId,
            'action' => $action,
            'description_key' => self::PREFIX.$key,
            'description_params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'changes' => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => self::IPS[array_rand(self::IPS)],
            'user_agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'created_at' => ($at->greaterThan($now) ? $now : $at)->toDateTimeString(),
        ];
    }

    /**
     * 기준 시각 뒤 ~ 최대 N시간 사이 시각 (지금을 넘지 않음).
     *
     * @param  Carbon  $base  기준 시각
     * @param  int  $maxHours  최대 지연 시간
     * @return Carbon 시각
     */
    private function timeAfter(Carbon $base, int $maxHours): Carbon
    {
        $at = $base->copy()->addMinutes(rand(1, max(1, $maxHours * 60)));

        return $at->greaterThan(now()) ? now() : $at;
    }

    /**
     * 임의 관리자 ID.
     *
     * @return int 관리자 ID
     */
    private function randomAdmin(): int
    {
        return $this->adminIds[array_rand($this->adminIds)];
    }
}
