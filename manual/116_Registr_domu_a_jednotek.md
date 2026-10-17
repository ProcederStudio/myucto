# 116. Registr domů a jednotek SVJ

Registr najdete v nabídce **Registr SVJ**. Eviduje domy, jednotky, služby a jejich časově účinné právní podklady. Nejde o seznam kontaktů: jedna vlastnická účast může zahrnovat více osob a jedna osoba může mít různé doložené role.

## Profil a oprávnění

Nejprve potvrďte profil SVJ, okamžik jeho účinnosti a právní podklad. Registr vyžaduje účetní rámec podle vyhlášky 504 k příslušnému datu. Právní forma z ARES slouží jako nápověda, zapnutí profilu nenahrazuje.

Čtení, správa podkladů, jejich potvrzování a export mají samostatná oprávnění. Uživatel může připravit čekající podklad, který následně potvrdí oprávněná osoba. Deaktivace profilu ponechá historii dostupnou. Firma ani kontakt s právní historií registru se nedají tvrdě smazat; kontakt můžete archivovat.

## Okamžik účinnosti a historie

Datum, čas a časový posun určují skutečný okamžik účinnosti. Například `1. 7. 2026`, `00:00:00` a `+02:00` znamenají začátek místního letního dne. Samotné datum není úplný okamžik. Neplatný text data opravte před odesláním formuláře.

Horní výběr určuje, ke kterému okamžiku chcete registr zobrazit. Volitelný okamžik znalosti dovoluje zjistit, jaké podklady byly tehdy zaznamenané. Změna účinnosti není přepsáním původního záznamu: každá potvrzená revize zůstává součástí historie.

## Domy, služby a jednotky

U domu uveďte označení, adresu, podklad prohlášení a okruh podílů. Úplnost registru potvrďte až po zanesení všech jednotek příslušného okruhu. Souhrn porovnává přesný součet podílů s jedním celkem a ukazuje, kde je třeba doplnit podklady.

Služby mají vlastní číselník. U jednotky vyberte dům, druh jednotky, služby a doložené vlastnosti. Podíly a další číselné vlastnosti zadávejte čitatelem a jmenovatelem. Zlomek `1/3` se neukládá jako zaokrouhlené desetinné číslo.

SJM tvoří jednu vlastnickou účast se dvěma členy. Zadáním obou manželů nevzniknou dva dluhy ani dvě poloviny. Spoluvlastnické účasti, zástupce, adresát a plátce mají vlastní doložený titul. Samotné uvedení plátce neurčuje příjemce případného přeplatku. Registr sám nepotvrzuje solidaritu, přechod dluhu ani nárok na kredit.

Potvrzené jednotky a služby získají stabilní systémové hodnoty dimenzí. Nové přiřazení musí odpovídat jejich skutečné časové platnosti i aktivnímu profilu. Pro účetní datum bez času se ověřuje celý místní den. Historické podklady zůstávají čitelné i po pozdější změně.

## Import a export

CSV nebo XLSX nejprve otevřete v náhledu. Osoby přiřaďte ke kontaktům dané firmy a služby k jejímu číselníku. Import kontakty automaticky nevytváří. Zkontrolujte podíly, členy účastí, role a účinnost; změna podkladů po náhledu vyžaduje nový náhled.

JSON export je samostatně oprávněná a auditovaná operace. Obsahuje právní podklady a účastníky, proto je dostupný z webového rozhraní. Veřejné API a MCP poskytují přehled číselníků a úplnosti evidence bez historie osob a platebních dispozic.

## Složky a verze předpisů

V nabídce **Složky a předpisy** nejprve vyberte dům a okamžik účinnosti. Složky rozlišují zálohy na správu, dlouhodobé zálohy, služby a doložené definitivní příspěvky. U nové složky vyplňte kód, název, druh a účinnost; záloha na služby má navíc vazbu na konkrétní službu registru.

Nová verze sazeb obsahuje celé rozhodnutí pro vybrané jednotky. Zadejte účinnost, datum rozhodnutí, jeho označení a důvod. Výslovně zvolte, zda jsou částky měsíční, čtvrtletní, roční nebo jednorázové. Změny uprostřed měsíce se automaticky nekrátí.

