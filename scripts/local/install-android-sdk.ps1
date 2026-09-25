# FoodOnTheGo — Android SDK via command-line tools (no admin). Local/test only.
#   SDK -> %LOCALAPPDATA%\Android\Sdk   (same path Android Studio uses)
$ErrorActionPreference = 'Stop'
$log = Join-Path $PSScriptRoot 'install-android-sdk.log'
function Log($m) { $line = "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m; Write-Host $line; Add-Content -Path $log -Value $line }

$env:JAVA_HOME = 'C:\dev\jdk-17'
$sdk = Join-Path $env:LOCALAPPDATA 'Android\Sdk'
$tools = Join-Path $sdk 'cmdline-tools\latest'
New-Item -ItemType Directory -Force $sdk | Out-Null
Log '=== install-android-sdk start ==='

if (-not (Test-Path (Join-Path $tools 'bin\sdkmanager.bat'))) {
  $page = Invoke-WebRequest -Uri 'https://developer.android.com/studio' -UseBasicParsing
  $url = ($page.Links | Where-Object { $_.href -match 'commandlinetools-win-\d+_latest\.zip' } | Select-Object -First 1).href
  if (-not $url) { throw 'command-line tools link not found' }
  Log "Downloading $url"
  $zip = Join-Path $env:TEMP 'cmdline-tools.zip'
  Invoke-WebRequest -Uri $url -OutFile $zip
  $tmp = Join-Path $env:TEMP 'cmdline-tools'; if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
  Expand-Archive -Path $zip -DestinationPath $tmp -Force
  New-Item -ItemType Directory -Force (Split-Path $tools) | Out-Null
  if (Test-Path $tools) { Remove-Item $tools -Recurse -Force }
  Move-Item (Join-Path $tmp 'cmdline-tools') $tools
  Remove-Item $zip, $tmp -Recurse -Force -ErrorAction SilentlyContinue
  Log 'command-line tools installed'
} else { Log 'command-line tools already present' }

$sdkmanager = Join-Path $tools 'bin\sdkmanager.bat'
Log 'Accepting licenses…'
$yes = ('y' + [Environment]::NewLine) * 15
$yes | & $sdkmanager --sdk_root="$sdk" --licenses 2>&1 | Select-Object -Last 2 | ForEach-Object { Add-Content -Path $log -Value $_ }
Log 'Installing platform-tools, platform 35, build-tools 35.0.0, emulator…'
& $sdkmanager --sdk_root="$sdk" 'platform-tools' 'platforms;android-35' 'build-tools;35.0.0' 'emulator' 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
Log "sdkmanager exit code: $LASTEXITCODE"

$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
foreach ($p in @((Join-Path $sdk 'platform-tools'), (Join-Path $tools 'bin'))) { if ($userPath -notlike "*$p*") { $userPath = "$userPath;$p" } }
[Environment]::SetEnvironmentVariable('Path', $userPath, 'User')
[Environment]::SetEnvironmentVariable('ANDROID_HOME', $sdk, 'User')
[Environment]::SetEnvironmentVariable('ANDROID_SDK_ROOT', $sdk, 'User')
Log 'PATH / ANDROID_HOME set (user scope)'

& 'C:\dev\flutter\bin\flutter.bat' config --android-sdk "$sdk" 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
& 'C:\dev\flutter\bin\flutter.bat' doctor --android-licenses 2>&1 | Select-Object -Last 2 | ForEach-Object { Add-Content -Path $log -Value $_ }
& 'C:\dev\flutter\bin\flutter.bat' doctor -v 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
Log '=== install-android-sdk end ==='
