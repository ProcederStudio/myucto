# 40. Fakturujeme - daňový průvodce

> Návod, jak správně vystavovat doklady z daňového hlediska: nastavit
> plátcovství, zvolit sazbu a klasifikaci, vystavit fakturu s reverse chargem,
> do EU nebo přes OSS a opravit chybný doklad. Pro každého, kdo v MyÚčtu
> fakturuje.

> [!WARNING]
> **Správnost faktury je vždy na uživateli.** MyÚčto.cz je účetní nástroj:
> generuje doklady, eviduje je, účtuje a sestavuje z nich výkazy. Není to daňový
> poradce. Sazba DPH, místo plnění, OSS, přenesená daňová povinnost a registrace
> k DPH v cizí zemi jsou odpovědností vystavitele faktury, nikoli aplikace.
> **Nestandardní situace vždy konzultujte s účetní nebo daňovým poradcem.** Cena
> za 30 minut konzultace je řádově nižší než sankce za špatně vystavenou fakturu.

Kapitola je **daňový rozcestník k vystavování dokladů**: co aplikace pozná
a doplní sama, kde vás nechá rozhodnout a kde končí (a váš účetní začíná).
Mechaniku obrazovek popisují [Faktury](14_Faktury.md) a
[Editor faktury](15_Faktura_editor.md), výkazy pak [Výkazy DPH](41_Vykazy_DPH.md).

## 40.1 Kdy to potřebujete

- Zakládáte firmu nebo se stáváte plátcem DPH, případně registraci rušíte.
- Překročili jste obrat 2 000 000 Kč a aplikace zobrazila banner o povinné registraci.
- Fakturujete firmě v jiném členském státě EU nebo stavební práce mezi plátci v ČR (reverse charge).
- Prodáváte spotřebitelům v EU a sledujete práh 10 000 EUR (OSS).
- Potřebujete zjednodušený doklad, zálohovou fakturu nebo opravit chybně vystavený doklad.
- Nevíte, jakou sazbu nebo klasifikaci DPH zvolit.

