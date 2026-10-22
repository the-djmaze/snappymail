# Quick Installation Guide

## Installation Steps

1. **Plugin is already created** at: `plugins/client-ip-passthrough/`

2. **Enable the Plugin:**
   - Log in to SnappyMail as an administrator
   - Navigate to: Admin Panel → Plugins
   - Find "Client IP Passthrough" in the plugin list
   - Click the "Enable" button
   - (Optional) Click the settings icon to configure plugin options

3. **Configure Your Reverse Proxy** (Required if behind Nginx/Apache/Cloudflare)

   See the README file for detailed proxy configuration instructions.

## Quick Configuration Checklist

- [ ] Plugin enabled in SnappyMail admin panel
- [ ] Reverse proxy configured to pass IP headers (if applicable)
- [ ] "Trust Proxy Headers" enabled in plugin settings (if behind proxy)
- [ ] Test with a real email send (check SMTP logs)
- [ ] Test with IMAP login (check IMAP logs if server supports ID command)

## Plugin Settings

All settings can be configured in the SnappyMail Admin Panel after enabling the plugin:

- **Enable SMTP IP Passthrough** (default: ON)
  - Modifies SMTP EHLO to include client IP

- **Enable IMAP IP Passthrough** (default: ON)
  - Sends IMAP ID command with client IP after login

- **Trust Proxy Headers** (default: ON)
  - IMPORTANT: Only enable if behind a trusted reverse proxy
  - Detects IP from: CF-Connecting-IP, X-Real-IP, X-Forwarded-For
  - If disabled, only uses REMOTE_ADDR

- **IPv6 Support** (default: ON)
  - Properly formats IPv6 addresses in EHLO messages

## Testing

### Quick Test - Verify IP Detection

Create this test file in your SnappyMail directory as `test-ip.php`:

```php
<?php
echo "Detected IP: ";
if (!empty($_SERVER["HTTP_CF_CONNECTING_IP"])) {
    echo $_SERVER["HTTP_CF_CONNECTING_IP"] . " (Cloudflare)";
} elseif (!empty($_SERVER["HTTP_X_REAL_IP"])) {
    echo $_SERVER["HTTP_X_REAL_IP"] . " (X-Real-IP)";
} elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
    echo explode(',', $_SERVER["HTTP_X_FORWARDED_FOR"])[0] . " (X-Forwarded-For)";
} elseif (!empty($_SERVER["HTTP_CLIENT_IP"])) {
    echo $_SERVER["HTTP_CLIENT_IP"] . " (HTTP_CLIENT_IP)";
} elseif (!empty($_SERVER["REMOTE_ADDR"])) {
    echo $_SERVER["REMOTE_ADDR"] . " (REMOTE_ADDR)";
} else {
    echo "unknown";
}
?>
```

Access: `https://your-domain.com/test-ip.php`

The displayed IP should be your actual client IP, not your proxy's IP.

### Full Test - Check Mail Server Logs

#### SMTP Test:
1. Log in to SnappyMail
2. Send a test email
3. Check SMTP logs: `sudo tail -f /var/log/mail.log`
4. Look for: `EHLO [your-client-ip]`

#### IMAP Test (if server supports RFC 2971):
1. Log in to SnappyMail
2. Check IMAP logs: `sudo tail -f /var/log/dovecot.log`
3. Look for ID command with client-ip parameter

### Check Plugin Logs

If SnappyMail logging is enabled, check for plugin messages:

```bash
# View SnappyMail logs
tail -f data/_data_/_default_/logs/*.log | grep "Client IP Passthrough"
```

You should see messages like:
- `Client IP Passthrough: SMTP EHLO set to [192.168.1.100] for user@example.com`
- `Client IP Passthrough: IMAP ID sent with client-ip=192.168.1.100 for user@example.com`

## Troubleshooting

### Wrong IP Detected?

1. Check that your reverse proxy is configured correctly
2. Verify proxy headers are being passed (use test-ip.php)
3. Ensure "Trust Proxy Headers" is enabled in plugin settings
4. Restart your web server after proxy configuration changes

### Plugin Not Working?

1. Verify plugin is enabled in Admin Panel
2. Check file permissions: `chmod -R 644 plugins/client-ip-passthrough/`
3. Check directory permissions: `chmod 755 plugins/client-ip-passthrough/`
4. Review SnappyMail error logs
5. Ensure SnappyMail version is 2.36.0 or higher

### IMAP ID Not Sent?

- Your IMAP server may not support RFC 2971 ID extension
- This is normal - the plugin will detect this and skip sending the command
- Check "Enable IMAP IP Passthrough" is enabled in settings

## Security Notes

**IMPORTANT:** Only enable "Trust Proxy Headers" if your SnappyMail instance is behind a trusted reverse proxy (Nginx, Apache, Cloudflare, etc.).

If your installation is directly accessible from the internet, disable this option to prevent IP spoofing. The plugin will then only use REMOTE_ADDR.

For Nginx users: Always use `set_real_ip_from` directives to restrict which IPs can set the real IP header.

## Support

For detailed information, see the README file.

For issues or questions:
- GitHub: https://github.com/the-djmaze/snappymail
- Documentation: Check the README file in this directory
