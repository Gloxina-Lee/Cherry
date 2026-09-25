param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('start', 'stop')]
    [string]$Action
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false

$dockerCommand = Get-Command docker.exe -ErrorAction SilentlyContinue
$docker = if ($dockerCommand) {
    $dockerCommand.Source
} else {
    Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe'
}
if (-not (Test-Path -LiteralPath $docker)) {
    throw '找不到 Docker CLI。请确认 Docker Desktop 已安装。'
}

$network = 'cherry-preview'
$database = 'cherry-preview-db'
$web = 'cherry-preview-web'
$databaseVolume = 'cherry-preview-db-data'
$wordpressVolume = 'cherry-preview-wp-data'
$databasePassword = 'cherry-preview-only'
$siteUrl = 'http://127.0.0.1:8396'
$themePath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

function Invoke-Docker {
    param([string[]]$DockerArguments)
    & $docker @DockerArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker 命令失败：docker $($DockerArguments -join ' ')"
    }
}

function Test-DockerResource {
    param([string]$Kind, [string]$Name)
    & $docker $Kind inspect $Name *> $null
    return $LASTEXITCODE -eq 0
}

function Test-ContainerRunning {
    param([string]$Name)
    $running = & $docker container inspect --format '{{.State.Running}}' $Name
    if ($LASTEXITCODE -ne 0) { throw "无法读取容器状态：$Name" }
    return $running -eq 'true'
}

& $docker info --format '{{.ServerVersion}}' *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker 引擎未就绪。请先启动 Docker Desktop。'
}

if ($Action -eq 'stop') {
    foreach ($name in @($web, $database)) {
        if ((Test-DockerResource container $name) -and (Test-ContainerRunning $name)) {
            Invoke-Docker @('stop', $name) | Out-Null
        }
    }
    Write-Host 'Cherry 预览站已停止；测试数据保留在 Docker 数据卷中。'
    return
}

if (-not (Test-DockerResource network $network)) {
    Invoke-Docker @('network', 'create', $network) | Out-Null
}
foreach ($volume in @($databaseVolume, $wordpressVolume)) {
    if (-not (Test-DockerResource volume $volume)) {
        Invoke-Docker @('volume', 'create', $volume) | Out-Null
    }
}

if (-not (Test-DockerResource container $database)) {
    Invoke-Docker @(
        'run', '-d', '--name', $database, '--network', $network,
        '--mount', "type=volume,source=$databaseVolume,target=/var/lib/mysql",
        '-e', 'MARIADB_DATABASE=wordpress',
        '-e', 'MARIADB_USER=wordpress',
        '-e', "MARIADB_PASSWORD=$databasePassword",
        '-e', 'MARIADB_ROOT_PASSWORD=cherry-preview-root',
        'mariadb:lts'
    ) | Out-Null
} elseif (-not (Test-ContainerRunning $database)) {
    Invoke-Docker @('start', $database) | Out-Null
}

if (-not (Test-DockerResource container $web)) {
    Invoke-Docker @(
        'run', '-d', '--name', $web, '--network', $network,
        '-p', '127.0.0.1:8396:80',
        '--mount', "type=volume,source=$wordpressVolume,target=/var/www/html",
        '--mount', "type=bind,source=$themePath,target=/var/www/html/wp-content/themes/Cherry,readonly",
        '-e', "WORDPRESS_DB_HOST=$database",
        '-e', 'WORDPRESS_DB_NAME=wordpress',
        '-e', 'WORDPRESS_DB_USER=wordpress',
        '-e', "WORDPRESS_DB_PASSWORD=$databasePassword",
        'wordpress:7.1-php8.3-apache'
    ) | Out-Null
} elseif (-not (Test-ContainerRunning $web)) {
    Invoke-Docker @('start', $web) | Out-Null
}

$databaseReady = $false
for ($attempt = 0; $attempt -lt 45; $attempt++) {
    & $docker exec $database mariadb -h 127.0.0.1 -u wordpress "-p$databasePassword" -e 'SELECT 1' *> $null
    if ($LASTEXITCODE -eq 0) { $databaseReady = $true; break }
    Start-Sleep -Seconds 1
}
if (-not $databaseReady) { throw 'MariaDB 未在 45 秒内就绪，请检查 docker logs cherry-preview-db。' }

$wordpressReady = $false
for ($attempt = 0; $attempt -lt 45; $attempt++) {
    & $docker exec $web test -f /var/www/html/wp-config.php *> $null
    if ($LASTEXITCODE -eq 0) { $wordpressReady = $true; break }
    Start-Sleep -Seconds 1
}
if (-not $wordpressReady) { throw 'WordPress 文件未在 45 秒内就绪，请检查 docker logs cherry-preview-web。' }

$cli = @(
    'run', '--rm', '--network', $network, '--volumes-from', $web,
    '-e', "WORDPRESS_DB_HOST=$database",
    '-e', 'WORDPRESS_DB_NAME=wordpress',
    '-e', 'WORDPRESS_DB_USER=wordpress',
    '-e', "WORDPRESS_DB_PASSWORD=$databasePassword",
    '--entrypoint', 'wp', 'wordpress:cli-php8.3'
)

$check = $cli + @('core', 'is-installed', '--allow-root')
& $docker @check *> $null
if ($LASTEXITCODE -ne 0) {
    Invoke-Docker ($cli + @(
        'core', 'install', "--url=$siteUrl", '--title=CherryPreview',
        '--admin_user=cherryadmin', '--admin_password=cherry-preview-2026',
        '--admin_email=preview@example.invalid', '--skip-email', '--allow-root'
    ))
    Write-Host '初始管理员：cherryadmin / cherry-preview-2026'
}

$getTheme = $cli + @('option', 'get', 'stylesheet', '--allow-root')
$activeTheme = & $docker @getTheme
if ($LASTEXITCODE -ne 0) { throw '无法读取当前启用的主题。' }
if ($activeTheme.Trim() -ne 'Cherry') {
    Invoke-Docker ($cli + @('theme', 'activate', 'Cherry', '--allow-root'))
}

# 当前主题在全新站点缺少此选项时会在 footer.php 报错。仅补测试数据，不覆盖已有设置。
$seedOptions = '$options = get_option("iro_options", []); if (!is_array($options)) { $options = []; } if (!array_key_exists("reception_background", $options)) { $options["reception_background"] = []; update_option("iro_options", $options); }'
Invoke-Docker ($cli + @('eval', $seedOptions, '--allow-root')) | Out-Null
Invoke-Docker @('exec', '-u', 'root', $web, 'sh', '-c', 'mkdir -p /var/www/html/wp-content/uploads && chown -R www-data:www-data /var/www/html/wp-content/uploads') | Out-Null

Write-Host "Cherry 预览站：$siteUrl"
Write-Host "主题设置：$siteUrl/wp-admin/admin.php?page=iro_options"
