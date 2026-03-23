<?php

/**
 * SnappyMail SSO Auth Plugin
 *
 * Authenticates users transparently via a trusted reverse proxy that sets an
 * HTTP header containing the authenticated user's e-mail address (e.g. Authelia,
 * Caddy, Traefik, or any other forward-auth proxy).
 *
 * No master IMAP user required. First-time users enter their own IMAP password
 * once; it is stored encrypted and reused for all future logins.
 *
 * Flow:
 *   1. Reverse proxy authenticates the user and sets the configured header.
 *   2. Plugin reads the header and looks up stored IMAP credentials.
 *   3. If credentials exist → create SSO hash → auto-login (no dialog shown).
 *   4. If no credentials yet → show login form (e-mail pre-filled, password required).
 *   5. After successful IMAP login → credentials are stored encrypted for next time.
 *
 * Storage: APP_PRIVATE_DATA/storage/_sso_/{sanitized-email}/primary.json
 */

use RainLoop\Plugins\AbstractPlugin;
use RainLoop\Plugins\Property;
use RainLoop\Enumerations\PluginPropertyType;

class SsoAuthPlugin extends AbstractPlugin
{
    const
        NAME        = 'SSO Auth',
        AUTHOR      = 'Markus Mauch',
        URL         = 'https://github.com/markusmauch',
        VERSION     = '1.0.0',
        RELEASE     = '2026-03-23',
        REQUIRED    = '2.36.0',
        CATEGORY    = 'Security',
        LICENSE     = 'MIT',
        DESCRIPTION = 'Authenticates users via a trusted reverse proxy header (e.g. Authelia, Caddy, Traefik). '
                    . 'No master IMAP credentials required. IMAP password is entered once and stored encrypted.';

    /** Cookie name used to break redirect loops on failed auto-login */
    const COOKIE_SSO_PENDING = '_sso_pending';

    /**
     * Cookie that records which SSO identity owns the current SnappyMail session.
     * Value: SHA-1 of the SSO e-mail, so the actual address is not exposed in the cookie.
     * Set on every successful SSO login; cleared on logout.
     */
    const COOKIE_SSO_USER = '_sso_user';

    public function Init(): void
    {
        $this->addHook('filter.http-paths',        'onFilterHttpPaths');
        $this->addHook('login.credentials.step-2', 'onLoginCredentialsStep2');
        $this->addHook('login.success',            'onLoginSuccess');
    }

