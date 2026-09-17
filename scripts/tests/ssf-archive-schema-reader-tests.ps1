[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$path = Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-archive-migration.php'
$source = Get-Content -Raw -Encoding UTF8 -LiteralPath $path
$failures = [Collections.Generic.List[string]]::new()

function Assert-True([string]$Name, [bool]$Condition) {
    if (-not $Condition) { $failures.Add($Name) }
}
function Assert-Contains([string]$Name, [string]$Expected) {
    Assert-True $Name $source.Contains($Expected)
}

$request = [regex]::Match($source, '(?m)^.*\?\$expand=sourceColumn&\$select=.*$').Value
Assert-True 'Graph column request found' (-not [string]::IsNullOrWhiteSpace($request))
Assert-True 'Graph request never selects multiChoice' (-not $request.Contains('multiChoice'))
Assert-True 'Graph request selects choice' $request.Contains(',choice,')
Assert-Contains 'Choice values preserved' "'choices' => array_values(array_map('strval'"
Assert-Contains 'Choice allowTextEntry preserved' "'allowTextEntry' => ! empty(`$settings['allowTextEntry'])"
Assert-Contains 'Choice displayAs preserved' "'displayAs' => `$display_as"
Assert-Contains 'Checkboxes identify multi-choice' "'checkBoxes' === `$display_as"
Assert-Contains 'Ambiguous choice schema marked' "`$schema_status = 'AMBIGUOUS'"
Assert-Contains 'Ambiguous schema blocks comparison' "'SUPPORTED' !== (`$source['schema_status'] ?? 'SUPPORTED')"
Assert-Contains 'Ambiguous schema blocks creation' "new WP_Error('ambiguous_column'"
Assert-Contains 'Lookup facet remains supported' "'personOrGroup', 'lookup', 'hyperlinkOrPicture'"
Assert-Contains 'Lookup metadata preserved generically' "`$settings = (array) (`$column[`$type] ?? array())"
Assert-Contains 'Simple text number date facets remain' "array('text', 'choice', 'number', 'currency', 'boolean', 'dateTime'"
Assert-Contains 'Friendly schema error shown' 'kolumnschema kunde inte'
Assert-Contains 'Technical details expandable' '<details><summary>Tekniska detaljer</summary>'
Assert-Contains 'Bearer token redacted' 'Bearer [REDACTED]'
Assert-Contains 'Secret fields redacted' '$1=[REDACTED]'
Assert-True 'No token or secret value rendered directly' (-not $source.Contains("get_error_data()['access_token']"))

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: archive migration Graph column schema reader regression tests.'
