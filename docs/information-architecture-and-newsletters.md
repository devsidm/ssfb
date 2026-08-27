# SSF information architecture and newsletters

## Current assessment

| Keep | Move | Add |
| --- | --- | --- |
| Start, Om SSF, Stadgar, Medlemskap, Ansökan, Medlemsfartyg, Nyheter and Kontakt retain their existing URLs. | The former flat primary navigation is grouped by visitor intent. `Lämna motion` belongs with member resources rather than organisation pages. | Editorial landing pages for Förbundet, Fartyg, Aktuellt and För medlemmar, plus missing supporting pages and the newsletter archive. |

No existing page URL is moved or removed by the navigation sync, so no redirect is required for this release. The former primary menu remains in WordPress as a separate menu; the active primary location is assigned to `SSF huvudmeny`.

## Navigation after sync

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
  Bli medlem
  Fartygsombud
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

## Publishing a newsletter

1. Open `Nyhetsbrev` in WordPress admin and choose `Lägg till nytt`.
2. Enter a title, issue, date, short description and select or upload a PDF from the Media Library. A cover image is optional.
3. Save as draft to preview, then publish. The year is derived from the date; the newest published date is automatically shown first.

The archive is `/nyhetsbrev/`. Individual editions get permanent WordPress URLs, show browser-native PDF viewing with an accessible fallback, and can be downloaded directly. The admin list has date/year columns, year filtering and non-blocking duplicate warnings for an issue/year or PDF reused elsewhere.

## Navigation synchronization

The protected `POST /wp-json/ssf-site/v1/sync-information-architecture` endpoint is used during deployment to create missing landing pages, build the two menus and flush rewrite rules. It requires an authenticated WordPress user with `edit_theme_options` and is safe to run repeatedly. Afterward, menus remain ordinary WordPress menus under `Utseende > Menyer`.
