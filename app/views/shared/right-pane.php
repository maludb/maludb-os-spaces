<?php /** The right pane (sso-shell.md): 380 px beside the main pane from 1280 px, a full page below; empty and hidden until slice 3 puts comments and slice 4 a thread in it. SP.rightPane.open(html, title) / .close(); an HTMX swap into #right-pane-body opens it. */ ?>
<aside id="right-pane" class="app-right-pane" aria-label="Details" hidden>
    <div class="app-right-pane-head">
        <span id="right-pane-title" class="text-truncate"></span>
        <button type="button" class="btn btn-light btn-sm btn-touch" id="right-pane-close" aria-label="Close"><i class="feather-x"></i></button>
    </div>
    <div class="app-right-pane-body" id="right-pane-body"></div>
</aside>
