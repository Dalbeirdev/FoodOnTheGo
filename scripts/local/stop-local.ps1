# FoodOnTheGo — stop only this project's local processes (never touches other apps).
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*artisan serve*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force; "stopped Laravel (pid $($_.ProcessId))" }
Get-CimInstance Win32_Process -Filter "Name='node.exe'" | Where-Object { $_.CommandLine -like '*vite*' -and $_.CommandLine -like '*customer-web*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force; "stopped Vite (pid $($_.ProcessId))" }
& 'C:\dev\redis\redis-cli.exe' -h 127.0.0.1 shutdown nosave 2>$null; 'stopped Redis (if running)'
& 'C:\dev\pgsql\bin\pg_ctl.exe' stop -D 'C:\dev\pgdata' -m fast 2>$null; 'stopped PostgreSQL (if running)'
