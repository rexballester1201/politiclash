<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Auth — registration, email verification, login.
 *
 * Registration asks for four things and nothing more: username, email,
 * password, confirmation. Username and email are both unique. A successful
 * signup credits the starting stake immediately so the new player can pick a
 * campaign and place tokens in the same session — waiting on an inbox before
 * you are allowed to play is how you lose people.
 *
 * Verification still matters (it gates nothing today but marks the account),
 * and the link is mailed on signup.
 */
class Auth extends MY_Controller
{
    // ── pages ───────────────────────────────────────────────────────────────

    public function register()
    {
        if ($this->me) redirect('play');
        $this->view('auth/register', [], [
            'title' => 'Create account', 'back' => base_url(),
        ]);
    }

    public function login()
    {
        if ($this->me) redirect('play');
        $this->view('auth/login', [], [
            'title' => 'Sign in', 'back' => base_url(),
        ]);
    }

    public function logout()
    {
        $this->session->sess_destroy();
        redirect(base_url());
    }

    // ── AJAX uniqueness ─────────────────────────────────────────────────────

    public function check_username()
    {
        $u = trim((string)$this->input->get('u'));
        if (!$this->valid_username($u)) {
            return $this->json(['ok' => false, 'reason' => 'format']);
        }
        $this->json(['ok' => !$this->m_user->username_taken($u)]);
    }

