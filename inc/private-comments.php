<?php
/** Private comment access must never depend on a self-reported email address. */
function cherry_comment_owner_cookie_name() {
    return 'cherry_comment_owner_' . COOKIEHASH;
}

function cherry_comment_owner_token() {
    $token = $_COOKIE[cherry_comment_owner_cookie_name()] ?? '';
    return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) ? $token : '';
}

function cherry_owns_comment($comment) {
    if (!$comment) {
        return false;
    }
    if ($comment->user_id) {
        return get_current_user_id() > 0 && get_current_user_id() === (int) $comment->user_id;
    }
    $token = cherry_comment_owner_token();
    $stored = get_comment_meta($comment->comment_ID, '_cherry_owner_hash', true);
    return $token !== '' && is_string($stored) && $stored !== ''
        && hash_equals($stored, hash('sha256', $token));
}

function cherry_can_read_private_comment($comment) {
    if (current_user_can('moderate_comments') || cherry_owns_comment($comment)) {
        return true;
    }
    return $comment->comment_parent > 0 && cherry_owns_comment(get_comment($comment->comment_parent));
}

function cherry_hide_private_comment($comment) {
    return $comment && get_comment_meta($comment->comment_ID, '_private', true)
        && !cherry_can_read_private_comment($comment);
}

function siren_private_message_hook($text, $comment = null) {
    $comment = get_comment($comment);
    return cherry_hide_private_comment($comment) ? __('The comment is private', 'sakurairo') : $text;
}
// REST uses comment_text directly, while templates use get_comment_text first.
add_filter('get_comment_text', 'siren_private_message_hook', PHP_INT_MAX, 2);
add_filter('comment_text', 'siren_private_message_hook', PHP_INT_MAX, 2);
add_filter('comment_text_rss', 'siren_private_message_hook', PHP_INT_MAX);
add_filter('get_comment_excerpt', function ($text, $id, $comment) {
    return siren_private_message_hook($text, $comment);
}, PHP_INT_MAX, 3);
add_filter('rest_prepare_comment', function ($response, $comment) {
    if (cherry_hide_private_comment($comment)) {
        $data = $response->get_data();
        if (isset($data['content'])) {
            $data['content'] = array('rendered' => __('The comment is private', 'sakurairo'));
        }
        $response->set_data($data);
    }
    // Even a response without private text can differ between readers.
    $response->header('Cache-Control', 'private, no-store');
    return $response;
}, PHP_INT_MAX, 2);
// Collection responses discard individual comment response headers.
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (preg_match('~^/wp/v2/comments(?:/|$)~', $request->get_route())) {
        $response->header('Cache-Control', 'private, no-store');
    }
    return $response;
}, PHP_INT_MAX, 3);

function siren_mark_private_message($comment_id) {
    if (isset($_POST['is-private'])) {
        update_comment_meta($comment_id, '_private', 'true');
    }
    $comment = get_comment($comment_id);
    if (!$comment || $comment->user_id) {
        return;
    }
    // Record ownership on public comments too, so their authors can read private replies.
    $token = cherry_comment_owner_token();
    if ($token === '') {
        if (headers_sent()) {
            return; // Fail closed; an email cookie must not grant access.
        }
        $token = bin2hex(random_bytes(32));
        if (!setcookie(cherry_comment_owner_cookie_name(), $token, array(
            'expires' => time() + YEAR_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ))) {
            return;
        }
        $_COOKIE[cherry_comment_owner_cookie_name()] = $token;
    }
    update_comment_meta($comment_id, '_cherry_owner_hash', hash('sha256', $token));
}
add_action('comment_post', 'siren_mark_private_message', 5);

// Private HTML must not be stored in shared caches, including pages viewed by guests.
add_action('template_redirect', function () {
    if (is_feed() || (is_singular() && get_comments(array(
        'post_id' => get_queried_object_id(),
        'meta_key' => '_private',
        'meta_value' => 'true',
        'number' => 1,
        'fields' => 'ids',
    )))) {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }
});
