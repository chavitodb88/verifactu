<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Domain\Verifactu\CancellationMode;
use App\Models\BillingHashModel;
use App\Models\SubmissionsModel;
use App\Services\VerifactuCanonicalService;
use App\Services\VerifactuService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class VerifactuServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh   = true;
    protected $migrate   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\TestSeeder::class;

    private VerifactuService $service;
    private BillingHashModel $billing;
    private SubmissionsModel $submissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service     = new VerifactuService();
        $this->billing     = new BillingHashModel();
        $this->submissions = new SubmissionsModel();
    }

    public function test_createCancellation_copies_fields_and_builds_chain_correctly(): void
    {
        // Previo en la cadena
        $companyId = 1;
        $issuerNif = 'B61206934';

        $this->billing->insert([
            'company_id'      => $companyId,
            'issuer_nif'      => $issuerNif,
            'series'          => 'F2025',
            'number'          => 9,
            'issue_date'      => '2025-11-20',
            'kind'            => 'alta',
            'status'          => 'accepted',
            'hash'            => 'OLDHASH',
            'chain_index'     => 1,
            'csv_text'        => 'dummy-prev',
            'datetime_offset' => '2025-11-20T10:00:00+01:00',
        ], true);

        // Factura original para anular
        $originalId = $this->billing->insert([
            'company_id'      => $companyId,
            'issuer_nif'      => $issuerNif,
            'series'          => 'F2025',
            'number'          => 10,
            'issue_date'      => '2025-11-21',
            'kind'            => 'alta',
            'status'          => 'accepted',
            'external_id'     => 'ERP-10',
            'hash'            => 'ORIGHASH',
            'prev_hash'       => 'OLDHASH',
            'chain_index'     => 2,
            'csv_text'        => 'dummy-orig',
            'datetime_offset' => '2025-11-21T10:00:00+01:00',
        ], true);

        $originalRow = $this->billing->find($originalId);

        $cancelRow = $this->service->createCancellation($originalRow, 'Factura emitida por error');

        // Datos base
        $this->assertSame('anulacion', $cancelRow['kind']);
        $this->assertSame('ready', $cancelRow['status']);
        $this->assertSame($issuerNif, $cancelRow['issuer_nif']);
        $this->assertSame($originalRow['series'], $cancelRow['series']);
        $this->assertSame((string)$originalRow['number'], (string)$cancelRow['number']);
        $this->assertSame('ERP-10', $cancelRow['external_id']);
        $this->assertSame($originalId, (int)$cancelRow['original_billing_hash_id']);
        $this->assertSame('Factura emitida por error', $cancelRow['cancel_reason']);

        // Modo de anulación calculado
        $this->assertContains(
            $cancelRow['cancellation_mode'],
            [
                CancellationMode::NO_AEAT_RECORD->value,
                CancellationMode::AEAT_REGISTERED->value,
                CancellationMode::PREVIOUS_CANCELLATION_REJECTED->value,
            ]
        );

        // Encadenamiento
        $this->assertSame('ORIGHASH', $cancelRow['prev_hash']);
        $this->assertGreaterThan(2, (int)$cancelRow['chain_index']);

        // Cadena + hash consistentes
        $expectedHash = VerifactuCanonicalService::sha256Upper($cancelRow['csv_text']);
        $this->assertSame($expectedHash, $cancelRow['hash']);

        // Totales técnicos
        $this->assertSame(0.0, (float)$cancelRow['vat_total']);
        $this->assertSame(0.0, (float)$cancelRow['gross_total']);
    }

    public function test_determineCancellationMode_no_previous_submissions(): void
    {
        $id = $this->billing->insert([
            'company_id'  => 1,
            'issuer_nif'  => 'B61206934',
            'series'      => 'F2025',
            'number'      => 20,
            'issue_date'  => '2025-11-22',
            'kind'        => 'alta',
            'status'      => 'accepted',
        ], true);

        $row       = $this->billing->find($id);
        $cancelRow = $this->service->createCancellation($row);

        $this->assertSame(CancellationMode::NO_AEAT_RECORD->value, $cancelRow['cancellation_mode']);
    }

    public function test_determineCancellationMode_register_accepted(): void
    {
        $id = $this->billing->insert([
            'company_id'  => 1,
            'issuer_nif'  => 'B61206934',
            'series'      => 'F2025',
            'number'      => 21,
            'issue_date'  => '2025-11-23',
            'kind'        => 'alta',
            'status'      => 'accepted',
        ], true);

        $this->submissions->insert([
            'billing_hash_id' => $id,
            'type'            => 'register',
            'status'          => 'accepted',
        ]);

        $row       = $this->billing->find($id);
        $cancelRow = $this->service->createCancellation($row);

        $this->assertSame(CancellationMode::AEAT_REGISTERED->value, $cancelRow['cancellation_mode']);
    }

    public function test_determineCancellationMode_rejected_cancel_has_priority(): void
    {
        $id = $this->billing->insert([
            'company_id'  => 1,
            'issuer_nif'  => 'B61206934',
            'series'      => 'F2025',
            'number'      => 22,
            'issue_date'  => '2025-11-24',
            'kind'        => 'alta',
            'status'      => 'accepted',
        ], true);

        $this->submissions->insert([
            'billing_hash_id' => $id,
            'type'            => 'register',
            'status'          => 'accepted',
        ]);

        $this->submissions->insert([
            'billing_hash_id' => $id,
            'type'            => 'cancel',
            'status'          => 'rejected',
        ]);

        $row       = $this->billing->find($id);
        $cancelRow = $this->service->createCancellation($row);

        $this->assertSame(
            CancellationMode::PREVIOUS_CANCELLATION_REJECTED->value,
            $cancelRow['cancellation_mode']
        );
    }

    public function test_scheduleRetry_inserts_error_and_updates_billing_hash(): void
    {
        $id = $this->billing->insert([
            'company_id' => 1,
            'issuer_nif' => 'B61206934',
            'series'     => 'F2025',
            'number'     => 30,
            'issue_date' => '2025-11-25',
            'kind'       => 'alta',
            'status'     => 'ready',
        ], true);

        $row = $this->billing->find($id);

        $ref = new \ReflectionMethod(VerifactuService::class, 'scheduleRetry');
        $ref->setAccessible(true);
        $ref->invoke($this->service, $row, $this->billing, 'Error SOAP simulado');

        $subs = $this->submissions->where('billing_hash_id', $id)->findAll();
        $this->assertCount(1, $subs);

        $this->assertSame('error', $subs[0]['status']);
        $this->assertSame('Error SOAP simulado', $subs[0]['error_message']);

        $updated = $this->billing->find($id);

        $this->assertSame('error', $updated['status']);
        $this->assertNotEmpty($updated['next_attempt_at']);
    }
}
