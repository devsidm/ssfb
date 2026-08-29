# SSF Information Architecture And Newsletters

## Current Assessment

| Keep | Move | Add |
| --- | --- | --- |
| Start, Om SSF, Stadgar, Medlemskap, Ansökan, Medlemsfartyg, Nyheter and Kontakt retain their existing URLs. | The former flat primary navigation is grouped by visitor intent. `Lämna motion` belongs with member resources rather than organisation pages. | Editorial landing pages for Förbundet, Fartyg, Aktuellt and För medlemmar, plus missing supporting pages and the newsletter archive. |

No existing page URL is moved or removed by the navigation sync, so no redirect is required for this release. The former primary menu remains in WordPress as a separate menu; the active primary location is assigned to `SSF huvudmeny`.

## Navigation After Sync

```text
Start
Förbundet
  Om SSF
  Styrelsen
  Stadgar & dokument
  Kontakt
Fartyg
  Medlemsfartyg
  Om traditionsfartyg
Medlemskap
  Medlemskap
  Ansökan
Aktuellt
  Nyheter
  Nyhetsbrev
  Kalender
För medlemmar
  Årsmöten
  Medlemsinformation
  Lämna motion
```

The theme provides a keyboard-accessible desktop dropdown and an explicit expand/collapse control for each mobile submenu. The footer gets a compact set of high-value links.

Informationen om fartygsombud ligger på sidan `Medlemskap`; det finns ingen separat offentlig sida för den rollen.

## Publishing A Newsletter

1. Open `Nyhetsbrev` in WordPress admin and choose `Lägg till nyhetsbrev`.
2. Enter a title, optional series, optional issue number, date or year, short description and select or upload a PDF from the Media Library. A cover image is optional.
3. Use **Jag känner bara till året** for older Fördevind editions where the exact publication date is unknown. WordPress stores an internal placeholder date for sorting, but the frontend only shows the year.
4. Save as draft to preview, then publish. A published newsletter must have a valid PDF and a valid year.

The archive is `/nyhetsbrev/`. Individual editions get permanent WordPress URLs, show browser-native PDF viewing with an accessible fallback, and can be downloaded directly. The admin list has series, issue, date/year columns, year filtering and non-blocking duplicate warnings for an issue/year or PDF reused elsewhere.

## Importing Older Fördevind PDFs

Use **Nyhetsbrev > Importera äldre nummer** for bulk entry of historical PDFs.

1. Choose multiple PDF files from the Media Library.
2. Review the suggested title, series, year and issue number. Filename parsing is best effort only.
3. Import creates draft newsletter posts with `date_precision = year_only`.
4. Publish each imported draft after reviewing metadata and description.

Import checks whether the same PDF or issue/year already exists and skips likely duplicates. The PDF relation is always stored as `_ssf_newsletter_pdf_id`, so after DEV-to-PROD media import the production newsletter must point to the production attachment ID.

## Navigation Synchronization

The protected `POST /wp-json/ssf-site/v1/sync-information-architecture` endpoint is used during deployment to create missing landing pages, build the two menus and flush rewrite rules. It requires an authenticated WordPress user with `edit_theme_options` and is safe to run repeatedly. Afterward, menus remain ordinary WordPress menus under `Utseende > Menyer`.
