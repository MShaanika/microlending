(function ($) {
  'use strict';

  // Every <select> gets a searchable select2 picker unless opted out with
  // .no-select2 -- mirrors app-datatables-init.js's "every table unless
  // .no-datatable" pattern. Exposed globally so refreshPageContent()
  // (app-ui.js) can re-run this after swapping in fresh #pageContent
  // markup, and so a page that clones its own rows (a repeatable line
  // item, a dynamic contact row) can call it on just the new row.
  window.initSelect2 = function (scope) {
    var $scope = scope ? $(scope) : $(document);

    $scope.find('select').addBack('select').not('.no-select2').each(function () {
      var $select = $(this);
      if ($select.hasClass('select2-hidden-accessible')) {
        return;
      }

      var $modal = $select.closest('.modal');
      $select.select2({
        width: '100%',
        dropdownParent: $modal.length ? $modal : undefined,
      });
    });
  };

  $(function () {
    window.initSelect2();

    // DataTables client-side pagination can move rows (and any per-row
    // <select>, e.g. an inline status picker) in and out of the DOM --
    // re-scan whichever table redrew rather than the whole document.
    $(document).on('draw.dt', function (e) {
      window.initSelect2(e.target);
    });
  });
})(jQuery);
