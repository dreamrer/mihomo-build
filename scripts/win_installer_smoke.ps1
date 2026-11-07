# Windows 安装包冒烟测试(2026-11-07):在一次性的 GitHub runner 上真装、真升级、真卸载。
#   · 安装目录故意带空格、单引号、方括号和中文;
#   · TUN 组件按 App 真正的注册脚本建服务(源码里的 tool/print_win_helper.dart 生成,不在这里另抄规则);
#   · 升级前先以管理员直跑一份 TUN 组件占着文件,看安装器能不能把它结束掉(看代码推断 32 位
#     PowerShell 结束不了 64 位进程,这里实测);
#   · 升级后服务还在不在(看代码:每次升级都会删)。
# 只在 CI 里跑(会装服务、改系统),本机不要跑。
param(
  [Parameter(Mandatory)][string]$Installer,
  [Parameter(Mandatory)][string]$Bin,        # 主程序文件名(不带 .exe)
  [Parameter(Mandatory)][string]$AppName,
  [Parameter(Mandatory)][string]$AppNameEn,
  [Parameter(Mandatory)][string]$AppId
)
$ErrorActionPreference = 'Stop'
if ($env:GITHUB_ACTIONS -ne 'true') { throw '只在一次性的 CI runner 上跑' }

$fail = [System.Collections.Generic.List[string]]::new()
$warn = [System.Collections.Generic.List[string]]::new()
function Check([string]$name, [bool]$ok, [string]$detail = '') {
  if ($ok) { "✅ $name" } else { "❌ $name $detail"; $script:fail.Add($name) }
}
function Note([string]$name, [bool]$ok, [string]$detail = '') {
  if ($ok) { "✅ $name" } else { "⚠️ $name $detail"; $script:warn.Add($name) }
}
function Retry([scriptblock]$cond, [int]$sec = 30) {
  $end = (Get-Date).AddSeconds($sec)
  while ((Get-Date) -lt $end) { try { if (& $cond) { return $true } } catch {} ; Start-Sleep -Milliseconds 500 }
  return $false
}
function Run([string]$exe, [string]$argLine, [int]$sec = 600) {
  $p = Start-Process -FilePath $exe -ArgumentList $argLine -PassThru
  if (-not $p.WaitForExit($sec * 1000)) { throw "超时: $exe" }
  return $p.ExitCode
}
function PeMachine([string]$f) {
  $r = [IO.BinaryReader]::new([IO.File]::OpenRead($f))
  try { $r.BaseStream.Position = 0x3c; $o = $r.ReadInt32(); $r.BaseStream.Position = $o + 4; return $r.ReadUInt16() } finally { $r.Dispose() }
}
# 经命名管道发一个 HTTP 请求。客户端是这个 PowerShell,不是登记的 App —— 组件应该拒绝(这正好证明它在跑、在核对)。
function PipeGet([string]$pipe, [string]$path) {
  $name = $pipe -replace '^\\\\\.\\pipe\\', ''
  $c = [System.IO.Pipes.NamedPipeClientStream]::new('.', $name, [System.IO.Pipes.PipeDirection]::InOut)
  try {
    $c.Connect(5000)
    $req = [Text.Encoding]::ASCII.GetBytes("GET $path HTTP/1.1`r`nHost: localhost`r`nConnection: close`r`n`r`n")
    $c.Write($req, 0, $req.Length); $c.Flush()
    $buf = New-Object byte[] 4096; $sb = [Text.StringBuilder]::new()
    while (($n = $c.Read($buf, 0, $buf.Length)) -gt 0) { [void]$sb.Append([Text.Encoding]::ASCII.GetString($buf, 0, $n)) }
    return $sb.ToString()
  } finally { $c.Dispose() }
}

$root = "C:\Apex CI\O'Brien [x] 测试"
$log = Join-Path $env:RUNNER_TEMP 'apex-smoke'
New-Item -ItemType Directory -Force -Path $log | Out-Null
"installer=$Installer  bin=$Bin  appName=$AppName  dir=$root"

# ─── A. 全新安装 ─────────────────────────────────────────────────────────
$code = Run $Installer "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /DIR=`"$root`" /LOG=`"$log\install.log`""
Check '静默安装退出码 0' ($code -eq 0) "exit=$code"
$main = Join-Path $root "$Bin.exe"
Check '主程序装上了' (Test-Path -LiteralPath $main) $main
$helper = Get-ChildItem -LiteralPath $root -Filter '*HelperService.exe' | Select-Object -First 1
$core = Get-ChildItem -LiteralPath $root -Filter '*Core.exe' | Select-Object -First 1
Check 'TUN 组件装上了' ($null -ne $helper)
Check '内核装上了' ($null -ne $core)
foreach ($f in @($main, $helper.FullName, $core.FullName, (Join-Path $root 'flutter_windows.dll'))) {
  if ($f -and (Test-Path -LiteralPath $f)) { Check "64 位: $(Split-Path -Leaf $f)" ((PeMachine $f) -eq 0x8664) }
}
Check '卸载程序在' (Test-Path -LiteralPath (Join-Path $root 'unins000.exe'))
$k64 = "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\{$AppId}_is1"
$k32 = "HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\{$AppId}_is1"
$key = if (Test-Path $k64) { $k64 } elseif (Test-Path $k32) { $k32 } else { $null }
Check '「应用和功能」里有这一项' ($null -ne $key)
if ($key) { "  卸载项在 $(if ($key -eq $k32) {'32 位'} else {'64 位'}) 视图" }

