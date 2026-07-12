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

  $(function () {
    $('table.table').not('.no-datatable').each(function () {
      var $table = $(this);

      if (isEmptyStateTable($table)) {
        return;
      }

      $table.DataTable({
        dom: 'Bfrtip',
        pageLength: 25,
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
      });
    });
  });
})(jQuery);
