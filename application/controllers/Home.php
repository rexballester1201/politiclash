<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Home — the cockpit and the public pages.
 *
 * The cockpit at "/" is deliberately open. A guest sees the whole live board:
 * ticking power figures, the scrolling activity feed, who is winning, what is
 * incoming. They just cannot touch anything. Watching a round in progress is
 * the pitch; the sign-up prompt sits inside the console rather than in front
 * of it.
 */
class Home extends MY_Controller
{
    public function index()
    {
        $round = $this->m_round->current();
        $phase = $this->m_round->phase_info($round);

        $this->view('cockpit', [
            'round'   => $round,
            'phase'   => $phase,
            'feed'    => $this->m_play->feed_latest(24),
            'history' => $this->m_round->recent(6),
            /* Null while a round is live. current() hands back the last settled
               round once nothing is open, which is exactly when the report is
               the thing worth looking at. */
            'report'  => $this->m_round->report($round, $this->me ? $this->me->user_id : null),
        ], [
            'title'   => 'War Room',
            'nav'     => 'home',
            'pads'    => (bool)$this->me,
            'cockpit' => true,
            'body_class' => 'is-cockpit',
        ]);
    }

    public function about()
    {
        $this->view('pages/about', [], ['title' => 'About', 'back' => base_url(), 'nav' => 'info']);
    }

    public function faq()
    {
        $this->view('pages/faq', [], ['title' => 'Help Centre', 'back' => base_url(), 'nav' => 'info']);
    }

    public function offline()
    {
        $this->view('pages/offline', [], ['title' => 'Offline', 'back' => base_url()]);
    }

    public function notfound()
    {
        $this->output->set_status_header(404);
        $this->view('pages/notfound', [], ['title' => 'Not found', 'back' => base_url()]);
    }
}
