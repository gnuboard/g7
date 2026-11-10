<?php

namespace Modules\Sirsoft\Page\Services\ContentMedia;

use App\Contracts\Extension\StorageInterface;
use App\Support\ContentMedia\AbstractContentMediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 페이지 본문 미디어 흐름 — 코어 골격(`AbstractContentMediaService`)에 페이지 어댑터와 모듈 저장소를 넘긴 얇은 클래스 (#887)
 *
 * 업로드·저장 동기화(연결 → 복제 → 제거)·서명·서빙·영구 삭제·정리는 골격이 돌리고, 페이지 몫(행 표·게이트·버전 참조 제거 정책)은
 * `PageContentMediaStore` 가 답한다. 페이지 서비스·리소스·컨트롤러는 이 클래스를 직접 부르지 않고 `PageContentMediaService`
 * (#883 부터의 공개 메서드를 유지하는 진입점)를 거친다. 표준 계약 엔드포인트(`RespondsWithContentMediaTarget`)처럼 골격 인스턴스가
 * 필요한 자리만 `PageContentMediaService::flow()` 로 이 클래스를 받는다.
 *
 * 저장소는 모듈 서비스 프로바이더가 넣는다(`$storageServices`) — 새 파일은 그 디스크에 쓰고, 기존 행은 행에 기록된 디스크
 * (`storageFor()` → `RowDiskStorage`)에서 읽고·복사하고·지운다.
 */
class PageContentMediaFlow extends AbstractContentMediaService
{
    /**
     * @param  PageContentMediaStore  $store  페이지 어댑터
     * @param  StorageInterface  $storage  모듈 저장소 (새 파일을 쓰는 기본 디스크)
     */
    public function __construct(PageContentMediaStore $store, StorageInterface $storage)
    {
        parent::__construct($store, $storage);
    }

    /**
     * 오래된 임시 본문 미디어를 정리하고, 파일을 모두 지워 비워진 날짜 디렉터리(`content-media/Y/m/d`)도 지웁니다.
     *
     * 골격 정리(`before_delete` → 행 디스크에서 파일 삭제 → 행 삭제) 뒤, 이번 회차 대상 행이 있던 디렉터리마다 그 행의 디스크에서
     * 남은 파일이 없을 때만 디렉터리를 지운다 — 종전 임시 첨부 정리 명령이 본문 미디어 임시 행까지 함께 처리하던 때의 동작이다
     * (#887 에서 본문 미디어 정리를 골격으로 옮기며 유지). 같은 날짜에 페이지에 이어진 파일이 있으면 디렉터리는 남는다.
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  종류마다 최대 건수
     * @param  bool  $dryRun  true 면 세기만
     * @return array{scanned: int, deleted: int, failed: int} 결과
     */
    public function pruneStale(int $days, int $limit, bool $dryRun = false): array
    {
        $targets = $dryRun ? collect() : $this->store->staleRows(Carbon::now()->subDays($days), $limit);

        $result = parent::pruneStale($days, $limit, $dryRun);

        if (! $dryRun) {
            $this->removeEmptyDirectories($targets);
        }

        return $result;
    }

    /**
     * 행들이 있던 디렉터리 중 파일이 하나도 남지 않은 것을 그 행의 디스크에서 지웁니다.
     *
     * @param  Collection<int, Model>  $rows  이번 회차 정리 대상 행
     * @return void 반환값 없음
     */
    private function removeEmptyDirectories(Collection $rows): void
    {
        $category = $this->store->storageCategory();
        $directories = [];

        foreach ($rows as $row) {
            $directory = dirname($this->store->storagePathOf($row));
            if ($directory === '' || $directory === '.') {
                continue;
            }

            $directories[$row->getAttribute('disk').'|'.$directory] = [$row, $directory];
        }

        foreach ($directories as [$row, $directory]) {
            $storage = $this->storageFor($row);

            if ($storage->files($category, $directory) !== []) {
                continue;
            }

            $storage->deleteDirectory($category, $directory);
        }
    }
}