U každé složky vyberte konkrétní jednotky, metodu výpočtu a právní podklad. Zákonný podílový klíč a rovný díl vlastní správy vyžadují potvrzenou úplnost registru domu. Rozhodné osoby pro služby jsou samostatným atributem jednotky; počet vlastníků se k výpočtu nepoužívá. SJM ani spoluvlastnictví nenásobí jednotkovou částku. Rozpočet se po ukončení jednotky bez nového rozhodnutí nepřerozděluje.

Náhled ukazuje částky po jednotkách, zaokrouhlení, rozdíly proti dřívějšímu uloženému stavu a změnu samotných sazeb na stejném novém základu. Zkontrolujte také překryvy s budoucími naplánovanými verzemi. Zbylé haléře připadnou největším zlomkovým zbytkům; při shodě rozhoduje stabilní pořadí jednotek.

Správa složek a potvrzení verze mají samostatná oprávnění. Potvrzení uloží novou neměnnou verzi a její původní podklady. Nevystavuje předpis, neurčuje dlužníka a neúčtuje. U změn záloh na služby je při vystavení potřeba doložit písemné oznámení, jeho doručení a odůvodnění; samotná sazba jejich splnění nepotvrzuje.

Historie zůstává čitelná i po vypnutí profilu. Veřejné API a MCP poskytují složky a přesné historické výpočty bez právní historie osob a rozhodovacích podkladů.

## Vydání a účtování předpisů

V nabídce **Vydání předpisů** vyberte dům, rozhodný okamžik registru a potvrzenou verzi sazeb. Zadejte období, vznik pohledávky, okamžik vyhotovení, účetní datum, splatnost a právní titul. Při změně firmy nebo rozhodného okamžiku načtěte podklady znovu. Předpisy jsou vedené v Kč.

Poté vyberte existující případ pro pokračování či opravu, nebo založte nový doložený případ. U nového případu uveďte jeho označení, konkrétní výskyt, právní titul, původní rozhodnutí, datum rozhodnutí a podklad. Stejný případ nelze vydat znovu pod jiným technickým identifikátorem, ani když byl původní předpis zrušen nebo stornován. Pokud se nový nárok překrývá s jiným případem jednotky, doložte jeho samostatnost vůči každému uvedenému případu. Opravy zachovávají původní označení a odlišení; u starších předpisů bez těchto údajů se historie zpětně nedoplňuje.

U každé jednotky potvrďte zavázané osoby, právní důvod, rozsah a způsob jejich odpovědnosti. Více osob u SJM představuje jednu společnou pohledávku. Samotné vlastnictví ani kontaktní role bez doloženého titulu nevytvářejí další dluh. Pro každou složku potvrďte pohledávkový účet 315, protiúčet, účetní podklad a případné další dimenze. Jednotka a služba se doplní z původních podkladů.

Změna zálohy na služby vyžaduje odůvodněné písemné oznámení a důkaz doručení všem dotčeným osobám, případně jejich oprávněnému zástupci. U měsíčních záloh lze změnu uplatnit nejdříve od měsíce následujícího po doručení. U jiné četnosti doložte také sjednanou účinnost a její právní podklad. Část období se automaticky nekrátí.

Nejprve vytvořte a zkontrolujte náhled. Vydání uloží neměnné předpisy a jejich podklady. Samostatná akce **Zaúčtovat dávku** vytvoří zápisy do deníku; vydání, zaúčtování a storno mají oddělená oprávnění. Vydaná částka není aktuálním saldem po úhradách.

Nezaúčtovaný předpis lze zrušit. Zaúčtovaný předpis opravte doloženým rozdílovým předpisem při zachování původních osob, období, účtů a dimenzí, nebo úplným stornem a novým předpisem. Náhradní plný předpis lze vydat až po zrušení či stornu všech původních a rozdílových předpisů daného případu. Storno může směřovat do otevřeného navazujícího období. Předchozí účetní zápisy se nepřepisují. Přímé úpravy těchto zápisů v obecném deníku nejsou povolené.

Detail uchovává původní podklady a další doložené události, například doručení upomínky nebo posouzení odpovědnosti. Zápis doručení sám zprávu neodesílá. Kontakty použité v právní historii archivujte místo mazání. API a MCP poskytují finanční řádky, stav a účetní vazby předpisů bez osobních a právních podkladů.

## Platby jednotek