    protected function configMapping(): array
    {
        return [
            Property::NewInstance('redirect_url')
                ->SetType(PluginPropertyType::STRING)
                ->SetLabel('Redirect URL')
                ->SetDescription(
                    'Where to redirect when the SSO header is absent (e.g. your SSO login page). '
                    . 'Leave empty to show the normal SnappyMail login dialog instead.'
                )
                ->SetDefaultValue(''),

            Property::NewInstance('header_name')
                ->SetType(PluginPropertyType::STRING)
                ->SetLabel('Header name')
                ->SetDescription(
                    'HTTP header set by the reverse proxy containing the authenticated user\'s e-mail address. '
                    . 'Default: Remote-Email'
                )
                ->SetDefaultValue('Remote-Email'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hooks
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Hook: filter.http-paths
     *
     * Fires very early in every request, before routing.  We intercept
     * index-page requests that are not yet authenticated:
     *
     *  • No SSO header  → redirect to configured URL (or fall through to login dialog)
     *  • SSO header + credentials stored → create SSO hash, redirect to /?sso
     *  • SSO header + no credentials     → fall through to login dialog
     *
     * @param array $aPaths  URL path segments (passed by reference from Service::Handle)
     */
    public function onFilterHttpPaths(array &$aPaths): void
    {
        // ── Skip non-index paths (sso, json, mailto, admin, …) ───────────────
        $sPath = !empty($aPaths[0]) ? strtolower($aPaths[0]) : '';
        if ($sPath && 'index' !== $sPath) {
            return;
        }

        // ── Skip admin panel requests ────────────────────────────────────────
        $sAdminKey = \RainLoop\Api::Config()->Get('admin_panel', 'key', '') ?: 'admin';
        $sQS = trim($_SERVER['QUERY_STRING'] ?? '');
        if (str_starts_with($sQS, $sAdminKey)) {
            return;
        }

        // ── Read SSO e-mail header ───────────────────────────────────────────
        $sSsoEmail = $this->getSsoEmail();

        // ── Skip if the same SSO identity already owns this session ──────────
        // We track the active SSO identity via a cookie (_sso_user = sha1 of
        // SSO e-mail).  If an account is active AND the cookie matches the
        // current SSO e-mail the user is simply browsing — leave the session alone.
        // If the cookie is missing or belongs to a different SSO identity we
        // fall through and force a re-login (e.g. overnight session where the
        // proxy now reports a different user).
        if (\RainLoop\Api::Actions()->getAccountFromToken(false)) {
            $sSsoUserHash = $_COOKIE[self::COOKIE_SSO_USER] ?? '';
            if ($sSsoEmail && $sSsoUserHash === sha1($sSsoEmail)) {
                return; // Same SSO identity → keep session
            }
            // Different SSO identity: force re-login.
            // Clear stale session cookie so SnappyMail accepts the new login.
            \SnappyMail\Cookies::clear(\RainLoop\Utils::SESSION_TOKEN);
            \SnappyMail\Cookies::clear(\RainLoop\Actions::AUTH_ADDITIONAL_TOKEN_KEY);
        }

        if (!$sSsoEmail) {
            // No header → redirect to configured SSO login page (if set)
            $sUrl = trim($this->Config()->Get('plugin', 'redirect_url', ''));
            if ($sUrl) {
                \MailSo\Base\Http::Location($sUrl);
                exit;
            }
            return; // No redirect URL configured: show normal login dialog
        }

        // ── If the previous auto-login attempt failed, show the login form ───
        // (the _sso_pending cookie is set just before we redirect to /?sso)
        if (!empty($_COOKIE[self::COOKIE_SSO_PENDING])) {
            $this->clearPendingCookie();
            return;
        }

        // ── Load stored primary IMAP account ────────────────────────────────
        $sJson = $this->loadPrimaryAccount($sSsoEmail);
        if (!$sJson) {
            // First visit: no stored account → show login dialog
            return;
        }

        $aAccount = json_decode($sJson, true);
        if (empty($aAccount['email']) || empty($aAccount['pass_enc'])) {
            return;
        }

        $sPassword = \SnappyMail\Crypt::DecryptFromJSON(
            $aAccount['pass_enc'],
            APP_SALT . $sSsoEmail
        );
        if (!$sPassword) {
            return;
        }

        // ── Create a one-time SSO hash and redirect ──────────────────────────
        $sSsoHash = \RainLoop\Api::CreateUserSsoHash($aAccount['email'], $sPassword);
        if ($sSsoHash) {
            // Record the SSO identity for this session before the auto-login redirect
            $this->setSsoUserCookie($sSsoEmail);
            // Mark that an auto-login attempt is in flight (breaks loops on failure)
            setcookie(self::COOKIE_SSO_PENDING, '1', time() + 120, '/', '', true, true);
            \MailSo\Base\Http::Location('/?sso&hash=' . urlencode($sSsoHash));
            exit;
        }
    }

    /**
     * Hook: login.credentials.step-2
     *
     * Fires when the login form is submitted (via DoLogin XHR).
     * We force the e-mail to the SSO identity so the user only needs
     * to enter the IMAP password.
     *
     * @param string $sEmail     (by reference)
     * @param string $sPassword  (by reference, not modified here)
     */
    public function onLoginCredentialsStep2(string &$sEmail, string &$sPassword): void
    {
        $sSsoEmail = $this->getSsoEmail();
        if (!$sSsoEmail) {
            return;
        }
        // Only override the email for the primary (first) login.
        // When a user adds an additional account, a primary account is already
        // active — in that case we must not touch the typed email.
        if (\RainLoop\Api::Actions()->getAccountFromToken(false)) {
            return;
        }
        // Override whatever the user typed with the proxy-verified identity
        $sEmail = $sSsoEmail;
    }

    /**
     * Hook: login.success
     *
     * Fires after a successful IMAP connect + login.
     * We persist the credentials (encrypted) for future auto-logins.
     */
    public function onLoginSuccess(\RainLoop\Model\Account $oAccount): void
    {
        $sSsoEmail = $this->getSsoEmail();
        if (!$sSsoEmail) {
            return;
        }

        $sPassword = $oAccount->ImapPass();
        if (!$sPassword) {
            return;
        }

        $sEncPass = \SnappyMail\Crypt::EncryptToJSON($sPassword, APP_SALT . $sSsoEmail);
        if (!$sEncPass) {
            return;
        }

        $aData = [
            'email'     => $oAccount->Email(),
            'imap_user' => $oAccount->ImapUser(),
            'pass_enc'  => $sEncPass,
        ];

        $this->storePrimaryAccount($sSsoEmail, json_encode($aData));

        // Record which SSO identity owns this session so we can detect a
        // user-switch on the next request (see onFilterHttpPaths).
        $this->setSsoUserCookie($sSsoEmail);

        // Clear the loop-guard cookie (auto-login now has fresh credentials)
        $this->clearPendingCookie();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Returns the SSO e-mail from the configured header, lowercased and trimmed. */
    private function getSsoEmail(): string
    {
        $sHeader = trim($this->Config()->Get('plugin', 'header_name', 'Remote-Email'));
        // Convert "Remote-Email" → "HTTP_REMOTE_EMAIL" (PHP $_SERVER key format)
        $sKey = 'HTTP_' . strtoupper(str_replace('-', '_', $sHeader));
        return strtolower(trim($_SERVER[$sKey] ?? ''));
    }

    /**
     * Returns the storage file path for the given SSO e-mail.
     * Path: APP_PRIVATE_DATA/storage/_sso_/{sanitized-email}/primary.json
     *
     * @param bool $bMkDir  Create the directory if it does not exist
     */
    private function getSsoStoragePath(string $sSsoEmail, bool $bMkDir = false): string
    {
        // Keep printable chars that are safe in directory names; replace others with _
        $sSanitized = preg_replace('/[^a-zA-Z0-9._@\-]/', '_', $sSsoEmail);
        $sDir = APP_PRIVATE_DATA . 'storage/_sso_/' . $sSanitized . '/';
        if ($bMkDir && !is_dir($sDir)) {
            mkdir($sDir, 0700, true);
        }
        return $sDir . 'primary.json';
    }

    /** Reads and returns the raw JSON string for the primary account, or null. */
    private function loadPrimaryAccount(string $sSsoEmail): ?string
    {
        $sPath = $this->getSsoStoragePath($sSsoEmail);
        if (is_readable($sPath)) {
            $sData = file_get_contents($sPath);
            return ($sData !== false && $sData !== '') ? $sData : null;
        }
        return null;
    }

    /** Writes the raw JSON string for the primary account. */
    private function storePrimaryAccount(string $sSsoEmail, string $sJson): bool
    {
        $sPath = $this->getSsoStoragePath($sSsoEmail, true);
        return file_put_contents($sPath, $sJson, LOCK_EX) !== false;
    }

    /** Expires the loop-guard cookie. */
    private function clearPendingCookie(): void
    {
        setcookie(self::COOKIE_SSO_PENDING, '', time() - 3600, '/', '', true, true);
    }

    /**
     * Sets the SSO-user cookie to the SHA-1 of the given e-mail.
     * Lasts for 30 days; refreshed on every auto-login.
     */
    private function setSsoUserCookie(string $sSsoEmail): void
    {
        setcookie(self::COOKIE_SSO_USER, sha1($sSsoEmail), time() + 86400 * 30, '/', '', true, true);
    }
}
