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

  // ---- newest first ------------------------------------------------------------------------------------------------
  // DataTables sorts by the first column, ascending, unless told otherwise -- which is how every list ended up oldest first no
  // matter what order the server sent. Every table now opens newest first: on its most "when it was created" date column when it
  // has one, otherwise on a running id/reference column, otherwise in the exact order the server sent (order: []).
  // A column the user clicks to sort still works as normal.

  var MONTHS = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };

  function cellText(html) {
    return $('<div>').html(String(html == null ? '' : html)).text().replace(/\s+/g, ' ').trim();
  }

  // Cell text -> a sortable timestamp, or null when it is not a date. Handles 2026-10-23[ 14:05[:09]], 23/10/2026, 23-10-2026,
  // 23 Oct 2026 and Oct 23, 2026 (each optionally followed by a time).
  function parseDate(text) {
    var m;
    var time = function (rest) {
      var t = /(\d{1,2}):(\d{2})(?::(\d{2}))?/.exec(rest || '');
      return t ? [+t[1], +t[2], +(t[3] || 0)] : [0, 0, 0];
    };
    if ((m = /^(\d{4})-(\d{2})-(\d{2})(.*)$/.exec(text))) {
      var t1 = time(m[4]);
      return Date.UTC(+m[1], +m[2] - 1, +m[3], t1[0], t1[1], t1[2]);
    }
    if ((m = /^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})(.*)$/.exec(text))) {
      var t2 = time(m[4]);
      return Date.UTC(+m[3], +m[2] - 1, +m[1], t2[0], t2[1], t2[2]);
    }
    if ((m = /^(\d{1,2})\s+([A-Za-z]{3})[a-z]*\.?,?\s+(\d{4})(.*)$/.exec(text)) && MONTHS[m[2].toLowerCase()] !== undefined) {
      var t3 = time(m[4]);
      return Date.UTC(+m[3], MONTHS[m[2].toLowerCase()], +m[1], t3[0], t3[1], t3[2]);
    }
    if ((m = /^([A-Za-z]{3})[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})(.*)$/.exec(text)) && MONTHS[m[1].toLowerCase()] !== undefined) {
      var t4 = time(m[4]);
      return Date.UTC(+m[3], MONTHS[m[1].toLowerCase()], +m[2], t4[0], t4[1], t4[2]);
    }
    return null;
  }

  // Lower number = better "when it was created" header. null = not a candidate. Deadlines and attributes of a person are never used.
  function headerRank(label) {
    var h = label.toLowerCase();
    if (/\b(due|expir|maturity|matures|birth|dob|valid|next|end|until|deadline|schedule|reminder|follow)/.test(h)) {
      return null;
    }
    if (/created|registered|submitted|applied|recorded|posted|logged|received|issued|captured|requested|opened|uploaded|added/.test(h)) {
      return 1;
    }
    if (/^date$|^when$|^time$|timestamp|(transaction|payment|disburse\w*|application|entry|journal|loan|start|booking) date|^date (of|paid|created)/.test(h)) {
      return 2;
    }
    if (/date|time|\bon$|\bat$/.test(h)) {
      return 3;
    }
    return null;
  }

  function isIdHeader(label) {
    return /^(id|no\.?|number|ref\.?|reference|ref no\.?|reference (no|number)|(loan|invoice|application|receipt|ticket|payment|journal|case) (id|no\.?|number|ref\w*))$/i.test(label.trim());
  }

  function columnValues($table, index) {
    var values = [];
    $table.find('tbody > tr').each(function () {
      var $cell = $(this).children('td').eq(index);
      values.push($cell.length ? cellText($cell.html()) : '');
    });
    return values;
  }

  // Which column to open on, and how to feed it to DataTables: {order: [[col, 'desc']], render: fn} or {order: []}.
  function newestFirst($table) {
    var headers = [];
    $table.find('thead > tr').last().children('th').each(function () {
      headers.push($(this).text().replace(/\s+/g, ' ').trim());
    });
    var $firstRow = $table.find('tbody > tr').first().children('td');
    if (headers.length === 0 || $firstRow.length !== headers.length) {
      return { order: [] };
    }

    // 1. a date column: best-ranked header first, leftmost among equals, and only if EVERY filled cell really parses as a date
    var best = null;
    for (var c = 0; c < headers.length; c++) {
      var rank = headerRank(headers[c]);
      if (rank === null || (best !== null && rank >= best.rank)) {
        continue;
      }
      var vals = columnValues($table, c).filter(function (v) { return v !== '' && v !== '-' && v !== '—'; });
      if (vals.length > 0 && vals.every(function (v) { return parseDate(v) !== null; })) {
        best = { col: c, rank: rank };
      }
    }
    if (best) {
      var hasOrderAttr = $firstRow.eq(best.col).attr('data-order') !== undefined;
      var spec = { order: [[best.col, 'desc']] };
      if (!hasOrderAttr) {
        spec.render = {
          target: best.col,
          fn: function (data, type) {
            if (type === 'sort' || type === 'type') {
              var ts = parseDate(cellText(data));
              return ts === null ? 0 : ts;
            }
            return data;
          },
        };
      }
      return spec;
    }

    // 2. a running id / reference column that is not just a 1..n row counter
    for (var i = 0; i < headers.length; i++) {
      if (!isIdHeader(headers[i])) {
        continue;
      }
      var nums = columnValues($table, i).map(function (v) {
        var m = /(\d+)\s*$/.exec(v);
        return m ? +m[1] : NaN;
      });
      if (nums.length < 2 || nums.some(isNaN)) {
        continue;
      }
      var counter = nums.every(function (n, k) { return n === k + 1; }) || nums.every(function (n, k) { return n === nums.length - k; });
      if (counter) {
        continue;
      }
      return {
        order: [[i, 'desc']],
        render: {
          target: i,
          fn: function (data, type) {
            if (type === 'sort' || type === 'type') {
              var m = /(\d+)\s*$/.exec(cellText(data));
              return m ? +m[1] : 0;
            }
            return data;
          },
        },
      };
    }

    // 3. nothing to go on: keep the order the server sent instead of re-sorting by the first column
    return { order: [] };
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

      var spec = newestFirst($table);
      var options = {
        dom: 'Bfrtip',
        pageLength: 25,
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
        order: spec.order,
      };
      if (spec.render) {
        options.columnDefs = [{ targets: spec.render.target, render: spec.render.fn }];
      }
      $table.DataTable(options);
    });
  };

  $(window.initDataTables);
})(jQuery);
