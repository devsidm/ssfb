# SSF Aktuellt

Pluginet `ssf-promotions` visar tidsstyrda och prioriterade budskap på startsidan, årsmötessidan eller alla vanliga innehållssidor.

## Redaktörsflöde

1. Öppna **Innehåll > Aktuellt**.
2. Välj **Lägg till budskap** och fyll i rubrik samt kort text.
3. Välj typ, prioritet, CTA, placering och visningsperiod.
4. Koppla vid behov ett årsmöte, nyhetsbrev, kalenderobjekt, nyhet eller sida. En egen URL kan användas utan relation.
5. Kontrollera förhandsvisningen och publicera.

Från ett årsmöte finns även knappen **Skapa startsidesbudskap**. Den förifyller årsmöte, middag, CTA, prioritet och tillgängliga anmälningsdatum.

## Status och sortering

- **Utkast**: WordPress-statusen är inte publicerad.
- **Schemalagd**: starttiden ligger i framtiden.
- **Aktiv**: publicerad och inom sin visningsperiod.
- **Utgången**: sluttiden har passerat.
- **Arkiverad**: arkivrutan är markerad.

Frontend visar högst tre budskap som standard. De sorteras först på prioritet (`100`, `80`, `50`, `10`) och därefter på senaste starttid. Utgångna, arkiverade och framtida budskap lämnar ingen tom yta i sidan.

Alla tider tolkas i WordPress konfigurerade tidszon. Tom start betyder direkt och tomt slut betyder tills vidare.

## Relationer

Relationer hanteras av providers via filtret `ssf_promotions_relation_providers`. Medföljande providers är:

- `annual_meeting`
- `newsletter`
- `event`
- `post` (nyhet eller sida)

Årsmötesprovidern bygger URL från den befintliga årsmötessidan och stöder ankare för översikt, kallelse, själva årsmötet, middag, dag 2, motioner och handlingar. Om ett relaterat objekt saknas används sparad egen URL, annars döljs CTA:n.

## Rendering

Startsidan använder hooken `ssf_home_after_hero`. Årsmötessidan använder `ssf_annual_meeting_after_header`. Övriga innehållssidor kan visa budskap placerade på **Alla sidor**.

Återanvändning:

```php
echo ssf_promotions_render_current(array(
    'location' => 'home',
    'max' => 3,
    'type' => '',
    'layout' => 'auto',
));
```

Shortcode: `[ssf_promotions max="3" layout="auto" location="home"]`

Gutenberg-block: **SSF – Aktuellt**.

## Behörighet och release

Administratörer får `manage_ssf_promotions`. Alla saves och dupliceringar kräver capability och nonce. Funktionen registreras som `promotions` i `SSF_Feature_Manager` med **Endast administratörer** som standard. Gör den publik under **SSF > System > Funktioner** efter granskning.

Aktiva post-ID:n cachelagras i fem minuter med både object cache och transient. Cacheversionen höjs när ett budskap sparas, arkiveras, återställs eller tas bort.

## Acceptanstest

1. Publicera ett aktivt årsmötesbudskap med ankaret middag och verifiera att CTA går till `?meeting=ID#ssf-am-dinner`.
2. Sätt sluttid till gårdagen och verifiera status **Utgången** samt att budskapet inte visas.
3. Sätt starttid till imorgon och verifiera status **Schemalagd** samt att budskapet inte visas.
4. Publicera fyra aktiva budskap och verifiera att de tre högst prioriterade visas.
5. Ta bort relaterat innehåll och verifiera adminvarningen samt att frontend förblir stabil.
6. Kontrollera 320 px: ingen horisontell scroll, läsbar text och fullbredds-CTA.
7. Tabba till CTA och verifiera tydlig fokusmarkering och begriplig länktext.

