<?php
/** Run with wp eval-file in a disposable WordPress installation only. */
if (!defined('WP_CLI') || !WP_CLI || getenv('CHERRY_SECURITY_TEST') !== '1') {
    exit('Disposable test installation required.');
}
$GLOBALS['cherry_security_checks'] = 0;
function security_check($condition, $label) {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['cherry_security_checks']++;
}
function security_challenge() {
    $captcha = new \Sakura\API\Captcha();
    $issued = $captcha->create_captcha_img();
    $stored = get_transient('cherry_captcha_' . $issued['id']);
    // Test code has server access; the HTTP response must not expose this verifier.
    for ($answer = 0; $answer <= 198; $answer++) {
        if (hash_equals($stored['answer'], hash_hmac('sha256', $answer . ':' . $issued['time'] . ':' . $issued['id'], wp_salt('nonce')))) {
            return [$captcha, $issued, (string) $answer];
        }
    }
    throw new RuntimeException('Generated challenge has no valid answer.');
}

$mode = $args[0] ?? 'main';
if ($mode === 'auth') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['skip_captcha_check' => '1'];
    $user = get_user_by('login', 'securityadmin');
    $callback = function_exists('CAPTCHA_CHECK') ? 'CAPTCHA_CHECK' : 'verify_turnstile';
    security_check(is_wp_error($callback($user, $user->user_login, 'password')), 'Client bypass must fail: ' . $callback);
    if ($callback === 'CAPTCHA_CHECK') {
        [$captcha, $issued, $answer] = security_challenge();
        $_POST = ['yzm' => $answer, 'timestamp' => (string) $issued['time'], 'id' => $issued['id']];
        security_check($callback($user, $user->user_login, 'password') === $user, 'Valid image captcha login');
        $_POST = ['yzm' => '1', 'timestamp' => 'invalid', 'id' => 'bad'];
        $errors = new WP_Error();
        lostpassword_CHECK($errors);
        security_check($errors->has_errors(), 'Lost password action must mutate the supplied error object');
        $errors = new WP_Error('existing', 'Keep existing registration errors');
        security_check(registration_CAPTCHA_CHECK($errors, 'user', 'a@example.invalid')->get_error_codes() === ['existing', 'prooffail'], 'Registration errors retained');
    } else {
        // Deterministic provider responses; no request or token reaches Cloudflare.
        add_filter('pre_http_request', function () {
            return ['headers' => [], 'body' => '{"success":true}', 'response' => ['code' => 200]];
        });
        $_POST = ['cf-turnstile-response' => 'local-test-token'];
        security_check($callback($user, $user->user_login, 'password') === $user, 'Valid Turnstile response');
    }
    $checks = $GLOBALS['cherry_security_checks'];
    WP_CLI::success("$checks authentication checks passed ($callback).");
    return;
}

$post = wp_insert_post(['post_title' => 'Security fixture', 'post_content' => 'Public content', 'post_status' => 'publish', 'comment_status' => 'open']);
$suffix = strtolower(wp_generate_password(8, false, false));
$owner = wp_create_user('security-owner-' . $suffix, wp_generate_password(), 'owner-' . $suffix . '@example.invalid');
$stranger = wp_create_user('security-stranger-' . $suffix, wp_generate_password(), 'stranger-' . $suffix . '@example.invalid');
$insert = function ($text, $user_id = 0, $parent = 0) use ($post) {
    return wp_insert_comment(['comment_post_ID' => $post, 'comment_content' => $text, 'comment_author' => 'Fixture', 'comment_author_email' => 'owner@example.invalid', 'user_id' => $user_id, 'comment_parent' => $parent, 'comment_approved' => 1]);
};
$private = $insert('PRIVATE_SECURITY_SENTINEL', $owner);
update_comment_meta($private, '_private', 'true');
$comment = get_comment($private);
wp_set_current_user(0);
$_COOKIE = ['comment_author_email_' . COOKIEHASH => 'owner@example.invalid'];
security_check(!str_contains(get_comment_text($private), 'PRIVATE_SECURITY_SENTINEL'), 'Email-cookie impersonation denied');
security_check(!str_contains(get_comment_excerpt($private), 'PRIVATE_SECURITY_SENTINEL'), 'Excerpt redacted');
$GLOBALS['comment'] = $comment;
security_check(!str_contains(apply_filters('comment_text_rss', $comment->comment_content), 'PRIVATE_SECURITY_SENTINEL'), 'RSS redacted');
$request = new WP_REST_Request('GET', '/wp/v2/comments/' . $private);
$response = rest_do_request($request);
security_check($response->get_status() === 200, 'Public REST response exists');
security_check(!str_contains(wp_json_encode($response->get_data()), 'PRIVATE_SECURITY_SENTINEL'), 'REST content redacted');
security_check(str_contains($response->get_headers()['Cache-Control'] ?? '', 'no-store'), 'REST no-store');
wp_set_current_user($stranger);
security_check(!cherry_can_read_private_comment($comment), 'Unrelated account denied');
wp_set_current_user($owner);
security_check(str_contains(get_comment_text($private), 'PRIVATE_SECURITY_SENTINEL'), 'Registered author can read');
wp_set_current_user(1);
security_check(cherry_can_read_private_comment($comment), 'Moderator can read');