V nabídce **Platby jednotek** zpracujete úhrady předpisů, samostatné kredity a jejich vracení. Zvolte firmu a rozhodný okamžik registru, poté načtěte podklady. Přehledy mají filtry jednotky a tlačítko **Načíst další**. Čtení, správa plateb, jejich zaúčtování a storna vyžadují příslušná oprávnění; pořízení nebo zaúčtování bankovního či pokladního zdroje vyžaduje také oprávnění dané agendy. Tento postup používá Kč.

### Dluhy a příslušenství

Na záložce **Dluhy a kredity** otevřete **Registrace dluhu**. Vyberte předpis a jeho kladné dosud nezaregistrované řádky jistiny. Doplňte označení titulu, splatnost, právní základ, režim a zajištění. Jeden předpis může obsahovat více samostatných dluhů. Společný dluh více osob zůstává jedním dluhem; počet členů nezvyšuje jeho částku.

V detailu dluhu lze navázat již zaúčtovanou samostatnou pohledávku jako příslušenství. Vyberte její složku a doložte titul i odpovědnou skupinu osob. Samotné navázání nový úrok nevypočítává.

### Přijetí, výplata a rozpis platby

Na záložce **Přijmout / vyplatit** vyberte bankovní nebo pokladní platbu, případně pořiďte nový pokladní doklad. Rozpis může obsahovat úhrady vybraných dluhů, doložené kredity a vratky rezervovaných kreditů.

U úhrady určete skutečného plátce, dlužníka, okamžik přijetí a hrazené dluhy. Okamžik zahrnuje čas i časový posun a jeho místní datum musí odpovídat zdroji platby. Platí-li třetí osoba, doložte její právní důvod. Volbu dluhů dlužníkem, porovnání zajištění, doručené upomínky nebo zvláštní rozpis složek zadávejte podle podkladů; aplikace je neodhaduje z variabilního symbolu či jména plátce.

Společné analytické zařazení rozdělte na přesné částky a úplné kombinace dimenzí. Nevyjasněnou jednotku ponechte nepřiřazenou. Tlačítkem **Zkontrolovat rozpis** připravte náhled a ověřte vypořádanou část i zbytek. **Potvrdit a zaúčtovat** uloží zdroj a rozpis společně. Po změně zadání je potřeba nový náhled.

Nevyjasněný zbytek zůstává na zúčtování platby a sám nevytváří dluh jednotky ani kredit jiné osoby. Jeho volné analytické zařazení můžete opravit s doloženým důvodem do otevřeného data; již vypořádané části tím nepřepisujete. Historie potvrzených rozpisů uvádí částky platné při potvrzení, nikoli dnešní dostupný zůstatek.

### Kredity, zápočet a rezervace

Při vytvoření kreditu výslovně určete jednotku, všechny oprávněné osoby, doklad nároku a splatnost. Příjemce kreditu se neodvozuje automaticky z plátce ani z aktuálního vlastníka. Společný nárok více osob zůstává jednou částkou.

V přehledu kreditů rozlišujte zůstatek, rezervovanou a dostupnou částku. Pro **Zápočet kreditu** vyberte dluhy téže oprávněné osoby nebo skupiny a doložte vzájemnost, způsobilost, projev zápočtu, jeho doručení a účinek. Po kontrole náhledu zápočet zaúčtujte.

Pro vrácení nejprve vytvořte rezervaci: uveďte částku, určení vratky a způsob vyplacení. Pro bankovní vratku zadejte účet příjemce, pro hotovostní skutečného příjemce a jeho podklad. Rezervovanou částku už nelze souběžně použít jinak. Zrušení nevyplacené rezervace nebo uvolnění jejího nevyplaceného zbytku vyžaduje důvod.

### Vratka a soubor ABO

Vratku na záložce **Přijmout / vyplatit** navažte na rezervaci a skutečný odchozí zdroj. Při hotovostní výplatě doložte osobu, která hotovost převzala, její jméno a doklad převzetí. Samotná rezervace ani vytvoření příkazu neznamenají zaplacení.

V části **Rezervace a vratky** označte bankovní rezervace ve stavu **Rezervováno**. Pro ABO vyberte účet firmy, splatnost a důvod; u každé platby zadejte variabilní symbol, nebo výslovně zvolte příkaz bez něj. Po kontrole a potvrzení stáhněte soubor a předejte jej bance. Vyřízení platby se dokládá následným bankovním pohybem.

