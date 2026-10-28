<?php

namespace Plugins\Sirsoft\Popup\Services;

use App\Extension\HookManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 팝업 서비스 — 훅 발행·트랜잭션·순서·복사·영구 삭제·본문 정화를 맡는다. 검증은 FormRequest, 쿼리는 Repository 가 한다.
 *
 * 발행 훅(접두 `sirsoft-popup.popup.`): before/after_create · update · delete · bulk_status · bulk_copy ·
 * bulk_delete · reorder, filter_create_data · filter_update_data · filter_list_params.
 * 수정 저장으로 기기별 이미지가 바뀌면 `sirsoft-popup.popup_image.after_change` 도 발행한다.
 * 활동 로그·공개 캐시 비우기는 after 훅을 구독하는 리스너가 한다.
 */
class PopupService
{
    /**
     * 마지막 등록·수정에서 본문 정화로 내용이 바뀌었는지 — 화면이 「일부 서식이 제거되었습니다」를 알리는 근거.
     */
    private bool $bodySanitized = false;

    /**
     * PopupService 생성자
     *
     * @param  PopupRepositoryInterface  $popups  팝업 Repository
     * @param  PopupImageService  $imageService  이미지 서비스(선택 적용·떼어 내기·복제·파일 삭제)
     * @param  PopupHtmlSanitizer  $sanitizer  본문 정화기
     */
    public function __construct(
        private PopupRepositoryInterface $popups,
        private PopupImageService $imageService,
        private PopupHtmlSanitizer $sanitizer,
    ) {}

    /**
     * 관리자 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터
     * @return LengthAwarePaginator 팝업 목록
     */
    public function getList(array $filters): LengthAwarePaginator
    {
        $filters = HookManager::applyFilters('sirsoft-popup.popup.filter_list_params', $filters);

        return $this->popups->paginateForAdmin($filters, now());
    }

    /**
     * 상태별 건수를 반환합니다.
     *
     * @return array{total: int, statuses: array<string, int>} 통계
     */
    public function getStatistics(): array
    {
        return $this->popups->statistics(now());
    }

    /**
     * 지금 노출 중인 팝업을 순서대로 반환합니다(목록 상단 요약).
     *
     * @return Collection<int, Popup> 노출 중 팝업
     */
    public function getActiveSummary(): Collection
    {
        return $this->popups->activeInOrder(now());
    }

    /**
     * 팝업 전체를 순서대로 반환합니다(순서 변경 창).
     *
     * @return Collection<int, Popup> 팝업 목록
     */
    public function getOrderList(): Collection
    {
        return $this->popups->listAllInOrder();
    }

    /**
     * 팝업을 찾습니다.
     *
     * @param  int  $id  팝업 ID
     * @return Popup 팝업
     */
    public function find(int $id): Popup
    {
        return $this->popups->findOrFail($id);
    }

    /**
     * 마지막 등록·수정에서 본문 정화로 내용이 바뀌었는지 반환합니다.
     *
     * @return bool 바뀌었으면 true
     */
    public function bodyWasSanitized(): bool
    {
        return $this->bodySanitized;
    }

