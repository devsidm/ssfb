param(
  [string]$BaseUrl = "https://ssfb.se/dev",
  [string]$Username = "ssfhosting"
)

$ErrorActionPreference = "Stop"

if (-not $env:SSF_WP_APP_PASSWORD) {
  throw "Set SSF_WP_APP_PASSWORD before running this script."
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

  if ($Body) {
    Invoke-RestMethod -Uri $uri -Method $Method -Headers $headers -ContentType "application/json; charset=utf-8" -Body ($Body | ConvertTo-Json -Depth 20)
  } else {
    Invoke-RestMethod -Uri $uri -Method $Method -Headers $headers
  }
}

Write-Host "WordPress user"
Invoke-WpApi "/wp/v2/users/me?context=edit" | Select-Object id, name, slug, roles | Format-List

Write-Host "Active theme"
Invoke-WpApi "/wp/v2/themes?status=active" | Select-Object stylesheet, name, version, status | Format-Table

Write-Host "Plugins"
Invoke-WpApi "/wp/v2/plugins?context=edit" | Select-Object plugin, name, status, version | Format-Table

Write-Host "Pages"
Invoke-WpApi "/wp/v2/pages?per_page=100&context=edit" | Select-Object id, slug, status, title | Format-Table

Write-Host "Posts"
Invoke-WpApi "/wp/v2/posts?per_page=10&context=edit" | Select-Object id, slug, status, date, title | Format-Table

Write-Host "Settings"
Invoke-WpApi "/wp/v2/settings" | Select-Object title, description, timezone, date_format, time_format, posts_per_page | Format-List