<!-- cols: 34 36 30 -->
| Situace | Co udělat | Kde v aplikaci |
|---|---|---|
| Firma se registruje k DPH nebo registraci ruší | Zapsat změnu do historie plátcovství | `Firma → Nastavení`, záložka **Daně a účetnictví**, sekce **Plátcovství DPH**, [§ 40.3](#403-krok-za-krokem-zapsat-platcovstvi-nebo-jeho-zmenu) |
| Fakturace firmě v EU nebo stavební práce v ČR | Zapnout reverse charge | hlavička faktury, [§ 40.4](#404-krok-za-krokem-faktura-s-reverse-chargem) |
| Doklad do 10 000 Kč bez údajů o odběrateli | Zjednodušený daňový doklad | editor faktury, [§ 40.5](#405-krok-za-krokem-zjednoduseny-danovy-doklad) |
| Prodej spotřebitelům v EU nad prahem | Režim OSS | [§ 40.6](#406-krok-za-krokem-zahranicni-fakturace-a-oss) |
| Klient reklamuje DPH na faktuře | Dobropis, nebo oprava podle § 43 | [§ 40.7](#407-krok-za-krokem-oprava-chybne-vystaveneho-dokladu) |

## 40.2 Než začnete

- **Údaje dodavatele a DIČ** vyplňte v `Firma → Nastavení` na záložce **Údaje firmy**.
- **Plátcovství DPH** zapište v `Firma → Nastavení` na záložce **Daně a účetnictví**, sekce **Plátcovství DPH** (viz [§ 40.3](#403-krok-za-krokem-zapsat-platcovstvi-nebo-jeho-zmenu)). Podle statusu se mění tvar dokladu, sazby i dostupné výkazy.
- **Sazby DPH** pro další státy (SK-23, PL-23 a podobně) si založte v číselnících, viz [§ 40.9.6](#4096-ciselnik-sazeb-dph).
- **U klientů** mějte vyplněné DIČ. U zahraničního DIČ ověřte registraci tlačítkem **Detaily plátce DPH** (VIES).
- Při pochybnostech si předem domluvte konzultaci s účetní, viz [§ 40.9.19](#40919-kdyz-si-nejste-jisti).

## 40.3 Krok za krokem: zapsat plátcovství nebo jeho změnu

1. Otevřete `Firma → Nastavení`, záložku **Daně a účetnictví**, a najděte sekci **Plátcovství DPH**.
2. Klikněte na **Přidat změnu**.
3. Vyplňte **Datum účinnosti** (může být i budoucí), zvolte **Stav** (**Plátce DPH**, **Neplátce DPH** nebo **Identifikovaná osoba**) a případně **Poznámku**.
4. Uložte řádek. Ukládá se okamžitě, bez tlačítka Uložit ve zbytku formuláře.
5. Hlásí-li aplikace konflikt s uzamčeným obdobím nebo podaným přiznáním, přečtěte si výčet kolizí. Chcete-li změnu přesto zapsat, klikněte na **Přesto uložit**. Potvrzení se zapíše do auditu.
6. Po zápisu registrace nebo jejího zrušení aplikace nabídne odkaz **Otevřít agendu § 79 / § 79a**. Otevřete ho a zadejte případné opravy odpočtu (viz [§ 40.9.5](#4095-vznik-a-zruseni-registrace-79-a-79a)).

**Jak poznáte, že je hotovo:** V historii je řádek s datem účinnosti a stavem (budoucí řádek nese štítek **budoucí**) a **Aktuální stav** ukazuje očekávaný status.

Datum existujícího řádku nejde změnit: řádek smažte a založte nový.

Překročil-li obrat limit, zobrazí se v `Firma → Nastavení` na záložce **Daně a účetnictví** banner **Obrat překročil limit pro registraci k DPH (§ 6 ZDPH)**. Klikněte v něm na **Zapsat do historie** a řádek se založí s odpovídajícím datem. Banner nic sám nepřepíná, **registraci i zápis do historie provádíte vy**.

> [!WARNING]
> Změna s účinností v uzamčeném období nebo před či uvnitř období už podaného přiznání vyžaduje výslovné potvrzení. **Podaná přiznání se tím nepřepočítají**, případné opravné či dodatečné tvrzení je na vás.

## 40.4 Krok za krokem: faktura s reverse chargem

Reverse charge (RC) přesouvá povinnost odvést DPH na příjemce faktury.

1. Je-li to opakující se klient, otevřete `Klienti`, jeho kartu a zaškrtněte **Reverse charge**. RC se pak předvyplní na dokladech pro tohoto klienta.
2. U zahraničního DIČ (jiný prefix než CZ) klikněte na **Detaily plátce DPH**. Aplikace ověří registraci přes evropský VIES. Bez platného VAT ID klient na RC nárok nemá.
3. Vytvořte fakturu a v hlavičce zaškrtněte **Reverse charge**, pokud se nezapnulo samo.
4. Zkontrolujte klasifikaci DPH řádků, u tuzemského RC i kód předmětu plnění (viz [§ 40.9.7](#4097-klasifikace-dph)).
5. Doklad vystavte. PDF nese zákonnou poznámku.

**Jak poznáte, že je hotovo:** Sumace nezobrazuje řádky DPH a PDF obsahuje poznámku. Pro tuzemského klienta „Daň odvede zákazník (přenesená daňová povinnost dle § 92a zákona o DPH)", pro zahraničního „… dle čl. 196 směrnice 2006/112/ES". EU plnění se současně vykáže v [souhrnném hlášení](44_Souhrnne_hlaseni.md).

> [!TIP]
> Nevíte, zda má klient na RC nárok? Nepoužívejte RC a dejte 21 %. Klient si DPH odpočte, vy ji odvedete. V nejhorším případě řešíte opravným dokladem.

Checkbox **Reverse charge** je skrytý, když je dodavatel neplátce DPH. Výjimkou je identifikovaná osoba (viz [§ 40.9.4](#4094-identifikovana-osoba-6g-6l-zdph)).

## 40.5 Krok za krokem: zjednodušený daňový doklad

1. V editoru faktury zaškrtněte **Zjednodušený daňový doklad (§ 30 ZDPH)**.
2. Odběratele vyberte, jen pokud ho na dokladu chcete mít. Pro prodej neznámému zákazníkovi (maloobchod, pokladní prodej) nechte pole **Klient** prázdné.
3. Prodáváte-li stálému klientovi, ale jeho údaje na dokladu mít nechcete, zaškrtněte **Netisknout odběratele na dokladu**. Klient na dokladu zůstane (statistiky, cenová hladina), jen se nevytiskne.
4. Vyplňte položky. Celkem včetně daně nesmí přesáhnout 10 000 Kč.
5. Doklad vystavte.

**Jak poznáte, že je hotovo:** Doklad nese nadpis **Zjednodušený daňový doklad**. Bez vybraného klienta se vystaví pro systémového **koncového zákazníka** firmy, který se v adresáři klientů neukazuje a na PDF ani webové faktuře se jako odběratel netiskne. Má-li firma nastavenou řadu zjednodušených dokladů, dostane doklad číslo z ní (viz [§ 40.9.10](#40910-zjednoduseny-danovy-doklad-30-zdph)). Odmítne-li aplikace doklad, hláška uvede důvod (viz [§ 40.8](#408-kdyz-neco-nejde)).

## 40.6 Krok za krokem: zahraniční fakturace a OSS

1. Určete situaci podle tabulky v [§ 40.9.13](#40913-zahranicni-fakturace).
2. V `Firma → Nastavení` na záložce **Daně a účetnictví** v sekci **Režim OSS (One Stop Shop)**, zapněte **OSS režim** a vyplňte **Platné od** (jen když překračujete práh nebo jste se k OSS rozhodli).
3. V `Systém → Sazby a číselníky` na záložce **Sazby DPH** založte sazbu členského státu **se správnou zemí**, například `SK-23` se zemí `SK`. Formulář předvyplňuje stát `CZ`, přepište ho.
4. Vystavte fakturu. Zařazení řádku do OSS aplikace odvodí sama a doplní doložku na dokladu.
5. Plnění, u kterých si aplikace není jistá, najdete v seznamu faktur filtrem **Místo plnění (OSS)** s volbou **Nejisté místo plnění (OSS)**. Označte je a vyřešte hromadnou akcí **Nastavit OSS**.
6. Podklad a XML najdete v `Daně → OSS přiznání` (viz [OSS](45_OSS.md)).

**Jak poznáte, že je hotovo:** Doklad nese OSS doložku, řádek se nezahrnuje do českého přiznání a účtuje se na samostatný účet 345.100.

Pro službu klientovi mimo EU použijte sazbu `CZ-0` a do poznámky pod položkami doplňte anglický text typu „Outside the scope of EU VAT - § 9(1) of Czech VAT Act". Nepoužívejte checkbox **Reverse charge** (viz [§ 40.9.15](#40915-export-mimo-eu-neni-reverse-charge)).

## 40.7 Krok za krokem: oprava chybně vystaveného dokladu

1. Rozhodněte, o jaký případ jde:
   - chybná **výše daně** (například špatná sazba) není dobropis, patří do evidence podle § 43,
   - chybné množství, cena nebo zrušená dodávka je opravný daňový doklad (dobropis),
   - doklad, který klientovi nikdy nešel, lze interně stornovat.
2. U dobropisu otevřete původní fakturu a vystavte k ní opravný doklad (viz [Editor faktury](15_Faktura_editor.md#1599-storno-vs-dobropis)). Patří do období, kdy byl doručen odběrateli.
3. Při opravě podle § 43 otevřete `Daně → Opravy DPH (§43, §79)` a opravu zadejte tam. Jde do dodatečného přiznání za období původního plnění.

**Jak poznáte, že je hotovo:** Opravný doklad je vystavený a navázaný na původní fakturu, případně je oprava zadaná v agendě Opravy DPH.

## 40.8 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Firma není k rozhodnému datu dokladu (...) plátcem DPH." | Doklad s DPH nelze vystavit, pokud firma k rozhodnému datu nebyla plátcem (§ 108 odst. 4 ZDPH, uvedená daň by se musela odvést) | Zkontrolujte historii plátcovství a datum plnění |
| Změna plátcovství vrátí konflikt s výčtem kolizí | Datum spadá do uzamčeného období, zámku k datu nebo podaného přiznání | Přečtěte kolize, případně klikněte na **Přesto uložit** |
| Nejde přepnout na plátce | Zapnutá paušální daň (paušalista nesmí být plátce DPH, § 7a ZDP) | Nejdřív vypněte paušální daň |
| Zjednodušený doklad nad limit | Doklad je nad 10 000 Kč včetně daně (§ 30 odst. 1) | Vystavte běžný daňový doklad |
| Zjednodušený doklad při reverse chargi | Odběratel potřebuje na dokladu své DIČ (§ 30 odst. 2), jinak plnění vypadne z kontrolního hlášení | Vystavte běžný doklad |
| Zjednodušený doklad u dodání do jiného členského státu | Bez identifikace odběratele nelze plnění vykázat v souhrnném hlášení (§ 30 odst. 2) | Vystavte běžný doklad |
| „Doklad bez odběratele lze vystavit jen jako zjednodušený daňový doklad" | Doklad nemá vybraného klienta, ale zjednodušený doklad není zaškrtnutý; běžný daňový doklad vyžaduje odběratele (§ 29) | Vyberte odběratele, nebo zaškrtněte zjednodušený daňový doklad |
| Import zahraničních dokladů končí chybou | U sazby členského státu zůstal stát `CZ` | Opravte pole Stát u sazby (viz [§ 40.9.6](#4096-ciselnik-sazeb-dph)) |
| Checkbox **Reverse charge** chybí | Dodavatel je neplátce DPH | Neplátce RC vystavit nemůže |
| Doklad s OSS řádkem nejde exportovat do Pohoda XML ani Stereo XML | Ty formáty nemají kam zapsat zemi spotřeby | Použijte jiný formát exportu, například ISDOC |

## 40.9 Podrobnosti a pravidla

### 40.9.1 Daňový status firmy

Daňový status dodavatele určuje chování celé aplikace: tvar dokladu, dostupné
sazby, povinné poznámky i to, jaké výkazy se nabídnou. MyÚčto rozlišuje **tři
stavy**: plátce DPH, neplátce a identifikovaná osoba (§ 6g-6l ZDPH).

Status **není zaškrtávátko**, které by platilo „ode dneška napořád". Je to
**historie změn**: každý řádek nese datum účinnosti a stav. Stav k libovolnému dni
je poslední řádek s účinností menší nebo rovnou tomu dni.

<!-- cols: 28 72 -->
| Vlastnost | Chování |
|---|---|
| **Datum účinnosti** | Může být i budoucí. Plánovanou změnu zapíšete dopředu a do systému se propíše až v den účinnosti (noční úlohou). |
| **Ukládání** | Řádky historie se ukládají okamžitě, nezávisle na tlačítku Uložit ve zbytku formuláře. |
| **Úprava data** | Datum existujícího řádku se nemění, řádek smažete a založíte nový. |
| **Kombinace** | Plátce a identifikovaná osoba se vylučují, identifikovaná osoba je z definice neplátce. |
| **Paušální daň** | Paušalista nesmí být plátce DPH (§ 7a ZDP). Aplikace přepnutí na plátce odmítne, dokud je zapnutá paušální daň. |

**Proč to není jen dnešní příznak.** Doklad se posuzuje **k rozhodnému datu
dokladu** (datum plnění, u dobropisu datum doručení opravného dokladu), ne podle
toho, jak je firma nastavená dnes:

- **PDF a exporty** vykreslí doklad podle stavu k jeho datu. Faktura vystavená v době plátcovství zůstane daňovým dokladem, i když firma později registraci zrušila.
- **Vystavení dokladu s DPH** aplikace **zablokuje**, pokud firma k rozhodnému datu plátcem nebyla (§ 108 odst. 4 ZDPH, uvedená daň by se musela odvést). V srpnu tak lze doúčtovat červnové plnění s DPH, ale červencové už ne.
- **Výkazy DPH** posuzují plátcovství **ke konci období výkazu**, ne podle dnešního stavu (viz [Výkazy DPH](41_Vykazy_DPH.md)).

**Retro zámek.** Změna s účinností v uzamčeném účetním období nebo před či uvnitř
období už podaného přiznání se neuloží potichu. Aplikace vrátí konflikt s výčtem
kolizí (uzamčené období, zámek k datu, podané přiznání) a uložit lze jen
s explicitním potvrzením, které se zapíše do auditu. **Podaná přiznání se tím
nepřepočítají.**

### 40.9.2 Co se na dokladu mění u neplátce

<!-- cols: 46 27 27 -->
| Co se mění | Plátce DPH | Neplátce DPH |
|---|---|---|
| Záhlaví dokladu | „Faktura - daňový doklad" | „Faktura" |
| Sloupec „DPH %" v tabulce položek | ano | **skrytý** |
| Sloupec „S DPH" | ano | **skrytý** (jen „Celkem") |
| Volba sazby DPH u položky | ano | **skrytá**, interně se ukládá 0 % |
| Přepínač cen „s DPH / bez DPH" | ano | **skrytý** |
| Reverse charge checkbox | ano (pro EU klienty s VAT ID) | **skrytý** (výjimka: identifikovaná osoba) |
| Sumace DPH (rozpis sazeb, „DPH celkem") | ano | **skrytá** |
| Poznámka „Není plátce DPH" na dokladu | ne | ano |

Neplátce podle ZDPH nemá nárok DPH účtovat ani vykazovat. Faktura je proto čistě
jednosloupcová (Cena/j, Celkem) a nese povinnou poznámku.

### 40.9.3 Kdy se plátcem stanete ze zákona (§ 6 a § 94 ZDPH)

Plátcovství **vzniká ze zákona z obratu**, ne přihláškou. Od 1. 1. 2025 se sleduje
**kalendářní rok** (dříve klouzavých 12 měsíců) a existují **dva limity s různým
následkem**:

<!-- cols: 50 50 -->
| Obrat za kalendářní rok | Plátcem se firma stává |
|---|---|
| přes **2 000 000 Kč** | od **1. ledna** následujícího kalendářního roku |
| přes **2 536 500 Kč** | **dnem následujícím** po dni překročení |

Aplikace obrat sleduje a v `Firma → Nastavení` na záložce **Daně a účetnictví** zobrazí **banner**: kolik obrat
činí, který limit překročil, ke kterému dni plátcovství vzniká a do kdy je potřeba
podat přihlášku k registraci (**10 pracovních dnů ode dne překročení
2 000 000 Kč**, § 94 odst. 1; kotvou lhůty je dolní limit, horní limit mění jen
den vzniku plátcovství). Tlačítkem **Zapsat do historie** rovnou založíte
odpovídající řádek.

U ročního limitu, kde přesný den překročení z dat vyjít nemusí, systém termín
označí jako **informativní** a řekne to nahlas. Roky do 2024 se neposuzují: starý
mechanismus klouzavých 12 měsíců aplikace vědomě nemodeluje, protože měl jinou
lhůtu i jiné datum vzniku. Banner nic sám nepřepíná.

### 40.9.4 Identifikovaná osoba (§ 6g-6l ZDPH)

Třetí stav mezi plátcem a neplátcem, typicky freelancer, který fakturuje služby
do EU (a/nebo nakupuje zahraniční služby typu reklamy či SaaS), ale v tuzemsku
plátcem není. V historii plátcovství založte řádek se stavem **Identifikovaná
osoba** k datu, od kterého povinnost vznikla.

<!-- cols: 28 72 -->
| Oblast | Chování identifikované osoby |
|---|---|
| Tuzemské faktury | beze změny, bez DPH, poznámka „Není plátce DPH" |
| Faktura **EU** klientovi s DIČ | po výběru klienta se automaticky zapne **reverse charge** a předvyplní klasifikace **22** (EU služby, souhrnné hlášení). PDF je daňový doklad s DIČ a klauzulí „daň odvede zákazník (čl. 196 směrnice 2006/112/ES)". Sazba DPH se **neuvádí** (samovyměří ji odběratel sazbou své země), částky jsou základ daně, sloupec se proto jmenuje „Bez DPH". Totéž platí v šabloně pravidelné fakturace. |
| Faktura klientovi mimo EU | bez RC, plnění je mimo předmět české DPH, žádná klauzule, žádné souhrnné hlášení |
| Souhrnné hlášení | podává se za měsíce s EU službami (kód 3), `Daně → Souhrnné hlášení` |
| Přijaté zahraniční doklady (klasifikace 23/24/25) | samovyměření DPH **bez nároku na odpočet**, daň se reálně platí |
| Přiznání k DPH | typ **identifikovaná osoba**, jen řádky samovyměření, **vždy měsíčně** a jen za měsíce, kdy povinnost vznikla |
| Kontrolní hlášení | **nepodává se nikdy**, stránka KH zobrazí upozornění |

> [!WARNING]
> Samovyměřená daň bez nároku na odpočet je u identifikované osoby **skutečný
> výdaj**. Lhůta pro podání přiznání i souhrnného hlášení je do 25. dne
> následujícího měsíce. Faktura za EU služby se vystavuje nejpozději do 15 dnů od
> konce měsíce plnění (§ 28).

### 40.9.5 Vznik a zrušení registrace (§ 79 a § 79a)

Změna statusu má daňový dopad i na **majetek a zásoby, které firma už drží**:

- **při registraci** může vzniknout nárok na odpočet z obchodního majetku pořízeného nejvýše 12 měsíců před vznikem plátcovství (**§ 79**),
- **při zrušení registrace** vzniká povinnost uplatněný odpočet snížit (**§ 79a**). U zásob se vrací celý, u dlouhodobého majetku jen podíl za roky zbývající z pěti- nebo desetileté lhůty.

Jakmile do historie zapíšete registraci nebo její zrušení, aplikace na to
**upozorní a nabídne proklik** do agendy `Daně → Opravy DPH (§43, §79)`. Položky
zadává účetní ručně, z dokladu nejde poznat, zda věc k rozhodnému dni pořád tvoří
obchodní majetek. Součet se promítne na **ř. 45 přiznání k DPH**, evidence sama do
deníku neúčtuje. Podrobně viz [Výkazy DPH](41_Vykazy_DPH.md#411319-opravy-dph-43-79-a-79a-pravidla).

### 40.9.6 Číselník sazeb DPH

Standardní seed obsahuje čtyři sazby pro Česko:

<!-- cols: 14 12 24 50 -->
| Kód | Sazba | Popis | Kdy použít |
|---|---|---|---|
| `CZ-21` | 21 % | Základní | Výchozí, většina zboží i služeb |
| `CZ-12` | 12 % | Snížená | Potraviny, knihy, ubytování, vodné/stočné, léčivé přípravky (úplný seznam je v příloze ZDPH) |
| `CZ-0` | 0 % | Osvobozeno | Plnění osvobozená podle § 51 ZDPH (například finanční služby, vzdělávání), vývoz. Také fallback pro neplátce. |
| `CZ-RC` | 0 % | Reverse charge | Přenesená daňová povinnost, sazba 0 %, daň odvádí příjemce |

Sazby spravujete v `Systém → Sazby a číselníky` na záložce **Sazby DPH** (viz
[Nastavení](96_Nastaveni.md#963-krok-za-krokem-ciselniky)). Můžete přidávat další (typicky sazby
členských států pro OSS, například `SK-23`, `PL-23`, `HU-27`), upravovat popisek
nebo zneplatnit zastaralé pomocí **Platí do**. Výchozí sazba se předvyplní u nově
přidané položky faktury.

> [!WARNING]
> **Pole Stát formulář předvyplňuje na `CZ`.** U sazby členského státu ho musíte
> přepsat. Sazba `SK-23` se zemí `CZ` je pro systém česká sazba 23 %, a takovou
> ČR nezná. Je to nejčastější příčina chyby při importu zahraničních dokladů.
> Detail v kapitole [OSS](45_OSS.md#45105-sazby-dph-cizich-zemi-a-pole-stat).

Sazby se přiřazují **per položku**, ne per celý doklad. Smíšené sazby v jedné
faktuře aplikace zvládá, sumace je rozepsaná po sazbách. Vystavené doklady si
sazbu drží na svých řádcích, takže **změna číselníku minulost nepřepíše**. Postup
při změně sazby s budoucí platností popisuje [Výkazy DPH](41_Vykazy_DPH.md#411322-zmena-sazby-dph-s-budouci-platnosti).

### 40.9.7 Klasifikace DPH

Sazba říká, kolik se počítá. **Klasifikační kód** říká, **kam plnění patří**:
řádek přiznání DPHDP3, oddíl kontrolního hlášení, směr použití (prodej/nákup),
zvláštní režimy (reverse charge, kód režimu KH, oprava nedobytné pohledávky)
a **kód předmětu plnění** pro tuzemský RC (§ 92b-92f) do sekce A.1 / B.1
kontrolního hlášení.

- Klasifikace se **přiřadí sama** podle sazby a situace na dokladu, v editoru ji lze přepsat per řádek i za celou hlavičku.
- Vestavěné systémové kódy jsou společné a needitovatelné. Vlastní kód si firma založit může, ale jen s vědomím dopadu na DPHDP3, KH a Knihu DPH.
- Není to popisek: **podle klasifikace se řádky zařazují do daňových sestav.**

Detail viz [Výkazy DPH](41_Vykazy_DPH.md#41139-klasifikacni-kody-dph) a [Nastavení](96_Nastaveni.md#96161-ciselniky-podrobnosti).

### 40.9.8 DUZP je to, co rozhoduje

V hlavičce dokladu jsou dvě různá data a pletou se často:

- **Vystaveno** - kdy doklad vznikl,
- **DUZP (datum uskutečnění zdanitelného plnění)** - **podle něj** se plnění zařadí do zdaňovacího období, posoudí plátcovství, ověří platnost sazby i platnost registrace do OSS.

Ve výchozím stavu se DUZP rovná datu vystavení. U doúčtování zpětného plnění změňte
DUZP, ne datum vystavení.

### 40.9.9 Zálohová faktura není daňový doklad

Zálohová faktura (proforma) je **výzva k platbě**, do výkazů DPH nevstupuje. Plátce
DPH má povinnost vystavit **daňový doklad k přijaté platbě** (§ 28 odst. 2 ZDPH)
s DUZP = den přijetí platby. MyÚčto ho u úhrady zálohové faktury vystaví jako
koncept automaticky (bankovní párování) nebo na klik:

- DPH se počítá **shora koeficientem** (§ 37) a platba se rozdělí mezi sazby zálohy poměrně podle jejich vah,
- doklad se čísluje v řadě faktur, do výkazů DPH / KH / Knihy DPH vstupuje v měsíci platby a vystavením je rovnou zaplacený,
- finální doklad (vyúčtování) pak ke zdaněným platbám přidá **záporné odpočtové řádky podle § 37a**, daní se jen zbytek, nic dvakrát,
- u cizoměnové platby se pro DPH použije **kurz k datu přijetí platby**, ne kurz původní proformy.

**Daňový doklad k platbě se nevystavuje** u neplátce, u plnění v přenesené daňové
povinnosti (u RC se záloha nedaní, daň vzniká až k DUZP plnění) a v režimu OSS
(daň se přiznává ke dni přijetí úplaty přímo v OSS přiznání, viz [OSS](45_OSS.md#451012-uctovani-oss-dane)).
Podrobně [Faktura PDF](16_Faktura_PDF.md#zalohova-faktura-danovy-doklad-k-prijate-platbe)
a [Editor faktury](15_Faktura_editor.md#1598-zalohova-faktura-a-danovy-doklad).

### 40.9.10 Zjednodušený daňový doklad (§ 30 ZDPH)

Do **10 000 Kč včetně daně** lze vystavit zjednodušený daňový doklad. Nemusí
obsahovat údaje o odběrateli, základ daně ani výši daně (§ 30a). V editoru je to
zaškrtávátko. Klient je u něj nepovinný: bez něj se doklad naváže na systémového
koncového zákazníka firmy a odběratel se na dokladu netiskne. V kontrolním hlášení
takový doklad patří do souhrnného oddílu A.5 (plnění do 10 000 Kč bez DIČ odběratele).
Běžný daňový doklad pro koncového zákazníka vystavit nejde, § 29 vyžaduje jméno a adresu
odběratele. Aplikace zjednodušený doklad **odmítne** ve třech případech, kde to zákon nedovolí:

<!-- cols: 40 60 -->
| Situace | Proč to nejde |
|---|---|
| Doklad je **nad 10 000 Kč** včetně daně | § 30 odst. 1 |
| Doklad je v **přenesené daňové povinnosti** | § 30 odst. 2, odběratel potřebuje na dokladu své DIČ, jinak plnění vypadne z kontrolního hlášení |
| Jde o **dodání zboží do jiného členského státu** | § 30 odst. 2, bez identifikace odběratele nelze plnění vykázat v souhrnném hlášení |

**Vlastní číselná řada.** Zjednodušené doklady se standardně číslují řadou faktur. Chcete-li je
číslovat zvlášť (například účtenky z pokladního prodeje), vyplňte v `Firma → Nastavení` na záložce
**Číslování faktur** pole **Šablona pro zjednodušený daňový doklad**. Řada má vlastní počítadlo
a přednost i před řadou zákazníka a kategorie tržby. Prázdné pole vrací dosavadní chování. Číslo
zůstává v řadě, ze které ho doklad dostal, i když se příznak zjednodušeného dokladu později změní;
kontrola úplnosti řady proto nehlásí falešné mezery (podrobnosti v
[§ 95.13.3](95_Multi_supplier.md#95133-ciselne-rady-faktur)).

**Netisknout odběratele.** Zjednodušený doklad údaje o odběrateli nevyžaduje, takže je smíte
vynechat i u vybraného klienta. Volba **Netisknout odběratele na dokladu** je v editoru pod
zaškrtávátkem zjednodušeného dokladu; výchozí hodnotu pro nové doklady nastavíte v
`Firma → Nastavení` na záložce **Fakturace**. Volba se ukládá na doklad, takže pozdější změna
nastavení firmy vystavená PDF nemění. Platí pro PDF i webovou fakturu, ISDOC odběratele nese dál
(strukturovaná data pro účetnictví příjemce), a to i ISDOC vložený přímo do PDF, takže odběratel
v souboru PDF zůstává, jen není vidět. Klient na dokladu zůstává, takže se počítá do
statistik i obratu a uplatní se jeho cenová hladina. Běžná faktura odběratele nést musí (§ 29),
u ní se volba neuloží. Změna volby u vystaveného dokladu archivuje dosavadní PDF a v uzavřeném
období ji nejde provést jako úpravu jen poznámek.

Úprava vystaveného zjednodušeného dokladu správcem prochází stejnými kontrolami jako vystavení:
doklad nad 10 000 Kč, v přenesené daňové povinnosti nebo s dodáním do jiného členského státu
aplikace odmítne.

### 40.9.11 Storno vs. dobropis

- **Dobropis (opravný daňový doklad, § 42)** je daňový doklad se zápornými částkami a vazbou na původní fakturu. Patří do období, kdy byl odběrateli doručen.
- **Interní storno** je jen interní zrušení dokladu, klientovi se nevystavuje.
- **Oprava chybně určené výše daně (§ 43)**, například špatná sazba, **není dobropis**. Patří zpětně do období původního plnění a jde do **dodatečného přiznání**. Eviduje se v `Daně → Opravy DPH (§43, §79)`.

Rozhodovací pravidlo je v [Editoru faktury](15_Faktura_editor.md#1599-storno-vs-dobropis).

### 40.9.12 Reverse charge: kdy ho vystavit

- **Tuzemský RC (§ 92a-92g ZDPH):** stavební a montážní práce mezi plátci v ČR, zlato, šrot, mobilní telefony, integrované obvody, plyn a elektřina pro obchodníka (přesný výčet § 92a-g). Oba subjekty musí být plátci DPH v ČR. U těchto plnění patří do kontrolního hlášení i **kód předmětu plnění**, který nese klasifikace DPH (viz [§ 40.9.7](#4097-klasifikace-dph)).
- **EU B2B s reverse charge:** dodavatel je plátce DPH v ČR (nebo identifikovaná osoba), klient je osoba povinná k dani v jiném členském státě s **platným VAT ID** ověřitelným přes VIES a jde o plnění s místem plnění v zemi příjemce podle § 9 odst. 1 ZDPH.

V obou případech aplikace účtuje 0 %, sumace neukáže řádky DPH a do PDF přidá
zákonnou poznámku. Přepnutí RC mění jen hlavičkový režim dokladu, nominální sazby
položek zůstávají. RC doklad v cizí měně má vlastní pravidla přepočtu pro výkazy,
viz [Výkazy DPH](41_Vykazy_DPH.md#411312-reverse-charge-v-cizi-mene).

### 40.9.13 Zahraniční fakturace

<!-- cols: 28 72 -->
| Scénář | Chování |
|---|---|
| **CZ B2B / B2C** | plně podporováno (21 % / 12 % / RC podle situace) |
| **EU B2B s platným VAT ID** | RC + VIES ověření + souhrnné hlášení |
| **EU B2C - běžné služby** | místo plnění zůstává v ČR podle § 9 odst. 2 ZDPH, fakturuje se **s českou daní**, do OSS to nepatří (i když je odběratel z Polska) |
| **EU B2C - TBE služby a prodej zboží na dálku** | místo plnění je v zemi zákazníka po překročení celounijního prahu **10 000 EUR/rok**, **režim OSS** se sazbou země spotřeby |
| **Mimo EU** (Švýcarsko, USA, UK) | typicky bez DPH (vývoz zboží / služba mimo předmět české daně), v editoru zvolte sazbu `CZ-0` |

### 40.9.14 OSS (One Stop Shop): co dělá aplikace sama

Režim OSS má **vlastní kapitolu:** [OSS](45_OSS.md) (nastavení, odvození řádku,
plnění k ručnímu posouzení, hromadná úprava, doložka na dokladu, účtování na
345.100, přepočet kurzem ECB, podání, archiv a evidence § 110f). Tady stačí vědět:

- **Zařazení řádku do OSS se odvozuje automaticky** ve všech kanálech: v editoru, při importu, u pravidelné fakturace, při synchronizaci z iDokladu a Fakturoidu, při čtení PDF i přes veřejné API. Ruční označování řádků není potřeba.
- **Rozhoduje nezávislý číselník sazeb členských států**, ne uživatelem zadaná sazba. Sazba, kterou číselník v zemi dodavatele nezná, se **nikdy nevykáže jako tuzemské plnění**, jinak by cizí daň tiše skončila na ř. 1 českého přiznání.
- **OSS řádky se nezahrnou** do českého přiznání k DPH, kontrolního hlášení ani Knihy DPH a účtují se na vlastní účet **345.100**, aby zůstatek 343 pořád seděl na přiznání.
- **Práh 10 000 EUR** aplikace sleduje na stránce OSS přiznání: čerpání za kalendářní rok, rozpad po zemích a upozornění od 80 % i po překročení. Přepočet je orientační a **režim sám nezapne**.
- **Plnění, u kterých si systém není jistý**, označí k ručnímu posouzení. Najdete je filtrem **Místo plnění (OSS)** v seznamu faktur a vyřešíte hromadnou akcí **Nastavit OSS**.
- **Historické doklady nemusíte zadávat ručně**, import vydaných faktur režim OSS odvodí sám (viz [Importy](21_Importy.md#2197-zahranicni-doklady-a-rezim-oss)).
- **Doklad s OSS řádkem se neexportuje do Pohoda XML ani Stereo XML**, ty formáty nemají kam zapsat zemi spotřeby.

**Příklad: slovenský spotřebitel, prodej zboží na dálku nad prahem.** Slovensko má
od **1. 1. 2025** základní sazbu **23 %**. Faktura slovenskému spotřebiteli nad OSS
prahem má mít:

- DIČ vystavitele s prefixem `CZ`,
- DIČ příjemce **prázdné** (B2C),
- sazbu DPH **23 %** ze sazebníku se zemí `SK`,
- měnu typicky EUR,
- OSS doložku na dokladu (aplikace ji doplní sama).

Daň se odvede přes OSS, ne přes tuzemské přiznání.

> [!WARNING]
> MyÚčto **neurčuje samo právní režim plnění, neuzavírá období a XML
> neodesílá**. Sledování prahu i kontrola sazeb jsou **upozornění**, ne závazné
> určení povinnosti. OSS přiznání se navíc nepodává obecnou cestou EPO, ale
> v samostatné aplikaci **MOSS/OSS** Daňového portálu (viz [OSS](45_OSS.md#451014-kde-se-oss-priznani-podava)).
> Před podáním vždy ověřte sazby, zemi spotřeby, přepočet a výsledné XML
> s účetní nebo daňovým poradcem.

### 40.9.15 Export mimo EU není reverse charge

Pro službu poskytnutou klientovi mimo EU (například americkému) se v ČR uplatňuje
0 %, plnění je mimo předmět české DPH podle § 9 odst. 1 ZDPH. To **není reverse
charge** v právním slova smyslu: checkbox **Reverse charge** je určený pro EU režim
a § 92a a generuje českou zákonnou poznámku, která pro třetí zemi není přesná.
**Pro export mimo EU použijte sazbu `CZ-0`** a do poznámky pod položkami doplňte
anglický text typu „Outside the scope of EU VAT - § 9(1) of Czech VAT Act".
Do souhrnného hlášení takové plnění nepatří.

### 40.9.16 Registrace k DPH ve více zemích

OSS pokrývá **B2C plnění do EU** a registraci v cílových státech ve většině případů
nahradí. Nepokryje ale situace, kdy máte v cizí zemi **skutečnou registraci
k DPH**, typicky e-shop s lokálním skladem, kde plnění začíná i končí v jiném státě.

MyÚčto má jeden dodavatelský profil s jedním DIČ. Řešení: založte druhého
dodavatele (`Systém → Firmy → Přidat`) a přepínejte mezi nimi přepínačem v hlavičce
(viz [Multi supplier](95_Multi_supplier.md)). **Není to plnohodnotná
multi-jurisdikční podpora**, přiznání k DPH pro každou zemi řešte s místní účetní.

### 40.9.17 Co MyÚčto dělá

- Vystavení dokladu: faktura, zálohová, daňový doklad k platbě, dobropis, storno, zjednodušený doklad.
- Evidence faktur, klientů, zakázek, plateb, pravidelnou fakturaci, upomínky a hromadné akce nad doklady.
- Generování PDF s QR platbou (SPAYD pro CZK, SEPA EPC pro EUR) a odesílání e-mailem přes vlastní SMTP.
- **ARES**, **VIES** a **registr plátců DPH (CRPDPH)**: doplnění údajů, ověření VAT ID, kontrola zveřejněných účtů a nespolehlivého plátce (§ 109).
- Bankovní importy **GPC/ABO i PDF výpisů** (KB, Fio, ČSOB, Raiffeisenbank, Česká spořitelna, mBank, Creditas a další), e-mailová avíza z IMAP, chytré párování plateb a platební příkazy.
- **AI extrakci přijatých dokladů** (Anthropic, Azure OpenAI, OpenAI, Google Gemini), vždy jen jako návrh k potvrzení člověkem.
- Export pro účetní v šesti formátech: **PDF ZIP, ISDOC 6.0.2, Pohoda XML, Stereo XML, Money S3 XML, CSV**, plus hromadný měsíční ZIP.
- **Podvojné účetnictví i daňovou evidenci**: účetní deník, hlavní knihu, předvahu, rozvahu, výsledovku, saldokonto, majetek a odpisy, uzávěrku, automat účtování.
- **Sklad a e-shop**: skladové karty, příjemky/výdejky, inventury, oceňování klouzavým průměrem, katalog, cenotvorbu z nákupní ceny.
- **Mzdy**: [úplný mzdový modul](75_Uplne_mzdy.md) (osobní karty, pracovní vztahy, docházka, absence a dovolená, mzdové složky, mzdový běh, srážky a exekuce, výplatní pásky, mzdový list, platby odvodů a účetní můstek) i jednodušší [Mzdovou rekapitulaci](64_Mzdy.md). Úplné mzdy zatím používejte ve zkušebním provozu podle upozornění níže.
- XML pro EPO portál MFČR: **přiznání k DPH (DPHDP3), kontrolní hlášení (DPHKH1), souhrnné hlášení (DPHSHV), daň z příjmů (DPFO/DPPO, řádné, opravné i dodatečné, včetně hospodářského roku)** a **OSS přiznání (OSSEI1)**.
- **Podání na EPO** ve dvou režimech: **přímé podání se ZAREP** (uznávaný elektronický podpis kvalifikovaným certifikátem, test oficiální podatelny a po samostatném potvrzení skutečné odeslání) a **asistované podání** (odeslání snapshotu na oficiální endpoint a otevření předvyplněného formuláře), viz [Archiv podání](49_Archiv_podani_a_rekonciliace.md).
- Rozšířené opravy DPH: **§ 43, § 46, § 74b, § 79 a § 79a**.
- Pojistné OSVČ: přehled sociálního pojištění pro ČSSZ **jako validovanou XML datovou větu** a přehled pro zdravotní pojišťovnu jako PDF pomůcku.
- Archiv podání s otiskem SHA-256, doručenkami a **rekonciliaci** podaného stavu proti dnešním datům.
- **REST API v1 (OpenAPI 3.1)**, MCP server a klientský portál.

### 40.9.18 Co MyÚčto nedělá

- **Produkční mzdy jako jediný zdroj, zatím.** [Mzdový modul](75_Uplne_mzdy.md) běží ve zkušebním provozu: výsledek, odvody, dokumenty i podání je nutné ověřit proti jinému důvěryhodnému zdroji a modul nemá být jediným podkladem pro výplatu ani pro zákonné podání.
- **IOSS ani režim mimo EU.** Vede se pouze **režim EU** OSS.
- **Podání OSS přes EPO.** `OSSEI1` se podává v samostatné aplikaci **MOSS/OSS** Daňového portálu, přímý ani asistovaný kanál ho proto nenabízí (viz [OSS](45_OSS.md#451014-kde-se-oss-priznani-podava)).
- **Jednotné automatické odeslání všech mzdových podání.** JMHZ lze po výslovném potvrzení řízeně odeslat přes VREP nebo ISDS a následně evidovat stav a protokol. Ostatní výstupy se podle druhu připraví ke stažení nebo se jejich splnění pouze zaeviduje. Kanály zdravotních pojišťoven nejsou jednotné a část postupu zůstává ruční.
- **Podání bez vašeho potvrzení.** Ani přímý kanál nic neodešle sám, kontrola částek a rozhodnutí podat zůstává na člověku.
- **Výrobu a kusovníky** (marži lze spočítat, ale výrobní zakázky ne).
- **Insolvenční rejstřík.**
- **Daňové poradenství.** Všechny výstupy jsou pomůcka k ověření.

Standardní tok je: **MyÚčto vystaví doklady, zaúčtuje je, vygeneruje výkazy,
uživatel nebo účetní je zkontroluje, podá se přímo z aplikace a archivuje se
doručenka.** Export do Pohody, Sterea, Money S3 nebo ISDOC je **volitelný**, hodí se,
když část agendy řešíte jinde, ale není nutnou součástí postupu.

### 40.9.19 Když si nejste jisti

V pochybnostech platí jednoduchá poučka: **vyberte konzervativnější variantu
a zeptejte se účetní**.

- **Nevíte, jestli má klient nárok na RC?** Nepoužijte RC, dejte 21 %. Klient si DPH odpočte, vy ji odvedete. V nejhorším řešíte opravným dokladem.
- **Nevíte, jestli plnění patří do tuzemska, nebo do OSS?** Neháděte sazbu. Ověřte, jestli je odběratel osobou povinnou k dani (má DIČ), jestli jde o TBE službu nebo zboží na dálku, a jestli jste přes práh 10 000 EUR. Pokud se ukáže, že řádek patřil jinam, opravuje se to **opravným OSS podáním za původní čtvrtletí** (viz [OSS](45_OSS.md#457-krok-za-krokem-oprava-plneni-z-minuleho-ctvrtleti)), respektive dodatečným přiznáním, ne dorovnáním v běžném období.
- **Řekne vám klient, že DPH je špatně?** V editoru opravíte a vystavíte **opravný daňový doklad**. Jestli šlo o chybnou **výši daně** (špatná sazba), není to dobropis. Patří to do evidence **§ 43** a do dodatečného přiznání za původní období.
- **Nevíte, jestli už jste plátce?** Zkontrolujte banner v `Firma → Nastavení` na záložce **Daně a účetnictví**. Plátcovství vzniká **ze zákona z obratu**, ne přihláškou, doměrek jde zpětně ode dne vzniku.

> [!TIP]
> Jednou ročně (typicky v lednu) projděte s účetní seznam svých klientů, sazeb
> a typů plnění. Pravidla DPH se mění: sazby, registrační limity, prahy OSS,
> elektronická fakturace.

## 40.10 Související kapitoly

- [Faktury](14_Faktury.md) a [Editor faktury](15_Faktura_editor.md)
- [Výkazy DPH](41_Vykazy_DPH.md)
- [Souhrnné hlášení](44_Souhrnne_hlaseni.md)
- [OSS](45_OSS.md)
- [Více dodavatelů](95_Multi_supplier.md)
