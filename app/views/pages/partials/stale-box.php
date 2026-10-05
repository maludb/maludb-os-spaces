<?php /** The stale box's template: a block changed under the writer; their words offered back, never merged (D7). */ ?>
<template id="stale-box-template">
    <div class="alert alert-warning sp-stale-box mt-1" role="alert">
        <div class="fw-semibold mb-1"><i class="feather-alert-triangle me-1"></i>Someone changed this block while you typed — your words:</div>
        <textarea class="form-control sp-stale-words" rows="3" readonly></textarea>
        <div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-light btn-sm btn-touch sp-stale-copy">Copy my words</button><button type="button" class="btn btn-light btn-sm btn-touch sp-stale-close">Dismiss</button></div>
    </div>
</template>
