# SnappyMail SSO Auth

Transparent single sign-on for SnappyMail via a trusted reverse proxy that sets an HTTP header with the authenticated user's e-mail address.

Works with any forward-auth proxy: **Authelia**, **Caddy**, **Traefik**, **Nginx** (auth_request), or any other solution that sets a `Remote-Email`-style header after authentication.

## How it differs from `proxy-auth`

| | `proxy-auth` | `sso-auth` |
|---|---|---|
| IMAP credentials | Requires a master user account on the IMAP server | Uses each user's own credentials |
| IMAP server requirement | Must support master user / impersonation | Any standard IMAP server |
| First login | Fully automatic | User enters own password once |
| Subsequent logins | Fully automatic | Fully automatic |

Use `sso-auth` when you cannot or do not want to configure a master user on your IMAP server.

## Flow

```
Browser → Reverse Proxy → SnappyMail
              ↓
    Sets Remote-Email header
              ↓
    Plugin reads header
              ↓
    Credentials stored? ──yes──→ Auto-login (no dialog)
              ↓ no
    Show login form (e-mail pre-filled)
              ↓
    User enters IMAP password
              ↓
    Credentials stored encrypted
              ↓
    Future visits: fully automatic
```

## Setup

### 1. Reverse proxy

Configure your proxy to authenticate the user and set the `Remote-Email` header. Example for **Authelia** with Nginx Proxy Manager:

```nginx
auth_request /internal/authelia/authz;
auth_request_set $redirection_url $upstream_http_location;
auth_request_set $email $upstream_http_remote_email;

# Return JSON for XHR requests when session expires, redirect for page navigation
error_page 401 =200 @authelia_error;
location @authelia_error {
    default_type application/json;
    if ($request_method = POST) {
        return 200 '{"Result":true}';
    }
    return 302 $redirection_url;
}

location / {
    proxy_set_header Remote-Email $email;
    # ...
}
```

> **Note on `{"Result":true}` for POST:** When a SnappyMail XHR request arrives with an
> expired proxy session, returning this response causes SnappyMail to call
> `location.reload()`, which triggers a full page reload. The page load is then
> intercepted by the proxy (401 → redirect to login). This avoids error dialogs in the UI.

### 2. SnappyMail — application.ini

If your proxy redirects to SnappyMail from a different (sub)domain, allow the
`Sec-Fetch-Site: same-site` header so SnappyMail accepts the final `/?sso&hash=` request:

```ini
[security]
secfetch_allow = "site=same-site"
```

For logout, set a custom logout link pointing to your SSO provider:

```ini
[labs]
custom_logout_link = "https://auth.example.com/logout?rd=https://mail.example.com"
```

### 3. Activate the plugin

In the SnappyMail admin panel → Extensions → enable **SSO Auth**.

Configure:

| Setting | Description | Example |
|---------|-------------|---------|
| **Redirect URL** | Where to redirect when the SSO header is absent. Leave empty to show the normal login dialog. | `https://auth.example.com` |
| **Header name** | HTTP header set by the proxy with the user's e-mail address. | `Remote-Email` |

## Credential storage

Each user's IMAP password is stored encrypted in:

```
APP_PRIVATE_DATA/storage/_sso_/{sanitized-email}/primary.json
```

The password is encrypted with `APP_SALT + SSO-email` using SnappyMail's built-in
`SnappyMail\Crypt` class. No plaintext credentials are stored.

## Additional accounts

Users can add extra IMAP accounts (e.g. a Gmail account) via the standard SnappyMail
account switcher. The plugin does not interfere with additional account management.

## Session tracking

The plugin sets a `_sso_user` cookie (SHA-1 of the SSO e-mail) to track which identity
owns the current session. If the proxy reports a different user on the next page load
(e.g. after an overnight session where another family member logged in), the old session
is cleared and the new user is logged in automatically.

## Loop protection

A `_sso_pending` cookie (120 s TTL) is set immediately before the `/?sso&hash=` redirect.
If auto-login fails (e.g. wrong stored password), the cookie prevents an infinite redirect
loop and the login form is shown instead so the user can re-enter their password.
