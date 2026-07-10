<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_2\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * 배송가능 국가명(available_countries[].name)의 빈 로케일 키를 제거.
 *
 * 구 국가 추가 폼은 한국어/영문 두 입력칸만 렌더했고, 운영자가 한쪽을 비운 채 저장하면
 * `{"ko":"프랑스","en":""}` 처럼 빈 문자열이 저장본에 박혔다. 빈 문자열은 "값이 있음" 도
 * "부재" 도 아닌 어중간한 상태다.
 *
 * 이 시스템의 계약은 "부재 로케일은 비워 둔다" 이다 — 기본 국가는 언어팩
 * (`sirsoft-ecommerce::settings.countries.{code}.name`)이 읽기 시점에 보강하고
 * (EcommerceSettingsService::getAllSettings), 운영자가 직접 추가한 국가는 운영자가 채운다.
 * 따라서 빈 문자열 키를 제거해 "부재" 로 정규화한다.
 *
 * 이름을 새로 채우지는 않는다 — 언어팩 보강이 그 역할을 하며, 저장본에 값을 박으면
 * 운영자 편집값으로 승격되어 이후 언어팩 갱신이 반영되지 않는다.
 *
 * name 이 배열이 아닌 구 스키마 잔재(문자열)는 이 마이그레이션의 책임이 아니라 건드리지 않는다.
 *
 * idempotent: 빈 키가 없고 모든 값이 trim 상태면 no-op (파일 쓰기 없음).
 *
 * V-1 안전: Illuminate\Support\Facades\File + 로컬 헬퍼만 사용.
 */
class PruneEmptyShippingCountryNameLocales implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    public function name(): string
    {
        return 'PruneEmptyShippingCountryNameLocales';
    }

    public function run(UpgradeContext $context): void
    {
        $path = $this->settingsFilePath();

        if (! File::exists($path)) {
            $context->logger->info('[ecommerce:1.0.2] shipping.json 미존재 — 국가명 빈 로케일 청소 스킵');

            return;
        }

        $settings = json_decode((string) File::get($path), true);
        if (! is_array($settings) || ! isset($settings['available_countries']) || ! is_array($settings['available_countries'])) {
            $context->logger->info('[ecommerce:1.0.2] shipping.json 에 배송가능 국가 목록 없음 — 국가명 빈 로케일 청소 스킵');

            return;
        }

        $countries = $settings['available_countries'];
        $prunedKeys = 0;

        foreach ($countries as $idx => $country) {
            $name = $country['name'] ?? null;

            // 구 스키마 잔재(문자열 name)는 대상 아님
            if (! is_array($name)) {
                continue;
            }

            $cleaned = $this->pruneEmptyLocales($name);

            if ($cleaned !== $name) {
                $prunedKeys += count($name) - count($cleaned);
                $countries[$idx]['name'] = $cleaned;
            }
        }

        if ($countries === $settings['available_countries']) {
            $context->logger->info('[ecommerce:1.0.2] 국가명에 빈 로케일 키 없음 — 변경 없음 (idempotent)');

            return;
        }

        $settings['available_countries'] = $countries;

        // 인코딩 플래그는 EcommerceSettingsService 의 저장부와 동일해야 한다.
        // 다르면 이 스텝이 국가명과 무관한 값(URL 등)의 이스케이프까지 조용히 바꿔 쓴다.
        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $context->logger->info(sprintf(
            '[ecommerce:1.0.2] 국가명 빈 로케일 키 %d개 제거 완료 (국가 %d개 검사)',
            $prunedKeys,
            count($countries),
        ));
    }

    /**
     * 다국어 이름 맵에서 빈 값(공백 포함) 로케일 키를 제거하고 남은 값을 trim 합니다.
     *
     * 모든 로케일이 비어 있으면 빈 배열을 반환합니다 — null 이 아니라 빈 배열이어야
     * 백엔드 검증(`shipping.available_countries.*.name` => array)이 깨지지 않습니다.
     *
     * @param  array<string, mixed>  $name  로케일 => 국가명 맵
     * @return array<string, string> 빈 로케일이 제거되고 trim 된 맵
     */
    private function pruneEmptyLocales(array $name): array
    {
        $cleaned = [];

        foreach ($name as $locale => $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $cleaned[$locale] = $trimmed;
        }

        return $cleaned;
    }

    /**
     * shipping.json 의 저장 경로를 반환합니다.
     *
     * 테스트 환경에서는 운영 storage 오염을 막기 위해 framework/testing 경로를 사용합니다
     * (EcommerceSettingsService 의 저장 경로 분기와 동일).
     *
     * @return string 설정 파일 절대 경로
     */
    private function settingsFilePath(): string
    {
        $base = app()->runningUnitTests()
            ? 'framework/testing/modules/'
            : 'app/modules/';

        return storage_path($base.self::MODULE_IDENTIFIER.'/settings/shipping.json');
    }
}
