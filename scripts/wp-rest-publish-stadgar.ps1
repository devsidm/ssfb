param(
  [Parameter(Mandatory = $true)]
  [string]$PdfPath,
  [string]$BaseUrl = "https://ssfb.se/dev",
  [string]$Username = "ssfhosting"
)

$ErrorActionPreference = "Stop"

if (-not $env:SSF_WP_APP_PASSWORD) {
  throw "Set SSF_WP_APP_PASSWORD before running this script."
}

if (-not (Test-Path -LiteralPath $PdfPath -PathType Leaf)) {
  throw "PDF-filen hittades inte: $PdfPath"
}

function Invoke-WpApi {
  param(
    [string]$Path,
    [string]$Method = "GET",
    [object]$Body = $null
  )

  $pair = "${Username}:$env:SSF_WP_APP_PASSWORD"
  $auth = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($pair))
  $headers = @{ Authorization = "Basic $auth" }
  $uri = "$BaseUrl/wp-json$Path"

  if ($null -ne $Body) {
    $json = $Body | ConvertTo-Json -Depth 20
    $utf8Body = [Text.Encoding]::UTF8.GetBytes($json)
    return Invoke-RestMethod -Uri $uri -Method $Method -Headers $headers -ContentType "application/json; charset=utf-8" -Body $utf8Body
  }

  return Invoke-RestMethod -Uri $uri -Method $Method -Headers $headers
}

$title = "Stadgar för Sveriges Segelfartygsförbund"
$sourceName = [IO.Path]::GetFileName($PdfPath)
$mediaResults = Invoke-WpApi "/wp/v2/media?per_page=100&context=edit&search=$([uri]::EscapeDataString('SSF Stadgar'))"
$mediaCandidates = foreach ($result in @($mediaResults)) {
  foreach ($candidate in @($result)) {
    if ($candidate.source_url -match '(?i)ssf[- ]stadgar\.pdf$') {
      $candidate
    }
  }
}
$media = @($mediaCandidates | Sort-Object date -Descending)[0]

