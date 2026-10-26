## Vad ändras?

Beskriv ändringen och varför den behövs.

## Verifiering

- [ ] `scripts/tests/ssf-ci-tests.ps1` är grön, eller avvikelsen är förklarad nedan.
- [ ] Relevanta PHP-, JavaScript- och releasekontroller är gröna.
- [ ] Ingen hemlighet, lokal miljöfil eller kunddata ingår.
- [ ] Runtime-ändringar i ett plugin har en motsvarande versionshöjning.

## Miljö och release

- [ ] Ingen live-miljö berörs av denna PR.
- [ ] DEV-preflight och relevant DEV-verifiering planeras före PROD.
- [ ] PROD kräver fortfarande separat serverkörning och exakt bekräftelse `DEPLOY`.
- [ ] Release-manifestet ändras endast i en särskild release-PR.

## Risk och återställning

Beskriv största risken och hur ändringen kan återställas.

## Codex review

Begär vid behov en manuell granskning med kommentaren `@codex review`.