    /**
     * 팝업을 만듭니다 — 맨 뒤에 놓고, 이미지형이면 임시 묶음에서 기기마다 한 장씩 붙입니다.
     *
     * 이미지 ID(pc_image_id·mobile_image_id)를 보냈으면 그것을, 보내지 않은 기기는 묶음의 가장 최근 이미지를 붙인다.
     * 붙지 않은 임시 이미지는 그대로 두고 고아 임시 이미지 정리가 지운다.
     *
     * @param  array<string, mixed>  $data  검증된 값 (temp_key 포함)
     * @param  int|null  $actorId  등록한 회원 ID
     * @return Popup 만든 팝업
     */
    public function create(array $data, ?int $actorId): Popup
    {
        HookManager::doAction('sirsoft-popup.popup.before_create', $data);
        $data = HookManager::applyFilters('sirsoft-popup.popup.filter_create_data', $data);

        $tempKey = (string) ($data['temp_key'] ?? '');
        $selected = $this->takeImageSelection($data);
        $data = $this->sanitizeBody($data);
        $isImage = ($data['content_type'] ?? null) === ContentType::Image->value;

        $popup = DB::transaction(function () use ($data, $tempKey, $selected, $isImage, $actorId) {
            $popup = $this->popups->create($data + [
                'sort_order' => $this->popups->nextSortOrder(),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            if ($isImage && $tempKey !== '') {
                $this->imageService->applySelection($popup, $this->imageService->resolveTempSelection($tempKey, $selected));
            }

            return $this->popups->findOrFail($popup->id);
        });

        HookManager::doAction('sirsoft-popup.popup.after_create', $popup);

        return $popup;
    }

    /**
     * 팝업을 고칩니다 — 수정 화면이 고른 이미지로 바꾸고, 직접 작성형으로 바꾸면 이미지를 뗍니다.
     *
     * 이미지는 저장할 때만 바뀐다(수정 화면의 업로드는 임시 묶음에 머문다). 떼어 낸 옛 이미지 파일은 커밋 뒤에 지운다.
     * 화면이 받은 세대 번호(`revision`)가 지금과 다르면 409 — 다른 관리자의 저장을 덮지 않는다.
     *
     * @param  Popup  $popup  대상 팝업
     * @param  array<string, mixed>  $data  검증된 값 (pc_image_id·mobile_image_id 는 보낸 것만 반영)
     * @param  int|null  $actorId  수정한 회원 ID
     * @return Popup 고친 팝업
     *
     * @throws ConflictHttpException 다른 관리자가 먼저 저장했을 때
     */
    public function update(Popup $popup, array $data, ?int $actorId): Popup
    {
        $expectedRevision = (int) ($data['revision'] ?? $popup->lock_version);
        unset($data['revision']);

        // 훅은 트랜잭션 밖에서 발행한다 — 리스너의 외부 호출·긴 작업이 잠금을 붙잡지 않게
        HookManager::doAction('sirsoft-popup.popup.before_update', $popup, $data);
        $data = HookManager::applyFilters('sirsoft-popup.popup.filter_update_data', $data, $popup);

        [$updated, $images, $snapshot] = DB::transaction(function () use ($popup, $data, $actorId, $expectedRevision) {
            $popup = $this->popups->findForUpdate($popup->id);
            if ($popup->lock_version !== $expectedRevision) {
                throw new ConflictHttpException(__('sirsoft-popup::messages.popup.conflict'));
            }

            $selection = $this->takeImageSelection($data);
            $data = $this->sanitizeBody($data);
            if (($data['content_type'] ?? $popup->content_type) === ContentType::Html->value) {
                // 직접 작성형은 이미지를 쓰지 않는다 — 남겨 두면 아무도 보지 않는 파일이 쌓인다
                $selection = ['pc' => null, 'mobile' => null];
            }
            $snapshot = $popup->toArray();
            $images = $this->imageService->applySelection($popup, $selection);

            return [$this->popups->update($popup, $data + ['updated_by' => $actorId]), $images, $snapshot];
        });

        $this->deleteReleasedImages($images['released']);
        foreach ($images['changes'] as $device => $change) {
            HookManager::doAction('sirsoft-popup.popup_image.after_change', $updated, $device, $change);
        }

        HookManager::doAction('sirsoft-popup.popup.after_update', $updated, $snapshot);

        return $updated;
    }

    /**
     * 팝업을 영구 삭제합니다 — PC·모바일 이미지를 떼어 낸 뒤 팝업을 지우고, 커밋 뒤 이미지 파일·행을 지웁니다.
     *
     * 파일 삭제가 실패한 이미지는 임시 이미지로 남아 고아 임시 이미지 정리가 다시 지운다.
     *
     * @param  Popup  $popup  대상 팝업
     * @return void 반환값 없음
     */
    public function delete(Popup $popup): void
    {
        HookManager::doAction('sirsoft-popup.popup.before_delete', $popup);

        $id = $popup->id;

        [$released, $snapshot] = DB::transaction(function () use ($id) {
            $popup = $this->popups->findForUpdate($id);
            $snapshot = $popup->toArray();
            $released = $this->imageService->releaseForPopups([$id]);
            $this->popups->delete($popup);

            return [$released, $snapshot];
        });
        $this->deleteReleasedImages($released);

        HookManager::doAction('sirsoft-popup.popup.after_delete', $id, $snapshot);
    }

    /**
     * 여러 팝업의 사용 여부(노출·비노출)를 바꿉니다.
     *
     * @param  array<int, int>  $ids  팝업 ID 목록
     * @param  bool  $isActive  사용 여부
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return int 바뀐 건수
     */
    public function bulkSetActive(array $ids, bool $isActive, ?int $actorId): int
    {
        HookManager::doAction('sirsoft-popup.popup.before_bulk_status', $ids, $isActive);

        [$count, $snapshots] = DB::transaction(function () use ($ids, $isActive, $actorId) {
            $popups = $this->popups->findMany($ids)->sortBy('id')
                ->map(fn (Popup $popup) => $this->popups->findForUpdate($popup->id));
            $snapshots = $popups->map(fn (Popup $popup) => $popup->toArray())->keyBy('id')->all();

            return [$this->popups->bulkSetActive($ids, $isActive, $actorId), $snapshots];
        });

        HookManager::doAction('sirsoft-popup.popup.after_bulk_status', $ids, $isActive, $snapshots);

        return $count;
    }

    /**
     * 여러 팝업을 복사합니다 — 복사본은 비활성으로 맨 뒤에(원래 순서 유지), 관리용 제목 뒤 「 (복사)」, 이미지 파일까지 복제.
     *
     * 비활성으로 만드는 이유: 복사 즉시 같은 팝업이 사용자 화면에 두 번 뜨지 않게(PO 결정 #812 R2).
     * 한 건 복사가 실패하면(이미지 파일 복제 실패 등) 그 건만 되돌리고 나머지는 계속한다 — 실패 id 는 결과로 돌려준다.
     *
     * @param  array<int, int>  $ids  원본 팝업 ID 목록
     * @param  int|null  $actorId  복사한 회원 ID
     * @return array{copies: array<int, int>, failed: array<int, int>} 원본 ID => 새 ID, 실패한 원본 ID
     */
    public function bulkCopy(array $ids, ?int $actorId): array
    {
        HookManager::doAction('sirsoft-popup.popup.before_bulk_copy', $ids);

        $sources = $this->popups->findMany($ids)->sortBy([['sort_order', 'asc'], ['id', 'asc']]);
        $suffix = (string) __('sirsoft-popup::messages.popup.copy_suffix');
        $copies = [];
        $failed = [];

        foreach ($sources as $source) {
            /** @var array<int, PopupImage> $written */
            $written = [];
            try {
                $newId = DB::transaction(function () use ($source, $suffix, $actorId, &$written) {
                    $copy = $this->popups->create([
                        'title' => mb_substr((string) $source->title, 0, max(0, 100 - mb_strlen($suffix))).$suffix,
                        'is_active' => false,
                        'sort_order' => $this->popups->nextSortOrder(),
                        'content_type' => $source->content_type,
                        'display_period' => $source->display_period,
                        'starts_at' => $source->starts_at,
                        'ends_at' => $source->ends_at,
                        'page_scope' => $source->page_scope,
                        'routes' => $source->routes,
                        'audience' => $source->audience,
                        'device' => $source->device,
                        'image_alt' => $source->image_alt,
                        'link_url' => $source->link_url,
                        'link_new_window' => $source->link_new_window,
                        'body_html' => $source->body_html,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);

                    foreach ([$source->pcImage, $source->mobileImage] as $image) {
                        if ($image !== null) {
                            $written[] = $this->imageService->duplicate($image, $copy->id, $actorId);
                        }
                    }

                    return $copy->id;
                });
                $copies[$source->id] = $newId;
            } catch (\Throwable $e) {
                // 행은 되돌려졌지만 이미 쓴 복제 파일은 남는다 — 여기서 거둔다
                foreach ($written as $image) {
                    $this->imageService->discardFile($image);
                }
                $failed[] = $source->id;
                Log::error('팝업 복사 실패 — 그 팝업만 복사하지 않았습니다', ['popup_id' => $source->id, 'error' => $e->getMessage()]);
            }
        }

        HookManager::doAction('sirsoft-popup.popup.after_bulk_copy', $copies, $failed);

        return ['copies' => $copies, 'failed' => $failed];
    }

    /**
     * 여러 팝업을 영구 삭제합니다 — 단건 삭제와 같이 이미지를 떼어 낸 뒤 팝업을 지우고, 커밋 뒤 이미지를 지웁니다.
     *
     * @param  array<int, int>  $ids  팝업 ID 목록
     * @return int 지운 건수
     */
    public function bulkDelete(array $ids): int
    {
        HookManager::doAction('sirsoft-popup.popup.before_bulk_delete', $ids);

        $popups = $this->popups->findMany($ids);

        [$count, $released, $snapshots] = DB::transaction(function () use ($popups) {
            $popups = $popups->sortBy('id')->map(fn (Popup $popup) => $this->popups->findForUpdate($popup->id));
            $snapshots = $popups->map(fn (Popup $popup) => $popup->toArray())->keyBy('id')->all();
            $released = $this->imageService->releaseForPopups($popups->pluck('id')->all());
            $deleted = 0;
            foreach ($popups as $popup) {
                $deleted += $this->popups->delete($popup) ? 1 : 0;
            }

            return [$deleted, $released, $snapshots];
        });
        $this->deleteReleasedImages($released);

        HookManager::doAction('sirsoft-popup.popup.after_bulk_delete', $ids, $snapshots);

        return $count;
    }

    /**
     * 팝업 순서(= 캐러셀 순서)를 바꿉니다(FormRequest 가 전체 id 인지 확인했다).
     *
     * @param  array<int, int>  $ids  새 순서의 팝업 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(array $ids): void
    {
        HookManager::doAction('sirsoft-popup.popup.before_reorder', $ids);

        DB::transaction(fn () => $this->popups->reorder($ids));

        HookManager::doAction('sirsoft-popup.popup.after_reorder', $ids);
    }

    /**
     * 직접 작성형 본문을 언어별로 정화합니다 — 이미지형이면 본문을 비웁니다.
     *
     * @param  array<string, mixed>  $data  검증된 값
     * @return array<string, mixed> 정화한 값
     */
    private function sanitizeBody(array $data): array
    {
        $this->bodySanitized = false;

        if (! array_key_exists('content_type', $data)) {
            return $data;
        }

        if ($data['content_type'] !== ContentType::Html->value) {
            $data['body_html'] = null;

            return $data;
        }

        $result = $this->sanitizer->sanitizeTranslations(is_array($data['body_html'] ?? null) ? $data['body_html'] : []);
        $this->bodySanitized = $result['changed'];
        $data['body_html'] = $result['bodies'];
        $data['image_alt'] = null;
        $data['link_url'] = null;
        $data['link_new_window'] = false;

        return $data;
    }

    /**
     * 검증된 값에서 이미지 선택(pc_image_id·mobile_image_id)과 temp_key 를 빼냅니다 — 팝업 행에 저장할 값이 아니다.
     *
     * @param  array<string, mixed>  $data  검증된 값 (참조로 받아 세 키를 지운다)
     * @return array<string, int|null> 보낸 기기 => 이미지 ID (보내지 않은 기기는 키가 없다)
     */
    private function takeImageSelection(array &$data): array
    {
        $selection = [];
        foreach (['pc' => 'pc_image_id', 'mobile' => 'mobile_image_id'] as $device => $field) {
            if (array_key_exists($field, $data)) {
                $selection[$device] = $data[$field] !== null ? (int) $data[$field] : null;
            }
        }
        unset($data['temp_key'], $data['pc_image_id'], $data['mobile_image_id']);

        return $selection;
    }

    /**
     * 팝업에서 떼어 낸 이미지의 파일·행을 지웁니다 — 실패분은 임시 이미지로 남아 정리 명령이 다시 지운다.
     *
     * @param  array<int, PopupImage>  $released  떼어 낸 이미지
     * @return void 반환값 없음
     */
    private function deleteReleasedImages(array $released): void
    {
        foreach ($released as $image) {
            $this->imageService->deleteImage($image);
        }
    }
}
