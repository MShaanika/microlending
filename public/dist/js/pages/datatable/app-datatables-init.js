(function ($) {
  'use strict';

  function isEmptyStateTable(table) {
    var $rows = table.find('tbody > tr');
    if ($rows.length !== 1) {
      return false;
    }
    var $cells = $rows.first().children('td');
    return $cells.length === 1 && $cells.first().attr('colspan');
  }

  // Exposed so the AJAX modal/list-refresh code (app-ui.js) can re-run this
  // after swapping in fresh table markup -- DOMContentLoaded only fires
  // once, but a refreshed table still needs DataTables applied to it.
  window.initDataTables = function () {
    $('table.table').not('.no-datatable').each(function () {
      var $table = $(this);

      if ($.fn.DataTable.isDataTable($table)) {
        return;
      }
      if (isEmptyStateTable($table)) {
        return;
      }

      $table.DataTable({
        dom: 'Bfrtip',
        pageLength: 25,
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
      });
    });
  };

  $(window.initDataTables);
})(jQuery);
