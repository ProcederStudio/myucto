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

Správa složek a potvrzení verze mají samostatná oprávnění. Potvrzení uloží novou neměnnou verzi a její původní podklady. Nevystavuje předpis, neurčuje dlužníka a neúčtuje. U změn záloh na služby bude při vystavení potřeba doložit písemné oznámení, jeho doručení a odůvodnění; samotná sazba jejich splnění nepotvrzuje.

Historie zůstává čitelná i po vypnutí profilu. Veřejné API a MCP poskytují složky a přesné historické výpočty bez právní historie osob a rozhodovacích podkladů.

## Reset dat

Reset se zachováním účtu a konfigurace firmy odstraní registr, jeho systémové dimenze jednotek a služeb i alokační klíče, které na ně odkazují. Vlastní dimenze a ostatní alokační klíče zachová. Pokud má zachovaná vlastní hodnota jako nadřazenou jednotku nebo službu registru, reset se zastaví před mazáním; nejprve opravte tuto vazbu.
