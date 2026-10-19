<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Service\Logbook\OdometerSource\ConnectContext;
use MyInvoice\Service\Logbook\OdometerSource\ConnectStep;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSample;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceDefinition;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceProvider;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceSession;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceVehicle;

/** Falešný zdroj pro test jádra: každé volání vehicles()/readOdometer() je jedno volání služby. */
final class FakeOdometerSource implements OdometerSourceProvider
{
    public const CODE = 'fake_source';

    public bool $approved = false;
    public bool $slowDown = false;
    public bool $denied = false;
    public ?string $rotateTo = null;
    public int $reads = 0;
    public ?int $dailyLimit = null;
    /** @var list<ConnectContext> */
    public array $contexts = [];
    /** @var list<array<string,string|int>> přístupy, se kterými se četlo */
    public array $credentialsSeen = [];
    /** @var list<array<string,string|int>> */
    public array $pendingSeen = [];
    /** @var list<OdometerSourceVehicle> */
    public array $vehicles;
    /** @var array<string,OdometerSample> */
    public array $samples = [];
    /** @var array<string,string> */
    public array $failFor = [];
    /** Neočekávaná chyba při čtení (např. z HTTP klienta). */
    public ?\Throwable $crash = null;

    public function __construct()
    {
        $this->vehicles = [new OdometerSourceVehicle('WBA00000000000001', 'WBA00000000000001', null, true)];
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function definition(): OdometerSourceDefinition
    {
        return new OdometerSourceDefinition(self::CODE, 'Fake', [['key' => 'key']], $this->dailyLimit);
    }

    public function startConnect(array $input, ConnectContext $context): ConnectStep
    {
        $this->contexts[] = $context;
        return ConnectStep::deviceCode('ABCD1234', 'https://login.example.test/link', 5, 300,
            ['key' => $input['key'], 'device' => 'synthetic-device']);
    }

    public function continueConnect(array $pending, array $input): ConnectStep
    {
        $this->pendingSeen[] = $pending;
        if ($this->denied) throw new OdometerSourceException(OdometerSourceException::ACCESS_DENIED);
        if ($this->slowDown) {
            $this->slowDown = false;
            return ConnectStep::waiting(10, ['device' => 'slow'] + $pending);
        }
        return $this->approved ? ConnectStep::connected(['token' => 'synthetic-token-1']) : ConnectStep::waiting(5);
    }

    public function vehicles(OdometerSourceSession $session): array
    {
        $session->spendCalls();
        return $this->vehicles;
    }

    public function readOdometer(OdometerSourceSession $session, string $vehicleRef): ?OdometerSample
    {
        $session->spendCalls();
        $this->reads++;
        $this->credentialsSeen[] = $session->credentials();
        if ($this->crash !== null) throw $this->crash;
        if (isset($this->failFor[$vehicleRef])) throw new OdometerSourceException($this->failFor[$vehicleRef]);
        if ($this->rotateTo !== null) {
            $session->update(['token' => $this->rotateTo]);
            $this->rotateTo = null;
        }
        return $this->samples[$vehicleRef] ?? null;
    }
}
