<?php

namespace Modules\Sirsoft\Page\Database\Seeders;

use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Models\PageVersion;

/**
 * 페이지 시연 샘플 시더 (--sample 전용)
 *
 * 설치 시더(PageSeeder)의 기본 페이지 6개는 모두 발행·버전 1·첨부 0 이라 관리자 화면의
 * 미발행 표시·버전 이력·복원·첨부 목록을 볼 수 없다(SB-5). 그 기능을 보여 줄 페이지 3개를 더 만든다.
 *
 * 1. sample-draft      : 미발행 초안 (발행했다가 내린 상태)
 * 2. sample-history    : 버전 3개, 현재 내용은 버전 2 로 복원된 상태
 * 3. sample-attachment : 발행 + 이미지 첨부 1개
 *
 * 이미 같은 slug 가 있으면 건너뛴다(운영자가 만든 페이지를 덮어쓰지 않는다).
 */
class PageSampleSeeder extends Seeder
{
    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('페이지 시연 샘플 생성 중...');

        $adminId = User::where('is_super', true)->value('id');
        $created = 0;

        $created += $this->createDraftPage($adminId);
        $created += $this->createHistoryPage($adminId);
        $created += $this->createAttachmentPage($adminId);

        $this->command->info("페이지 시연 샘플 생성 완료: {$created}개");
    }

    /**
     * 미발행 초안 페이지를 만듭니다.
     *
     * @param  int|null  $adminId  작성자 ID
     * @return int 만든 페이지 수
     */
    private function createDraftPage(?int $adminId): int
    {
        if ($this->exists('sample-draft')) {
            return 0;
        }

        $createdAt = now()->subDays(12);

        $page = $this->createPage('sample-draft', [
            'ko' => '이벤트 안내 (초안)', 'en' => 'Event Notice (Draft)',
        ], [
            'ko' => '<p>다음 달 이벤트 안내 초안입니다. 확정 후 발행합니다.</p>',
            'en' => '<p>Draft notice for next month\'s event. It will be published once confirmed.</p>',
        ], false, null, 1, $adminId, $createdAt);

        $this->snapshot($page, 1, $adminId, $createdAt);

        return 1;
    }

    /**
     * 버전 3개, 현재 버전 2 로 복원된 페이지를 만듭니다.
     *
     * @param  int|null  $adminId  작성자 ID
     * @return int 만든 페이지 수
     */
    private function createHistoryPage(?int $adminId): int
    {
        if ($this->exists('sample-history')) {
            return 0;
        }

        $createdAt = now()->subDays(20);
        $versions = [
            1 => ['ko' => '<p>운영 시간: 평일 09:00 ~ 18:00</p>', 'en' => '<p>Hours: Weekdays 09:00 - 18:00</p>'],
            2 => ['ko' => '<p>운영 시간: 평일 09:00 ~ 18:00, 토요일 10:00 ~ 14:00</p>', 'en' => '<p>Hours: Weekdays 09:00 - 18:00, Saturday 10:00 - 14:00</p>'],
            3 => ['ko' => '<p>운영 시간: 매일 09:00 ~ 21:00</p>', 'en' => '<p>Hours: Daily 09:00 - 21:00</p>'],
        ];
        $title = ['ko' => '운영 시간 안내', 'en' => 'Business Hours'];

        // 현재 내용은 버전 2 (버전 3 을 올렸다가 버전 2 로 되돌림)
        $page = $this->createPage('sample-history', $title, $versions[2], true, $createdAt->copy()->addHour(), 2, $adminId, $createdAt);

        foreach ($versions as $number => $content) {
            $at = $createdAt->copy()->addDays(($number - 1) * 4);
            $this->backdate(PageVersion::create([
                'page_id' => $page->id,
                'version' => $number,
                'title' => $title,
                'content' => $content,
                'content_mode' => 'html',
                'seo_meta' => null,
                'created_by' => $adminId,
            ]), $at);
        }

        return 1;
    }

    /**
     * 이미지 첨부가 있는 발행 페이지를 만듭니다.
     *
     * @param  int|null  $adminId  작성자 ID
     * @return int 만든 페이지 수
     */
    private function createAttachmentPage(?int $adminId): int
    {
        if ($this->exists('sample-attachment')) {
            return 0;
        }

        $createdAt = now()->subDays(5);

        $page = $this->createPage('sample-attachment', [
            'ko' => '오시는 길', 'en' => 'Directions',
        ], [
            'ko' => '<p>첨부한 약도를 참고해 주세요.</p>',
            'en' => '<p>Please refer to the attached map.</p>',
        ], true, $createdAt->copy()->addMinutes(30), 1, $adminId, $createdAt);

        $this->snapshot($page, 1, $adminId, $createdAt);

        if (function_exists('imagecreatetruecolor')) {
            $image = imagecreatetruecolor(640, 400);
            imagefill($image, 0, 0, imagecolorallocate($image, 90, 140, 200));
            imagestring($image, 5, 260, 190, 'Sample Map', imagecolorallocate($image, 255, 255, 255));
            ob_start();
            imagejpeg($image, null, 85);
            $content = (string) ob_get_clean();
            imagedestroy($image);

            $storedFilename = Str::uuid().'.jpg';
            $path = $createdAt->format('Y/m/d').'/'.$storedFilename;
            $storage = new ModuleStorageDriver('sirsoft-page', 'modules');
            $storage->put('attachments', $path, $content);

            $attachment = PageAttachment::create([
                'page_id' => $page->id,
                'hash' => Str::random(12),
                'original_filename' => 'map.jpg',
                'stored_filename' => $storedFilename,
                'disk' => $storage->getDisk(),
                'path' => $path,
                'mime_type' => 'image/jpeg',
                'size' => strlen($content),
                'collection' => 'attachments',
                'order' => 1,
                'meta' => ['width' => 640, 'height' => 400],
                'created_by' => $adminId,
            ]);
            $this->backdate($attachment, $createdAt->copy()->addMinutes(10));
            PageSampleRunRegistry::addAttachment((int) $attachment->id);
        } else {
            $this->command->warn('  - GD 확장이 없어 첨부 이미지를 만들지 않았습니다.');
        }

        return 1;
    }

    /**
     * 페이지 행을 만듭니다.
     *
     * @param  string  $slug  슬러그
     * @param  array<string, string>  $title  다국어 제목
     * @param  array<string, string>  $content  다국어 본문
     * @param  bool  $published  발행 여부
     * @param  Carbon|null  $publishedAt  발행 시각
     * @param  int  $currentVersion  현재 버전 번호
     * @param  int|null  $adminId  작성자 ID
     * @param  Carbon  $createdAt  작성 시각
     * @return Page 만든 페이지
     */
    private function createPage(string $slug, array $title, array $content, bool $published, ?Carbon $publishedAt, int $currentVersion, ?int $adminId, Carbon $createdAt): Page
    {
        $page = Page::create([
            'slug' => $slug,
            'title' => $this->withSiteLocale($title),
            'content' => $this->withSiteLocale($content),
            'content_mode' => 'html',
            'published' => $published,
            'published_at' => $publishedAt,
            'current_version' => $currentVersion,
            'created_by' => $adminId,
            'updated_by' => $adminId,
        ]);

        $this->backdate($page, $createdAt);
        PageSampleRunRegistry::addPage((int) $page->id);
        $this->command->info("  - [{$slug}] 생성 완료");

        return $page;
    }

    /**
     * 현재 내용으로 버전 스냅샷을 남깁니다.
     *
     * @param  Page  $page  페이지
     * @param  int  $version  버전 번호
     * @param  int|null  $adminId  작성자 ID
     * @param  Carbon  $at  시각
     * @return void 반환값 없음
     */
    private function snapshot(Page $page, int $version, ?int $adminId, Carbon $at): void
    {
        $this->backdate(PageVersion::create([
            'page_id' => $page->id,
            'version' => $version,
            'title' => $page->title,
            'content' => $page->content,
            'content_mode' => $page->content_mode,
            'seo_meta' => $page->seo_meta,
            'created_by' => $adminId,
        ]), $at);
    }

    /**
     * 모델의 작성 시각을 과거로 맞춥니다 (created_at 은 대량 할당 대상이 아니다).
     *
     * @param  Model  $model  모델
     * @param  Carbon  $at  시각
     * @return void 반환값 없음
     */
    private function backdate($model, Carbon $at): void
    {
        $attributes = ['created_at' => $at];
        if ($model->usesTimestamps() && $model->getUpdatedAtColumn() !== null) {
            $attributes[$model->getUpdatedAtColumn()] = $at;
        }

        $model->forceFill($attributes);
        $model->timestamps = false;
        $model->saveQuietly();
        $model->timestamps = true;
    }

    /**
     * 사이트 기본 언어 값이 없으면 fallback 언어(app.fallback_locale) 값, 그것도 없으면 첫 값으로 채웁니다.
     *
     * @param  array<string, string>  $values  로케일별 값
     * @return array<string, string> 사이트 기본 언어 값이 보장된 값
     */
    private function withSiteLocale(array $values): array
    {
        $siteLocale = (string) config('app.site_locale', config('app.locale', 'ko'));

        if ($siteLocale !== '' && ! isset($values[$siteLocale])) {
            // 다국어 fallback chain — app.fallback_locale 값, 없으면 첫 값
            $values[$siteLocale] = $values[config('app.fallback_locale', 'ko')] ?? (reset($values) ?: '');
        }

        return $values;
    }

    /**
     * 같은 slug 의 페이지가 있으면 건너뜁니다.
     *
     * @param  string  $slug  슬러그
     * @return bool 이미 있으면 true
     */
    private function exists(string $slug): bool
    {
        if (Page::where('slug', $slug)->exists()) {
            $this->command->warn("  - [{$slug}] 이미 존재합니다. 스킵합니다.");

            return true;
        }

        return false;
    }
}
