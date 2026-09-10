# Medlemsansökningar

Det befintliga `ssf_application`-flödet använder den gemensamma Vessel Profile-modellen i `ssf-medlemsfartyg`. En ny ansökan skapar ett länkat fartygsutkast men publicerar aldrig fartyg eller kontaktuppgifter automatiskt.

## Flöde

1. Medlemsväg: `normal`, `small_registered`, `restoration` eller `new_traditional`.
2. Fartygsombud.
3. Gemensamma fartygsfakta.
4. Historia och frågor för vald medlemsväg.
5. Huvudbild, övriga bilder och bilagor.
6. Granskning, samtycke, antispam och inskickning.

WordPress sparar först strukturerad ansöknings- och fartygsdata. Därefter skapas en arkiv-PDF och en asynkron SharePoint-synk köas. SharePoint-fel påverkar inte den mottagna ansökans ärendenummer eller statuslänk, men visas i admin och försöks om automatiskt.

## SharePoint

Destinationen konfigureras under **SSF > System > Microsoft 365** och använder den centrala Graph-klienten och dess app-only-autentisering.

```text
Medlemsansökningar/
  År/
    SSF-ÅR-NUMMER - Fartyg/
      Ansökan-SSF-ÅR-NUMMER-Fartyg.pdf
      Bilder/
      Bilagor/
```

Ansökans mapp får metadata med de konfigurerade interna fältnamnen: WordPress-ID, ansökningsnummer, status, fartyg, fartygsombud, inkommet datum, ansökningsväg och extern statuskommentar. E-post lagras inte som metadata.

Status läses var 30:e minut direkt från mappens sparade ListItem-ID. Endast fältet för extern statuskommentar läses för sökanden. En ändrad status går genom den befintliga idempotenta `transition()`-funktionen, historikförs och skickar ett statusmail. Oförändrad status skickar inget mail.