Existující export je u rezervace dostupný i po novém načtení stránky. Zobrazuje název souboru a aktuální stavy rezervací celé dávky; jeho stažení nevytváří nový příkaz. U dávky se zaplacenou nebo zrušenou rezervací se zobrazí archivní upozornění. Archivní soubor znovu neposílejte jako nový příkaz. Zjistí-li se změna stavu až při stahování, nejprve se zobrazí upozornění a stažení musíte znovu výslovně zvolit.

Odmítnutý nebo zrušený bankovní příkaz řešte akcí pro zrušení předání s důvodem a dokladem. Ta neruší příkaz v bance; jeho stav nejprve ověřte a vyřešte také tam.

### Opravy, storna a karta

Na záložce **Opravy a historie** dohledáte operace i jejich detaily. Záporný rozdílový předpis navažte na původní řádky a doložte úlevu z konkrétních úhrad. Případný kredit musí mít určené oprávněné osoby. Storno vyžaduje datum a důvod, u opravy také okamžik účinnosti a podklad. Použitý či rezervovaný výsledný kredit může stornu bránit. Původní účetní zápisy se nepřepisují.

Na záložce **Karta** zadejte stav poznání k okamžiku a případně omezte jednotku nebo osobu. Rozhodný okamžik určuje, k jakému stavu se díváte; okamžik znalosti určuje, které tehdy zaznamenané podklady smí výsledek použít. Karta odděluje hrubé pohledávky, kredity, rezervace, dostupné kredity a expozici z ručení. Dluh jednoho vlastníka se neskrývá kreditem jiného a kredit automaticky nepřechází na nového vlastníka. Popisky osob a jednotek jsou z aktuální evidence.

Respektujte upozornění na neúplnost a přesnost jen na den. Hodnota **Neznámé** není nula. Karta pokrývá zaznamenané předpisy, platby a doložené převzetí počátečních osobních pohledávek a kreditů. Vyúčtování služeb není zahrnuté. Samotná karta nenahrazuje potvrzení dluhu.

### Stránkované saldo přes API a MCP

Veřejné API `GET /api/v1/property/catalog/payments/saldo` a MCP nástroj `list_property_saldo` poskytují samostatný souhrn jednotek při oprávnění číst registr. Zadejte `as_of` a `known_at`; `limit` je 1 až 100 jednotek, výchozí hodnota 25. Pro další stránku předejte nezměněný `next_cursor` jako `cursor` a zachovejte oba časy i firmu. Jednotky včetně neaktivních a ukončených jsou řazeny podle stabilního ID.

Souhrn odděluje hrubé pohledávky, evidované příslušenství, jistinu, kredity, rezervované a dostupné kredity. Příslušenství představuje doložené pohledávky po jejich úhradách, nikoli automaticky dopočítané úroky. Částky jsou haléřové řetězce; `null` znamená neznámý výsledek. Zohledněte také `precision`, `coverage` a počty nejistých událostí či právních podkladů k prověření. Doložené počáteční pohledávky a kredity jsou zahrnuté. Vyúčtování služeb není pokryté; souhrn nepotvrzuje úplnou finanční pozici.

Každá stránka má vlastní konzistentní snapshot. Více stránek nemusí při souběžných změnách představovat jeden společný stav firmy. `read_scope_digest` se vztahuje k ověřeným finančním a právním návaznostem vybraných jednotek, není otiskem plné karty ani oprávněním zaúčtovat platbu. Podrobné historie poskytuje samostatná karta jednotky. Popisky jednotek jsou aktuální.

## Reset dat

Reset se zachováním účtu a konfigurace firmy odstraní registr, jeho systémové dimenze jednotek a služeb i alokační klíče, které na ně odkazují. Vlastní dimenze a ostatní alokační klíče zachová. Pokud má zachovaná vlastní hodnota jako nadřazenou jednotku nebo službu registru, reset se zastaví před mazáním; nejprve opravte tuto vazbu.


## Saldo a právní historie

V **Úhrady SVJ → Saldo a právní historie** zvolte okamžik stavu a okamžik znalosti. Přehled načítá 25, 50 nebo 100 jednotek na stránce, včetně jednotek s historickými záznamy. Každá stránka má vlastní konzistentní snímek. Stránky proto nesčítejte jako ověřený souhrn celé firmy.

