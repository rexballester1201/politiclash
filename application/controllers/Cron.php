<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Cron — the clock that actually runs the game.
 *
 * Every endpoint is gated by the token in pc_settings.cron_token. Change it
 * before deploying.
 *
 *   * * * * *    curl -s https://HOST/cron/tick/<token>     bots + conflicts
 *   5 0 * * *    curl -s https://HOST/cron/open/<token>     open the round
 *   1 21 * * *   curl -s https://HOST/cron/settle/<token>   settle the round
 *
 * open and settle are both idempotent, so a retry after a failed run is safe.
 * If cron is unavailable, /cron/auto/<token> on any interval does the right
 * thing by looking at the clock — one line instead of three.
 */
class Cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('GameEngine');
        $this->load->library('BotBrain');
        $this->load->model(['m_user', 'm_play', 'm_round']);
        @set_time_limit(280);
    }

    private function gate($token)
    {
        $expected = $this->m_user->setting('cron_token', '');
        if (!$expected || !hash_equals((string)$expected, (string)$token)) {
            $this->output->set_status_header(403)->set_output('forbidden');
            return false;
        }
        return true;
    }

    private function out($data)
    {
        /**
         * Never let a cron response be cached.
         *
         * These are plain GET URLs with a static path. A CDN or reverse proxy
         * in front of the site — Cloudflare caches aggressively and this stack
         * already sits behind it — can happily serve a cached 200 for
         * /cron/auto/<token>. The cron job would then report success every
         * minute while the application never actually runs: rounds never open,
         * bots never move, nothing settles, and nothing looks broken.
         */
        $this->output
            ->set_content_type('application/json')
            ->set_header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
            ->set_header('Pragma: no-cache')
            ->set_header('X-Robots-Tag: noindex, nofollow')
            ->set_output(json_encode($data, JSON_PRETTY_PRINT));
    }

    /** Is the wall clock inside today's contribution window? */
    private function inside_window()
    {
        $now   = time();
        $open  = strtotime(date('Y-m-d ').$this->m_user->setting('round_opens', PC_ROUND_OPEN).':00');
        $close = strtotime(date('Y-m-d ').$this->m_user->setting('round_closes', PC_ROUND_CLOSE).':00');
        return ($now >= $open && $now < $close);
    }

    // =========================================================================
    // OPEN — 00:00
    // =========================================================================
    public function open($token = '')
    {
        if (!$this->gate($token)) return;

        // Opening outside the window would create a round that is born already
        // closed — opens_at/closes_at are today's times, so a round started at
        // 22:00 is instantly past its own deadline. The next tick settles it
        // empty and the duplicate guard then blocks a real one until tomorrow.
        if (!$this->inside_window() && $this->input->get('force') !== '1') {
            return $this->out(['ok' => false, 'reason' => 'outside_window',
                'window' => $this->m_user->setting('round_opens', PC_ROUND_OPEN)
                           .'–'.$this->m_user->setting('round_closes', PC_ROUND_CLOSE),
                'hint' => 'append ?force=1 to override']);
        }

        // Make sure the electorate exists before the first round.
        $seed = $this->botbrain->seed((int)$this->m_user->setting('bot_count', 100));

        $res = $this->m_round->open_today();
        if (!$res['ok']) return $this->out(['ok' => false] + $res + ['seed' => $seed]);

        $round = $this->m_round->by_id($res['round_id']);
        $plan  = $this->botbrain->plan_round($round);

        $this->out(['ok' => true, 'round' => $res, 'bots' => $plan, 'seed' => $seed]);
    }

    // =========================================================================
    // TICK — every minute
    // =========================================================================
    public function tick($token = '')
    {
        if (!$this->gate($token)) return;

        $round = $this->m_round->open_round();
        if (!$round) return $this->out(['ok' => true, 'idle' => 'no_open_round']);

        $phase = $this->m_round->phase_info($round);

        // Conflicts land on their own timers, independent of the bots.
        $resolved = $this->m_play->resolve_due_conflicts($round->round_id, $round);

        $bots = ['acted' => 0];
        if ((int)$this->m_user->setting('bots_enabled', 1) === 1) {
            // A bot whose plan predates this round has not been planned yet.
            $unplanned = (int)$this->db->where('plan_round_id !=', (int)$round->round_id)
                                       ->or_where('plan_round_id IS NULL')
                                       ->count_all_results('pc_bot_profiles');
            if ($unplanned > 0) $this->botbrain->plan_round($round);

            $bots = $this->botbrain->tick($round, $phase, 12);
        }

        // The round is over but settlement has not run — close it out now.
        $auto_settled = null;
        if (!$phase['is_open'] && time() >= $phase['closes_ts']) {
            $auto_settled = $this->m_round->settle($round);
        }

        if (mt_rand(1, 60) === 1) $this->m_play->prune_feed(3000);

        $this->out([
            'ok' => true, 'round' => (int)$round->round_no, 'phase' => $phase['phase'],
            'conflicts_resolved' => $resolved, 'bots' => $bots,
            'auto_settled' => $auto_settled,
        ]);
    }

    // =========================================================================
    // SETTLE — 21:00
    // =========================================================================
    public function settle($token = '')
    {
        if (!$this->gate($token)) return;

        $round = $this->m_round->open_round();
        if (!$round) {
            $round = $this->db->where('status', 'resolving')
                              ->order_by('round_no', 'DESC')->limit(1)
                              ->get('pc_rounds')->row();
        }
        if (!$round) return $this->out(['ok' => false, 'reason' => 'nothing_to_settle']);

        /**
         * Refuse to settle a round that is still running.
         *
         * This endpoint has no time awareness of its own, so a cron scheduled
         * in the wrong timezone — say 21:01 UTC against a Manila game — would
         * end every round eight hours early, every day, and look like it was
         * working. Settlement is irreversible, so it has to be asked for
         * explicitly outside the normal close.
         */
        $phase = $this->m_round->phase_info($round);
        if ($phase['is_open'] && $this->input->get('force') !== '1') {
            return $this->out(['ok' => false, 'reason' => 'round_still_open',
                'closes_at' => $round->closes_at,
                'remaining_seconds' => $phase['remaining'],
                'hint' => 'append ?force=1 to settle early on purpose']);
        }

        $this->out($this->m_round->settle($round));
    }

    // =========================================================================
    // AUTO — one entry point, decides for itself
    // =========================================================================
    public function auto($token = '')
    {
        if (!$this->gate($token)) return;

        $now   = time();
        $open  = strtotime(date('Y-m-d ').$this->m_user->setting('round_opens', PC_ROUND_OPEN).':00');
        $close = strtotime(date('Y-m-d ').$this->m_user->setting('round_closes', PC_ROUND_CLOSE).':00');
        $round = $this->m_round->open_round();

        $log = [];

        if (!$round && $now >= $open && $now < $close) {
            $this->botbrain->seed((int)$this->m_user->setting('bot_count', 100));
            $r = $this->m_round->open_today();
            $log['opened'] = $r;
            if (!empty($r['ok'])) {
                $round = $this->m_round->by_id($r['round_id']);
                $log['planned'] = $this->botbrain->plan_round($round);
            }
        }

        if ($round) {
            $phase = $this->m_round->phase_info($round);
            $log['conflicts'] = $this->m_play->resolve_due_conflicts($round->round_id, $round);

            if ($phase['is_open']) {
                if ((int)$this->m_user->setting('bots_enabled', 1) === 1) {
                    $log['bots'] = $this->botbrain->tick($round, $phase, 12);
                }
            } elseif ($now >= $phase['closes_ts']) {
                $log['settled'] = $this->m_round->settle($round);
            }
        }

        // Housekeeping. The ticker is disposable, but nothing else trims it —
        // and when auto is the only scheduled job (the recommended setup) this
        // is the only place it can happen. Roughly once an hour at 1/min.
        if (mt_rand(1, 60) === 1) $this->m_play->prune_feed(3000);

        $this->out(['ok' => true] + $log);
    }

    // =========================================================================
    // SEED — first-run helper: schema check, bots, first round
    // =========================================================================
    /**
     * Send one test message and report exactly what the mail layer did.
     *
     *   curl -s https://HOST/cron/mailtest/<cron_token>
     *
     * Verification mail has two failure modes that look identical from the
     * outside — the send fails, or the send succeeds and the recipient's
     * provider discards it — and registering an account to find out which tells
     * you nothing, because the answer is only in an 8 MB log. This says which,
     * and on a refusal hands back the SMTP conversation, which is the only
     * place a real reason ever appears.
     *
     * Deliberately NOT an open relay: the recipient comes from
     * pc_settings.mail_test_to, never from the URL, so a leaked cron token
     * cannot turn this into a spam cannon. Uses the same pcmail helper as the
     * real thing, because a test configured differently tests nothing.
     */
    public function mailtest($token = '')
    {
        if (!$this->gate($token)) return;

        $to = trim((string)$this->m_user->setting('mail_test_to', ''));
        if ($to === '') {
            return $this->out(['ok' => false,
                'error' => 'Set pc_settings.mail_test_to to the address to send to.']);
        }

        $this->load->helper('pcmail');
        $cfg  = pc_mail_config();
        $from = pc_mail_from();
        $host = isset($cfg['smtp_host']) ? $cfg['smtp_host'] : '';

        $this->load->library('email');
        $this->email->initialize($cfg);
        $this->email->from($from[0], $from[1]);
        $this->email->to($to);
        $this->email->subject('PolitiClash mail test');
        $this->email->message("If you are reading this, mail works.\n\nSent "
            .date('Y-m-d H:i:s')." via ".($host === '' ? 'PHP mail()' : 'SMTP '.$host).".\n");

        $sent = $this->email->send(FALSE);

        $this->out([
            'ok'        => (bool)$sent,
            'transport' => $host === '' ? 'php mail()' : 'smtp '.$host.':'.$cfg['smtp_port'].' '.$cfg['smtp_crypto'],
            'from'      => $from[0],
            'to'        => $to,
            /* On success this is the interesting part: it left the server, so
               anything still missing is the recipient's provider dropping it —
               look at SPF and DKIM for the From domain, not at this code. */
            'note'      => $sent
                ? 'Accepted by the mail layer. If it does not arrive, it is being discarded after we hand it over — check SPF/DKIM for '.substr(strrchr($from[0], '@'), 1)
                : 'Rejected before leaving. The debug trace below says why.',
            'debug'     => $sent ? null : trim(strip_tags($this->email->print_debugger(['headers']))),
        ]);
    }

    public function seed($token = '')
    {
        if (!$this->gate($token)) return;

        $seed = $this->botbrain->seed((int)$this->m_user->setting('bot_count', 100));

        $out = ['ok' => true, 'bots' => $seed];
        if (!$this->m_round->current()) {
            $r = $this->m_round->open_today();
            $out['round'] = $r;
            if (!empty($r['ok'])) {
                $out['planned'] = $this->botbrain->plan_round($this->m_round->by_id($r['round_id']));
            }
        }
        $this->out($out);
    }
}
