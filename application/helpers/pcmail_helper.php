<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * pcmail — one definition of how this install sends mail.
 *
 * Transport lives in pc_settings rather than a config file for the same reason
 * cron_token does: local and live are different databases, so they get
 * different mail settings with no file to remember to swap before an upload.
 *
 * Leave mail_smtp_host empty and this returns a PHP mail() configuration, which
 * is what most shared hosts want and what XAMPP cannot do at all.
 *
 * Shared by Auth (verification links) and Cron (mailtest), because a test that
 * configures itself differently from the real thing tests nothing.
 */

if (!function_exists('pc_mail_config')) {
    /** CI Email library configuration for this install. */
    function pc_mail_config()
    {
        $CI =& get_instance();

        $cfg = [
            'mailtype' => 'text',
            'charset'  => 'utf-8',
            'wordwrap' => TRUE,
            /* CI3 defaults newline to "\n". Plenty of SMTP servers mangle or
               reject headers that are not CRLF-terminated, and the symptom is
               the worst kind — send() reports success and nothing arrives. */
            'newline'  => "\r\n",
            'crlf'     => "\r\n",
        ];

        $host = trim((string)$CI->m_user->setting('mail_smtp_host', ''));
        if ($host !== '') {
            $cfg['protocol']     = 'smtp';
            $cfg['smtp_host']    = $host;
            $cfg['smtp_user']    = (string)$CI->m_user->setting('mail_smtp_user', '');
            $cfg['smtp_pass']    = (string)$CI->m_user->setting('mail_smtp_pass', '');
            $cfg['smtp_port']    = (int)$CI->m_user->setting('mail_smtp_port', 587);
            $cfg['smtp_crypto']  = (string)$CI->m_user->setting('mail_smtp_crypto', 'tls');
            $cfg['smtp_timeout'] = 12;
        }
        return $cfg;
    }
}

if (!function_exists('pc_mail_from')) {
    /**
     * [address, display name] to send as.
     *
     * SET THIS ON ANY SUBDOMAIN INSTALL. The fallback below is the site's own
     * host, which on politiclash.giggora.com means no-reply@politiclash.giggora
     * .com — and a cPanel account has giggora.com as a mail domain, not the
     * subdomain. Exim refuses the -f return-path CI3 hands to mail() for a
     * domain it does not serve, mail() returns false, and nothing is sent, on a
     * server whose parent domain mails perfectly well. Point this at an address
     * on the registrable domain instead.
     *
     * Beyond that it is the setting that decides whether mail arrives, not
     * whether it sends. A From on a domain that has not authorised this host is
     * the likeliest reason a message leaves cleanly and is never seen: Gmail and
     * Outlook discard unauthenticated mail silently — no bounce, not even a spam
     * folder. When relaying through a provider's SMTP, this usually has to be
     * the mailbox being authenticated as.
     */
    function pc_mail_from()
    {
        $CI =& get_instance();
        $from = trim((string)$CI->m_user->setting('mail_from', ''));
        if ($from === '') {
            $from = 'no-reply@'.parse_url(base_url(), PHP_URL_HOST);
        }
        $name = trim((string)$CI->m_user->setting('mail_from_name', ''));
        return [$from, $name !== '' ? $name : PC_NAME];
    }
}
