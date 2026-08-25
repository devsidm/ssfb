# SSF Stadgar & Dokument

`ssf-stadgar` är ett eget WordPress-plugin för SSF:s stadgar och styrdokument. Det använder en custom post type (`ssf_document`) och WordPress vanliga postmetadata i stället för egna databastabeller. Det gör att dokument får revisioner, behörighetskontroll och mediehantering på samma sätt som övrigt WordPress-innehåll.

## Datamodell

Varje dokument innehåller titel och webbversion i WordPress-redigeraren, samt dokumenttyp, version, antagningsdatum, antagen av, beskrivning, ändringsanteckning, PDF, snabböversikt, relationer, sorteringsordning och markering för gällande version som metadata.

Dokument kan vara `Utkast`, `Publicerad` eller `Arkiverad`. Bara ett publicerat dokument av typen `Stadgar` kan vara gällande. När en ny stadga publiceras som gällande flyttas den tidigare automatiskt till arkivet och finns kvar i versionshistoriken.

## Så publicerar du nya stadgar

1. Öppna **Stadgar & dokument** i WordPress-admin och välj **Lägg till dokument**.
2. Ange titel, typ `Stadgar`, version och antagningsuppgifter.
3. Välj PDF från mediabiblioteket.
4. Kontrollera eller redigera snabböversikten. Varje rad har formatet `Rubrik | ankare`.
5. Skriv den tillgängliga webbversionen i redigeraren och använd en rubrik för varje paragraf.
6. Markera **Detta är gällande version av stadgarna**.
7. Välj **Publicerad** och uppdatera dokumentet.

PDF-analysen är avsiktligt försiktig. Pluginet gör ett försök att läsa textlagret och identifiera paragraf-rubriker, men publicering fungerar alltid även när en PDF inte kan tolkas. Administratören kan då skriva webbtext och snabböversikt manuellt.

## Publik sida

Sidan `/stadgar/` innehåller kortkoden `[ssf_stadgar]`. Den visar den gällande versionen först, därefter snabböversikt och läsbar stadgetext, relaterade dokument och arkiverade tidigare versioner. Ingressen och statusmeddelandet redigeras under **Stadgar & dokument > Inställningar**.
