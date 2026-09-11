# Medlemsansökningar

Det befintliga `ssf_application`-flödet är vidareutvecklat, inte ersatt. Formuläret, Vessel Profile-modellen, PDF-genereringen, den säkra tokenlänken, filuppladdningen, Graph-klienten och SSF:s centrala e-postmall återanvänds.

## Publikt flöde

1. Medlemsväg: `normal`, `small_registered`, `restoration` eller `new_traditional`.
2. Fartygsombud.
3. Gemensamma fartygsfakta.
4. Historia och frågor för vald medlemsväg.
5. Huvudbild, övriga bilder och bilagor.
6. Granskning, samtycke, antispam och inskickning.

De interna vägnycklarna behålls för bakåtkompatibilitet. Visningsnamnen är Normalfallet, Mindre registrerat fartyg, Fartyg under restaurering och Nybyggt traditionsfartyg.

WordPress skapar ansökan och fartygsutkastet först. Därefter skapas arkiv-PDF och en asynkron SharePoint-synk köas. SharePoint-fel påverkar inte ärendenummer eller statuslänk.

## Två statusfält

`ApplicationStatus` beskriver handläggningen:

```text
Inkommen
Under granskning
Begär komplettering
Väntar på komplettering
Inspektion ska bokas
Inspektion bokad
Under slutbedömning
Godkänd som aspirant
Avslagen
```

`MembershipStatus` beskriver relationen till SSF:

```text
Ej medlem
Aspirant
Uppföljning
Medlemsfartyg
Avslutad
```

WordPress är master för giltiga övergångar, historik, e-post, aspirantdatum och medlemsstatus. SharePoint får initiera en ändring av ansökningsstatus, men WordPress validerar den innan den används. Okända eller ogiltiga värden lämnar WordPress oförändrat och ger en adminvarning.

## Aspirantår

Ett godkännande kräver ett faktiskt beslutsdatum. Då sätts medlemsstatus till Aspirant, aspirantstart till beslutsdatum och uppföljningsdatum till exakt ett år senare. Saknas `DecisionDate` vid ett SharePoint-godkännande skickas inget godkännandemail och ärendet markeras med varningen "Beslutsdatum saknas".

Ett dagligt WP-Cron-jobb ändrar förfallna aspiranter till Uppföljning. Vyn **SSF > Medlemskap > Aspiranter** markerar ärenden 60 respektive 30 dagar före uppföljningen. Medlemsfartyg kräver därefter ett separat aktivt beslut av en behörig beslutsfattare; det sker aldrig automatiskt.

## SharePoint-ärendet

Ansökningsmappen är ärendet och får metadata. PDF, bilder och bilagor får inte workflowstatus.

```text
Medlemsansökningar/
  År/
    SSF-ÅR-NUMMER - Fartyg/
      Ansökan-SSF-ÅR-NUMMER-Fartyg.pdf
      Bilder/
      Bilagor/
```

WordPress sparar Site ID, Drive ID, List ID, mappens DriveItem ID och mappens ListItem ID. Normal statusläsning och statusskrivning använder lagrat ListItem ID direkt, aldrig mappnamn, filnamn eller fartygsnamn. Även när schemakontrollen misslyckas sparas mappens stabila ID:n så att kopplingen kan återupptas efter manuell rättning.

## Manuella kolumner

Skapa följande kolumner manuellt i dokumentbiblioteket. Internnamnen ska vara exakt dessa ASCII-värden:

| Internnamn | Visningsnamn | Typ |
| --- | --- | --- |
| `ApplicationNumber` | Ansökningsnummer | Enskild textrad |
| `VesselName` | Fartyg | Enskild textrad |
| `ApplicationPath` | Ansökningsväg | Val |
| `ApplicationStatus` | Ansökningsstatus | Val |
| `MembershipStatus` | Medlemsstatus | Val |
| `ReceivedDate` | Inkommen datum | Datum och tid |
| `DecisionDate` | Beslutsdatum | Datum |
| `AspirantStartDate` | Aspirant från | Datum |
| `AspirantReviewDate` | Aspirant uppföljning | Datum |
| `WordPressApplicationID` | WordPress-ID | Enskild textrad |

Valen för `ApplicationPath` är de fyra visningsnamnen ovan. Valen för statuskolumnerna är de exakta statusvärdena i föregående avsnitt.

**SSF > Medlemskap > Processinställningar > Kontrollera SharePoint-konfiguration** testar autentisering, site, drive, lista, kolumnläsning och en befintlig ärendemapps läs-/skrivåtkomst. Kontrollen visar saknade val samt HTTP- och Graph-felkod, men skapar eller ändrar aldrig kolumner. Skrivtestet återlagrar enbart aktuell ansökningsstatus på en redan länkad mapp.

## Synk och historik

Status läses ungefär var 30:e minut under ett globalt lås och ett separat ärendelås. Ändringar jämförs med aktuell WordPress-status, valideras och historikförs med från-status, till-status, tid, källa och känd aktör. Relevant sökandemail skickas endast vid en faktisk övergång. Automatiskt härledda medlems- och aspirantfält skrivs därefter tillbaka till samma ListItem.

`Begär komplettering` skickar kompletteringsmejlet och övergår därefter till `Väntar på komplettering`. När sökanden använder tokenlänken återgår ärendet till `Under granskning`. Interna SharePoint- eller inspektionskommentarer visas aldrig på statussidan.

## Befintliga ärenden

Ingen destruktiv migrering körs. Äldre WordPress-statusar känns fortfarande igen och visas markerade som äldre ärenden. Saknad medlemsstatus läses som Ej medlem. Befintliga SharePoint-fältnamn i en sparad miljöprofil behålls; de nya fälten får rekommenderade standardnamn. Äldre mappar utan fullständig ID-koppling kan öppnas och visar en administrativ uppmaning att synka filerna igen för att komplettera kopplingen.