Pohledávky, jistina, evidované příslušenství, kredity, rezervace a volné kredity se zobrazují odděleně. Neznámá částka není nula. Přehled upozorňuje na denní přesnost, nevyřešené záporné řádky a potřebné právní posouzení. Zahrnuje evidované předpisy, úhrady a doložené počáteční stavy S3/S4/S6; vyúčtování služeb není zahrnuto. Otisk stránky není oprávněním k zápisu ani potvrzením úplné finanční pozice.

**Karta pohledávek a kreditů** přebírá vybranou jednotku a oba časové okamžiky. Načte se samostatně tlačítkem pro načtení karty. **Právní historie, převody a potvrzení** ukazuje vlastnické revize známé k okamžiku znalosti, přechody dluhů a předchozí i nové skupiny odpovědnosti. Společný dluh se počítá jednou, i když má více zavázaných osob. Ručení se neslučuje s pohledávkou. Jména v historickém přehledu pocházejí z aktuálního adresáře; identitu určuje číslo osoby. Nové osoby v uložené právní události pocházejí z jejích neměnných podkladů.

### Přechod dluhu a ručení

Pracovník s oprávněním připravovat přechody dluhů vybere dluhy, rozhodnutí, druh nabytí, použitelnou právní úpravu a důkazy nabytí, zjistitelnosti dluhu a ručení. U každého dluhu nastaví nové skupiny osob, právní základ, rozsah a platnost. Změna vlastníka sama dluh ani kredit nepřevádí. Nepoužitelnost přechodu a nevyřešené posouzení zachovávají dosavadní právní skupiny; potvrzení bez doložené změny není náhradou právního posouzení.

Náhled ověřuje aktuální podklady, pořadí právních událostí a rozsahy odpovědnosti. Potvrzení vyžaduje samostatné oprávnění potvrzovat právní přechody dluhů. Při změně podkladů nebo po uložení je nutné přehled znovu načíst a podle potřeby upravit okamžik znalosti. Starý náhled nelze použít jako oprávnění k další změně.

### Neměnné potvrzení

Vydání potvrzení vyžaduje samostatné oprávnění, žadatele, doložené oprávnění žadatele, účel a výslovné vymezení neúplného rozsahu. Nejprve zkontrolujte náhled, potom potvrzení vydejte. Neúplné nebo nejisté podklady mohou vydání zablokovat.

Uložené potvrzení se otevírá podle jeho čísla a zobrazuje původní časové okamžiky, podklady a kontrolní otisk. Pozdější převod jej nepřepočítá. Potvrzení se týká pouze evidovaných předpisů, úhrad a doložených počátečních stavů, nikoli bezpodmínečné bezdlužnosti. Právní potvrzení a změny jsou dostupné pouze přihlášenému uživateli aplikace, ne prostřednictvím veřejného API nebo MCP.

## Převzetí počátečních stavů SVJ

V části **Počáteční stavy SVJ** převezmete vlastní strukturované účetní podklady ve formátu `myucto.property.opening.v1`. Soubor JSON obsahuje pole `format` s tímto označením a objekt `request`. Nejde o import formátu jiného účetního programu. ID jednotek, služeb a osob musí odpovídat registru vybrané firmy; historický původ každé položky musí mít doloženou platnou vazbu jednotky a služby.

Zadejte účetní datum, okamžik účinnosti v témže českém kalendářním dni, důvod a podklad převzetí. Úhrada převzaté pohledávky a použití převzatého kreditu v zápočtu nesmí předcházet účinnosti převzetí. Historické datum vzniku původního dluhu tuto hranici neposouvá. Rozlište čtyři kategorie:

