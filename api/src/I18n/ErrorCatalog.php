<?php

declare(strict_types=1);

namespace MyInvoice\I18n;

/**
 * Bilingual katalog backend chybových hlášek.
 *
 * Klíč = literální CZ text (přesně jak ho píše Json::error). Hodnota = EN ekvivalent.
 *
 * Chybový text s PHP proměnnými ($var, {$var}) sem nepatří — zůstává v CZ
 * (na seznamu výjimek, ~10 ks; lze upgradovat na placeholder formát kdykoli).
 *
 * Json::error() projde každý message přes lookup() — pokud nenajde, vrátí původní text.
 */
final class ErrorCatalog
{
    /** @var array<string,string> CZ → EN */
    private const MAP = [
        'Uzavřené účetní knihy nelze měnit. Nejprve je znovu otevřete.' => 'Closed accounting books cannot be changed. Reopen them first.',
        'Výkazy 504 nelze porovnat: liší se období, rozsah nebo členění řádků.' => 'The Decree 504 statements cannot be compared because their accounting period, scope or row structure differs.',
        'Převod účetních výkazů podle vyhlášky 504 a jejich porovnání s jiným účetním programem zatím nejsou podporovány.' => 'Importing financial statements under Decree 504 and comparing them with another accounting system are not yet supported.',
        'Přehled peněžních toků a změn vlastního kapitálu podle vyhlášky 500 není dostupný pro účetní rámec 504.' => 'Cash flow and equity statements under Decree 500 are not available for accounting framework 504.',
        'Podklady výkazu 504 pro export nejsou úplné nebo platné.' => 'The data for exporting the Decree 504 statement is incomplete or invalid.',
        'Vyhláška 504 nestanoví účelovou výsledovku.' => '504 does not define an income statement by function.',
        'Výkazy podle vyhlášky 504 před rokem 2024 zatím nejsou podporované.' => '504 statements before 2024 are not supported.',
        'Vlastní mapování účtu nesmí změnit předepsaný řádek výkazu 504.' => 'A 504 account override cannot change the prescribed statement row.',
        'Účetní rámec se mění uvnitř vykazovaného období.' => 'Accounting framework changes inside the reporting period.',
        'Účetní období neexistuje.' => 'Accounting period does not exist.',
        'Účet nemá jednoznačné přiřazení k řádku výkazu 504.' => 'An account is not uniquely mapped to the 504 statement.',
        'Neplatný rozsah účetního výkazu.' => 'Invalid statement scope.',
        'PDF a XLSX šablony výkazů 504 zatím nejsou k dispozici.' => 'PDF and XLSX templates for 504 statements are not available yet.',
        'Rozpad zaúčtované činnosti nesouhlasí s částkou řádku.' => 'Posted activity allocation does not reconcile.',
        'Rozpad zaúčtované činnosti je nejednoznačný nebo neúplný.' => 'Posted activity allocation is ambiguous or incomplete.',
        'Zařazení činnosti na zaúčtovaném řádku chybí nebo není platné.' => 'Posted activity classification is missing or invalid.',
        'Datum výkazu leží mimo účetní období.' => 'Statement date is outside the accounting period.',
        'Definice výkazu 504 není úplná.' => 'The 504 statement definition is incomplete.',
        'Definice výkazu 504 chybí nebo zatím není podporovaná.' => 'The 504 statement definition is missing or unsupported.',
        'Mapování účtu neodpovídá účtové skupině nebo řádku výkazu 504.' => 'The account mapping conflicts with the 504 account group or statement row.',
        'Účet výsledku běžného období nesouhlasí s výsledovkou.' => 'The current result account does not reconcile to profit and loss.',
        'Před sestavením oficiální rozvahy 504 je nutné vypořádat výsledek ve schvalovacím řízení.' => 'The pending result must be resolved before an official 504 balance sheet can be produced.',
        'Typ zaúčtovaného účtu neodpovídá účtové třídě vyhlášky 504.' => 'The posted account type conflicts with the 504 account class.',
        'Vybraná dimenze a činnost vyžadují společnou alokaci.' => 'The selected dimension and activity require a joint allocation.',
        'Editor mapování výkazů pro rámec 504 zatím není k dispozici.' => 'The statement mapping editor is not available for framework 504.',
        'Vlastní účet nemá ověřenou vazbu na účetní rámec.' => 'The custom account has no verified binding to the accounting framework.',
        'Systémovou dimenzi ani význam použité hodnoty nelze změnit. Pro nový význam založte novou hodnotu.' => 'A system dimension or the meaning of a used value cannot be changed. Create a new value for a new meaning.',
        'Kód systémové dimenze již používá vlastní typ. Vlastní typ nelze automaticky převést.' => 'A custom type already uses the system dimension code. A custom type cannot be converted automatically.',
        'Kód systémové hodnoty již používá vlastní hodnota.' => 'A custom value already uses the system value code.',
        'Zápis nelze zaúčtovat bez povinných systémových dimenzí.' => 'An entry cannot be posted without the required system dimensions.',
        'Systémová dimenze nepatří firmě.' => 'The system dimension does not belong to this company.',
        'Rozdělení více systémových dimenzí musí mít společné alokační řádky.' => 'Splitting multiple system dimensions requires shared allocation rows.',
        'Hodnota systémové dimenze nepatří firmě nebo typu.' => 'The system dimension value does not belong to this company or type.',
        'Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.' => 'A closed system dimension value cannot be assigned to a new row.',
        'Převod účetnictví je podporován pouze pro období vedená celé v rámci vyhlášky 500.' => 'Accounting migration is supported only for periods maintained entirely under Decree 500.',
        'Neplatný původ přiřazení dimenze.' => 'Invalid dimension assignment origin.',
        'Protizápis neodpovídá živému zaúčtovanému zdroji.' => 'The reversal does not match a live posted source entry.',
        'Konec intervalu nesmí předcházet začátku.' => 'The interval end must not precede its start.',
        'ARES je dočasně nedostupný.' => 'ARES is temporarily unavailable.',
        'Přešifrování je potřeba potvrdit.' => 'The re-encryption has to be confirmed.',
        'Přebalení je potřeba potvrdit.' => 'The re-wrap has to be confirmed.',
        'Smazání nešifrovaných dokumentů osob po výmazu je nevratné a je potřeba ho potvrdit zvlášť.' => 'Deleting unencrypted documents of erased persons is irreversible and has to be confirmed separately.',
        'Aktuální heslo není správné.' => 'Current password is incorrect.',
        'Aplikace ještě není inicializovaná. Otevřete /setup pro vytvoření admin účtu.' => 'Application is not initialized yet. Open /setup to create the admin account.',
        'Chybí invoice_id.' => 'Missing invoice_id.',
        'Chybí project_id.' => 'Missing project_id.',
        'Chybí title.' => 'Missing title.',
        'Chyba konfigurace serveru.' => 'Server configuration error.',
        'Canonical hostname koliduje s vlastní doménou firmy.' => 'The canonical hostname conflicts with a company custom domain.',
        'Canonical přechod není pro tuto cestu povolený.' => 'Canonical handoff is not allowed for this path.',
        'DIČ musí mít prefix země a 2-12 znaků (např. CZ12345678, NL123456789B01).' => 'VAT ID must have a country prefix and 2–12 characters (e.g. CZ12345678, NL123456789B01).',
        'Dobropis ani storno nelze stornovat.' => 'Credit note and cancellation cannot be cancelled.',
        'DNS nebo HTTPS ověření domény selhalo.' => 'DNS or HTTPS domain verification failed.',
        'Doména musí mít čerstvě ověřené DNS a HTTPS.' => 'The domain must have freshly verified DNS and HTTPS.',
        'Doména se během ověření změnila; spusť kontrolu znovu.' => 'The domain changed during verification; run the check again.',
        'Doména se před ověřením změnila; spusť kontrolu znovu.' => 'The domain changed before verification; run the check again.',
        'Demo režim umožňuje funkce vyzkoušet, změny se ale neukládají.' => 'Demo mode lets you try features, but changes are not saved.',
        'Email je už registrovaný.' => 'Email is already registered.',
        'Email se nepodařilo odeslat: ' => 'Failed to send email: ',
        'Faktura byla mezitím změněna.' => 'Invoice has been modified in the meantime.',
        'Faktura musí obsahovat alespoň jednu položku.' => 'Invoice must contain at least one item.',
        'Fakturu s částkou k úhradě 0 nebo méně nelze označit jako zaplacenou.' => 'An invoice with amount due 0 or less cannot be marked as paid.',
        'Hesla se neshodují.' => 'Passwords do not match.',
        'Heslo musí mít alespoň 12 znaků.' => 'Password must be at least 12 characters.',
        'Hostname nastavený v app.url nelze použít jako vlastní doménu firmy. Zadejte jiný hostname.'
            => 'The hostname configured in app.url cannot be used as a company custom domain. Enter a different hostname.',
        'Interní storno se klientovi neposílá.' => 'Internal cancellation is not sent to the client.',
        'IČO musí mít 8 číslic.' => 'Reg. No. must have 8 digits.',
        'Jméno je povinné.' => 'Name is required.',
        'Lze označit jako zaplacené jen vystavenou nebo odeslanou fakturu.' => 'Only an issued or sent invoice can be marked as paid.',
        'Lze poslat jen vystavenou fakturu.' => 'Only an issued invoice can be sent.',
        'Lze pouze ze zálohové faktury (proforma).' => 'Only allowed from a proforma invoice.',
        'Lze smazat jen draft fakturu (vystavenou jen storno/dobropis).' => 'Only a draft invoice can be deleted (issued ones only via cancel/credit note).',
        'Lze vystavit jen draft fakturu.' => 'Only a draft invoice can be issued.',
        'Lze zrušit jen vystavenou/odeslanou/zaplacenou fakturu.' => 'Only an issued/sent/paid invoice can be cancelled.',
        'Měna nenalezena.' => 'Currency not found.',
        'Množství nesmí být 0.' => 'Quantity must not be 0.',
        'Měna s tímto kódem už existuje.' => 'A currency with this code already exists.',
        'Nelze deaktivovat posledního aktivního admina.' => 'Cannot deactivate the last active admin.',
        'Nelze odebrat admin roli ani deaktivovat posledního aktivního admina.' => 'Cannot remove the admin role or deactivate the last active admin.',
        'Nelze parsovat: ' => 'Cannot parse: ',
        'Nelze smazat vlastní účet.' => 'Cannot delete your own account.',
        'Nelze vytvořit ZIP.' => 'Cannot create ZIP.',
        'Není vybrána žádná faktura.' => 'No invoice selected.',
        'Neplatná role.' => 'Invalid role.',
        'Neplatné datum.' => 'Invalid date.',
        'Neplatné přihlašovací údaje.' => 'Invalid credentials.',
        'Neplatný email.' => 'Invalid email.',
        'Neplatný kód měny.' => 'Invalid currency code.',
        'Neplatný nebo chybějící CSRF token.' => 'Invalid or missing CSRF token.',
        'Neplatný token.' => 'Invalid token.',
        'Nepodařilo se vygenerovat PDF: ' => 'Failed to generate PDF: ',
        'Nepřihlášený uživatel.' => 'Not authenticated.',
        'Nová hesla se neshodují.' => 'New passwords do not match.',
        'Origin nesedí s app URL.' => 'Origin does not match the app URL.',
        'Parametr month musí být YYYY-MM.' => 'Parameter "month" must be YYYY-MM.',
        'Platnost tokenu vypršela.' => 'Token has expired.',
        'Pouze admin nebo účetní.' => 'Admin or accountant only.',
        'Pro pokračování je nutné aktivovat dvoufaktorové ověření.' => 'You must activate two-factor authentication to continue.',
        'Proforma musí být označená jako zaplacená.' => 'Proforma must be marked as paid.',
        'Produkční provoz nelze aktivovat, dokud aktuální mzdová data vyžadují nepodporovaný scénář.' => 'Production operation cannot be enabled while current payroll data requires an unsupported scenario.',
        'Příliš mnoho pokusů. Zkus to později.' => 'Too many attempts. Try again later.',
        'Setup již proběhl.' => 'Setup has already been completed.',
        'Soubor chybí.' => 'File missing.',
        'Soubor je prázdný.' => 'File is empty.',
        'Storno doklad nelze editovat.' => 'A cancellation document cannot be edited.',
        'Storno nedostává varsymbol.' => 'A cancellation document does not get a variable symbol.',
        'Supplier nevyplněn (spusť setup).' => 'Supplier not configured (run setup).',
        'Tato IP adresa nemá přístup k aplikaci.' => 'This IP address is not allowed to access the application.',
        'TOTP už je aktivní. Pro reset použij: php api/bin/reset-2fa.php <email>.' => 'TOTP is already enabled. To reset it, use: php api/bin/reset-2fa.php <email>.',
        'Token nebo heslo chybí.' => 'Token or password missing.',
        'Token už byl použit.' => 'Token has already been used.',
        'Upomínat lze jen faktury s kladnou částkou k úhradě.' => 'Only invoices with a positive amount due can be reminded.',
        'Uživatel nenalezen.' => 'User not found.',
        'Vystavenou fakturu nelze editovat.' => 'An issued invoice cannot be edited.',
        'Vyžaduje se CAPTCHA.' => 'CAPTCHA required.',
        'Výkaz lze smazat pouze v draftu (admin: ?force=1).' => 'Work report can only be deleted on a draft (admin: ?force=1).',
        'Výkaz lze upravit pouze v draftu (admin: ?force=1).' => 'Work report can only be edited on a draft (admin: ?force=1).',
        'Výpis nenalezen.' => 'Bank statement not found.',
        'Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.' => 'Amount due must be greater than 0. Use a credit note for a zero or negative document.',
        'Zakázka nenalezena.' => 'Project not found.',
        'Záporné množství i záporná cena zároveň nejsou povolené.' => 'Negative quantity and negative unit price at the same time are not allowed.',
        'Země s tímto iso2 už existuje.' => 'A country with this iso2 already exists.',
        'Záloha nesmí být záporná.' => 'Advance payment must not be negative.',
        'cfg.bank_import.scan_root není nastaveno nebo adresář neexistuje.' => 'cfg.bank_import.scan_root is not set or the directory does not exist.',
        'cfg.smtp.from_email není nastaveno.' => 'cfg.smtp.from_email is not set.',
        'code a rate_percent jsou povinné.' => '"code" and "rate_percent" are required.',
        'code musí být 3 znaky.' => '"code" must be 3 characters.',
        'iso2 musí být 2 znaky.' => '"iso2" must be 2 characters.',
        'mode musí být "internal" nebo "credit_note".' => '"mode" must be "internal" or "credit_note".',
        'Žádný platný příjemce (chybí email klienta).' => 'No valid recipient (client email missing).',
        // Změna druhu dokladu u DDKP (§ 28 ZDPH) — hlášky nesou důvod, proč to nejde.
        'Daňový doklad k platbě je navázaný na zálohovou fakturu a jeho DPH už byla uplatněna — druh dokladu proto změnit nelze. Nejdřív zrušte vazbu na zálohu, nebo doklad stornujte.' => 'The tax document for a payment is linked to an advance invoice and its VAT has already been claimed, so the document kind cannot be changed. Remove the advance link first, or cancel the document.',
        'Daňovým dokladem k platbě je už vyúčtovaná konečná faktura — druh dokladu proto změnit nelze. Nejdřív zrušte vyúčtování na konečné faktuře.' => 'A final invoice already settles this tax document for a payment, so the document kind cannot be changed. Remove the settlement on the final invoice first.',
        // Otevírací rozvaha aktivace — rozhraní tyhle věty ukazuje doslova (jsou to jediné
        // místo, kde je vidět KTERÝ řádek je vadný), takže musí být přeložitelné.
        'Otevírací zápis patří uzávěrce předchozího období.' => 'The opening entry belongs to the previous period closing.',
        'Období zahájení účetnictví není otevřené.' => 'The accounting start period is not open.',
        'Otevírací rozvaha není vyrovnaná.' => 'The opening balance is not balanced.',
        'Otevírací rozvaha neobsahuje žádné řádky.' => 'The opening balance has no rows.',
        'Strana musí být MD nebo D a částka musí být kladná.' => 'Side must be Dr or Cr and the amount must be positive.',
        'Řádek rozvahy není platný.' => 'The opening balance row is not valid.',
        'Účet 701 doplní systém automaticky.' => 'Account 701 is added by the system automatically.',
        'Počáteční účet rozvažný doplní systém automaticky.' => 'The opening balance account is added by the system automatically.',
        'Převod počátečních stavů mezi účetními rámci zatím není podporován.' => 'Transferring opening balances between accounting frameworks is not supported yet.',
        'Tato operace není v daném účetním rámci podporována.' => 'This operation is not supported in this accounting framework.',
        'Výsledek hospodaření nesouhlasí s převáděnými výsledkovými zůstatky.' => 'The profit or loss does not match the result balances being transferred.',
        'Účet není v aktivní účtové osnově: ' => 'Account is not in the active chart of accounts: ',
        'Účet je na stejné straně uveden vícekrát: ' => 'The account appears twice on the same side: ',
        // Časté hlášky používané v jednom callsite, ale stejný text se opakuje (např. not_found):
        'Klient nenalezen.' => 'Client not found.',
        'Faktura nenalezena.' => 'Invoice not found.',
        'Validace selhala' => 'Validation failed',
        // H-02 — spravovaná instalace. Texty jsou SSOT ve
        // {@see \MyInvoice\Service\System\ManagedModeGuard}; při jejich změně
        // je nutné přepsat i tenhle překlad, jinak zůstane EN uživatel u CZ věty.
        'Tohle je spravovaná instalace — aktualizace nasazuje provozovatel, aby na všech instancích běžela stejná verze.' => 'This is a managed installation — updates are deployed by the operator so that every instance runs the same version.',
        'Adresu aplikace nastavuje ve spravované instalaci provozovatel — je navázaná na směrování požadavků i na licenci.' => 'In a managed installation the application URL is set by the operator — request routing and the licence both depend on it.',
        'Odesílání e-mailů zajišťuje ve spravované instalaci provozovatel. Vlastní SMTP server ani obálkovou adresu tu nastavit nelze; odesílatele (From, Reply-To) měnit můžete.' => 'In a managed installation e-mail is sent by the operator. A custom SMTP server or envelope address cannot be set here; you can still change the sender (From, Reply-To).',
        'Skenování adresářů na serveru je ve spravované instalaci vypnuté. Výpisy i doklady nahrávejte souborem nebo e-mailem.' => 'Scanning server directories is disabled in a managed installation. Upload statements and documents as files or send them by e-mail.',
        'Prostředí daňové správy nastavuje ve spravované instalaci provozovatel — ostrá instalace podává vždy naostro.' => 'In a managed installation the tax authority environment is set by the operator — a production installation always files for real.',
        'Ladicí režim ve spravované instalaci zapíná provozovatel.' => 'In a managed installation debug mode is switched on by the operator.',
        'Demo režim ve spravované instalaci není k dispozici.' => 'Demo mode is not available in a managed installation.',
        'Ve spravované instalaci nastavuje doménu provozovatel — certifikát a směrování pro vlastní doménu je potřeba zřídit na jeho straně.' => 'In a managed installation the domain is set by the operator — the certificate and routing for a custom domain have to be provisioned on their side.',
        'Tuhle věc drží ve spravované instalaci provozovatel — z aplikace ji změnit nelze.' => 'In a managed installation this is held by the operator — it cannot be changed from the application.',
    ];

    /**
     * Vrátí EN překlad pokud existuje v katalogu, jinak původní text.
     * Pro $locale = 'cs' (default) vrací vždy původní.
     *
     * Ošetřuje i prefix-match: pokud je v katalogu klíč "Email se nepodařilo odeslat: "
     * a vstup je "Email se nepodařilo odeslat: connection timeout", přeloží prefix.
     */
    public static function lookup(string $cs, string $locale): string
    {
        if ($locale === 'cs' || $cs === '') return $cs;
        if (isset(self::MAP[$cs])) return self::MAP[$cs];
        // Prefix-match pro hlášky končící zprávou výjimky
        foreach (self::MAP as $key => $en) {
            if (str_ends_with($key, ': ') && str_starts_with($cs, $key)) {
                return $en . substr($cs, strlen($key));
            }
        }
        return $cs;
    }
}
