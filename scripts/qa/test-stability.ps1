param([ValidateRange(1,5)][int]$Rounds=4,[ValidateRange(5,60)][int]$MaxMinutes=40)
$ErrorActionPreference='Stop'
$root=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $root
$activeRunners=@(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
    $_.CommandLine -and $_.CommandLine.Contains($root) -and $_.CommandLine -match 'vendor[\\/]phpunit[\\/]phpunit[\\/]phpunit'
})
if($activeRunners.Count -gt 0){throw 'Ya existe un runner PHPUnit del repositorio; no se comparte el esquema de fresapos_test.'}
$runId='stability-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N').Substring(0,8)
$directory=Join-Path '.tools' $runId
New-Item -ItemType Directory -Path $directory | Out-Null
$clock=[Diagnostics.Stopwatch]::StartNew()
$oldTimeout=$env:COMPOSER_PROCESS_TIMEOUT
$results=@()
$completed=$false
$failure=$null
$cases=@(
    @{name='historical-two-keys'; expected=1; arguments=@('tests/Integration/CheckoutConcurrencyTest.php','--filter','two_keys')},
    @{name='historical-same'; expected=1; arguments=@('tests/Integration/CheckoutConcurrencyTest.php','--filter','@same')},
    @{name='historical-discounts'; expected=1; arguments=@('tests/Integration/SaleDiscountsConcurrencyTest.php','--filter','two_discounts')}
)
for($round=0;$round -lt $Rounds;$round++) {
    $order=if($round -eq 0){'default'}elseif($round -eq 1){'reverse'}else{'random'}
    $orderArguments=@("--order-by=$order")
    if($order -eq 'random'){$orderArguments+=@("--random-order-seed=$(1700+$round)")}
    $cases+=@{name="combined-$round-$order";expected=25;arguments=@('tests/Integration/CheckoutConcurrencyTest.php','tests/Integration/SaleDiscountsConcurrencyTest.php')+$orderArguments}
}
$cases+=@{name='related-concurrency';arguments=@(Get-ChildItem tests/Integration -Filter '*ConcurrencyTest.php' | Where-Object {$_.BaseName -notin @('CheckoutConcurrencyTest','SaleDiscountsConcurrencyTest')} | ForEach-Object {'tests/Integration/'+$_.Name})}
try {
    $env:COMPOSER_PROCESS_TIMEOUT='900'
    foreach($case in $cases) {
        if($clock.Elapsed.TotalMinutes -ge $MaxMinutes){throw 'Limite total alcanzado; no se inicia otro escenario.'}
        $started=(Get-Date).ToUniversalTime().ToString('o')
        $before=[Diagnostics.Stopwatch]::StartNew()
        $log=Join-Path $directory ($case.name+'.log')
        $xml=Join-Path $directory ($case.name+'.xml')
        $arguments=@('test','--')+$case.arguments+@('--log-junit',$xml)
        # PowerShell directo evita que local.cmd reinterprete metacaracteres de filtros.
        $ErrorActionPreference='Continue'
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/local.ps1 composer @arguments *> $log
        $ErrorActionPreference='Stop'
        $code=$LASTEXITCODE
        $testCount=0
        $assertions=0
        if(Test-Path -LiteralPath $xml) {
            [xml]$junit=Get-Content -Raw -LiteralPath $xml
            $testCases=@($junit.SelectNodes('//testcase'))
            $testCount=$testCases.Count
            $assertions=($testCases | ForEach-Object {[int]$_.assertions} | Measure-Object -Sum).Sum
        }
        $results+=@{name=$case.name;arguments=$case.arguments;started_at_utc=$started;duration_ms=$before.Elapsed.TotalMilliseconds;exit_code=$code;tests=$testCount;assertions=$assertions;log=$log;junit=$xml}
        @{run_id=$runId;max_minutes=$MaxMinutes;rounds=$Rounds;parallel_test_runners=1;results=$results} | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 (Join-Path $directory 'results.json')
        Get-Content $log -Tail 8
        if($code -ne 0){throw "Fallo visible en $($case.name); bateria detenida."}
        if($testCount -eq 0 -or ($case.ContainsKey('expected') -and $testCount -ne $case.expected)){throw "Cantidad de casos inesperada en $($case.name); revisar filtro."}
    }
    $completed=$true
} catch {
    $failure=$_.Exception.Message
    throw
} finally {
    @{run_id=$runId;completed=$completed;failure=$failure;max_minutes=$MaxMinutes;maximum_active_command_seconds=900;rounds=$Rounds;parallel_test_runners=1;duration_ms=$clock.Elapsed.TotalMilliseconds;results=$results} | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 (Join-Path $directory 'results.json')
    $env:COMPOSER_PROCESS_TIMEOUT=$oldTimeout
    Write-Output "Evidence: $directory"
}


