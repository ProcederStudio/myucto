# Lokální numerická regresní brána

Samostatný CLI harness načítá aplikaci z explicitního `--code-root`. Stejný harness lze použít nad dvěma checkouty, včetně baseline `499eb4dd1`. Neprovádí export databáze, klonování, migrace, seedování ani odesílání podání. Finanční služby nemění.

Toto je první, **neúplný manifest**. `status=ok` znamená shodu implementovaných případů, nikoli pokrytí všech účetních a daňových oblastí. `coverage.complete` zůstává `false`.

## Vstupy a bezpečnost

Capture přijímá pouze již připravenou izolovanou databázi se jménem odpovídajícím `^[a-z][a-z0-9_]*_[a-f0-9]{8,32}_test$`. Odmítne i takto pojmenovanou databázi, pokud ji aktuálně používá konfigurace checkoutu. Hesla načítá původní `Config`, nepíší se do argumentů ani manifestu. Výchozí host musí být `localhost`, `127.0.0.1` nebo `::1`. Docker vyžaduje zároveň `--docker-test-host HOST` a `MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST=1`; jde výhradně o testovací host.

Výstupní soubor ani pracovní adresář nesmí být uvnitř žádného Git repozitáře nebo code rootu. Kontrola používá `realpath`, tedy i junction/symlink ekvivalenty, a porovnává cesty bez ohledu na velikost písmen. Rodič výstupu musí již existovat. Existující soubor se nepřepisuje. Výstupní adresář zabezpečte oprávněními operačního systému: JSON obsahuje skutečné návratové hodnoty služeb. Nikdy jej nedávejte do fixtures, commitu, veřejného CI artefaktu nebo zprávy.

Každý případ i preflight mají vlastní PHP proces, `MYINVOICE_DATA_DIR`, cache a tmp. Původní `Connection::pdo`, `LoggingPdo` a `LoggingPdoStatement` jsou instrumentovány pouze v paměti. Přijímají se jen ověřené SHA-256 jejich zdrojů; neznámá změna skončí chybou. Těla původních metod, nastavení PDO, SQL mode, timezone i sdílení handlů zůstávají zachována. Před každým vrácením připojení se ověří skutečné `SELECT DATABASE()`, `@@session.tx_read_only` a aktivní transakce. Nový handle dostane výchozí READ ONLY session a transakci. Výstup se dokončí až po opětovné kontrole a rollbacku.

SQL tripwire zastaví zápis ještě před native prepare. Pokus i PDOException z chybějící tabulky nebo jiného selhání zůstávají chybou, i když je služba spolkne. Totéž platí pro cURL, stream URL a blokované síťové/procesové funkce, včetně volání pod `@`. Očekávaný potlačený lokální cache miss (`SchemaCache` čte neexistující soubor přes `@file_get_contents`) zachovává původní PHP chování. Worker nesmí vytvářet další procesy. Redis je vypnutý. Nejde o OS sandbox pro nedůvěryhodný PHP kód: manifest volá pouze pevně vyjmenované aplikační služby. Přidání další služby vyžaduje review jejích I/O cest. Globální firewall ani původní zdrojové soubory se nemění.

## Spuštění

CLI vstup je `api/bin/regression-compare.php`. Argumenty jsou dvojice `--jméno hodnota`, bez shellových skriptů.

Capture vyžaduje:

- `--code-root`: absolutní cesta checkoutu s vlastním správným Composer autoloadem a konfigurací.
- `--database`: explicitní unikátní testovací kopie.
- `--as-of`: datum `YYYY-MM-DD`; určuje konec účetních filtrů a PSR hodiny.
- `--execution-date`: skutečný kalendářní den v časové zóně aplikace. Oba capture musí běžet ve stejný den; změna dne za běhu je chyba. Nativní `date()` a SQL hodiny se nepřepisují.
- `--dataset-id`: SHA-256 společného vstupního dumpu. Operátor ručí za totožnost kopií; harness nečte zdrojovou databázi ani sám totožnost dumpu nedokazuje.
- `--output`: nový absolutní soubor mimo Git, v privátním adresáři.

Režim `compare` vyžaduje `--baseline`, `--candidate` a `--output`. Oba capture musí mít stejný dataset, datum, execution date, manifest a rozsah pokrytí. Code rooty mohou být jiné. Výstup obsahuje úplný seznam strukturálních a numerických rozdílů po cestách; hodnoty zůstávají v privátních capture souborech.