- Osobní pohledávka: jediný právní případ s jistinou a případným doloženým příslušenstvím. Více spoludlužníků nevytváří více částek. Předejte právní titul, splatnost, pravidla odpovědnosti, právní režim alokace, zajištění a doložené upomínky.
- Kredit: hrubý závazek na 379.910 s výslovně určenými oprávněnými osobami. Může náležet bývalému vlastníkovi. Převzetí kreditu nevytváří bankovní ani pokladní platbu. Rezervace, zápočet a skutečná vratka používají stejnou evidenci jako ostatní kredity.
- Záloha: dlouhodobá 955 nebo 955.100, správní 324.200 nebo službová 324.1xx. Doložte účel, termín vypořádání, předepsáno, zaplaceno, použito a vazby nezaplacených částek na konkrétní převzaté osobní případy. Zůstatek je předepsáno minus použito.
- Přenesené služby: technické náklady 315.2xx a párové dohady 388.2xx / 389.1xx podle jednotky, služby a období. Předejte profil účtování, příjemce služby, podklad rozdělení, protistranu a předepsané, zaplacené, vypořádané a zrušené neuhrazené částky. Technický účet 315.2xx sám není osobním dluhem.

Částky se předávají jako řetězce celých haléřů. Každý případ má doložené `case_ref` a `occurrence_ref`; jednotlivé složky mají jedinečný `key`, účetní `role`, `account_code` a `amount_cents`. Vazba nezaplacené částky obsahuje stejné reference osobního případu a částku. Všechny zálohy a služby mají samostatně ověřené relační vazby; podklad nezakládá automatické vyúčtování následujícího roku.

Použijte **Nové otevření**, pokud deník ještě neobsahuje otevření. Úplná sestava včetně `other_balances` musí být účetně vyrovnaná. **Navázat existující** vyžaduje ID otevření, jeho aktuální verzi a u každé složky `source_line_id`; nevytváří další účetní částku. Relevantní řádky otevření musí být pokryté celé a přesně jednou. Náhled zobrazí skutečné účetní řádky a aktuální revizi. Potvrzení odmítne mezitím změněný podklad.

Převzetí se eviduje v jednom počátečním období firmy. Běžné otevření dalšího roku nevytváří znovu osobní pohledávky ani kredity. Historii lze stáhnout ve vlastním formátu. Oprava vyžaduje **Revizi** s podkladem, důvodem, novým datem účinnosti a očekávanou revizí; v jedné transakci obrátí původní účetní zápis a vytvoří náhradu. **Storno** vytvoří doložený opačný zápis. Historické původy se nemažou. Navázané úhrady, rezervace či právní události opravu blokují.

Karta rozlišuje rozhodný okamžik a okamžik znalosti: pozdější doložená revize nemění to, co bylo známo dříve. Rekonciliace odděluje osobní pohledávky a kredity od technických služeb. Pokrytí `opening_journals_only` potvrzuje pouze vazby otevření; `complete_financial_position=false` nadále neznamená potvrzení celé finanční pozice.

Veřejné čtení `GET /api/v1/property/catalog/openings` a MCP `list_property_openings` poskytují stránkované účetní role a částky bez právních podkladů a osob. Použijte `after_id` podle `next_cursor` a limit 1 až 100. Zápis, potvrzení a revize vyžadují přihlášenou obsluhu a samostatná oprávnění.

### Příklad vlastního souboru

Následující syntetický příklad zachycuje pohledávku 10 000 Kč a kredit jiné osoby 10 000 Kč. ID, data a označení podkladů nahraďte skutečně doloženými údaji vybrané firmy. Účty musí existovat v její osnově. Příklad nepředstavuje doporučení právního režimu konkrétního případu.

```json
{
  "format": "myucto.property.opening.v1",
  "request": {
    "mode": "post_new",
    "accounting_date": "2026-01-01",
    "effective_at": "2026-01-01T00:00:00+01:00",
    "expected_revision": 0,
    "evidence_ref": "Podepsané převzetí",
    "reason": "Převzetí účetních stavů",
    "items": [
      {
        "unit_id": 1,
        "service_id": null,
        "origin_at": "2026-01-01T00:00:00+01:00",
        "period_from": "2026-01-01",
        "period_to": "2026-01-01",
        "due_date": "2026-01-02",
        "occurrence_ref": "2026",
        "title_ref": "Doložený právní titul",
        "evidence_ref": "Doložená inventura",
        "case_ref": "pohledavka-A",
        "kind": "debt",
        "components": [
          {
            "key": "principal",
            "role": "principal",
            "account_code": "315.100",
            "amount_cents": "1000000"
          }
        ],
        "liability_groups": [
          {
            "group_key": "A",
            "role": "debtor",
            "mode": "individual",
            "basis": "documented_title",
            "members": [
              1
            ],
            "extent": {
              "num": "1",
              "den": "1"
            },
            "evidence_ref": "Doložený titul",
            "legal_reason": "Doložený osobní závazek",
            "valid_from": "2026-01-01T00:00:00+01:00",
            "valid_to": null
          }
        ],
        "legal": {
          "basis": "legal_act",
          "legal_act_date": "2025-12-15",
          "evidence": "Doložené rozhodnutí",
          "regime": "general"
        },
        "security": {
          "kind": "unsecured",
          "evidence": "Doložené posouzení"
        }
      },
      {
        "unit_id": 1,
        "service_id": null,
        "origin_at": "2026-01-01T00:00:00+01:00",
        "period_from": "2026-01-01",
        "period_to": "2026-01-01",
        "due_date": "2026-01-02",
        "occurrence_ref": "2026",
        "title_ref": "Doložený právní titul",
        "evidence_ref": "Doložená inventura",
        "case_ref": "kredit-B",
        "kind": "credit",
        "components": [
          {
            "key": "credit",
            "role": "credit",
            "account_code": "379.910",
            "amount_cents": "1000000"
          }
        ],
        "creditor_ids": [
          2
        ]
      }
    ],
    "other_balances": []
  }
}
```


