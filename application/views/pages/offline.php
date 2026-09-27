<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div style="max-width:400px;margin:0 auto;padding:44px 0;text-align:center">
  <div style="font-size:56px;color:var(--warn);line-height:1"><i class="ph-fill ph-wifi-high"></i></div>
  <h1 class="display" style="font-size:1.8rem;margin-top:16px">No signal</h1>
  <p class="lede" style="margin:0 auto 24px">
    PolitiClash needs a connection to read the live board. The app shell is cached,
    but the round state is not — a stale war room is worse than none.
  </p>
  <button class="btn btn-primary btn-block" onclick="location.reload()">
    <i class="ph ph-arrow-clockwise"></i> Try again</button>
</div>
