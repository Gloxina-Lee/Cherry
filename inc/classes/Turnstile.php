<?php

namespace Sakura\API;

class Turnstile
{
  public function script()
  {
    $site_key = iro_opt('turnstile_site_key');
    $theme = iro_opt('turnstile_theme') ?: 'light';
    return <<<JS
        <script>
            function _turnstileOnLoad() {
                turnstile.render('.cf-turnstile', {
                    sitekey: `{$site_key}`,
                    theme: `{$theme}`,
                });
            };
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=_turnstileOnLoad" async defer></script>
    JS;
  }

  public function html()
  {
    return <<<HTML
        <div class="cf-turnstile"></div>
    HTML;
  }

  public function verify($token, $ip)
  {
    $secret_key = iro_opt('turnstile_secret_key');
    $response = wp_safe_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
      'timeout' => 15,
      'body' => [
        'secret' => $secret_key,
        'response' => $token,
        'remoteip' => $ip,
      ],
    ]);
    
    if (is_wp_error($response)) {
      return [
        'success' => false,
        'error' => $response->get_error_message(),
      ];
    } else {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }
  }
}