    public function check_email()
    {
        $e = trim((string)$this->input->get('e'));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['ok' => false, 'reason' => 'format']);
        }
        $this->json(['ok' => !$this->m_user->email_taken($e)]);
    }

    // ── register ────────────────────────────────────────────────────────────

    public function do_register()
    {
        if ($this->input->method() !== 'post') redirect('register');

        $username = trim((string)$this->input->post('username'));
        $email    = trim((string)$this->input->post('email'));
        $pass     = (string)$this->input->post('password');
        $pass2    = (string)$this->input->post('password2');

        $err = null;
        if (!$this->valid_username($username)) {
            $err = 'Username must be 3–32 characters: letters, numbers, dot, dash or underscore.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'That email address does not look valid.';
        } elseif (strlen($pass) < 8) {
            $err = 'Password must be at least 8 characters.';
        } elseif ($pass !== $pass2) {
            $err = 'The two passwords do not match.';
        } elseif ($this->m_user->username_taken($username)) {
            $err = 'That username is already taken.';
        } elseif ($this->m_user->email_taken($email)) {
            $err = 'That email is already registered. Try signing in instead.';
        }

        if ($err) {
            $this->session->set_flashdata('err', $err);
            $this->session->set_flashdata('old', ['username' => $username, 'email' => $email]);
            redirect('register');
        }

        $res = $this->m_user->create($username, $email, $pass);
        if (!$res) {
            // Lost a race on one of the UNIQUE indexes.
            $this->session->set_flashdata('err', 'That username or email was just taken. Try another.');
            redirect('register');
        }

        $sent = $this->send_verification($email, $username, $res['token']);

        $this->m_play->feed(null, 'join', null, $username,
            $username.' registered and joined the race with '
            .number_format($this->m_user->starting_balance()).' tokens.', 'good');

        $this->session->sess_regenerate(TRUE);
        $this->session->set_userdata('pc_uid', $res['user_id']);
        $this->m_user->touch_login($res['user_id']);

        /* Do not claim to have sent mail that did not go. The account is real
           either way and the player is logged in either way — but telling them
           to go and check an inbox that will stay empty sends them looking for
           our bug instead of at the resend button. */
        $this->session->set_flashdata('ok',
            'Account created. '.number_format($this->m_user->starting_balance())
            .' tokens are in your wallet.'
            .($sent ? ' We have emailed a verification link to '.$email.'.' : ''));
        if (!$sent) {
            $this->session->set_flashdata('err',
                'We could not send the verification email to '.$email
                .' just now. You can play in the meantime — resend it from Account.'
                .$this->dev_link());
        }

        redirect('choose');
    }

    // ── verify ──────────────────────────────────────────────────────────────

    public function verify($token = '')
    {
        $u = $this->m_user->by_verify_token($token);

        if (!$u) {
            $this->view('auth/verified', ['state' => 'bad'], ['title' => 'Verification', 'back' => base_url()]);
            return;
        }
        if (!empty($u->verify_expires) && strtotime($u->verify_expires) < time()) {
            $this->view('auth/verified', ['state' => 'expired', 'u' => $u],
                ['title' => 'Verification', 'back' => base_url()]);
            return;
        }

        $this->m_user->mark_verified($u->user_id);
        $this->session->sess_regenerate(TRUE);
        $this->session->set_userdata('pc_uid', $u->user_id);
        $this->view('auth/verified', ['state' => 'ok', 'u' => $u],
            ['title' => 'Verification', 'back' => base_url()]);
    }

    public function resend()
    {
        $this->require_login();
        if ($this->me->email_verified) redirect('account');

        $token = $this->m_user->new_verify_token($this->me->user_id);
        if ($this->send_verification($this->me->email, $this->me->username, $token)) {
            $this->session->set_flashdata('ok', 'Verification link sent to '.$this->me->email.'.');
        } else {
            $this->session->set_flashdata('err',
                'We could not send the verification email. Mail is not configured on this'
                .' server yet — see pc_settings.mail_smtp_host.'.$this->dev_link());
        }
        redirect('account');
    }

    // ── login ───────────────────────────────────────────────────────────────

    public function do_login()
    {
        if ($this->input->method() !== 'post') redirect('login');

        $login = trim((string)$this->input->post('login'));
        $pass  = (string)$this->input->post('password');

        $u = $this->m_user->by_login($login);

        // One message for every failure mode — do not confirm which half was wrong.
        if (!$u || !password_verify($pass, $u->password_hash)) {
            $this->session->set_flashdata('err', 'Wrong username or password.');
            $this->session->set_flashdata('old', ['login' => $login]);
            redirect('login');
        }
        if ($u->status !== 'active') {
            $this->session->set_flashdata('err', 'That account is not active.');
            redirect('login');
        }

        // Regenerate before adopting the identity. Without this, a session id
        // planted on the visitor beforehand stays valid once they authenticate,
        // which is session fixation — the planter is then logged in as them.
        $this->session->sess_regenerate(TRUE);
        $this->session->set_userdata('pc_uid', (int)$u->user_id);
        $this->m_user->touch_login($u->user_id);

        $after = $this->session->flashdata('after_login');
        redirect($after ? $after : ($u->campaign_id === null ? 'choose' : 'play'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function valid_username($u)
    {
        return (bool)preg_match('/^[A-Za-z0-9._-]{3,32}$/', $u);
    }

    /**
     * On development, hand back the verification link that could not be mailed.
     * XAMPP has no mail server and never will, so without this the whole verify
     * path is untestable locally unless somebody sets up an SMTP account first —
     * which is why the link was previously buried in an 8 MB CI log.
     *
     * Not a leak: it is the registrant's own token, shown only to them, only
     * when the send failed. It stops appearing the moment ENVIRONMENT is set to
     * production, which is on the pre-launch list anyway (DEPLOY.md Step 6).
     */
    private function dev_link()
    {
        return (ENVIRONMENT === 'development' && $this->last_link !== '')
            ? ' Development link: '.$this->last_link
            : '';
    }

    /**
     * Send the verification link. Returns TRUE only if the mail actually left —
     * callers must not tell the player it was sent otherwise.
     *
     * Transport comes from pc_settings, not from a config file, for the same
     * reason cron_token does: local and live have different databases, so they
     * get different mail settings without anybody having to remember to swap a
     * file before uploading. Leave mail_smtp_host empty and this falls back to
     * PHP mail(), which is what most shared hosts want and what XAMPP cannot do
     * at all. When nothing can be sent the link goes to the CI log, so local
     * development is never blocked on having an SMTP account.
     */
    /** The link from the last send_verification(), for the development fallback. */
    private $last_link = '';

    private function send_verification($email, $username, $token)
    {
        $link = $this->last_link = base_url('verify/'.$token);

        $body = "Hello {$username},\n\n"
              . "Confirm your PolitiClash account:\n{$link}\n\n"
              . "The link is good for 48 hours. If you did not sign up, ignore this message.\n\n"
              . "— PolitiClash";

        $this->load->helper('pcmail');
        $cfg  = pc_mail_config();
        $from = pc_mail_from();

        try {
            $this->load->library('email');
            $this->email->initialize($cfg);     // also covers a second send per request
            $this->email->from($from[0], $from[1]);
            $this->email->to($email);
            $this->email->subject('Confirm your PolitiClash account');
            $this->email->message($body);

            if ($this->email->send(FALSE)) return TRUE;

            /* print_debugger carries the SMTP conversation, which is the only
               place the real reason for a refusal ever shows up. */
            log_message('error', 'PolitiClash verify mail failed for '.$email
                .' via '.(empty($cfg['smtp_host']) ? 'php mail()' : 'smtp '.$cfg['smtp_host'])
                .' — link: '.$link
                .' — '.trim(strip_tags($this->email->print_debugger(['headers']))));
        } catch (Exception $e) {
            log_message('error', 'PolitiClash verify mail exception: '.$e->getMessage().' — link: '.$link);
        }
        return FALSE;
    }
}
