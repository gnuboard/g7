<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Exports\Formats\CsvExportWriter;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Models\ExportDownload;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 데이터 내보내기의 회원 이름 (#490 9단계, P22)
 *
 * | 자리 | 이름 |
 * | --- | --- |
 * | 새 요청의 요청자 스냅샷, 다운로드 이력의 받은 사람 스냅샷 | 그 시점의 이커머스 관리자 영역 표시 이름 |
 * | 이미 남은 스냅샷 | 설정이나 회원 이름을 바꿔도 그대로 (기록물) |
 * | 내보낸 파일의 회원 칸(마일리지의 회원과 지급자, 주문의 주문자) | 실명. 이름이 없으면 빈 칸 |
 */
class ExportDisplayNameTest extends ExportTestCase
{
    private const PERMISSIONS = [
        'sirsoft-ecommerce.exports.create',
        'sirsoft-ecommerce.exports.read',
        'sirsoft-ecommerce.exports.download',
        'sirsoft-ecommerce.exports.delete',
        'sirsoft-ecommerce.exports.personal_data',
        'sirsoft-ecommerce.orders.read',
        'sirsoft-ecommerce.mileage.read',
        'core.users.read',
    ];

    private User $admin;

    /**
     * 큐를 가짜로 두고 표시 이름 설정을 기본값으로 되돌린 뒤 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateExportJob::class]);
        $this->areas(null);

        $this->admin = $this->createAdminUser(self::PERMISSIONS);
        $this->admin->forceFill(['name' => '관리자본명', 'nickname' => '운영자'])->save();
        $this->admin->refresh();
    }

    /**
     * 영역 설정을 바꿉니다.
     *
     * @param  array<string, array<string, mixed>>|null  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    private function areas(?array $areas): void
    {
        config(['g7_settings.core.member.display_name_areas' => $areas]);
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }

    /**
     * 마일리지 내역 내보내기를 요청하고 파일 생성까지 마칩니다.
     *
     * @param  array<int, int>  $ids  거래 ID
     * @param  array<int, string>  $fields  항목 키
     * @return Export 작업
     */
    private function exportMileage(array $ids, array $fields): Export
    {
        return $this->export([
            'type' => 'mileage', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => $fields,
            'row_unit' => 'transaction', 'scope' => 'selected', 'selected_ids' => $ids,
            'locale_mode' => 'locale', 'include_totals' => false, 'reason' => '표시 이름 점검',
        ]);
    }

    /**
     * 내보내기를 요청하고 파일 생성까지 마칩니다.
     *
     * @param  array<string, mixed>  $body  요청 본문
     * @return Export 작업
     */
    private function export(array $body): Export
    {
        $this->travel(1)->seconds();
        $id = (int) $this->actingAs($this->admin)
            ->postJson($this->exportsUrl(), $body, ['Idempotency-Key' => 'dn-'.uniqid()])
            ->assertStatus(202)->json('data.id');
        app(ExportService::class)->generate($id);

        return Export::findOrFail($id);
    }

    /**
     * 작업의 파일을 내려받아 행 배열로 풉니다.
     *
     * @param  Export  $export  작업
     * @return array<int, array<int, string>> 행 (머리 줄 포함)
     */
    private function downloadRows(Export $export): array
    {
        $content = $this->actingAs($this->admin)->get($this->exportsUrl("/{$export->id}/download"))->assertOk()->streamedContent();
        $this->assertStringStartsWith(CsvExportWriter::BOM, $content);
        $lines = array_values(array_filter(explode("\r\n", substr($content, strlen(CsvExportWriter::BOM))), fn (string $line) => $line !== ''));

        return array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), $lines);
    }

    /**
     * 마일리지 적립 거래를 만듭니다.
     *
     * @param  User  $user  회원
     * @param  User|null  $grantedBy  지급한 관리자
     * @return MileageTransaction 거래
     */
    private function mileage(User $user, ?User $grantedBy = null): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000, 'granted_by' => $grantedBy?->id,
        ]);
    }

    /**
     * 새 요청의 요청자 스냅샷과 다운로드 이력의 받은 사람 스냅샷은 그 시점의 이커머스 관리자 영역 표시 이름이다.
     *
     * @scenario case=export_new_snapshot_is_admin_area_display_name
     *
     * @effects export_requester_snapshot_is_admin_area_display_name, export_download_snapshot_is_admin_area_display_name, export_snapshot_keeps_length_limit
     *
     * @return void 반환값 없음
     */
    public function test_new_snapshots_store_admin_area_display_name(): void
    {
        $member = $this->createUser();
        $transaction = $this->mileage($member);

        // 기본 설정: 관리자 영역이 이름 고정이라 종전과 같이 이름이 남는다
        $default = $this->exportMileage([$transaction->id], ['mileage.created_at']);
        $this->assertSame('관리자본명', $default->requester_name_snapshot);

        $this->areas(['sirsoft-ecommerce:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $export = $this->exportMileage([$transaction->id], ['mileage.created_at']);
        $this->assertSame('운영자', $export->requester_name_snapshot);

        $this->downloadRows($export);
        $this->assertSame('운영자', ExportDownload::where('export_id', $export->id)->value('user_name_snapshot'));

        // 긴 표시 이름(이름(닉네임) 종류로 112자)은 종전과 같은 규칙(표시 폭 97 뒤에 말줄임)으로 잘린다
        $this->areas(['sirsoft-ecommerce:admin' => ['mode' => 'fixed', 'kind' => 'name_nickname']]);
        $this->admin->forceFill(['name' => str_repeat('가', 90), 'nickname' => str_repeat('나', 20)])->save();
        $long = $this->exportMileage([$transaction->id], ['mileage.created_at']);
        $this->assertSame(Str::limit(str_repeat('가', 90).'('.str_repeat('나', 20).')', 97), $long->requester_name_snapshot);
        $this->assertStringEndsWith('...', $long->requester_name_snapshot);
        $this->assertLessThanOrEqual(100, mb_strlen($long->requester_name_snapshot));
    }

    /**
     * 이미 남은 스냅샷은 표시 이름 설정이나 회원 이름을 바꿔도 그대로이고, 이력 응답의 요청자와 받은 사람은 저장된 스냅샷을 그대로 보인다 (R11).
     *
     * @scenario case=export_past_snapshot_is_immutable
     *
     * @effects export_history_requester_is_stored_snapshot, export_download_history_user_is_stored_snapshot, export_past_snapshot_unchanged_by_setting_or_rename
     *
     * @return void 반환값 없음
     */
    public function test_r11_past_snapshots_are_kept_and_history_shows_them_as_stored(): void
    {
        $member = $this->createUser();
        $export = $this->exportMileage([$this->mileage($member)->id], ['mileage.created_at']);
        $this->downloadRows($export);
        $this->assertSame('관리자본명', $export->requester_name_snapshot);

        // 설정을 닉네임으로 바꾸고 회원 이름도 바꾼다
        $this->areas([
            'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'sirsoft-ecommerce:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
        ]);
        $this->admin->forceFill(['name' => '개명한이름', 'nickname' => '새별명'])->save();

        $this->assertSame('관리자본명', $export->fresh()->requester_name_snapshot);
        $this->assertSame('관리자본명', ExportDownload::where('export_id', $export->id)->value('user_name_snapshot'));

        $row = collect($this->actingAs($this->admin)->getJson($this->exportsUrl())->assertOk()->json('data.data'))->firstWhere('id', $export->id);
        $this->assertSame('관리자본명', $row['requester']['name'], '이력의 요청자가 저장된 스냅샷이 아니라 지금 이름으로 나갔다');
        $this->assertSame($this->admin->uuid, $row['requester']['uuid']);

        $detail = $this->actingAs($this->admin)->getJson($this->exportsUrl("/{$export->id}"))->assertOk()->json('data');
        $this->assertSame('관리자본명', $detail['requester']['name']);

        $downloads = $this->actingAs($this->admin)->getJson($this->exportsUrl("/{$export->id}/downloads"))->assertOk()->json('data.data');
        $this->assertSame('관리자본명', $downloads[0]['user']['name']);
    }

    /**
     * 요청자 검색은 남긴 이름과 함께 지금 회원의 이름, 닉네임, 이메일을 찾는다.
     *
     * @scenario case=export_requester_search_matches_name_and_nickname
     *
     * @effects export_requester_search_matches_snapshot, export_requester_search_matches_nickname, export_requester_search_keeps_email
     *
     * @return void 반환값 없음
     */
    public function test_requester_search_matches_snapshot_name_nickname_and_email(): void
    {
        $export = $this->exportMileage([$this->mileage($this->createUser())->id], ['mileage.created_at']);
        $ids = fn (string $keyword): array => array_column(
            $this->actingAs($this->admin)->getJson($this->exportsUrl('?'.http_build_query(['search_field' => 'requester', 'search_keyword' => $keyword])))->assertOk()->json('data.data'),
            'id',
        );

        $this->assertSame([$export->id], $ids('관리자본'), '남긴 이름으로 찾지 못했다');
        $this->assertSame([$export->id], $ids('운영자'), '닉네임으로 찾지 못했다');
        $this->assertSame([$export->id], $ids($this->admin->email), '이메일로 찾지 못했다');
        $this->assertSame([], $ids('없는사람'));
    }

    /**
     * 내보낸 파일의 회원 칸은 표시 이름 설정과 무관하게 실명이고, 이름이 없는 회원은 빈 칸으로 나가며 오류가 없다 (R11).
     *
     * @scenario case=export_file_member_columns_are_real_names
     *
     * @effects export_file_mileage_member_is_real_name, export_file_mileage_granter_is_real_name, export_file_order_orderer_is_entered_name, export_file_null_name_is_blank
     *
     * @return void 반환값 없음
     */
    public function test_r11_exported_file_keeps_real_names_and_blank_for_null_name(): void
    {
        $this->areas([
            'core:site' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
        ]);

        $member = $this->createUser();
        $member->forceFill(['name' => '홍길동', 'nickname' => '길동이'])->save();
        $nameless = $this->createUser();
        $nameless->forceFill(['name' => null, 'nickname' => '무명씨'])->save();
        $namelessGranter = $this->createAdminUser([]);
        $namelessGranter->forceFill(['name' => null, 'nickname' => '이름없는관리자'])->save();

        $first = $this->mileage($member, $this->admin);
        $second = $this->mileage($nameless, $namelessGranter);

        $export = $this->exportMileage([$first->id, $second->id], ['mileage.member_name', 'mileage.member_uuid', 'mileage.granted_by_name']);
        $this->assertSame(ExportStatusEnum::COMPLETED, $export->status, '이름이 없는 회원 때문에 파일 생성이 실패했다');

        $rows = collect($this->downloadRows($export))->slice(1)->keyBy(fn (array $row) => $row[1]);
        $this->assertSame(['홍길동', '관리자본명'], [$rows[$member->uuid][0], $rows[$member->uuid][2]]);
        $this->assertSame(['', ''], [$rows[$nameless->uuid][0], $rows[$nameless->uuid][2]], '이름이 없는 회원의 칸이 비지 않았다');

        // 주문의 주문자 칸은 주문서에 적힌 이름이다
        $order = OrderFactory::new()->paid()->create(['user_id' => $member->id]);
        OrderOptionFactory::new()->create(['order_id' => $order->id]);
        OrderAddressFactory::new()->create(['order_id' => $order->id, 'address_type' => 'shipping', 'orderer_name' => '홍길동']);
        $orderExport = $this->export([
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.order_number', 'address.orderer_name'],
            'row_unit' => 'order', 'scope' => 'selected', 'selected_ids' => [$order->id],
            'locale_mode' => 'locale', 'include_totals' => false, 'reason' => '표시 이름 점검',
        ]);
        $orderRows = $this->downloadRows($orderExport);
        $this->assertSame([$order->order_number, '홍길동'], $orderRows[1]);
    }
}