if (-not $media) {
  $pair = "${Username}:$env:SSF_WP_APP_PASSWORD"
  $auth = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($pair))
  $uploadHeaders = @{
    Authorization = "Basic $auth"
    "Content-Disposition" = "attachment; filename=`"$sourceName`""
  }
  $media = Invoke-RestMethod -Uri "$BaseUrl/wp-json/wp/v2/media" -Method Post -Headers $uploadHeaders -ContentType "application/pdf" -InFile $PdfPath
}

$outline = @(
  @{ title = "§ 1 Namn och ändamål"; anchor = "1-namn-och-andamal" },
  @{ title = "§ 2 Föreningens verksamhet"; anchor = "2-foreningens-verksamhet" },
  @{ title = "§ 3 Medlemskap"; anchor = "3-medlemskap" },
  @{ title = "§ 4 Föreningens uppbyggnad"; anchor = "4-foreningens-uppbyggnad" },
  @{ title = "§ 5 Röstförfarande"; anchor = "5-rostforfarande" },
  @{ title = "§ 6 Årsmöte"; anchor = "6-arsmote" },
  @{ title = "§ 7 Extra möte"; anchor = "7-extra-mote" },
  @{ title = "§ 8 Styrelse"; anchor = "8-styrelse" },
  @{ title = "§ 9 Verksamhets- och räkenskapsår samt revision"; anchor = "9-verksamhets-och-rakenskapsar-samt-revision" },
  @{ title = "§ 10 Stadgeändring"; anchor = "10-stadgeandring" },
  @{ title = "§ 11 Medlemsavgift"; anchor = "11-medlemsavgift" },
  @{ title = "§ 12 Uteslutning"; anchor = "12-uteslutning" },
  @{ title = "§ 13 Upplösning"; anchor = "13-upplosning" }
)

$onlineContent = @"
<p><strong>Dessa stadgar antogs 1976 och är reviderade 1978, 1979, 1981, 1984, 1991, 1997, 2001, 2004, 2005, 2012, 2017 och 2018.</strong></p>
<h3 id="1-namn-och-andamal">§ 1 Namn och ändamål</h3>
<p>Förbundets namn är Sveriges Segelfartygsförbund och dess ändamål är att främja bevarandet och brukandet av segelfartyg och segelfartyg med hjälpmotor, som används eller tidigare använts som seglande yrkesfartyg. Vid nybyggnation skall förbundet verka för att de nya fartygen byggs så att de överensstämmer med vedertagna traditioner för äldre segelfartyg i yrkessjöfart.</p>
<h3 id="2-foreningens-verksamhet">§ 2 Föreningens verksamhet</h3>
<p>Förbundet ska verka för sitt ändamål främst genom att tillse att anslutna fartyg underhålls och förbyggs med största möjliga hänsyn till dess kulturhistoriska värde, traditionella material och de riggtyper som i olika stadier varit typiska för dessa fartyg. Motsvarande gäller beträffande överbyggnader och utrustningsdetaljer.</p>
<p>Förbundet skall till medlemmarna förmedla råd och riktlinjer beträffande riggning, utrustning samt annan information i anslutning därtill.</p>
<p>Förbundet skall bevara och föra vidare kunskapen om att manövrera, handha och underhålla segelskutor.</p>
<p>Förbundet skall verka för att sprida kännedom om och förståelse för bevarandet och brukandet av de fartyg och fartygstyper som omfattas av föreningens ändamål.</p>
<h3 id="3-medlemskap">§ 3 Medlemskap</h3>
<p>Medlem kan vara fartyg eller stödmedlem. Fartyg måste företrädas av ett fartygsombud. Ett fartygsombud företräder ett eller fler fartyg anslutet/anslutna till förbundet. Stödmedlem kan vara enskild eller i form av familj. Som stödmedlem kan styrelsen invälja var och en som vill stödja förbundets verksamhet. Stödfamiljemedlemskap innebär att par som är mantalsskrivna på samma ort, inklusive barn som är under arton år räknas som familj och betalar en gemensam årsavgift.</p>
<p>Fartyg som är medlem i förbundet skall vara segelfartyg eller segelfartyg med hjälpmotor, som används eller tidigare använts som seglande yrkesfartyg med en längd i huvuddäck överstigande 12 meter och bredd 4 meter. Fartyg understigande dessa mått registrerade i svenskt skeppsregister kan efter särskild prövning anslutas till förbundet. Även sådant fartyg under restaurering kan vara anslutet till förbundet. Även nybyggda fartyg byggda och utformade i överensstämmelse med vedertagna traditioner för äldre segelfartyg i yrkessjöfart kan anslutas till förbundet.</p>
<p>Intressesökande fartyg ska fullgöra ett provår som aspirant. Provåret räknas från det styrelsen godkänt fartygsombudets skriftliga ansökan. Efter fullgjord anmärkningsfri aspiranttid beviljas medlemskap som fartyg. Förekommer anmärkning på fartyget har styrelsen rätt att vägra fortsatt medlemskap.</p>
<p>Fartyg kan endast vara anslutet till förbundet genom ett (1) fartygsombud. Fartygsombud skall göra skriftlig ansökan till styrelsen och däri lämna de uppgifter som begärs på av årsmötet fastställt formulär. Under aspirantåret skall det företrädda fartyget besiktigas utav minst två av styrelsen utsedda personer. För avslagna ansökningar skall styrelsen redogöra vid nästkommande årsmöte.</p>
<p>Fartyg kan endast vara anslutet till förbundet genom ett (1) fartygsombud. Person som önskar inträda i förbundet som fartygsombud skall därom göra skriftlig ansökan till styrelsen samt däri lämna de uppgifter som begärs på av årsmötet fastställt formulär. Intressesökande som fartygsombud erhåller först enskilt medlemskap och har därefter att fullgöra ett provår som aspirant. Provåret räknas från det styrelsen godkänt fartygsombudets skriftliga ansökan.</p>
<h3 id="4-foreningens-uppbyggnad">§ 4 Föreningens uppbyggnad</h3>
<p>Årsmötet är högsta beslutande organ. Föreningens styrelse ansvarar gemensamt för föreningens angelägenheter, men för de beslut som fattas inom styrelsen är endast de ansvariga som deltagit däri. Föreningens styrelse väljes av årsmötet till ett antal av minst tre och en suppleant. För att av styrelsen fattade beslut skall vara giltiga skall samtliga ledamöter vara kallade och minst tre av dessa närvarande. Protokoll och närvarolista skall föras på styrelsemöte och årsmöte samt extra möte.</p>
<h3 id="5-rostforfarande">§ 5 Röstförfarande</h3>
<p>Röstberättigad är den som erhållit medlemskap före den 31/12 föregående år och som dessutom erlagt medlemsavgift för innevarande år. Varje röstberättigad enskild medlem äger en (1) röst. Fartygsombuden tilldelas för varje år ett likafördelat rösttal beräknat per den 31/12 föregående år, så att de gemensamt innehar 60% av samtliga enskilda medlemmars och fartygsombuds totala röstetal.</p>
<p>Denna beräkning görs enligt formeln i original-PDF:en.</p>
<p>Beslut i frågor sker genom öppen omröstning. Om någon så påfordrar för en särskild fråga skall beslut ske genom sluten omröstning.</p>
<h3 id="6-arsmote">§ 6 Årsmöte</h3>
<p>Ordinarie årsmöte hålles varje år under första kvartalet. Skriftlig kallelse och dagordning till årsmöte skall vara medlemmarna tillsända minst 1 månad före årsmötet, valberedningens förslag bifogas kallelsen. Motion skall vara styrelsen tillhanda senast årsskiftet före årsmötet och vara medlemmarna tillsänd minst en månad före årsmötet.</p>
<p>Vid årsmötet skall följande frågor behandlas:</p>
<ul>
<li>Val av ordförande, sekreterare samt två justeringsmän för mötet.</li>
<li>Fråga om mötet är i stadgeenlig ordning utlyst.</li>
<li>Fastställande av röstetal för fartygsombuden i enlighet med §5.</li>
<li>Styrelsens redogörelse för det gångna verksamhetsåret.</li>
<li>Revisionsberättelsen.</li>
<li>Fråga om ansvarsfrihet för den avgående styrelsen.</li>
<li>Fastställa antalet ledamöter i styrelsen.</li>
<li>Val av styrelsens ordförande.</li>
<li>Val av styrelsens övriga ledamöter.</li>
<li>Välja två revisorer och en suppleant.</li>
<li>Beslut om räkenskapsårets resultat (=överskott/underskott) och fastställande av balansräkning.</li>
<li>Fastställa medlemsavgifter för det nya året.</li>
<li>Välja valberedning.</li>
<li>Motioner.</li>
<li>Tid och plats för nästa årsmöte.</li>
</ul>
<h3 id="7-extra-mote">§ 7 Extra möte</h3>
<p>Extra möte hålles när styrelsen så finner behövligt, eller när minst 10 röstberättigade därom gör skriftlig framställan till styrelsen med angivande av de frågor som önskas behandlade. Kallelse till extra möte skall ske skriftligt till samtliga medlemmar minst tre veckor i förväg.</p>
<h3 id="8-styrelse">§ 8 Styrelse</h3>
<p>Föreningens hemvist och adress skall vara den sittande sekreterarens. Styrelsen skall bestå av minst: Ordförande, sekreterare, kassör, suppleant. Kassör jämte ordförande har rätt att teckna föreningens firma. Styrelsen ansvarar för utskickningen av kallelser till och protokoll från årsmöten och arbetar i övrigt i enlighet med av årsmötet fattade beslut.</p>
<h3 id="9-verksamhets-och-rakenskapsar-samt-revision">§ 9 Verksamhets- och räkenskapsår samt revision</h3>
<p>Verksamhetsåret omfattar tiden från årsmötet till och med närmast följande årsmöte. Räkenskapsåret sammanfaller med kalenderåret. Räkenskaperna jämte styrelseprotokoll, medlemsmatrikel och inventarieförteckning skall tillsändas revisorerna senast 1 månad före årsmötet. Revisorerna åligger att granska styrelsens förvaltning och räkenskaper under det senaste räkenskapsåret samt att överlämna revisionsberättelse till årsmötet.</p>
<h3 id="10-stadgeandring">§ 10 Stadgeändring</h3>
<p>Förslag till ändring eller tillägg till dessa stadgar skall vara styrelsen tillhanda senast två veckor före det sammanträde på vilket det skall behandlas. För dylikt förlags antagande fordras att det godkännes på ett styrelsemöte och ett årsmöte med 2/3-dels majoritet.</p>
<h3 id="11-medlemsavgift">§ 11 Medlemsavgift</h3>
<p>Medlemsavgift och fartygsavgift till föreningen bestämmes av årsmötet efter förslag av styrelsen. Medlem som inte erlagt och vid anfordran inte erlägger fastställd avgift anses ha utträtt ur föreningen.</p>
<h3 id="12-uteslutning">§ 12 Uteslutning</h3>
<p>Om styrelsen finner att en medlem motverkar föreningens syfte, eller att fartygsombud företräder ett fartyg som icke längre uppfyller av föreningen ställda krav, skall styrelsen tillställa medlemmen en skriftlig varning. Om detta inte leder till åsyftad verkan skall årsmötet eller ett extra möte ta upp frågan om uteslutning av medlemmen.</p>
<h3 id="13-upplosning">§ 13 Upplösning</h3>
<p>Skulle fråga om föreningens upplösning uppstå skall underrättelse därom ske skriftligen till föreningens medlemmar. Föreningen upplöses genom beslut av två på varandra följande möten, varav ett årsmöte, med minst 1 månads mellanrum och 2/3-dels majoritet. Fattas beslut om föreningens upplösning skall föreningens tillgångar tillfalla något inom sjöfarten ideellt eller välgörande ändamål. Paragraf 13 i dessa stadgar kan ej ändras.</p>
<p><em>Den läsbara versionen är transkriberad från original-PDF:en. Originalet är den dokumentversion som ska användas vid frågor om exakt lydelse.</em></p>
"@

$documentBody = @{
  title = $title
  content = $onlineContent
  status = "publish"
  meta = @{
    "_ssf_document_type" = "stadgar"
    "_ssf_document_version" = "2018"
    "_ssf_document_summary" = "Stadgarna antogs 1976 och reviderades senast 2018."
    "_ssf_document_pdf_id" = [int]$media.id
    "_ssf_document_outline" = ($outline | ConvertTo-Json -Compress)
    "_ssf_document_current" = $true
    "_ssf_document_sort" = 0
  }
}

$documentResults = Invoke-WpApi "/wp/v2/ssf-documents?per_page=100&context=edit&status=any"
$documents = foreach ($result in @($documentResults)) {
  foreach ($candidate in @($result)) {
    $candidate
  }
}
$document = @($documents | Where-Object {
  $_.title.raw -eq $title -or $_.title.rendered -like "Stadgar*Segelfartygsf*"
})[0]
if ($document) {
  $document = Invoke-WpApi "/wp/v2/ssf-documents/$($document.id)" "POST" $documentBody
} else {
  $document = Invoke-WpApi "/wp/v2/ssf-documents" "POST" $documentBody
}

$page = @(Invoke-WpApi "/wp/v2/pages?slug=stadgar&context=edit") | Select-Object -First 1
if ($page) {
  Invoke-WpApi "/wp/v2/pages/$($page.id)" "POST" @{ content = "[ssf_stadgar]" } | Out-Null
} else {
  Invoke-WpApi "/wp/v2/pages" "POST" @{ title = "Stadgar"; slug = "stadgar"; status = "publish"; content = "[ssf_stadgar]" } | Out-Null
}

Write-Host "Stadgar publicerade. Dokument-ID: $($document.id), PDF-ID: $($media.id)"
