/**
 * Copied from core/misc/batch.js and replaced the updateCallback().
 **/

(function ($, Drupal) {
  Drupal.behaviors.batch = {
    attach: function attach(context, settings) {
      var batch = settings.batch;
      var $progress = $('[data-drupal-progress]').once('batch');
      var progressBar;

      function updateCallback(progress, status, pb) {
        if (progress === '100') {
          pb.stopMonitoring();

          Drupal.ajax({
            url: "".concat(batch.uri, "&op=finished")
          }).execute();
        }
      }

      function errorCallback(pb) {
        $progress.prepend($('<p class="error"></p>').html(batch.errorMessage));
        $('#wait').hide();
      }

      if ($progress.length) {
        progressBar = new Drupal.ProgressBar('updateprogress', updateCallback, 'POST', errorCallback);
        progressBar.setProgress(-1, batch.initMessage);
        progressBar.startMonitoring("".concat(batch.uri, "&op=do"), 10);
        $progress.empty();
        $progress.append(progressBar.element);
      }
    }
  };
})(jQuery, Drupal);
