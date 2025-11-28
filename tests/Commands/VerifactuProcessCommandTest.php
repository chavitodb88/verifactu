<?php

declare(strict_types=1);

namespace Tests\Commands;

use App\Commands\VerifactuProcess;
use App\Models\BillingHashModel;
use Config\Services;
use Tests\Support\ApiTestCase;

final class VerifactuProcessCommandTest extends ApiTestCase
{
    /**
     * Procesa un billing_hash en estado ready y lo marca como accepted
     * cuando el servicio Verifactu no lanza errores.
     */
    public function test_processes_ready_item_and_marks_as_accepted(): void
    {
        $bhModel = new BillingHashModel();
        $now     = date('Y-m-d H:i:s');

        $id = $bhModel->insert([
            'company_id'      => 1,
            'series'          => 'F2025',
            'number'          => '1',
            'issue_date'      => '2025-11-20',
            'issuer_nif'      => 'B61206934',
            'kind'            => 'alta',
            'status'          => 'ready',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // Servicio Verifactu falso que simula un envío correcto.
        $fakeSvc = new class {
            public ?int $lastId = null;

            public function sendToAeat(int $billingHashId): void
            {
                $this->lastId = $billingHashId;

                $model = new BillingHashModel();
                $model->update($billingHashId, [
                    'status'        => 'accepted',
                    'processing_at' => null,
                ]);
            }
        };

        Services::injectMock('verifactu', $fakeSvc);

        $command = new VerifactuProcess(
            service('logger'),
            service('commands')
        );

        $command->run([]);

        $row = $bhModel->find($id);

        $this->assertSame($id, $fakeSvc->lastId);
        $this->assertSame('accepted', $row['status']);
        $this->assertNull($row['processing_at']);
    }

    /**
     * Cuando el servicio lanza una excepción, el comando debe marcar el registro
     * como error y reprogramar el siguiente intento a ~15 minutos vista.
     */
    public function test_schedules_retry_when_service_throws(): void
    {
        $bhModel = new BillingHashModel();
        $now     = date('Y-m-d H:i:s');

        $id = $bhModel->insert([
            'company_id' => 1,
            'series'     => 'F2025',
            'number'     => '2',
            'issue_date' => '2025-11-20',
            'issuer_nif' => 'B61206934',
            'kind'       => 'alta',
            'status'     => 'ready',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fakeSvc = new class {
            public ?int $lastId = null;

            public function sendToAeat(int $billingHashId): void
            {
                $this->lastId = $billingHashId;
                throw new \RuntimeException('SOAP down');
            }
        };

        Services::injectMock('verifactu', $fakeSvc);

        $before = new \DateTimeImmutable();

        $command = new VerifactuProcess(
            service('logger'),
            service('commands')
        );

        $command->run([]);

        $row = $bhModel->find($id);

        $this->assertSame($id, $fakeSvc->lastId);
        $this->assertSame('error', $row['status']);
        $this->assertNull($row['processing_at']);
        $this->assertNotNull($row['next_attempt_at']);

        $next = new \DateTimeImmutable($row['next_attempt_at']);
        $diff = $next->getTimestamp() - $before->getTimestamp();

        $this->assertGreaterThanOrEqual(14 * 60, $diff);
        $this->assertLessThanOrEqual(16 * 60, $diff);
    }

    /**
     * Si no hay elementos en cola, el comando no debe llamar al servicio.
     */
    public function test_when_there_are_no_items_command_does_not_call_service(): void
    {
        $bhModel = new BillingHashModel();
        $this->assertSame(0, $bhModel->countAll());

        $fakeSvc = new class {
            public bool $called = false;

            public function sendToAeat(int $billingHashId): void
            {
                $this->called = true;
            }
        };

        Services::injectMock('verifactu', $fakeSvc);

        $command = new VerifactuProcess(
            service('logger'),
            service('commands')
        );

        $command->run([]);

        $this->assertFalse($fakeSvc->called);
        $this->assertSame(0, $bhModel->countAll());
    }

    /**
     * Los registros con next_attempt_at en el futuro no deben ser procesados.
     */
    public function test_items_with_future_next_attempt_are_not_processed(): void
    {
        $bhModel = new BillingHashModel();
        $now     = date('Y-m-d H:i:s');

        $processableId = $bhModel->insert([
            'company_id'      => 1,
            'series'          => 'F2025',
            'number'          => '10',
            'issue_date'      => '2025-11-20',
            'issuer_nif'      => 'B61206934',
            'kind'            => 'alta',
            'status'          => 'ready',
            'next_attempt_at' => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $futureId = $bhModel->insert([
            'company_id'      => 1,
            'series'          => 'F2025',
            'number'          => '11',
            'issue_date'      => '2025-11-20',
            'issuer_nif'      => 'B61206934',
            'kind'            => 'alta',
            'status'          => 'error',
            'next_attempt_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $fakeSvc = new class {
            /** @var int[] */
            public array $calledIds = [];

            public function sendToAeat(int $billingHashId): void
            {
                $this->calledIds[] = $billingHashId;

                $model = new BillingHashModel();
                $model->update($billingHashId, [
                    'status'        => 'accepted',
                    'processing_at' => null,
                ]);
            }
        };

        Services::injectMock('verifactu', $fakeSvc);

        $command = new VerifactuProcess(
            service('logger'),
            service('commands')
        );

        $command->run([]);

        sort($fakeSvc->calledIds);

        $this->assertSame([$processableId], $fakeSvc->calledIds);

        $processedRow = $bhModel->find($processableId);
        $futureRow    = $bhModel->find($futureId);

        $this->assertSame('accepted', $processedRow['status']);
        $this->assertSame('error', $futureRow['status']);
    }

    /**
     * El parámetro limit del comando restringe el número de elementos procesados.
     */
    public function test_respects_limit_parameter(): void
    {
        $bhModel = new BillingHashModel();
        $now     = date('Y-m-d H:i:s');

        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $bhModel->insert([
                'company_id' => 1,
                'series'     => 'F2025',
                'number'     => (string) $i,
                'issue_date' => '2025-11-20',
                'issuer_nif' => 'B61206934',
                'kind'       => 'alta',
                'status'     => 'ready',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $fakeSvc = new class {
            /** @var int[] */
            public array $calledIds = [];

            public function sendToAeat(int $billingHashId): void
            {
                $this->calledIds[] = $billingHashId;

                $model = new BillingHashModel();
                $model->update($billingHashId, [
                    'status'        => 'accepted',
                    'processing_at' => null,
                ]);
            }
        };

        Services::injectMock('verifactu', $fakeSvc);

        $command = new VerifactuProcess(
            service('logger'),
            service('commands')
        );

        $command->run(['1']);

        $this->assertCount(1, $fakeSvc->calledIds);
        $this->assertContains($fakeSvc->calledIds[0], $ids);
    }
}