Stdout obsahuje pouze agregáty a anonymní case IDs. Výjimky se nezapisují syrovými zprávami, SQL, parametry ani trace. Sanitizovaný typ výjimky, doménový kód, zdrojový soubor/řádek warningu a sticky porušení jsou v lokálním JSON. Chybějící kurz, seed, lazy write, síťový pokus, nepotlačený warning, timeout nebo fatální chyba nejsou skip. Shodná výjimka v obou revizích není úspěch. Chyba, rozdíl, prázdný capture nebo nulové numerické pokrytí vrací exit code 2.

## První manifest

| Oblast | Skutečná volání a filtry |
| --- | --- |
| Rozvaha a VZZ | `FinancialStatementService::balanceSheet`, `incomeStatement`, `incomeStatementByFunction`; každé účetní období, explicitní asOf, full/small/micro, dimension=null |
| Předvaha | `TrialBalanceService::build`; explicitní od/do, syntetika i analytika, před i po uzavření |
| Hlavní kniha | `GeneralLedgerService::build`; stejná období, oba analytické a uzávěrkové přepínače, explicitní prázdný filter |
| DPPO | `TaxReturnService::previewReadOnly`, `readOnlyInputs`, `balanceDueReadOnly` a samostatný `DppoReturnDataProvider::gather` se skutečnými read-only inputs; PO a celé skončené účetní období |
| DPH/KH/SH | `VatLedgerService::rows/returnRows`, `DphBookBuilder`, `DphPriznaniBuilder`, `KontrolniHlaseniBuilder`, `SouhrnneHlaseniBuilder`; celé kalendářní měsíce zasahující vybraná účetní období, bez draftů, měsíční řádná varianta |

Budoucí účetní období, neukončené daňové období a non-PO DPPO jsou v `coverage.not_applicable` spočteny explicitně. Nepokryté oblasti jsou vyjmenovány v `coverage.not_implemented`: DPFO, daňová evidence, dimenze, uzávěrkové náhledy, čtvrtletní VAT, samostatná numerická projekce XML a firmy bez účetního období. Výběr VAT je technický měsíční kontrolní řez, nikoli potvrzení zákonné periodicity podání konkrétní firmy. Nepodporovaný rámec nebo nedostupná služba se neobcházejí.

## Porovnání

Desetinná čísla se kanonizují řetězcově, bez konverze DECIMAL na float, bez epsilon tolerance a bez zaokrouhlování výsledku. `1.2300` a `1.23` jsou numericky shodné, nejmenší nenulový rozdíl zůstává rozdílem. PHP float se porovnává podle přesné JSON reprezentace vrácené PHP; harness nepředstírá vyšší přesnost, než vrací služba. Nula a null jsou různé, neztrácejí se nulové řádky ani duplicity a pořadí seznamu zůstává významné. Asociativní klíče se porovnávají podle jména.

IDs, kódy a enum `accounting_decree` se porovnávají beze změny typu, ale nezvyšují počet numerických hodnot. Přidané pole, například `podklady.accounting_decree=500`, se objeví jako `added_field` a vyžaduje review. Neexistuje automatický seznam tolerovaných rozdílů. Jediná normalizace výstupu je prezentační `entity.prepared_at` na explicitní asOf; ostatní data a časové údaje zůstávají viditelné.

## Ověření harnessu

Unit testy jsou `RegressionComparatorTest` a `RegressionInstrumentationTest`. Opt-in `MYINVOICE_REGRESSION_SYNTHETIC_DB=1` povolí `RegressionMariaDbInstrumentationTest`: vytvoří vlastní náhodnou lokální testovací DB pouze se syntetickou DECIMAL/NULL tabulkou. Ověřuje shodu původního/instrumentovaného PDO, skutečný READ ONLY stav, sdílení i nové připojení a zachycený UPDATE.

Stejný opt-in povolí `RegressionCaptureTest`. Ten vytváří jinou vlastní náhodnou databázi, spustí plné migrace přes `migrate.php --no-backfills --no-analyze` a `ci-seed.php`, vloží syntetické doklady a přes skutečný `PostingService` zaúčtuje nenulové částky. Následný capture volá každý implementovaný kind skutečným kontejnerem v samostatných READ ONLY procesech. Kontroluje úspěch všech případů, přesný hospodářský výsledek, nenulové finanční hodnoty včetně XML a předání ručních DPPO inputs do provideru. Nepoužívá ručně zjednodušené aplikační tabulky. Oba integrační testy databázi odstraní pouze po úspěšném CREATE a nastavení owned flagu. Testy se spouštějí standardním PHPUnit s explicitním absolutním `-c api/phpunit.xml` a testovací DB.

Před ostrým lokálním srovnáním je nutné samostatně reviewovat přípravu dvou izolovaných kopií, migrační strategii a chybějící oblasti manifestu. Tento nástroj přípravu kopií neautomatizuje.
