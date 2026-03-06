$file = "files.php"
$start = 2238  # start of old duplicate modal block
$end = 2488    # ends just before HIDDEN FORMS section
$lines = Get-Content $file
$keep = $lines[0..($start - 2)] + $lines[($end - 1)..($lines.Count - 1)]
[System.IO.File]::WriteAllLines((Resolve-Path $file).Path, $keep)
Write-Host "Done. New line count: $($keep.Count)"
