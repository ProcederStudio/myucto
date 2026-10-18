<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use PDO;

/**
 * Generuje obsah `/etc/cron.d/myinvoice` pro vestavěný cron v Docker image.
 *
 * Zdrojem pravdy je {@see CronCatalog} — stejný seznam úloh + frekvencí, jaký
 * ukazuje UI „Systém → Plánované úlohy". Crontab se tak při Docker buildu generuje
 * z katalogu (tools/generateDockerCrontab.php), místo aby se ručně opisoval a časem
 * se rozešel (např. by chyběl cron-backup-documents).
 *
 * Každý řádek volá wrapper `/usr/local/bin/myucto-cron-run`, který načte runtime
 * ENV (cron je v Debianu nedědí) a spustí PHP skript jako www-data s logem do
 * `${MYINVOICE_DATA_DIR}/log/cron`.
 *
 * Crontab se generuje dvakrát:
 *   - při Docker buildu BEZ brány (fallback — runtime konfigurace ještě neexistuje),
 *   - při startu kontejneru S bránou (entrypoint), takže úlohy, které u téhle
 *     instalace nemají co dělat, se vůbec nenaplánují. Viz {@see CronJobGate}.
 */
final class DockerCrontabGenerator
{
    /**
     * MUSÍ se shodovat se jménem, pod kterým wrapper instalují Dockerfile
     * i Dockerfile.alpine — jinak cron úlohy spustí, ony okamžitě skončí na
     * neexistujícím souboru a protože je v crontabu MAILTO="" a wrapper (který
     * jediný loguje do log/cron) se vůbec nespustí, selhání NEZANECHÁ ŽÁDNOU
     * STOPU. Instalace pak běží bez záloh a kontrol a nic na to neupozorní.
     * Přesně tohle způsobil pozůstatek přejmenování projektu (issue #6):
     * image instaloval `myucto-cron-run`, konstanta držela staré `myinvoice-`.
     * Shodu hlídá DockerCrontabWrapperNameTest proti obou Dockerfilům.
     */
    public const WRAPPER = '/usr/local/bin/myucto-cron-run';

    /**
     * Běží instalace v Docker image (úlohy plánuje vestavěný cron)? Cesty leží
     * mimo `open_basedir` sdíleného hostingu, proto přes {@see \MyInvoice\Support\OpenBasedir}.
     */
    public static function runningInContainer(): bool
    {
        return \MyInvoice\Support\OpenBasedir::isFile('/.dockerenv')
            || \MyInvoice\Support\OpenBasedir::isFile(self::WRAPPER);
    }

    /**
     * @param CronJobGate|null $gate Bez brány se vygenerují všechny úlohy z katalogu
     *                               (build-time fallback). S bránou jen ty, které
     *                               u téhle instalace mají smysl plánovat.
     * @param string $mode {@see CronScheduleMode} — INDIVIDUAL vypíše jednotlivé
     *                     úlohy (default, beze změny chování), DISPATCHER jediný
     *                     řádek s plánovačem.
     * @param PDO|null $pdo Připojení k DB kvůli smluvně řízeným rozvrhům
     *                      ({@see CronCatalog::withContractedSchedules()}). Při BUILDU
     *                      image DB neexistuje → použije se katalogový default a
     *                      entrypoint crontab po migracích přegeneruje.
     */
    public static function generate(
        ?CronJobGate $gate = null,
        string $mode = CronScheduleMode::INDIVIDUAL,
        ?PDO $pdo = null,
    ): string {
        if ($gate === null) {
            $jobs = $mode === CronScheduleMode::DISPATCHER
                ? array_values(array_filter(CronCatalog::all(), static fn (array $j): bool => ($j['dispatcher_only'] ?? false) === true))
                : CronCatalog::dispatchable();
        } else {
            // `schedulableJobs()` řeší jen restart-stabilní `requires_config`;
            // explicitní vypnutí přes `cron.disabled_jobs` je nutné odfiltrovat
            // navíc, jinak by se vypnutá úloha (spravovaná instalace) do
            // vygenerovaného crontabu přesto dostala.
            $jobs = array_values(array_filter(
                $gate->schedulableJobs($mode),
                static fn (array $j): bool => !$gate->isDisabledByConfig((string) $j['script']),
            ));
        }

        // Rozvrh záloh řídí `backup_schedule_contract`, ne katalog — jinak by vygenerovaný
        // crontab tvrdil něco jiného než dispatcher a než uložený kontrakt.
        $jobs = CronCatalog::withContractedSchedules($jobs, $pdo);

        $candidates = $mode === CronScheduleMode::DISPATCHER ? count($jobs) : count(CronCatalog::dispatchable());
        $skipped = $candidates - count($jobs);

        $lines = [
            '# Vestavěný cron MyInvoice — GENEROVÁNO z CronCatalog (tools/generateDockerCrontab.php).',
            '# Neupravovat ručně; změny frekvencí patří do api/src/Service/Cron/CronCatalog.php.',
            '# Režim plánování: ' . $mode . (
                $mode === CronScheduleMode::DISPATCHER
                    ? ' (jediná položka spouští ostatní úlohy — Systém → Plánované úlohy)'
                    : ' (každá úloha vlastní položkou)'
            ),
        ];
        if ($gate !== null && $mode !== CronScheduleMode::DISPATCHER) {
            $lines[] = sprintf(
                '# Filtrováno podle konfigurace instalace: %d úloh naplánováno, %d vynecháno.',
                count($jobs),
                $skipped,
            );
        }
        $lines = array_merge($lines, [
            'SHELL=/bin/sh',
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'CRON_TZ=Europe/Prague',
            'MAILTO=""',
            '',
        ]);
        foreach ($jobs as $job) {
            $lines[] = sprintf(
                '%s www-data %s api/bin/%s.php',
                $job['linux_cron'],
                self::WRAPPER,
                $job['script'],
            );
        }
        // /etc/cron.d soubory MUSÍ končit novým řádkem, jinak je cron ignoruje.
        return implode("\n", $lines) . "\n";
    }
}
