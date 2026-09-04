# SSF Vessel Profile

`SSF_Medlemsfartyg_Profile` är den gemensamma tjänsten och fältkatalogen för fartygsdata.

## Objekt och relationer

- `ssf_application` beskriver processen: ansökningsnummer, route, kontakt, status, granskning och inspektion.
- `medlemsfartyg` beskriver fartyget och är source of truth för fartygsdata.
- `_ssf_linked_ship_id` på ansökan pekar på fartyget.
- `_ssf_source_application_id` på fartyget pekar på den skapande ansökan.
- `_ssf_application_vessel_snapshot` är ett uttryckligt granskningssnapshot från inskickstillfället. Det används inte som levande fartygsprofil.

Nya ansökningar skapar ett `medlemsfartyg` med WordPress-status `draft` och `_ssf_public_visibility=draft`. Ett medlemsbeslut sätter synligheten till `review`; det publicerar inte fartyget automatiskt.

## Routes

- `normal`
- `small_registered`
- `restoration`
- `new_traditional`

Route sparas på både ansökan och fartyg som `_ssf_application_route`.

## Modes

- `MODE_APPLICATION`: ny ansökan, utan fartygsombudets publika kontaktval.
- `MODE_UPDATE`: tokenbaserad uppdatering av befintligt fartyg.
- `MODE_PORTAL`: inloggad redigering för kopplade fartygsombud.
- `MODE_ADMIN`: samtliga profilfält i WordPress-admin.

Anropa `fields_for($route, $mode)`, `collect(...)`, `validate(...)`, `values(...)`, `save(...)` och `render(...)` för framtida portalvyer. En ny portal behöver därför ingen ny fartygsmodell.

## Lagring och legacy

Schemat återanvänder befintliga WordPress-fält och `_ssf_*`-metadata. Ingen gammal data raderas. Läsning har fallback för bland annat:

- `_ssf_main_deck_length` -> `_ssf_length`
- `_ssf_build_place` -> `_ssf_shipyard`
- `post_excerpt` -> `_ssf_short_presentation`

När en ny kort presentation sparas synkas även `_ssf_short_presentation` för äldre mallar och integrationer. Gamla ansökningar utan kopplat fartyg fortsätter läsa `_ssf_application_data`.

## Publicering och persondata

`_ssf_public_visibility` kan vara `draft`, `review`, `public` eller `hidden`. Endast publicerade WordPress-poster med `public`, samt äldre publicerade poster utan nyckeln, visas publikt.

Kontaktfält är interna som standard. Namn/organisation, e-post, telefon och webbplats har separata samtyckesfält. E-post och telefon visas inte enbart för att ett generellt kontaktval är satt.

Huvudbild och galleri ligger på samma `medlemsfartyg`. I update-läget kan fartygsombudet välja en befintlig eller ny bild som huvudbild; nya bilder läggs till utan att befintligt galleri raderas.

## Ändringsspår

Varje profilsparning uppdaterar:

- `_ssf_profile_updated_at`
- `_ssf_profile_updated_source` (`application`, `admin`, `update_link` eller `portal`)

WordPress revisionsstöd för `medlemsfartyg` är fortsatt aktiverat.
