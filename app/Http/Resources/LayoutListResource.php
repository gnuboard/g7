<?php

namespace App\Http\Resources;

use App\Support\LayoutDescription;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * 레이아웃 **목록** 전용 리소스
 *
 * 코드 편집 화면의 파일 목록은 행마다 이름·설명·라우트·크기·수정일·업데이트 표시만 쓴다.
 * 편집 대상 본문은 선택 시 상세 엔드포인트(`current_layout`)가 따로 받아온다.
 *
 * 종전에는 목록·상세가 {@see LayoutResource} 하나를 공용해 목록 응답에도 각 레이아웃의
 * `content`(본문 전체) · `components` · `data_sources` · `metadata` 가 실렸다.
 * 실측(sirsoft-admin_basic): 응답 18.30MB 중 `content` 가 17.41MB(95%). 화면은 102개 중
 * 1개 본문만 필요한데 101개를 더 받아 전역 상태에 상주시켰고, 디버그 모드에서는 그 상태를
 * 추적하다 렌더러가 메모리 한계를 넘었다.
 *
 * 본문에서 파생되는 값(`description` · `size`)은 조회 계층이 미리 계산해 넘긴다 —
 * 이 리소스는 본문을 보지 않는다.
 *
 * @since 7.0.6
 */
class LayoutListResource extends BaseApiResource
{
    /** @var array<string, string|null> 레이아웃 이름 → 라우트 path 매핑 */
    private array $routePathMap = [];

    /** @var array<string, mixed> 소유 템플릿의 프론트엔드 다국어 사전 (점 표기 중첩) */
    private array $translations = [];

    /**
     * 라우트 path 매핑을 주입합니다.
     *
     * @param  array<string, string|null>  $routePathMap  레이아웃 이름 → 라우트 path
     * @return $this
     */
    public function withRoutePathMap(array $routePathMap): self
    {
        $this->routePathMap = $routePathMap;

        return $this;
    }

    /**
     * 설명 해석에 쓸 소유 템플릿 사전을 주입합니다.
     *
     * @param  array  $translations  템플릿 프론트엔드 다국어 데이터
     * @return $this
     */
    public function withTranslations(array $translations): self
    {
        $this->translations = $translations;

        return $this;
    }

    /**
     * 목록 행을 배열로 변환합니다.
     *
     * @param  Request  $request  요청 객체
     * @return array 목록 행 데이터
     */
    public function toArray(Request $request): array
    {
        $name = $this->getValue('name');
        $size = (int) $this->getValue('size', 0);

        return [
            'id' => $this->getValue('id'),
            'template_id' => $this->getValue('template_id'),
            'name' => $name,
            'description' => LayoutDescription::resolve($this->getValue('description'), $name, $this->translations),

            // 이 레이아웃을 사용하는 라우트의 path (routes.json 기준). 파일 선택 시 ?route=
            // 동기화 / 위지윅에서 넘어온 ?route= 로 해당 파일 복원에 사용.
            'route_path' => $this->routePathMap[$name] ?? null,

            'size' => $size,
            'size_formatted' => $this->formatFileSize($size),
            'has_update' => false,

            // 낙관적 잠금 — 편집 진입 후 저장 요청에 expected_lock_version 으로 전달
            'lock_version' => (int) $this->getValue('lock_version', 0),

            // 상세 응답(LayoutResource)의 formatTimestamps() 와 같은 규약 — 사용자 timezone
            // 기준 문자열이다. 원시 ISO 를 그대로 내보내면 목록의 수정일 표기만 형식이 달라진다.
            'created_at' => $this->formatDateTimeStringForUser($this->toCarbon($this->getValue('created_at'))),
            'updated_at' => $this->formatDateTimeStringForUser($this->toCarbon($this->getValue('updated_at'))),
        ];
    }

    /**
     * 값을 Carbon 인스턴스로 변환합니다.
     *
     * 경량 조회는 배열 행을 넘기므로 값이 Carbon 일 수도, 문자열일 수도, null 일 수도 있다.
     *
     * @param  mixed  $value  변환할 값
     * @return Carbon|null 변환된 Carbon 또는 null
     */
    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    /**
     * 파일 크기를 사람이 읽는 단위로 변환합니다.
     *
     * @param  int  $bytes  바이트 수
     * @return string 변환된 크기 문자열
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
