<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\Cron\DockerCrontabGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pojistka, že vestavěný cron v Docker image zůstane v souladu s CronCatalog —
 * tj. obsahuje VŠECHNY úlohy + frekvence (akceptační kritérium issue #64).
 * Kdyby někdo přidal úlohu do katalogu a zapomněl ji v Dockeru, test spadne.
 */
final class DockerCrontabGeneratorTest extends TestCase
{
    public function testCrontabCoversEveryCatalogJob(): void
    {
        // `dispatchable()` = katalog bez položky dispatcheru. Ta se v default
        // režimu neplánuje schválně — spouštěla by tytéž úlohy podruhé.
        $crontab = DockerCrontabGenerator::generate();
        foreach (CronCatalog::dispatchable() as $job) {
            $expected = sprintf(
                '%s www-data %s api/bin/%s.php',
                $job['linux_cron'],
                DockerCrontabGenerator::WRAPPER,
                $job['script'],
            );
            self::assertStringContainsString(
                $expected,
                $crontab,
                "crontab musí obsahovat úlohu {$job['script']} s frekvencí {$job['linux_cron']}",
            );
        }
    }

    public function testCrontabIsWellFormed(): void
    {
        $crontab = DockerCrontabGenerator::generate();
        // /etc/cron.d soubor MUSÍ končit novým řádkem, jinak ho cron ignoruje.
        self::assertStringEndsWith("\n", $crontab);
        self::assertStringContainsString('CRON_TZ=Europe/Prague', $crontab);
        // Žádná úloha navíc ani chybějící proti katalogu.
        $jobLines = array_filter(
            explode("\n", $crontab),
            static fn (string $l): bool => str_contains($l, DockerCrontabGenerator::WRAPPER),
        );
        self::assertCount(count(CronCatalog::dispatchable()), $jobLines);
    }

    /**
     * Nejdůležitější invariant celé dvojrežimovosti: v default režimu se
     * dispatcher NEPLÁNUJE. Kdyby se tam dostal, běžel by vedle jednotlivých
     * položek a spouštěl je podruhé — u generování pravidelných faktur nebo
     * zaúčtování mezd to znamená duplicitní doklady.
     */
    public function testIndividualModeNeverSchedulesTheDispatcher(): void
    {
        foreach ([DockerCrontabGenerator::generate(), DockerCrontabGenerator::generate(new CronJobGate(new Config([]), null))] as $crontab) {
            self::assertStringNotContainsString('api/bin/' . CronCatalog::DISPATCHER_SCRIPT . '.php', $crontab);
        }
    }

    /**
     * A zrcadlově: v režimu dispatcheru se neplánuje NIC jiného než on.
     */
    public function testDispatcherModeSchedulesOnlyTheDispatcher(): void
    {
        $crontab = DockerCrontabGenerator::generate(null, CronScheduleMode::DISPATCHER);

        $jobLines = array_values(array_filter(
            explode("\n", $crontab),
            static fn (string $l): bool => str_contains($l, DockerCrontabGenerator::WRAPPER),
        ));
        self::assertCount(1, $jobLines, 'Režim dispatcheru musí mít právě jednu položku.');
        self::assertStringContainsString('api/bin/' . CronCatalog::DISPATCHER_SCRIPT . '.php', $jobLines[0]);
        self::assertStringStartsWith('* * * * *', $jobLines[0]);

        foreach (CronCatalog::dispatchable() as $job) {
            self::assertStringNotContainsString('api/bin/' . $job['script'] . '.php', $crontab);
        }
    }

    /**
     * Brána nesmí dispatcher vyhodit ani u instalace bez jakékoli konfigurace —
     * jinak by po přepnutí režimu neběželo vůbec nic.
     */
    public function testDispatcherSurvivesGateWithEmptyConfig(): void
    {
        $crontab = DockerCrontabGenerator::generate(
            new CronJobGate(new Config([]), null),
            CronScheduleMode::DISPATCHER,
        );
        self::assertStringContainsString('api/bin/' . CronCatalog::DISPATCHER_SCRIPT . '.php', $crontab);
    }

    /**
     * Bez brány (build-time) musí projít celý katalog — image nesmí přijít
     * o úlohu jen proto, že runtime konfigurace při buildu neexistuje.
     */
    public function testGeneratorWithoutGateKeepsFullCatalog(): void
    {
        self::assertSame(DockerCrontabGenerator::generate(), DockerCrontabGenerator::generate(null));
    }

    public function testGateDropsJobsWithUnconfiguredDirectory(): void
    {
        // Prázdný config → žádná z `requires_config` úloh nemá nastavený adresář.
        $gate = new CronJobGate(new Config([]), null);
        $crontab = DockerCrontabGenerator::generate($gate);

        $conditional = array_filter(CronCatalog::dispatchable(), static fn (array $j): bool => isset($j['requires_config']));
        self::assertNotEmpty($conditional, 'Test ztrácí smysl, pokud katalog nemá podmíněné úlohy.');

        foreach ($conditional as $job) {
            self::assertStringNotContainsString(
                'api/bin/' . $job['script'] . '.php',
                $crontab,
                "Úloha {$job['script']} bez nastaveného {$job['requires_config']} se nemá plánovat.",
            );
        }

        // Nepodmíněné úlohy naopak musí zůstat.
        foreach (CronCatalog::dispatchable() as $job) {
            if (isset($job['requires_config']) || isset($job['requires_config_flag'])
                || ($job['requires_managed'] ?? false) === true) {
                continue;
            }
            self::assertStringContainsString('api/bin/' . $job['script'] . '.php', $crontab);
        }
    }

    /**
     * Úloha jen pro spravovaný provoz se na self-hostu nesmí naplánovat.
     *
     * ⚠️ `cron-storage-usage` je jediná úloha, která prochází celý datový
     * strom. Bez `app.managed` by to dělala každou hodinu pro nikoho — kvóta
     * není nastavená, takže naměřené číslo nic nevynucuje.
     */
    public function testManagedOnlyJobIsDroppedOnSelfHostedAndKeptWhenManaged(): void
    {
        $managedOnly = array_filter(
            CronCatalog::dispatchable(),
            static fn (array $j): bool => ($j['requires_managed'] ?? false) === true,
        );
        self::assertNotEmpty($managedOnly, 'Test ztrácí smysl, pokud katalog nemá úlohu jen pro spravovaný provoz.');

        $selfHosted = DockerCrontabGenerator::generate(new CronJobGate(new Config([]), null));
        $managed = DockerCrontabGenerator::generate(
            new CronJobGate(new Config(['app' => ['managed' => true]]), null),
        );

        foreach ($managedOnly as $job) {
            $entry = 'api/bin/' . $job['script'] . '.php';
            self::assertStringNotContainsString($entry, $selfHosted, "{$job['script']} nemá na self-hostu co plánovat.");
            self::assertStringContainsString($entry, $managed, "{$job['script']} musí ve spravovaném provozu běžet.");
        }
    }

    /**
     * Brána crontabu se NESMÍ ptát na opt-in AI. Ten se přepíná v UI za běhu,
     * zatímco crontab se generuje jen při startu kontejneru — zapnutí AI by se
     * projevilo až po restartu, tiše a bez jakéhokoli signálu. Dynamické podmínky
     * patří do CronPreflight uvnitř skriptu.
     */
    public function testGateSchedulesAiJobsRegardlessOfOptIn(): void
    {
        $gate = new CronJobGate(new Config([]), null);
        $crontab = DockerCrontabGenerator::generate($gate);
        self::assertStringContainsString('api/bin/cron-ai-worker.php', $crontab);
    }

    public function testGatedCrontabStaysWellFormed(): void
    {
        $crontab = DockerCrontabGenerator::generate(new CronJobGate(new Config([]), null));
        self::assertStringEndsWith("\n", $crontab);
        self::assertStringContainsString('CRON_TZ=Europe/Prague', $crontab);
    }

    public function testAiJobsUseCanonicalCronEntrypointsAndExpectedSchedules(): void
    {
        $jobs = [];
        foreach (CronCatalog::all() as $job) {
            $jobs[$job['script']] = $job;
        }

        self::assertSame('*/10 * * * *', $jobs['cron-ai-worker']['linux_cron']);
        self::assertSame('every_10_min', $jobs['cron-ai-worker']['recommended']);
        self::assertTrue($jobs['cron-ai-worker']['requires_ai_opt_in']);
        self::assertSame('0 4 * * *', $jobs['cron-ai-rule-miner']['linux_cron']);
        self::assertSame('daily_0400', $jobs['cron-ai-rule-miner']['recommended']);

        $root = dirname(__DIR__, 5);
        foreach ([
            'api/bin/cron-ai-worker.php',
            'api/bin/cron-ai-rule-miner.php',
            'cmd/cron-ai-worker.cmd',
            'cmd/cron-ai-worker.sh',
            'cmd/cron-ai-rule-miner.cmd',
            'cmd/cron-ai-rule-miner.sh',
        ] as $path) {
            self::assertFileExists($root . '/' . $path);
        }
    }

    /**
     * Issue #6: crontab volal `/usr/local/bin/myinvoice-cron-run`, ale oba
     * Dockerfily instalují wrapper jako `myucto-cron-run` (pozůstatek
     * přejmenování projektu). Cron úlohu spustil, ta okamžitě skončila na
     * neexistujícím souboru — a protože je v crontabu MAILTO="" a wrapper, který
     * jediný loguje do log/cron, se vůbec nespustil, selhání NEZANECHALO ŽÁDNOU
     * STOPU. Instalace běžela bez záloh, bez kontroly integrity deníku a bez
     * upomínek a nic na to neupozornilo.
     *
     * Testy výš tohle chytit NEMOHLY: obě strany porovnání braly WRAPPER, takže
     * si konstanta odpovídala sama se sebou. Kontrakt je až vůči Dockerfilům.
     */
    public function testWrapperNameMatchesWhatDockerfilesInstall(): void
    {
        $root = dirname(__DIR__, 5);
        $wrapper = DockerCrontabGenerator::WRAPPER;

        foreach (['Dockerfile', 'Dockerfile.alpine'] as $dockerfile) {
            $contents = (string) file_get_contents($root . '/' . $dockerfile);
            self::assertStringContainsString(
                'cp docker/cron-run.sh ' . $wrapper,
                $contents,
                "{$dockerfile} musí instalovat wrapper pod jménem {$wrapper} — jinak cron tiše neběží vůbec",
            );
        }

        // Entrypointy počítají naplánované úlohy grepem přes jméno wrapperu.
        // Rozejde-li se, hlásí „0 úloh" u plného crontabu a svádí na špatnou
        // diagnózu (že všechno odfiltrovala brána CronJobGate).
        foreach (['docker-entrypoint.sh', 'docker/entrypoint-alpine.sh'] as $entrypoint) {
            $contents = (string) file_get_contents($root . '/' . $entrypoint);
            self::assertStringContainsString(
                'grep -c ' . basename($wrapper) . ' /etc/cron.d/myucto',
                $contents,
                "{$entrypoint} musí počítat úlohy podle jména wrapperu {$wrapper}",
            );
        }
    }

    /**
     * Detekce běhu v Dockeru nesmí mít jméno wrapperu opsané druhou rukou: obě akce
     * volají sdílenou detekci, ta se ptá na konstantu (a mimo open_basedir vůbec ne).
     */
    public function testDockerDetectionUsesTheSharedConstant(): void
    {
        $root = dirname(__DIR__, 5);
        foreach ([
            'api/src/Action/Admin/CronJobsAction.php',
            'api/src/Action/Admin/SetCronScheduleModeAction.php',
        ] as $path) {
            $contents = (string) file_get_contents($root . '/' . $path);
            self::assertStringContainsString('DockerCrontabGenerator::runningInContainer()', $contents, $path);
            self::assertStringNotContainsString('-cron-run', $contents, $path);
        }
        $generator = (string) file_get_contents($root . '/api/src/Service/Cron/DockerCrontabGenerator.php');
        self::assertStringContainsString('OpenBasedir::isFile(self::WRAPPER)', $generator);
    }
}
