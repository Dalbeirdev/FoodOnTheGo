# FoodOnTheGo — prepare the LOCAL PostgreSQL databases (idempotent, LOCAL ONLY).
# Creates the dedicated test database and makes sure PostGIS is enabled in both the local and the test database.
# CREATE EXTENSION needs a superuser; the application role (fotg_local) is deliberately not one, so this runs as
# the local superuser whose credentials live in C:\dev\pg-local-credentials.txt (never committed).
$ErrorActionPreference = 'Stop'
$psql = 'C:\dev\pgsql\bin\psql.exe'
$cred = @{}
Get-Content 'C:\dev\pg-local-credentials.txt' | Where-Object { $_ -match '=' } | ForEach-Object { $k, $v = $_ -split '=', 2; $cred[$k.Trim()] = $v.Trim() }
$env:PGPASSWORD = $cred['password']
function Sql($db, $q) { & $psql -h 127.0.0.1 -p 5432 -U $cred['superuser'] -d $db -v ON_ERROR_STOP=1 -Atc $q }

$testDb = 'foodonthego_test'
if (-not (Sql 'postgres' "select 1 from pg_database where datname = '$testDb'")) {
  Sql 'postgres' "create database $testDb owner $($cred['app_user']) encoding 'UTF8' template template0" | Out-Null
  Write-Host "[OK]   created database $testDb" -ForegroundColor Green
} else { Write-Host "[OK]   database $testDb exists" -ForegroundColor Green }

foreach ($db in @($cred['app_database'], $testDb)) {
  Sql $db 'create extension if not exists postgis' | Out-Null
  $v = Sql $db 'select postgis_lib_version()'
  $enc = Sql $db 'show server_encoding'
  Write-Host "[OK]   ${db}: PostGIS $v, encoding $enc" -ForegroundColor Green
}
Remove-Item Env:PGPASSWORD
