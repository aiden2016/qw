$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$releaseRoot = Join-Path $projectRoot 'release'
$packageDirectory = Join-Path $releaseRoot 'AidenWebsite'
$zipPath = Join-Path $releaseRoot 'AidenWebsite.zip'
$temporaryDirectory = Join-Path $env:TEMP ('aiden-website-build-' + [guid]::NewGuid().ToString('N'))

if ((Test-Path $packageDirectory) -or (Test-Path $zipPath)) {
  throw "Build output already exists under $releaseRoot. Move or rename it before building again."
}

$archiveName = 'php-8.5.12-1-nts-Win32-vs17-x64.zip'
$archiveUrl = 'https://windows.php.net/downloads/releases/' + $archiveName
$checksumUrl = 'https://windows.php.net/downloads/releases/sha256sum.txt'
$runtimeDirectory = Join-Path $temporaryDirectory 'php'
$pythonDistribution = Join-Path $temporaryDirectory 'python-dist'
$pythonWork = Join-Path $temporaryDirectory 'python-work'
$siteDirectory = Join-Path $packageDirectory 'site'

New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null
try {
  New-Item -ItemType Directory -Path $releaseRoot -Force | Out-Null
  $archivePath = Join-Path $temporaryDirectory $archiveName
  Invoke-WebRequest -Uri $archiveUrl -OutFile $archivePath -UseBasicParsing
  $checksumResponse = Invoke-WebRequest -Uri $checksumUrl -UseBasicParsing
  $checksumLine = ($checksumResponse.Content -split "`n") | Where-Object { $_ -match [regex]::Escape($archiveName) } | Select-Object -First 1
  if (-not $checksumLine) {
    throw 'The official PHP SHA-256 checksum was not found.'
  }
  $expectedHash = ($checksumLine.Trim() -split '\s+')[0].ToLowerInvariant()
  $actualHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
  if ($actualHash -ne $expectedHash) {
    throw 'The PHP runtime archive checksum does not match the official checksum.'
  }

  Expand-Archive -LiteralPath $archivePath -DestinationPath $runtimeDirectory
  $phpExecutable = Join-Path $runtimeDirectory 'php.exe'
  if (-not (Test-Path $phpExecutable)) {
    throw 'php.exe was not found in the verified PHP archive.'
  }
  $systemRuntimeDirectory = Join-Path $env:SystemRoot 'System32'
  $runtimeDlls = @(
    'vcruntime140.dll',
    'vcruntime140_1.dll',
    'msvcp140.dll',
    'msvcp140_1.dll',
    'msvcp140_2.dll',
    'msvcp140_atomic_wait.dll',
    'concrt140.dll'
  )
  foreach ($runtimeDll in $runtimeDlls) {
    $runtimeDllPath = Join-Path $systemRuntimeDirectory $runtimeDll
    if (Test-Path -LiteralPath $runtimeDllPath) {
      Copy-Item -LiteralPath $runtimeDllPath -Destination $runtimeDirectory
    }
  }
  if (-not (Test-Path (Join-Path $runtimeDirectory 'vcruntime140.dll'))) {
    throw 'The Microsoft Visual C++ runtime DLL was not found on this build machine.'
  }
  $extensionDirectory = Join-Path $runtimeDirectory 'ext'
  $extensionCheck = & $phpExecutable -n -d "extension_dir=$extensionDirectory" -d 'extension=php_mbstring.dll' -m 2>&1
  if ($LASTEXITCODE -ne 0 -or ($extensionCheck -join "`n") -notmatch '(?im)^mbstring$') {
    throw "Bundled PHP could not load mbstring: $($extensionCheck -join ' ')"
  }

  New-Item -ItemType Directory -Path $packageDirectory | Out-Null
  New-Item -ItemType Directory -Path $siteDirectory | Out-Null
  Copy-Item -LiteralPath $runtimeDirectory -Destination (Join-Path $packageDirectory 'php') -Recurse

  $siteFiles = @(
    'app.php',
    'index.html',
    'aiden.txt',
    '答辩.txt',
    '.users.json',
    '.resources.json',
    '.categories.json',
    '.announcements.json',
    '.password-resets.php',
    '.htaccess',
    'nginx.htaccess',
    '.maintenance.lock'
  )
  foreach ($name in $siteFiles) {
    $source = Join-Path $projectRoot $name
    if (Test-Path -LiteralPath $source -PathType Leaf) {
      Copy-Item -LiteralPath $source -Destination (Join-Path $siteDirectory $name)
    }
  }
  if (-not (Test-Path (Join-Path $siteDirectory 'app.php'))) {
    throw 'app.php was not found in the project directory.'
  }

  $launcherExecutable = Join-Path $pythonDistribution 'AidenWebsite.exe'
  & python -m PyInstaller --noconfirm --clean --onefile --windowed --name AidenWebsite --distpath $pythonDistribution --workpath $pythonWork --specpath $temporaryDirectory (Join-Path $projectRoot 'AidenWebsiteLauncher.py')
  if ($LASTEXITCODE -ne 0 -or -not (Test-Path $launcherExecutable)) {
    throw 'PyInstaller failed to produce AidenWebsite.exe.'
  }
  Copy-Item -LiteralPath $launcherExecutable -Destination $packageDirectory

  $readme = @'
AIDEN 网站桌面启动器

运行 AidenWebsite.exe 后，程序会在本机启动 PHP 网站并自动打开浏览器。
服务只绑定到 127.0.0.1，不会向局域网或互联网开放。
关闭启动器窗口即可停止本地网站服务。

网站数据保存在：
%LOCALAPPDATA%\AidenWebsite\site
程序首次运行时会将此发布包中的网站文件和现有 JSON 数据复制到该目录。
后续网站的账号、公告、分类和资源变更保存在此处。

PHP 运行环境及所需的 Visual C++ 运行库文件随本程序目录附带。
'@
  Set-Content -LiteralPath (Join-Path $packageDirectory 'README.txt') -Value $readme -Encoding UTF8

  Add-Type -AssemblyName System.IO.Compression.FileSystem
  [System.IO.Compression.ZipFile]::CreateFromDirectory(
    $packageDirectory,
    $zipPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false
  )
  Write-Output "Package created: $zipPath"
  Write-Output "Executable: $(Join-Path $packageDirectory 'AidenWebsite.exe')"
}
catch {
  if (Test-Path $packageDirectory) {
    Remove-Item -LiteralPath $packageDirectory -Recurse -Force
  }
  if (Test-Path $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
  }
  throw
}
finally {
  if (Test-Path $temporaryDirectory) {
    Remove-Item -LiteralPath $temporaryDirectory -Recurse -Force
  }
}
