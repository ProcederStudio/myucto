<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Action\Logbook\OdometerGapsAction;
use MyInvoice\Action\Logbook\OdometerReadingsAction;
use MyInvoice\Action\Logbook\TripTemplatesAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\Logbook\LogbookSummaryService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Pravidelné cesty a doplnění km podle stavu tachometru: mezera mezi zapsanými jízdami
 * a odečtem se rozdělí na navazující jízdy, zbytek volitelně soukromě.
 */
#[Group('integration')]
final class OdometerGapFillTest extends TestCase
{
    use LogbookFixtures;

    protected function setUp(): void
    {
        $this->bootLogbook();
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testFillCreatesChainedTripsAndPrivateRemainder(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0001', 1000, '2099-03-01');
        $business = $this->category($this->supplierA, 'tst_business', false);
        $this->category($this->supplierA, 'tst_private', true);
        $template = $this->json($this->call(TripTemplatesAction::class, 'create', 'POST', [
            'label' => 'Domov → kancelář', 'origin' => 'Domov', 'destination' => 'Kancelář',
            'purpose' => 'Jednání v kanceláři', 'distance_km' => 38.4, 'category_id' => $business,
            'time_start' => '07:30', 'time_end' => '08:10',
        ]))['id'];

        $reading = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST', [
            'car_id' => $car, 'reading_date' => '2099-03-02', 'odometer' => 1087,
        ]));
        $gaps = $this->json($this->call(OdometerGapsAction::class, 'list', 'GET', []));
        self::assertSame([[$reading['id'], 1000, 1087, 87]], array_map(
            static fn (array $g): array => [$g['reading_id'], $g['odometer_from'], $g['odometer_to'], $g['km']],
            array_values(array_filter($gaps, static fn (array $g): bool => $g['car_id'] === $car)),
        ));

        $res = $this->call(OdometerGapsAction::class, 'fill', 'POST', [
            'reading_id' => $reading['id'],
            'trips' => [
                ['template_id' => $template, 'trip_date' => '2099-03-02'],
                ['template_id' => $template, 'trip_date' => '2099-03-02', 'origin' => 'Kancelář', 'destination' => 'Domov',
                    'time_start' => '17:00', 'time_end' => '17:40'],
            ],
            'remainder_private' => true,
            'remainder_purpose' => 'Soukromá jízda',
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());

        // Soukromý zbytek nemá čas odjezdu, proto ho souhrn i přepočet tachometru řadí
        // v daném dni jako první — řetěz tachometru musí jít ve stejném pořadí.
        $rows = $this->pdo->prepare(
            "SELECT t.odometer_start, t.odometer_end, t.distance_km, t.purpose, tc.is_private
               FROM trips t LEFT JOIN trip_categories tc ON tc.id = t.category_id
              WHERE t.supplier_id = ? AND t.car_id = ? ORDER BY t.trip_date, COALESCE(t.time_start, '00:00:00'), t.id"
        );
        $rows->execute([$this->supplierA, $car]);
        $trips = $rows->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([
            [1000, 1010, '10.2', 'Soukromá jízda', 1],
            [1010, 1049, '38.4', 'Jednání v kanceláři', 0],
            [1049, 1087, '38.4', 'Jednání v kanceláři', 0],
        ], array_map(static fn (array $r): array => [(int) $r[0], (int) $r[1], $r[2], $r[3], (int) $r[4]], $trips));

        $summary = $this->container->get(LogbookSummaryService::class)->periodSummary($this->supplierA, '2099-01-01', '2099-12-31');
        $vehicle = array_values(array_filter($summary['vehicles'], static fn (array $v): bool => $v['car_id'] === $car))[0];
        self::assertSame(0, $vehicle['continuity_issues'], json_encode($vehicle['continuity_detail']));

        $after = $this->json($this->call(OdometerGapsAction::class, 'list', 'GET', []));
        self::assertSame([], array_values(array_filter($after, static fn (array $g): bool => $g['car_id'] === $car)));
    }

    public function testPartialFillLeavesTheRestAsGap(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0002', 500, null);
        $reading = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST', [
            'car_id' => $car, 'reading_date' => '2099-03-02', 'odometer' => 600,
        ]));

        $res = $this->call(OdometerGapsAction::class, 'fill', 'POST', [
            'reading_id' => $reading['id'],
            'trips' => [['trip_date' => '2099-03-02', 'distance_km' => 40, 'purpose' => 'Klient']],
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());

        $gaps = array_values(array_filter(
            $this->json($this->call(OdometerGapsAction::class, 'list', 'GET', [])),
            static fn (array $g): bool => $g['car_id'] === $car,
        ));
        self::assertSame([540, 600, 60], [$gaps[0]['odometer_from'], $gaps[0]['odometer_to'], $gaps[0]['km']]);
    }

    public function testTripsLongerThanGapAreRejected(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0003', 500, null);
        $reading = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST', [
            'car_id' => $car, 'reading_date' => '2099-03-02', 'odometer' => 550,
        ]));

        $res = $this->call(OdometerGapsAction::class, 'fill', 'POST', [
            'reading_id' => $reading['id'],
            'trips' => [['trip_date' => '2099-03-02', 'distance_km' => 51]],
        ]);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame('exceeds_gap', $this->json($res)['error']['code']);
        self::assertSame(0, $this->tripCount($car));
    }

    public function testTripDateOutsideGapIsRejected(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0004', 500, '2099-03-01');
        $reading = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST', [
            'car_id' => $car, 'reading_date' => '2099-03-05', 'odometer' => 550,
        ]));

        $res = $this->call(OdometerGapsAction::class, 'fill', 'POST', [
            'reading_id' => $reading['id'],
            'trips' => [['trip_date' => '2099-03-06', 'distance_km' => 10]],
        ]);
        self::assertSame(400, $res->getStatusCode());
        self::assertSame(0, $this->tripCount($car));
    }

    public function testRegressingReadingIsRejected(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0005', 500, null);
        $this->call(OdometerReadingsAction::class, 'create', 'POST', ['car_id' => $car, 'reading_date' => '2099-03-02', 'odometer' => 700]);

        $earlierHigher = $this->call(OdometerReadingsAction::class, 'create', 'POST', ['car_id' => $car, 'reading_date' => '2099-03-01', 'odometer' => 800]);
        $laterLower = $this->call(OdometerReadingsAction::class, 'create', 'POST', ['car_id' => $car, 'reading_date' => '2099-03-03', 'odometer' => 650]);

        self::assertSame('odometer_regression', $this->json($earlierHigher)['error']['code']);
        self::assertSame('odometer_regression', $this->json($laterLower)['error']['code']);
    }

    public function testReadingBelowEarlierTripEndIsRejected(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0009', 500, null);
        $this->container->get(TripRepository::class)->create($this->supplierA, [
            'car_id' => $car, 'trip_date' => '2099-03-01', 'odometer_start' => 500, 'odometer_end' => 700,
            'distance_km' => 200, 'purpose' => 'Klient',
        ], null);

        $res = $this->call(OdometerReadingsAction::class, 'create', 'POST', ['car_id' => $car, 'reading_date' => '2099-03-02', 'odometer' => 650]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('odometer_regression', $this->json($res)['error']['code']);
    }

    public function testImpossibleCalendarDatesAreRejected(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0010', 500, '2099-02-01');
        $reading = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST', [
            'car_id' => $car, 'reading_date' => '2099-03-05', 'odometer' => 550,
        ]));

        $badReading = $this->call(OdometerReadingsAction::class, 'create', 'POST', ['car_id' => $car, 'reading_date' => '2099-02-31', 'odometer' => 520]);
        self::assertSame(400, $badReading->getStatusCode(), (string) $badReading->getBody());

        $badFill = $this->call(OdometerGapsAction::class, 'fill', 'POST', [
            'reading_id' => $reading['id'],
            'trips' => [['trip_date' => '2099-02-31', 'distance_km' => 10]],
        ]);
        self::assertSame(400, $badFill->getStatusCode(), (string) $badFill->getBody());
        self::assertSame(0, $this->tripCount($car));
    }

    public function testSameDayReadingIsOverwritten(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0006', 500, null);
        $first = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST', ['car_id' => $car, 'reading_date' => '2099-03-02', 'odometer' => 600]));
        $second = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST', ['car_id' => $car, 'reading_date' => '2099-03-02', 'odometer' => 610]));

        self::assertSame($first['id'], $second['id']);
        self::assertSame(610, $second['odometer']);
    }

    public function testForeignTenantReferencesAreRejected(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0007', 500, null);
        $foreignCar = $this->carWithStart($this->supplierB, 'TST0008', 500, null);
        $foreignCategory = $this->category($this->supplierB, 'tst_foreign', false);
        $foreignReading = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST',
            ['car_id' => $foreignCar, 'reading_date' => '2099-03-02', 'odometer' => 600], $this->supplierB));
        $foreignTemplate = $this->json($this->call(TripTemplatesAction::class, 'create', 'POST',
            ['label' => 'Cizí cesta', 'distance_km' => 10], $this->supplierB))['id'];
        $reading = $this->json($this->call(OdometerReadingsAction::class, 'create', 'POST',
            ['car_id' => $car, 'reading_date' => '2099-03-02', 'odometer' => 600]));

        self::assertSame(400, $this->call(OdometerReadingsAction::class, 'create', 'POST',
            ['car_id' => $foreignCar, 'reading_date' => '2099-03-03', 'odometer' => 700])->getStatusCode());
        self::assertSame(400, $this->call(TripTemplatesAction::class, 'create', 'POST',
            ['label' => 'X', 'distance_km' => 10, 'category_id' => $foreignCategory])->getStatusCode());
        self::assertSame(400, $this->call(TripTemplatesAction::class, 'create', 'POST',
            ['label' => 'X', 'distance_km' => 10, 'car_id' => $foreignCar])->getStatusCode());
        self::assertSame(404, $this->call(OdometerGapsAction::class, 'fill', 'POST',
            ['reading_id' => $foreignReading['id'], 'trips' => [['trip_date' => '2099-03-02', 'distance_km' => 10]]])->getStatusCode());
        self::assertSame(400, $this->call(OdometerGapsAction::class, 'fill', 'POST',
            ['reading_id' => $reading['id'], 'trips' => [['template_id' => $foreignTemplate, 'trip_date' => '2099-03-02']]])->getStatusCode());
        self::assertSame(400, $this->call(OdometerGapsAction::class, 'fill', 'POST',
            ['reading_id' => $reading['id'], 'trips' => [['trip_date' => '2099-03-02', 'distance_km' => 10, 'category_id' => $foreignCategory]]])->getStatusCode());
        self::assertSame(404, $this->call(OdometerReadingsAction::class, 'delete', 'DELETE', [], null, ['id' => (string) $foreignReading['id']])->getStatusCode());
        self::assertSame(404, $this->call(TripTemplatesAction::class, 'update', 'PUT', ['label' => 'Y'], null, ['id' => (string) $foreignTemplate])->getStatusCode());
        self::assertSame(0, $this->tripCount($car));
    }

    public function testTemplatePartialUpdateKeepsOmittedFields(): void
    {
        $id = $this->json($this->call(TripTemplatesAction::class, 'create', 'POST', [
            'label' => 'Domov → klient', 'origin' => 'Domov', 'distance_km' => 12.5, 'time_start' => '09:00',
        ]))['id'];

        $updated = $this->json($this->call(TripTemplatesAction::class, 'update', 'PUT', ['distance_km' => 14, 'time_start' => ''], null, ['id' => (string) $id]));

        self::assertSame('Domov', $updated['origin']);
        self::assertEquals(14, $updated['distance_km']);
        self::assertNull($updated['time_start']);
    }

    private function carWithStart(int $supplierId, string $registration, int $odometer, ?string $date): int
    {
        $car = $this->car($supplierId, $registration);
        $this->pdo->prepare('UPDATE cars SET odometer_start = ?, odometer_start_date = ? WHERE id = ?')
            ->execute([$odometer, $date, $car]);
        return $car;
    }

    private function category(int $supplierId, string $code, bool $private): int
    {
        $this->pdo->prepare('UPDATE trip_categories SET is_archived = 1 WHERE supplier_id = ? AND is_private = ?')
            ->execute([$supplierId, $private ? 1 : 0]);
        $this->pdo->prepare('INSERT INTO trip_categories (supplier_id, code, label, is_private) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $code, $code, $private ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function tripCount(int $carId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM trips WHERE car_id = ?');
        $stmt->execute([$carId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param class-string $class
     * @param array<string,mixed> $body
     * @param array<string,string> $args
     */
    private function call(string $class, string $method, string $verb, array $body, ?int $supplierId = null, array $args = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($verb, '/api/logbook/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        return $this->container->get($class)->$method($request, new Response(), $args);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, (string) $response->getBody());
        return $decoded;
    }
}