## Vyúčtování služeb SVJ

V agendě **Vyúčtování služeb SVJ** připravíte dokument pro jednu jednotku, službu a doložený rozsah. Příprava dokumentu, jeho uložení a účetní aplikace jsou samostatné kroky. Agenda nemění aktivaci účetního rámce firmy.

1. Zvolte jednotku a službu z registru, referenci vyúčtování a období. Vyplňte účetní datum, rozhodný okamžik úhrad, stav znalostí a reference podkladů. Příjemce vyberte z adresáře; nárok se neodvozuje automaticky z vlastnictví.
2. Vyhledejte doložené zálohy mezi vystavenými předpisy nebo převzatými počátečními stavy. Katalog uvádí popis, datum, období a částku. U vystavených předpisů vyberte také odpovídající náklad 315.2; převzatý službový náklad je součástí příslušného počátečního stavu.
3. Otevřete náhled dokumentu. Server stanoví **Z** (předepsané zálohy), **P** (úhrady k rozhodnému okamžiku) a **S** (doložené skutečné náklady). Teprve potom dokument uložte. Pozdější platby původní částky dokumentu nemění.
4. Pro účetní aplikaci načtěte aktuální podklady k účetnímu datu. Přehled odděluje převzaté historické úhrady, současné fyzické platby a neuhrazený původní dluh. Pozdější platby jsou označené samostatně.
5. Výslovně rozdělte použití záloh. Pokud vzniká kredit, zvolte konkrétní část převzatých úhrad nebo konkrétní fyzickou platbu, která se má uvolnit. Vyplňte příjemce, doložený titul, splatnost a účet z osnovy. Nový nárok vyžaduje dlužníky, právní titul a skupiny odpovědnosti odpovídající příjemcům dokumentu.
6. Zkontrolujte serverový náhled účetní aplikace. Ukazuje použití záloh, snížení původního dluhu, zrušenou neuhrazenou část, uvolněné platby, kredit, nový nárok a účetní zápis. Zaúčtování vyžaduje samostatné oprávnění.

Aktuální souhrn u dokumentu ukazuje pouze jeho navazující nové nároky a kredity. Jistinu, fyzické úhrady a příslušenství zobrazuje odděleně; u kreditu rozlišuje zůstatek, rezervaci a dostupnou část. Nejde o celkové saldo firmy ani úplný výrok o osobní odpovědnosti. Původní dluhy a celou finanční pozici ověřujte na kartě jednotky.

Účetní storno se vztahuje ke konkrétní aplikaci. Před potvrzením zkontrolujte její dopady, datum a odůvodnění. Server odmítne storno, pokud tomu brání navazující použití. Dokument se nepřepisuje; po stornu připravte novou revizi se stejnou referencí. Historie zachovává původní dokumenty, aplikace a storna.

Při změně podkladů nebo konfliktu verze obnovte data a vytvořte nový náhled. Aplikace zápis neopakuje automaticky s novým ověřením. Čtení, příprava, zaúčtování a storno respektují oddělená oprávnění; veřejné API a MCP poskytují pouze kurátorované čtení bez právních podkladů a zdrojových důkazů.