wp_set_current_user(0);
$guest_token = bin2hex(random_bytes(32));
$_COOKIE = [cherry_comment_owner_cookie_name() => $guest_token];
$_POST = [];
$parent = $insert('Public guest parent');
siren_mark_private_message($parent);
$_POST = ['is-private' => '1'];
$guest_private = $insert('GUEST_PRIVATE_SENTINEL');
siren_mark_private_message($guest_private);
security_check(cherry_can_read_private_comment(get_comment($guest_private)), 'Guest bearer token can read own comment');
$reply = $insert('PRIVATE_REPLY_SENTINEL', 1, $parent);
update_comment_meta($reply, '_private', 'true');
security_check(cherry_can_read_private_comment(get_comment($reply)), 'Guest parent can read private reply');
$_COOKIE[cherry_comment_owner_cookie_name()] = bin2hex(random_bytes(32));
security_check(!cherry_can_read_private_comment(get_comment($reply)), 'Unrelated guest token denied');
security_check(!str_contains(get_comment_text($guest_private), 'GUEST_PRIVATE_SENTINEL'), 'Other guest cannot read private comment');
security_check(get_comment($guest_private)->comment_content === 'GUEST_PRIVATE_SENTINEL', 'Redaction does not modify stored content');
$GLOBALS['iro_options']['live_search_comment'] = true;
security_check(!str_contains(wp_json_encode(\Sakura\API\Cache::search_json()), 'PRIVATE_SECURITY_SENTINEL'), 'Search stays redacted');

[$captcha, $issued, $answer] = security_challenge();
security_check(strlen($issued['id']) === 64 && !isset($issued['answer']), 'Opaque captcha ID');
security_check($captcha->check_captcha($answer, $issued['time'], $issued['id'])['code'] === 5, 'Issued captcha accepted');
security_check($captcha->check_captcha($answer, $issued['time'], $issued['id'])['code'] !== 5, 'Captcha replay rejected');
security_check($captcha->check_captcha('42', time(), password_hash('42' . time(), PASSWORD_DEFAULT))['code'] !== 5, 'Legacy forged hash rejected');
security_check($captcha->check_captcha('42', time(), bin2hex(random_bytes(32)))['code'] !== 5, 'Unissued challenge rejected');
[$captcha, $issued, $answer] = security_challenge();
security_check($captcha->check_captcha('199', $issued['time'], $issued['id'])['code'] !== 5, 'Wrong answer rejected');
security_check($captcha->check_captcha($answer, $issued['time'], $issued['id'])['code'] !== 5, 'Wrong attempt consumes challenge');
[$captcha, $issued, $answer] = security_challenge();
security_check($captcha->check_captcha($answer, $issued['time'] + 1, $issued['id'])['code'] !== 5, 'Timestamp tampering rejected');
[$captcha, $issued, $answer] = security_challenge();
$key = 'cherry_captcha_' . $issued['id'];
$stored = get_transient($key);
$stored['time'] = time() - 61;
set_transient($key, $stored, 60);
security_check($captcha->check_captcha($answer, $stored['time'], $issued['id'])['code'] !== 5, 'Expired challenge rejected');
$_POST = ['yzm' => [], 'timestamp' => '123', 'id' => []];
security_check(\Sakura\API\Captcha::check_request('yzm')['code'] === 3, 'Malformed form values rejected');
security_check(str_contains(create_CAPTCHA()->get_headers()['Cache-Control'], 'no-store'), 'Captcha responses not cached');

$gallery = new \Sakura\API\gallery();
$root = wp_get_upload_dir()['basedir'] . '/iro_gallery';
$fixture = $root . '/img/security-fixture';
wp_mkdir_p($fixture);
$image = imagecreatetruecolor(20, 10);
imagejpeg($image, $fixture . '/same.jpg');
imagepng($image, $fixture . '/same.png');
imagegif($image, $fixture . '/animation.gif');
imagedestroy($image);
file_put_contents($fixture . '/broken.jpg', 'invalid image');
file_put_contents($fixture . '/existing.png', file_get_contents($fixture . '/same.png'));
file_put_contents($fixture . '/existing.png.webp', 'preexisting file must survive');
file_put_contents($root . '/backup/sentinel', 'old backup');
$files = glob($fixture . '/*');
$files[] = $root . '/backup/sentinel';
$files[] = $root . '/imglist.json';
$before = array_combine($files, array_map('hash_file', array_fill(0, count($files), 'sha256'), $files));
$gallery->webp();
foreach ($before as $path => $hash) {
    security_check(is_file($path) && hash_file('sha256', $path) === $hash, 'Preserve original/existing/index: ' . basename($path));
}
security_check(getimagesize($fixture . '/same.jpg.webp')[2] === IMAGETYPE_WEBP, 'JPEG converted');
security_check(getimagesize($fixture . '/same.png.webp')[2] === IMAGETYPE_WEBP, 'Same-stem PNG converted without collision');
security_check(!file_exists($fixture . '/animation.gif.webp'), 'GIF animation preserved');
security_check(!file_exists($fixture . '/broken.jpg.webp'), 'Invalid source leaves no output');
$converted_hash = hash_file('sha256', $fixture . '/same.jpg.webp');
$gallery->webp();
security_check(hash_file('sha256', $fixture . '/same.jpg.webp') === $converted_hash, 'Repeated conversion does not overwrite');
$gallery->init();
$index = json_decode(file_get_contents($root . '/imglist.json'), true);
$paths = array_merge($index['long'], $index['wide']);
security_check(in_array('/iro_gallery/img/security-fixture/same.jpg.webp', $paths, true), 'Rebuilt index uses copy');
security_check(!in_array('/iro_gallery/img/security-fixture/same.jpg', $paths, true), 'Rebuilt index avoids duplicate original');
security_check(count($paths) === count(array_unique($paths)), 'Index copies deduplicated');
$checks = $GLOBALS['cherry_security_checks'];
WP_CLI::success("$checks security regression checks passed. Fixture post: $post");