# ─── B. 按 App 的方式注册 TUN 组件 ───────────────────────────────────────
$svcName = $null
if (Test-Path 'tool/print_win_helper.dart') {
  $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
  $keyFile = Join-Path $log 'key'
  Set-Content -NoNewline -LiteralPath $keyFile -Value (-join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) }))
  $done = Join-Path $log 'done'
  $j = dart tool/print_win_helper.dart --app-name-en $AppNameEn --app-name $AppName --install-dir $root `
         --client-exe-name $Bin --client-sid $sid --auth-source $keyFile --done $done | ConvertFrom-Json
  $svcName = $j.service
  Check 'App 算出的组件路径就是装上的那个' ($j.helperExe -eq $helper.FullName) "app=$($j.helperExe) installed=$($helper.FullName)"
  powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand $j.encoded
  Check '注册脚本跑完' (Retry { Test-Path -LiteralPath $done } 60)
  $svc = Get-CimInstance Win32_Service -Filter "Name='$svcName'"
  Check '服务命令行逐字一致' ($svc.PathName -eq $j.binPath) "got: $($svc.PathName)"
  Check '服务在跑' (Retry { (Get-Service $svcName).Status -eq 'Running' } 20)
  Start-Sleep -Seconds 1
  $r = ''
  try { $r = PipeGet $j.pipe '/version' } catch { $r = "$_" }
  Check '组件在管道上答话,并拒绝冒充的程序' ($r -match 'x-apex-helper-reject: exe_mismatch') $r
} else {
  Note '源码里没有 tool/print_win_helper.dart(老版本),跳过组件注册' $false
}

# ─── C. 覆盖升级 ─────────────────────────────────────────────────────────
$marker = Join-Path $env:APPDATA 'apex-smoke-marker.txt'
# 升级前以管理员直跑一份组件(App 装服务失败时的兜底形态),看安装器能不能结束它
# 带全套参数、换一个服务名(不和上面的服务抢管道名),否则它一启动就因缺参数自己退出,这项就测了个寂寞
$sid2 = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$k2 = Join-Path $log 'key2'; Set-Content -NoNewline -LiteralPath $k2 -Value 'smoke'
$direct = Start-Process -FilePath $helper.FullName -PassThru -ArgumentList `
  "--auth-file `"$k2`" --service-name smokeDirect --client-exe `"$main`" --client-sid $sid2"
Start-Sleep -Seconds 2
$directUp = -not $direct.HasExited
Note '直跑的组件起来了(下面这项才有意义)' $directUp
$code = Run $Installer "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /LOG=`"$log\upgrade.log`""
Check '覆盖升级退出码 0' ($code -eq 0) "exit=$code"
Check '升级后主程序还在' (Test-Path -LiteralPath $main)
if ($directUp) { Note '升级时结束了直跑的组件' ($direct.HasExited) '(32 位 PowerShell 按路径结束不了 64 位进程?)' }
if (-not $direct.HasExited) { Stop-Process -Id $direct.Id -Force -ErrorAction SilentlyContinue }
if ($svcName) {
  # 2026-11-07 起新安装程序会把服务照原样建回来(update_installer.iss RestoreHelperService)
  Check '升级后 TUN 服务还在(不用重新授权)' ($null -ne (Get-Service $svcName -ErrorAction SilentlyContinue))
  Check '升级后 TUN 服务在跑' (Retry { (Get-Service $svcName).Status -eq 'Running' } 20)
  $r2 = ''
  try { $r2 = PipeGet $j.pipe '/version' } catch { $r2 = "$_" }
  Check '升级后组件照常在管道上答话' ($r2 -match 'x-apex-helper-reject: exe_mismatch') $r2
  $p2 = (Get-CimInstance Win32_Service -Filter "Name='$svcName'").PathName
  Check '升级后服务命令行没变' ($p2 -eq $j.binPath) "got: $p2"
}

# ─── D. 卸载 ─────────────────────────────────────────────────────────────
$code = Run (Join-Path $root 'unins000.exe') "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /LOG=`"$log\uninstall.log`""
"卸载程序第一段退出码 $code"
# 第一段马上退出,真正干活的是带 /SECONDPHASE 的子进程
Check '卸载跑完(卸载项消失)' (Retry { -not ($key -and (Test-Path $key)) } 180)
Check '安装目录删掉了' (Retry { -not (Test-Path -LiteralPath $root) } 60) ((Get-ChildItem -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue | Select-Object -First 10 | ForEach-Object FullName) -join '; ')
if ($svcName) { Check '服务删掉了' (Retry { -not (Get-Service $svcName -ErrorAction SilentlyContinue) } 30) }
Check '没有残留进程' (-not (Get-Process | Where-Object { $_.Path -and $_.Path.StartsWith($root) }))

# ─── 收尾 ────────────────────────────────────────────────────────────────
if ($svcName) { sc.exe delete $svcName *> $null }
Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -LiteralPath $marker -Force -ErrorAction SilentlyContinue
""
"警告 $($warn.Count) 项: $($warn -join ' / ')"
"失败 $($fail.Count) 项: $($fail -join ' / ')"
if ($fail.Count -gt 0) { exit 1 }
