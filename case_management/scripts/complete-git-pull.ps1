# Complete a non-interactive pull/merge for case-management-system
Set-Location $PSScriptRoot\..

if (Test-Path ".git\.MERGE_MSG.swp") {
    Remove-Item ".git\.MERGE_MSG.swp" -Force
}

if (Test-Path "pages\dashboard.php") {
    git add "pages/dashboard.php"
}

$env:GIT_EDITOR = "true"
git pull origin main --no-edit

if (Test-Path ".git\MERGE_HEAD") {
    git commit --no-edit
}

git status
