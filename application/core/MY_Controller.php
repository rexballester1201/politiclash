<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Controller — shared base.
 *
 * Holds the session-backed current user, the view helpers every page uses,
 * and the JSON responder. Guest mode is a first-class state, not an error:
 * the cockpit is fully readable without an account, which is the whole hook.
 */
class MY_Controller extends CI_Controller
{
    /** @var object|null current user row, null for guests */
    protected $me = null;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('GameEngine');
        $this->load->model('m_user');
        $this->load->model('m_play');
        $this->load->model('m_round');
        $this->load->helper(['url', 'form']);

        $uid = $this->session->userdata('pc_uid');
        if ($uid) {
            $this->me = $this->m_user->by_id($uid);
            if (!$this->me || $this->me->status !== 'active') {
                $this->session->unset_userdata('pc_uid');
                $this->me = null;
            }
        }
    }

    // ── guards ──────────────────────────────────────────────────────────────

    protected function require_login($redirect = 'login')
    {
        if (!$this->me) {
            $this->session->set_flashdata('after_login', uri_string());
            redirect($redirect);
        }
    }

    protected function require_campaign()
    {
        $this->require_login();
        if ($this->me->campaign_id === null) redirect('choose');
    }

    // ── rendering ───────────────────────────────────────────────────────────

    /**
     * Render a page.
     *
     * $opts:
     *   title      browser + header title
     *   nav        'game'|'account'|... which bottom-nav item is lit
     *   back       URL for the back arrow (drill-down pages); when set the
     *              bottom nav is suppressed, matching the Giggora pattern
     *   pads       show the floating game pads
     *   body_class extra class on .page
     */
    protected function view($tpl, $data = [], $opts = [])
    {
        $data['me']   = $this->me;
        $data['opts'] = array_merge([
            'title'   => PC_NAME,
            'nav'     => null,
            'back'    => null,
            'pads'    => false,
            'cockpit' => false,
            'body_class' => '',
        ], $opts);

        $this->load->view('_head', $data);
        $this->load->view($tpl, $data);
        $this->load->view('_foot', $data);
    }

    // ── JSON ────────────────────────────────────────────────────────────────

    protected function json($payload, $code = 200)
    {
        $this->output
            ->set_status_header($code)
            ->set_content_type('application/json', 'utf-8')
            ->set_header('Cache-Control: no-store')
            ->set_output(json_encode($payload));
    }

    protected function fail($reason, $code = 400, $extra = [])
    {
        $this->json(array_merge(['ok' => false, 'reason' => $reason], $extra), $code);
    }

    /** POST endpoints are JSON-only and must come from a real session. */
    protected function require_post_login()
    {
        if ($this->input->method() !== 'post') { $this->fail('post_only', 405); return false; }
        if (!$this->me) { $this->fail('not_logged_in', 401); return false; }
        return true;
    }
}
