param([string]$BaseUrl = 'http://127.0.0.1:18396')
$ErrorActionPreference = 'Stop'
if ($BaseUrl -ne 'http://127.0.0.1:18396') { throw 'This script targets the disposable security fixture only.' }
$script:checks = 0
function Assert-Security($Condition, $Message) {
    if (-not $Condition) { throw $Message }
    $script:checks++
}
$login = Invoke-WebRequest "$BaseUrl/wp-login.php" -SessionVariable session
$login = Invoke-WebRequest "$BaseUrl/wp-login.php" -Method Post -WebSession $session -Body @{
    log = 'securityadmin'; pwd = 'cherry-security-test-2026'; 'wp-submit' = 'Log In'; testcookie = '1'
} -SkipHttpErrorCheck
$settings = Invoke-WebRequest "$BaseUrl/wp-admin/admin.php?page=iro_options" -WebSession $session -SkipHttpErrorCheck
Assert-Security ($settings.StatusCode -eq 200 -and $settings.Content.Contains('cherry_gallery')) 'Admin login/settings unavailable.'
Assert-Security (-not $settings.Content.Contains('iro_act=gallery_webp')) 'Old unsafe controller link remains.'
$indexUrl = "$BaseUrl/wp-content/uploads/iro_gallery/imglist.json"
$before = (Invoke-WebRequest $indexUrl).Content
$unauth = Invoke-WebRequest "$BaseUrl/wp-admin/admin-post.php" -Method Post -Body @{action='cherry_gallery'; operation='webp'} -SkipHttpErrorCheck
Assert-Security ($unauth.StatusCode -ne 200) 'Anonymous conversion accepted.'
$invalid = Invoke-WebRequest "$BaseUrl/wp-admin/admin-post.php" -Method Post -WebSession $session -Body @{action='cherry_gallery'; operation='webp'} -SkipHttpErrorCheck
Assert-Security ($invalid.StatusCode -eq 403) 'Missing nonce not rejected.'
$confirm = Invoke-WebRequest "$BaseUrl/wp-admin/admin-post.php?action=cherry_gallery&operation=webp" -WebSession $session
Assert-Security ($confirm.Content.Contains('method="post"')) 'GET must render a POST confirmation.'
$nonce = [regex]::Match($confirm.Content, 'name="_wpnonce" value="([^"]+)"').Groups[1].Value
Assert-Security ($nonce.Length -gt 0) 'Confirmation nonce missing.'
$swapped = Invoke-WebRequest "$BaseUrl/wp-admin/admin-post.php" -Method Post -WebSession $session -Body @{action='cherry_gallery'; operation='init'; _wpnonce=$nonce} -SkipHttpErrorCheck
Assert-Security ($swapped.StatusCode -eq 403) 'Nonce accepted for a different operation.'
$legacy = Invoke-WebRequest "$BaseUrl/wp-admin/admin.php?iro_act=gallery_webp" -WebSession $session -SkipHttpErrorCheck
Assert-Security (-not $legacy.Content.Contains('Successfully backed up images')) 'Legacy GET performs conversion.'
Assert-Security ((Invoke-WebRequest $indexUrl).Content -eq $before) 'Denied/GET requests changed the gallery index.'
$valid = Invoke-WebRequest "$BaseUrl/wp-admin/admin-post.php" -Method Post -WebSession $session -Body @{action='cherry_gallery'; operation='webp'; _wpnonce=$nonce} -SkipHttpErrorCheck
Assert-Security ($valid.StatusCode -eq 200 -and $valid.Content.Contains('Originals and the current index are unchanged')) 'Authorized conversion failed.'
Assert-Security ((Invoke-WebRequest $indexUrl).Content -eq $before) 'Conversion changed the active index.'
$confirmInit = Invoke-WebRequest "$BaseUrl/wp-admin/admin-post.php?action=cherry_gallery&operation=init" -WebSession $session
$initNonce = [regex]::Match($confirmInit.Content, 'name="_wpnonce" value="([^"]+)"').Groups[1].Value
$rebuild = Invoke-WebRequest "$BaseUrl/wp-admin/admin-post.php" -Method Post -WebSession $session -Body @{action='cherry_gallery'; operation='init'; _wpnonce=$initNonce} -SkipHttpErrorCheck
Assert-Security ($rebuild.StatusCode -eq 200 -and $rebuild.Content.Contains('Successfully initialized')) 'Authorized index rebuild failed.'
$rest = Invoke-WebRequest "$BaseUrl/?rest_route=/wp/v2/comments&per_page=100"
Assert-Security (-not ($rest.Content -match 'PRIVATE_SECURITY_SENTINEL|GUEST_PRIVATE_SENTINEL|PRIVATE_REPLY_SENTINEL')) 'Private content leaked over HTTP REST.'
Assert-Security (($rest.Headers['Cache-Control'] -join ',').Contains('no-store')) 'REST comment collection can be cached.'
$feed = Invoke-WebRequest "$BaseUrl/?feed=comments-rss2"
Assert-Security (-not ($feed.Content -match 'PRIVATE_SECURITY_SENTINEL|GUEST_PRIVATE_SENTINEL|PRIVATE_REPLY_SENTINEL')) 'Private content leaked over HTTP RSS.'
$captcha = Invoke-WebRequest "$BaseUrl/?rest_route=/sakura/v1/captcha/create"
Assert-Security (($captcha.Content | ConvertFrom-Json).id -match '^[a-f0-9]{64}$') 'Captcha endpoint failed.'
Assert-Security (($captcha.Headers['Cache-Control'] -join ',').Contains('no-store')) 'Captcha HTTP response is cacheable.'
$guestPage = Invoke-WebRequest "$BaseUrl/?p=1" -SessionVariable guest
$guestText = 'HTTP_PRIVATE_' + [guid]::NewGuid().ToString('N')
$submitted = Invoke-WebRequest "$BaseUrl/wp-comments-post.php" -Method Post -WebSession $guest -Body @{
    comment_post_ID = '1'; comment_parent = '0'; author = 'HTTP Guest'; email = 'http-guest@example.invalid';
    comment = $guestText; 'is-private' = '1'
} -SkipHttpErrorCheck
Assert-Security ($submitted.StatusCode -eq 200 -and $submitted.Content.Contains($guestText)) 'Guest cannot read newly submitted private comment.'
$ownerCookie = @($guest.Cookies.GetCookies([uri]$BaseUrl) | Where-Object Name -Like 'cherry_comment_owner_*')
Assert-Security ($ownerCookie.Count -eq 1 -and $ownerCookie[0].HttpOnly) 'Guest ownership cookie missing or readable by JavaScript.'
$ownView = Invoke-WebRequest "$BaseUrl/?p=1" -WebSession $guest
Assert-Security ($ownView.Content.Contains($guestText)) 'Guest ownership does not survive reload.'
Assert-Security (($ownView.Headers['Cache-Control'] -join ',').Contains('no-store')) 'Personalized HTML can be cached.'
$publicView = Invoke-WebRequest "$BaseUrl/?p=1"
Assert-Security (-not $publicView.Content.Contains($guestText)) 'Guest private comment leaked to a new visitor.'
foreach ($path in @('/', '/?p=1', '/?page_id=2', '/?cat=1', '/?s=Hello', '/?p=999999', '/wp-login.php')) {
    $page = Invoke-WebRequest "$BaseUrl$path" -SkipHttpErrorCheck
    Assert-Security ($page.StatusCode -in @(200,404) -and $page.Content -notmatch 'Fatal error|critical error') "Page failed: $path"
}
Write-Output "$script:checks HTTP security/page checks passed."
