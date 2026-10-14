<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder as Builder;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeSource as Source;
use PHPUnit\Framework\TestCase;

/**
 * Jediná funkce „odpracováno ze zdroje souhrnu" a návrh bloků ze souhrnu
 * importu docházky. Obojí je čistý převod bez databáze.
 */
final class PayrollWorkedTimeSourceTest extends TestCase
{
    public function testImportSummaryPassesMillihoursThroughWithoutDays(): void
    {
        $worked = Source::fromSnapshot([
            'schema_version' => Builder::IMPORT_SUMMARY_DERIVATION_VERSION,
            'import_summary' => ['values' => ['worked_hours' => 160_500, 'overtime_hours' => 4_250], 'worked_days' => null],
        ], '2026-07-01');

        self::assertSame(Source::KIND_IMPORT_SUMMARY, $worked['kind']);
        self::assertSame([], $worked['issues']);
        self::assertSame(160_500, $worked['worked_millihours']);
        self::assertSame(9_630, $worked['worked_minutes']);
        self::assertNull($worked['worked_days'], 'Podklady dny nenesou a nedopočítávají se.');
        self::assertSame(4_250, $worked['overtime_millihours']);
        self::assertSame(255, $worked['overtime_minutes']);
    }

    public function testMillihoursThatAreNotWholeMinutesKeepMinutesUnstated(): void
    {
        $worked = Source::fromImportSummary(['values' => ['worked_hours' => 160_333]]);

        self::assertSame(160_333, $worked['worked_millihours']);
        self::assertNull($worked['worked_minutes']);
    }

    public function testOvertimeAboveWorkedHoursIsAnIssue(): void
    {
        $worked = Source::fromImportSummary(['values' => ['worked_hours' => 8_000, 'overtime_hours' => 9_000]]);

        self::assertSame(['import_overtime_exceeds_worked'], array_column($worked['issues'], 'code'));
    }

    public function testMissingSummaryIsAnIssueNotZero(): void
    {
        $worked = Source::fromSnapshot(['import_summary' => null], '2026-07-01');

        self::assertSame(['import_summary_missing'], array_column($worked['issues'], 'code'));
        self::assertNull($worked['worked_millihours']);
    }

    public function testEntriesCountHoursDaysAndOvertimeFromOnePass(): void
    {
        $worked = Source::fromSnapshot(['time_entries' => [
            self::entry('2026-07-06 06:00:00', '2026-07-06 14:30:00', 30),
            self::entry('2026-07-06 15:00:00', '2026-07-06 17:00:00', 0, 'overtime'),
            // Interval s nulovou čistou dobou není odpracovaný den.
            self::entry('2026-07-07 06:00:00', '2026-07-07 06:30:00', 30),
        ]], '2026-07-01');

        self::assertSame(Source::KIND_TIME_ENTRIES, $worked['kind']);
        self::assertSame([], $worked['issues']);
        self::assertSame(600, $worked['worked_minutes']);
        self::assertSame(10_000, $worked['worked_millihours']);
        self::assertSame(1, $worked['worked_days']);
        self::assertSame(120, $worked['overtime_minutes']);
    }

    public function testDateFreeImportHoursAreSuggestedWithTotals(): void
    {
        $suggestions = Builder::importConditionalSuggestions([
            'worked_hours' => 150_000,
            'vacation_hours' => 8_000,
            'doctor_hours' => 2_000,
            'obstacle_employer_hours' => 3_500,
            'night_hours' => 20_000,
            'holiday_hours' => 8_000,
        ]);

        self::assertSame('8', $suggestions['vacation_hours']);
        self::assertSame('2', $suggestions['employee_obstacle_paid_hours']);
        self::assertSame('3.5', $suggestions['employer_obstacle_hours']);
        self::assertSame('13.5', $suggestions['unworked_total_hours']);
        self::assertSame('13.5', $suggestions['unworked_paid_hours']);
        self::assertTrue($suggestions['unworked_hours_occurred']);
        self::assertTrue($suggestions['work_obstacles_occurred']);
        self::assertNull($suggestions['dpn_with_employer_compensation_hours']);
    }

    /**
     * Nemoc a ošetřovné převod zapisuje s daty, souhrn importu je nenese; jejich hodiny
     * z kalendáře se přidají k dovolené ze souhrnu, jinak by ELDP nemělo z čeho vzniknout.
     */
    public function testDatedAbsenceHoursJoinTheImportSummary(): void
    {
        $suggestions = Builder::importConditionalSuggestions(
            ['worked_hours' => 120_000, 'vacation_hours' => 8_000],
            ['dpn_with_employer_compensation' => 960, 'dpn_without_employer_compensation' => 480, 'care' => 450],
        );

        self::assertSame('8', $suggestions['vacation_hours']);
        self::assertSame('16', $suggestions['dpn_with_employer_compensation_hours']);
        self::assertSame('8', $suggestions['dpn_without_employer_compensation_hours']);
        self::assertSame('7.5', $suggestions['care_hours']);
        self::assertSame('39.5', $suggestions['unworked_total_hours']);
        // Placené: dovolená a nemoc v okně náhrady, ne nemoc za oknem ani ošetřovné.
        self::assertSame('24', $suggestions['unworked_paid_hours']);
        self::assertTrue($suggestions['unworked_hours_occurred']);
        self::assertFalse($suggestions['work_obstacles_occurred']);

        self::assertNull(Builder::importConditionalSuggestions(['vacation_hours' => 8_000], null)['vacation_hours'],
            'Nepřítomnost, kterou nejde spolehlivě odvodit, nenavrhuje nic.');
    }

    public function testHoursNeedingAbsenceDatesSuggestNothing(): void
    {
        $values = ['worked_hours' => 150_000, 'vacation_hours' => 8_000, 'sick_hours' => 16_000];

        $suggestions = Builder::importConditionalSuggestions($values);

        self::assertSame(['sick_hours'], Builder::importHoursRequiringDates($values));
        self::assertNull($suggestions['vacation_hours'], 'Částečný návrh by v úhrnu 10275 tiše chyběl.');
        self::assertNull($suggestions['unworked_total_hours']);
        self::assertNull($suggestions['unworked_hours_occurred']);
    }

    public function testMonthWithoutUnworkedHoursAnswersNo(): void
    {
        $suggestions = Builder::importConditionalSuggestions(['worked_hours' => 176_000, 'fund_hours' => 176_000]);

        self::assertFalse($suggestions['unworked_hours_occurred']);
        self::assertFalse($suggestions['work_obstacles_occurred']);
        self::assertNull($suggestions['unworked_total_hours']);
    }

    /** @return array<string,mixed> */
    private static function entry(string $startsAtUtc, string $endsAtUtc, int $breakMinutes, string $category = 'regular'): array
    {
        return [
            'category' => $category,
            'starts_at_utc' => $startsAtUtc,
            'ends_at_utc' => $endsAtUtc,
            'timezone_name' => 'Europe/Prague',
            'break_minutes' => $breakMinutes,
        ];
    }
}
