//This file contains all common functionality for the application

/**
 * Payment method helpers.
 *
 * A business location keeps the payment methods it accepts and the default account of each
 * one in BusinessLocation::default_payment_accounts. The sale screens use it to offer only
 * the methods enabled for the selected location; these helpers hold that logic so the sale
 * screens (pos.js) and the expense forms behave the same way.
 */

//Looks for the location of a payment row inside the given container only
function __find_location_payment_accounts($container) {
    var $location = $container
        .find('select#select_location_id, select#expense_location_id, select#location_id')
        .first();

    var payment_accounts = $location.length
        ? $location.find('option:selected').data('default_payment_accounts')
        : null;

    //Screens without a location dropdown keep the location on a hidden input
    if (!payment_accounts) {
        payment_accounts = $container.find('input#location_id').data('default_payment_accounts');
    }

    return payment_accounts ? payment_accounts : null;
}

/**
 * Returns the default_payment_accounts of the location a payment row belongs to.
 *
 * The container is searched first so a form carrying its own location (the expense modal
 * opened over the POS screen) is not mixed up with the location of the screen behind it,
 * then the page, because some screens keep the location dropdown outside of the form.
 */
function __get_location_payment_accounts($container) {
    if (!$container || !$container.length) {
        return __find_location_payment_accounts($(document));
    }

    var payment_accounts = __find_location_payment_accounts($container);

    return payment_accounts ? payment_accounts : __find_location_payment_accounts($(document));
}

//Hides the payment methods which are not enabled for the location selected inside the container
function __set_payment_types_dropdown($container) {
    if (!$container || !$container.length) {
        $container = $(document);
    }

    var payment_settings = __get_location_payment_accounts($container);
    payment_settings = payment_settings ? payment_settings : [];

    var enabled_payment_types = [];
    for (var key in payment_settings) {
        if (payment_settings[key] && payment_settings[key]['is_enabled']) {
            enabled_payment_types.push(key);
        }
    }

    //No settings for the location, keep every method available
    if (!enabled_payment_types.length) {
        return;
    }

    $container.find('.payment_types_dropdown > option').each(function () {
        //skip if advance, it is not a location setting
        if ($(this).val() && $(this).val() != 'advance') {
            if (enabled_payment_types.indexOf($(this).val()) != -1) {
                $(this).removeClass('hide');
            } else {
                $(this).addClass('hide');
            }
        }
    });
}

/**
 * Selects a method available for the location when the selected one got hidden,
 * cash is preferred the same way it is on the sale screens.
 */
function __reset_hidden_payment_type($dropdown) {
    if (!$dropdown.length || !$dropdown.find('option:selected').hasClass('hide')) {
        return;
    }

    var $available = $dropdown.find('option:not(.hide)').filter(function () {
        return $(this).val();
    });

    if ($available.length) {
        var method = $available.filter('[value="cash"]').length ? 'cash' : $available.first().val();
        $dropdown.val(method);
    }
}

//Sets the account of a payment row to the location default of the selected payment method
function __set_default_payment_account($dropdown) {
    var default_accounts = __get_location_payment_accounts($dropdown.closest('form'));
    if (!default_accounts) {
        return;
    }

    var payment_type = $dropdown.val();
    //The location may have no setting saved for the method
    var default_account =
        payment_type && default_accounts[payment_type] && default_accounts[payment_type]['account']
            ? default_accounts[payment_type]['account']
            : '';

    var $payment_row = $dropdown.closest('.payment_row');
    var row_index = $payment_row.find('.payment_row_index').val();
    var $account_dropdown = $payment_row.find('select#account_' + row_index);

    if ($account_dropdown.length) {
        $account_dropdown.val(default_account);
        $account_dropdown.change();
    }
}

$(document).on('submit', 'form', function (e) {
    if (!__is_online()) {
        e.preventDefault();
        toastr.error(LANG.not_connected_to_a_network);
        return false;
    }

    $(this).find('button[type="submit"]').attr('disabled', true);
});
$(document).ready(function () {
    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);

    $.ajaxSetup({
        beforeSend: function (jqXHR, settings) {
            if (!__is_online()) {
                toastr.error(LANG.not_connected_to_a_network);
                return false;
            }
            if (settings.url.indexOf('http') === -1) {
                settings.url = base_path + settings.url;
            }
        },
    });

    update_font_size();
    if ($('#status_span').length) {
        var status = $('#status_span').attr('data-status');
        if (status === '1') {
            toastr.success($('#status_span').attr('data-msg'));
        } else if (status == '' || status === '0') {
            toastr.error($('#status_span').attr('data-msg'));
        }
    }

    //Default setting for select2
    $.fn.select2.defaults.set('minimumResultsForSearch', 6);
    if ($('html').attr('dir') == 'rtl') {
        $.fn.select2.defaults.set('dir', 'rtl');
    }
    $.fn.datepicker.defaults.todayHighlight = true;
    $.fn.datepicker.defaults.autoclose = true;
    $.fn.datepicker.defaults.format = datepicker_date_format;

    //Toastr setting
    toastr.options.preventDuplicates = true;
    toastr.options.timeOut = '3000';

    //Play notification sound on success, error and warning
    toastr.options.onShown = function () {
        if ($(this).hasClass('toast-success')) {
            var audio = $('#success-audio')[0];
            if (audio !== undefined) {
                audio.play();
            }
        } else if ($(this).hasClass('toast-error')) {
            var audio = $('#error-audio')[0];
            if (audio !== undefined) {
                audio.play();
            }
        } else if ($(this).hasClass('toast-warning')) {
            var audio = $('#warning-audio')[0];
            if (audio !== undefined) {
                audio.play();
            }
        }
    };

    //Default setting for jQuey validator
    jQuery.validator.setDefaults({
        errorPlacement: function (error, element) {
            if (element.hasClass('select2') && element.parent().hasClass('input-group')) {
                error.insertAfter(element.parent());
            } else if (element.hasClass('select2')) {
                error.insertAfter(element.next('span.select2-container'));
            } else if (element.parent().hasClass('input-group')) {
                error.insertAfter(element.parent());
            } else if (element.parent().hasClass('multi-input')) {
                error.insertAfter(element.closest('.multi-input'));
            } else if (element.parent().hasClass('input_inline')) {
                error.insertAfter(element.parent());
            } else if (element.hasClass('upload-element')) {
                error.insertAfter(element.closest('.input-group'));
            } else {
                error.insertAfter(element);
            }
        },

        invalidHandler: function () {
            toastr.error(LANG.some_error_in_input_field);
        },
    });

    jQuery.validator.addMethod(
        'max-value',
        function (value, element, param) {
            var is_draft = false;
            if (
                $(element).hasClass('pos_quantity') &&
                $('select#status').length &&
                $('select#status').val() !== 'final'
            ) {
                is_draft = true;
            }
            return is_draft || this.optional(element) || !(param < __number_uf(value));
        },
        function (params, element) {
            return $(element).data('msg-max-value');
        }
    );

    jQuery.validator.addMethod('abs_digit', function (value, element) {
        return this.optional(element) || Number.isInteger(Math.abs(__number_uf(value)));
    });

    //Set global currency to be used in the application
    __currency_symbol = $('input#__symbol').val();
    __currency_thousand_separator = $('input#__thousand').val();
    __currency_decimal_separator = $('input#__decimal').val();
    __currency_symbol_placement = $('input#__symbol_placement').val();
    if ($('input#__precision').length > 0) {
        __currency_precision = $('input#__precision').val();
    } else {
        __currency_precision = 2;
    }

    if ($('input#__quantity_precision').length > 0) {
        __quantity_precision = $('input#__quantity_precision').val();
    } else {
        __quantity_precision = 2;
    }

    //Set page level currency to be used for some pages. (Purchase page)
    if ($('input#p_symbol').length > 0) {
        __p_currency_symbol = $('input#p_symbol').val();
        __p_currency_thousand_separator = $('input#p_thousand').val();
        __p_currency_decimal_separator = $('input#p_decimal').val();
    }

    __currency_convert_recursively($(document), $('input#p_symbol').length);

    // Simple function to remove currency symbol and HTML tags from string
    function __remove_currency_symbol(str) {
        // DataTables can pass numbers, objects, null - convert all to string
        if (typeof str !== 'string') {
            str = String(str);
        }
        
        // HTML REMOVAL: Simple regex to remove HTML tags
        str = str.replace(/<[^>]*>/g, ''); 
        
        // Check 1: Variable exists, Check 2: Has value, Check 3: Symbol present in string
        if (typeof __currency_symbol !== 'undefined' && __currency_symbol && str.includes(__currency_symbol)) {
            // SIMPLE REPLACEMENT: Replace all occurrences of currency symbol with empty string
            str = str.split(__currency_symbol).join('');  
        }
        
        return str.trim();
    }

    var buttons = [
        // {
        //     extend: 'copy',
        //     text: '<i class="fa fa-files-o" aria-hidden="true"></i> ' + LANG.copy,
        //     className: 'btn-sm',
        //     exportOptions: {
        //         columns: ':visible',
        //     },
        //     footer: true,
        // },
        {
            extend: 'csv',
            text: '<i class="fa fa-file-csv" aria-hidden="true"></i> ' + LANG.export_to_csv,
            className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
            exportOptions: {
                columns: ':visible',
                format: {
                    body: function(data, row, column, node) {
                        // Check if the node or its children have data-is_quantity="true"
                        var $node = $(node);
                        var $quantityElement = $node.find('[data-is_quantity="true"]');
                        
                        if ($quantityElement.length > 0) {
                            return $quantityElement.attr('data-orig-value');
                        }
                        // Remove currency symbol from the cell data
                        return __remove_currency_symbol(data);
                    },
                    footer: function(data, row, column, node) {
                        // Remove currency symbol from the footer data
                        return __remove_currency_symbol(data);
                    }
                }
            },
            footer: true,
            // Tables marked `hide-footer` (e.g. product list) skip the footer in CSV.
            action: function(e, dt, button, config) {
                if ($(dt.table().node()).hasClass('hide-footer')) config.footer = false;
                $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
            },
        },
        {
            extend: 'excel',
            text: '<i class="fa fa-file-excel" aria-hidden="true"></i> ' + LANG.export_to_excel,
            className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
            exportOptions: {
                columns: ':visible',
                format: {
                    body: function(data, row, column, node) {
                        // Check if the node or its children have data-is_quantity="true"
                        var $node = $(node);
                        var $quantityElement = $node.find('[data-is_quantity="true"]');
                        if ($quantityElement.length > 0) {
                            return $quantityElement.attr('data-orig-value');
                        }
                        // Remove currency symbol from the cell data
                        return __remove_currency_symbol(data);
                    },
                    footer: function(data, row, column, node) {
                        // Remove currency symbol from the footer data
                        return __remove_currency_symbol(data);
                    }
                }
            },
            footer: true,
            // Tables marked `hide-footer` (e.g. product list) skip the footer in Excel.
            action: function(e, dt, button, config) {
                if ($(dt.table().node()).hasClass('hide-footer')) config.footer = false;
                $.fn.dataTable.ext.buttons.excelHtml5.action.call(this, e, dt, button, config);
            },
        },
        {
            extend: 'print',
            text: '<i class="fa fa-print" aria-hidden="true"></i> ' + LANG.print,
            className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
            exportOptions: {
                columns: ':visible',
                stripHtml: true,
            },
            footer: true,
            customize: function (win) {
                if ($('.print_table_part').length > 0) {
                    $($('.print_table_part').html()).insertBefore(
                        $(win.document.body).find('table')
                    );
                }
                if ($(win.document.body).find('table.hide-footer').length) {
                    $(win.document.body).find('table.hide-footer tfoot').remove();
                }
                __currency_convert_recursively($(win.document.body).find('table'));
            },
        },
        {
            extend: 'colvis',
            text: '<i class="fa fa-columns" aria-hidden="true"></i> ' + LANG.col_vis,
            className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
        },
    ];

    // PDF export button (portrait). To show images in PDF, add `data-pdf-include` to the column's <th>.
    var pdf_btn = {
        extend: 'pdf',
        text: '<i class="fa fa-file-pdf" aria-hidden="true"></i> ' + LANG.export_to_pdf,
        className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
        exportOptions: {
            // Skip hidden columns. Skip "not-export" columns unless they have data-pdf-include.
            columns: function (idx, data, node) {
                return $(node).is(':visible') && (!$(node).hasClass('not-export') || $(node).data('pdfInclude'));
            },
            format: {
                // Use the cached image for image cells, plain text for everything else.
                body: function(data, row, column, node) {
                    var img = $(node).find('img')[0];
                    var cached = img && window._pdfImageCache && window._pdfImageCache[img.src];
                    return cached || $(node).text().trim();
                }
            }
        },
        footer: true,
        // Tables marked `hide-footer` (e.g. product list) skip the footer in PDF.
        action: function(e, dt, button, config) {
            if ($(dt.table().node()).hasClass('hide-footer')) config.footer = false;
            $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
        },
        // Turn the image data into real images in the PDF.
        customize: function(doc) {
            // Smaller font + tighter margins so wide tables fit on the page.
            doc.defaultStyle.fontSize = 8;
            doc.pageMargins = [20, 20, 20, 20];
            doc.content.forEach(function(b) {
                if (!b.table) return;
                b.table.widths = b.table.body[0].map(function() { return '*'; });
                b.table.body.forEach(function(row) {
                    row.forEach(function(c, j) {
                        var v = typeof c === 'string' ? c : (c && c.text);
                        if (v && v.indexOf('data:image') === 0) row[j] = { image: v, width: 40, height: 40 };
                    });
                });
            });
        }
    };

    // PDF dropdown — Portrait / Landscape choices, styled to match the toolbar's outline button.
    if (!document.getElementById('pdf-dropdown-style')) {
        var pdfDropdownStyle = document.createElement('style');
        pdfDropdownStyle.id = 'pdf-dropdown-style';
        pdfDropdownStyle.textContent =
            // Panel — vertical stack, white background (kills AdminLTE's #00c0ef cyan).
            '.dt-button-collection.pdf-orient-collection{' +
            'display:flex !important;flex-direction:column !important;gap:6px !important;padding:6px !important;' +
            'background:#fff !important;border:1px solid rgba(0,0,0,.15) !important;border-radius:.375rem !important;' +
            'box-shadow:0 4px 6px -1px rgba(0,0,0,.1) !important;min-width:160px !important;' +
            'margin:0 !important;list-style:none !important;column-count:1 !important}' +
            '.pdf-orient-collection,.pdf-orient-collection *{outline:0 !important}' +
            '.pdf-orient-collection>li{all:unset !important;display:block !important}' +
            // Items — only direct <a>/<button> of <li>, never the <li> itself.
            '.pdf-orient-collection>li>a,.pdf-orient-collection>li>button{' +
            'all:unset !important;box-sizing:border-box !important;display:flex !important;align-items:center !important;' +
            'width:100% !important;height:1.5rem !important;padding:0 .5rem !important;' +
            'font-size:.75rem !important;font-weight:600 !important;color:#000 !important;' +
            'background:#fff !important;border:1px solid #000 !important;border-radius:.375rem !important;' +
            'cursor:pointer !important;text-align:left !important}' +
            '.pdf-orient-collection>li>a:hover,.pdf-orient-collection>li>a:focus,' +
            '.pdf-orient-collection>li>a:active,.pdf-orient-collection>li>a.active,' +
            '.pdf-orient-collection>li>button:hover,.pdf-orient-collection>li>button:focus,' +
            '.pdf-orient-collection>li>button:active,.pdf-orient-collection>li>button.active{' +
            'background:#1f2937 !important;color:#fff !important;border-color:#1f2937 !important;' +
            'box-shadow:none !important;text-shadow:none !important}' +
            '.pdf-orient-collection>li>a>*,.pdf-orient-collection>li>button>*{' +
            'background:transparent !important;color:inherit !important;box-shadow:none !important}';
        document.head.appendChild(pdfDropdownStyle);
    }

    var pdf_item_class = 'tw-dw-btn-xs tw-dw-btn tw-dw-btn-outline';

    var pdf_dropdown = {
        extend: 'collection',
        text: '<i class="fa fa-file-pdf" aria-hidden="true"></i> ' + LANG.export_to_pdf + ' <i class="fa fa-caret-down" aria-hidden="true"></i>',
        className: 'tw-dw-btn-xs tw-dw-btn tw-dw-btn-outline tw-my-2',
        collectionLayout: 'pdf-orient-collection',
        autoClose: true,
        buttons: [
            $.extend(true, {}, pdf_btn, {
                text: LANG.portrait,
                className: pdf_item_class
            }),
            $.extend(true, {}, pdf_btn, {
                text: LANG.landscape,
                className: pdf_item_class,
                orientation: 'landscape',
                pageSize: 'A4',
            })
        ]
    };

    if (non_utf8_languages.indexOf(app_locale) == -1) {
        buttons.push(pdf_dropdown);
    }

    if ($('#view_export_buttons').length < 1) {
        buttons = [];
    }
    //Datables
    jQuery.extend($.fn.dataTable.defaults, {
        //Uncomment below line to enable save state of datatable.
        //stateSave: true,
        fixedHeader: true,
        //Toolbar: length menu | export buttons | search. The length menu is a fixed
        //~160px control ("Show _MENU_ entries", nowrap), so col-sm-1 (76px) clipped its
        //box and the text painted over the export buttons. col-sm-3 fits it; the buttons
        //give up the same 2 columns so the row still totals 12.
        dom: '<"row margin-bottom-20 text-center"<"col-sm-3"l><"col-sm-6"B><"col-sm-3"f> r>tip',
        buttons: buttons,
        aLengthMenu: [
            [25, 50, 100, 200, 500, 1000, -1],
            [25, 50, 100, 200, 500, 1000, LANG.all],
        ],
        iDisplayLength: __default_datatable_page_entries,
        language: {
            searchPlaceholder: LANG.search + ' ...',
            search: '',
            lengthMenu: LANG.show + ' _MENU_ ' + LANG.entries,
            emptyTable: LANG.table_emptyTable,
            info: LANG.table_info,
            infoEmpty: LANG.table_infoEmpty,
            loadingRecords: LANG.table_loadingRecords,
            processing: LANG.table_processing,
            zeroRecords: LANG.table_zeroRecords,
            paginate: {
                first: LANG.first,
                last: LANG.last,
                next: LANG.next,
                previous: LANG.previous,
            },
        },
    });

   

    if ($('input#iraqi_selling_price_adjustment').length > 0) {
        iraqi_selling_price_adjustment = true;
    } else {
        iraqi_selling_price_adjustment = false;
    }

    //Input number
    $(document).on(
        'click',
        '.input-number .quantity-up, .input-number .quantity-down',
        function () {
            var input = $(this).closest('.input-number').find('input');
            var qty = __read_number(input);
            var step = 1;
            if (input.data('step')) {
                step = input.data('step');
            }
            var min = parseFloat(input.data('min'));
            var max = parseFloat(input.data('max'));

            if ($(this).hasClass('quantity-up')) {
                //if max reached return false
                if (typeof max != 'undefined' && qty + step > max) {
                    return false;
                }

                __write_number(input, qty + step);
                input.change();
            } else if ($(this).hasClass('quantity-down')) {
                //if max reached return false
                if (typeof min != 'undefined' && qty - step < min) {
                    return false;
                }

                __write_number(input, qty - step);
                input.change();
            }
        }
    );

    $('div.pos-tab-menu>div.list-group>a').click(function (e) {
        e.preventDefault();
        $(this).siblings('a.active').removeClass('active');
        $(this).addClass('active');
        var index = $(this).index();
        $('div.pos-tab>div.pos-tab-content').removeClass('active');
        $('div.pos-tab>div.pos-tab-content').eq(index).addClass('active');
    });

    $('.scroll-top-bottom').each(function () {
        $(this).topScrollbar();
    });

    $('.datetimepicker').datetimepicker({
        format: moment_date_format + ' ' + moment_time_format,
        ignoreReadonly: true,
    });
});

//Default settings for daterangePicker
var ranges = {};
ranges[LANG.today] = [moment(), moment()];
ranges[LANG.yesterday] = [moment().subtract(1, 'days'), moment().subtract(1, 'days')];
ranges[LANG.last_7_days] = [moment().subtract(6, 'days'), moment()];
ranges[LANG.last_30_days] = [moment().subtract(29, 'days'), moment()];
ranges[LANG.this_month] = [moment().startOf('month'), moment().endOf('month')];
ranges[LANG.last_month] = [
    moment().subtract(1, 'month').startOf('month'),
    moment().subtract(1, 'month').endOf('month'),
];
ranges[LANG.this_month_last_year] = [
    moment().subtract(1, 'year').startOf('month'),
    moment().subtract(1, 'year').endOf('month'),
];
ranges[LANG.this_year] = [moment().startOf('year'), moment().endOf('year')];
ranges[LANG.last_year] = [
    moment().startOf('year').subtract(1, 'year'),
    moment().endOf('year').subtract(1, 'year'),
];
ranges[LANG.this_financial_year] = [financial_year.start, financial_year.end];
ranges[LANG.last_financial_year] = [
    moment(financial_year.start._i).subtract(1, 'year'),
    moment(financial_year.end._i).subtract(1, 'year'),
];

var dateRangeSettings = {
    showDropdowns : true,
    linkedCalendars : false,
    ranges: ranges,
    startDate: financial_year.start,
    endDate: financial_year.end,
    locale: {
        cancelLabel: LANG.clear,
        applyLabel: LANG.apply,
        customRangeLabel: LANG.custom_range,
        format: moment_date_format,
        toLabel: '~',
    },
};

//Check for number string in input field, if data-decimal is 0 then don't allow decimal symbol and if no_neg then don't allow  negative value
$(document).on('keypress', 'input.input_number', function (event) {
    var is_decimal = $(this).data('decimal');

    if (is_decimal == 0) {
        if (__currency_decimal_separator == '.') {
            var regex = new RegExp(/^[0-9,-]+$/);
        } else {
            var regex = new RegExp(/^[0-9.-]+$/);
        }
    } else {
        var regex = new RegExp(/^[0-9.,-]+$/);
    }

    // Check for no negative values
    if(is_decimal == 'no_neg'){
        var regex = new RegExp(/^[0-9.,]+$/);
    }

    var key = String.fromCharCode(!event.charCode ? event.which : event.charCode);
    if (!regex.test(key)) {
        event.preventDefault();
        return false;
    }
});

//Select all input values on click
$(document).on('click', 'input', function (event) {
    $(this).select();
});

$(document).on('click', '.toggle-font-size', function (event) {
    localStorage.setItem('upos_font_size', $(this).data('size'));
    update_font_size();
});
$(document).on('click', '.sidebar-toggle', function () {
    var sidebar_collapse = localStorage.getItem('upos_sidebar_collapse');
    if ($('body').hasClass('sidebar-collapse')) {
        localStorage.setItem('upos_sidebar_collapse', 'false');
    } else {
        localStorage.setItem('upos_sidebar_collapse', 'true');
    }
});

//Ask for confirmation for links
$(document).on('click', 'a.link_confirmation', function (e) {
    e.preventDefault();
    swal({
        title: LANG.sure,
        icon: 'warning',
        buttons: true,
        dangerMode: true,
    }).then((confirmed) => {
        if (confirmed) {
            window.location.href = $(this).attr('href');
        }
    });
});

//Change max quantity rule if lot number changes
$('table#stock_adjustment_product_table tbody').on('change', 'select.lot_number', function () {
    var tr = $(this).closest('tr');
    var qty_element = tr.find('input.product_quantity');
    var qty_available_el = tr.find('.qty_available_text');

    var multiplier = 1;
    var unit_name = '';
    var sub_unit_length = tr.find('select.sub_unit').length;
    if (sub_unit_length > 0) {
        var select = tr.find('select.sub_unit');
        multiplier = parseFloat(select.find(':selected').data('multiplier'));
        unit_name = select.find(':selected').data('unit_name');
    }

    if ($(this).val()) {
        var lot_qty = $('option:selected', $(this)).data('qty_available');
        var max_err_msg = $('option:selected', $(this)).data('msg-max');

        if (sub_unit_length > 0) {
            lot_qty = lot_qty / multiplier;
            var lot_qty_formated = __number_f(lot_qty, false);
            max_err_msg = __translate('lot_max_qty_error', {
                max_val: lot_qty_formated,
                unit_name: unit_name,
            });
        }

        qty_element.attr('data-rule-max-value', lot_qty);
        qty_element.attr('data-msg-max-value', max_err_msg);

        qty_element.rules('add', {
            'max-value': lot_qty,
            messages: {
                'max-value': max_err_msg,
            },
        });
        if (qty_available_el.length) {
            qty_available_el.text(__currency_trans_from_en(lot_qty, false));
        }
    } else {
        var default_qty = qty_element.data('qty_available');
        var default_err_msg = qty_element.data('msg_max_default');

        if (sub_unit_length > 0) {
            default_qty = default_qty / multiplier;
            var lot_qty_formated = __number_f(default_qty, false);
            default_err_msg = __translate('pos_max_qty_error', {
                max_val: lot_qty_formated,
                unit_name: unit_name,
            });
        }

        qty_element.attr('data-rule-max-value', default_qty);
        qty_element.attr('data-msg-max-value', default_err_msg);

        qty_element.rules('add', {
            'max-value': default_qty,
            messages: {
                'max-value': default_err_msg,
            },
        });

        if (qty_available_el.length) {
            qty_available_el.text(__currency_trans_from_en(default_qty, false));
        }
    }
    qty_element.trigger('change');
});
//POS header: same bootstrap tooltip on every action button, instead of the
//native title tooltip most of them were falling back to.
//Bound on body, not document: the popover handler in app.js returns false for
//.popover-default buttons, which stops the event before it reaches document.
var pos_header_tooltips = '.pos-header a[title], .pos-header button[title]';
$('body').on('mouseenter', pos_header_tooltips, function () {
    var el = $(this);
    if (!el.data('bs.tooltip')) {
        el.tooltip({
            container: 'body',
            placement: el.data('placement') || 'bottom',
            trigger: 'manual',
        });
    }
    el.tooltip('show');
});
$('body').on('mouseleave click', pos_header_tooltips, function () {
    $(this).tooltip('hide');
});
$('button#return_sale').click(function () {
    $(this).popover('toggle');
});
$('button#service_staff_replacement').click(function () {
    $(this).popover('toggle');
});

jQuery.validator.addMethod(
    'min-value',
    function (value, element, param) {
        return this.optional(element) || !(param > __number_uf(value));
    },
    function (params, element) {
        return $(element).data('min-value');
    }
);

$(document).on('click', '.view_uploaded_document', function (e) {
    e.preventDefault();
    var src = $(this).data('href');
    var html =
        '<div class="modal-dialog" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body"><img src="' +
        src +
        '" class="img-responsive" alt="Uploaded Document"></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button> <a href="' +
        src +
        '" class="btn btn-success" download=""><i class="fa fa-download"></i> Download</a></div></div></div>';
    $('div.view_modal').html(html).modal('show');
});

$(document).on('click', '#accordion .box-header', function (e) {
    if (e.target.tagName == 'A' || e.target.tagName == 'I') {
        return false;
    }
    $(this).find('.box-title a').click();
});

$(document).on('shown.bs.modal', '.contains_select2, .view_modal', function () {
    $(this)
        .find('.select2')
        .each(function () {
            var $p = $(this).parent();
            $(this).select2({ dropdownParent: $p });
        });
});

//common configuration : tinyMCE editor

tinymce.overrideDefaults({
    height: 300,
    language: app_locale, // Set language dynamically
    language_url: base_path + '/js/lang/tiny/' + app_locale + '.js', // Dynamic URL
    theme: 'silver',
    plugins: [
        'advlist autolink link image lists charmap print preview hr anchor pagebreak',
        'searchreplace wordcount visualblocks visualchars code fullscreen insertdatetime media nonbreaking',
        'table template paste help',
    ],
    toolbar:
        'undo redo | styleselect | bold italic | alignleft aligncenter alignright alignjustify |' +
        ' bullist numlist outdent indent | link image | print preview media fullpage | ' +
        'forecolor backcolor',
    menu: {
        favs: { title: 'My Favorites', items: 'code | searchreplace' },
    },
    menubar: 'favs file edit view insert format tools table help',
});

// Prevent Bootstrap dialog from blocking focusin
$(document).on('focusin', function (e) {
    if ($(e.target).closest('.tox-tinymce-aux, .moxman-window, .tam-assetmanager-root, .select2-container').length) {
        e.stopImmediatePropagation();
    }
});

//search parameter in url
function urlSearchParam(param) {
    var results = new RegExp('[?&]' + param + '=([^&#]*)').exec(window.location.href);
    if (results == null) {
        return null;
    } else {
        return results[1];
    }
}

// For dropdown hidden issue
// (function() {
//   var dropdownMenu;
//   $('table').on('show.bs.dropdown', function(e) {
//     dropdownMenu = $(e.target).find('.dropdown-menu');
//     $('body').append(dropdownMenu.detach());
//     var eOffset = $(e.target).offset();
//     if(dropdownMenu.hasClass('dropdown-menu-right')) {
//         dropdownMenu.css({
//             'display': 'block',
//             'top': eOffset.top + $(e.target).outerHeight(),
//             'left': 'auto',
//             'right': 0
//         });
//     } else {
//         dropdownMenu.css({
//             'display': 'block',
//             'top': eOffset.top + $(e.target).outerHeight(),
//             'left': eOffset.left
//         });
//     }
//   });
//   $('table').on('hide.bs.dropdown', function(e) {
//     $(e.target).append(dropdownMenu.detach());
//     dropdownMenu.hide();
//   });
// })();

function updateOnlineStatus() {
    if (!__is_online()) {
        $('#online_indicator').removeClass('text-success');
        $('#online_indicator').addClass('text-danger');
    } else {
        $('#online_indicator').removeClass('text-danger');
        $('#online_indicator').addClass('text-success');
    }
}

$(document).on('change', '.cash_denomination', function () {
    var total = 0;
    var table = $(this).closest('table');
    table.find('tbody tr').each(function () {
        var denomination = parseFloat($(this).find('.cash_denomination').attr('data-denomination'));
        var count = $(this).find('.cash_denomination').val()
            ? parseInt($(this).find('.cash_denomination').val())
            : 0;
        var subtotal = denomination * count;
        total = total + subtotal;
        $(this).find('span.denomination_subtotal').text(__currency_trans_from_en(subtotal, true));
    });

    table.find('span.denomination_total').text(__currency_trans_from_en(total, true));
    table.find('input.denomination_total_amount').val(total);
});

//autofocus select2 search input
let forceFocusFn = function () {
    // Gets the search input of the opened select2
    var searchInput = document.querySelector('.select2-container--open .select2-search__field');
    // If exists
    if (searchInput) searchInput.focus(); // focus
};

// Every time a select2 is opened
$(document).on('select2:open', () => {
    // We use a timeout because when a select2 is already opened and you open a new one, it has to wait to find the appropiate
    setTimeout(() => forceFocusFn(), 200);
});

function copyToClipboard(element_id) {
    var temp = $('<input>');
    $('body').append(temp);
    temp.val($('#' + element_id).text()).select();
    document.execCommand('copy');
    temp.remove();
    toastr.success(LANG.copied_to_clipboard);
}

// This function escapes HTML characters in a given string to prevent XSS attacks.
function escapeHtml(str) {
    if (typeof str !== 'string') return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Sidebar interactions (search, dropdowns, mobile toggle, collapse)
$(function () {
    // --- Sidebar menu search filter ---
    function filterSidebar(q) {
        q = q.trim().toLowerCase();
        var anyVisible = false;

        $('#side-bar').children().each(function () {
            if (!q) {
                $(this).show();
                anyVisible = true;
            } else {
                var match = $(this).text().toLowerCase().indexOf(q) !== -1;
                $(this).toggle(match);
                if (match) anyVisible = true;
            }
        });

        $('#sidebar-no-results').toggleClass('tw-hidden', anyVisible || !q);
        $('#sidebar-search-clear').toggleClass('tw-hidden', !q);
    }

    $('#sidebar-search').on('input', function () {
        filterSidebar($(this).val());
    });

    $('#sidebar-search-clear').on('click', function () {
        $('#sidebar-search').val('').focus();
        filterSidebar('');
    });

    // --- Sidebar dropdown toggle ---
    $(document).on('click', '.drop_down', function (event) {
        event.preventDefault();
        var $chiled = $(this).next('.chiled');
        $('.chiled').not($chiled).slideUp();
        $chiled.slideToggle(function () {
            $('.svg').each(function () {
                var $currentSvgElement = $(this);
                if ($currentSvgElement.closest('.drop_down').next('.chiled').is(':visible')) {
                    $currentSvgElement.html(
                        '<path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M6 9l6 6l6 -6" />'
                    );
                } else {
                    $currentSvgElement.html(
                        '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 6l-6 6l6 6" />'
                    );
                }
            });
        });
    });

    // --- Sidebar mobile open / overlay close ---
    $(document).on('click', '.small-view-button', function () {
        $('.side-bar').addClass('small-view-side-active');
        $('.overlay').fadeIn('slow');
    });

    $(document).on('click', '.overlay', function () {
        $('.overlay').fadeOut('slow');
        $('.side-bar').removeClass('small-view-side-active');
    });

    // --- Sidebar responsive resize ---
    $(window).on('resize', function () {
        if ($(window).width() >= 992) {
            $('.overlay').fadeOut('slow');
            $('.side-bar').removeClass('small-view-side-active');
        }
        if ($('.side-bar').hasClass('small-view-side-active')) {
            $('.overlay').fadeIn('slow');
        }
    });

    // --- Sidebar collapse toggle ---
    $(document).on('click', '.side-bar-collapse', function () {
        $('.side-bar').toggle('slow');
    });
});

/**
 * Keep body attached dropdowns glued to their input while the content scrolls.
 *
 * daterangepicker appends its calendar to <body>, positions it once from
 * element.offset() and only re-runs move() on window resize. The content area
 * scrolls inside #scrollable-container rather than the window, so without this
 * the calendar stays put while its input scrolls away.
 *
 * select2 walks its own scroll parents, and datetimepicker places its widget
 * next to the input, so neither needs help here.
 */
$(function () {
    var $scroll_area = $('#scrollable-container');

    if (!$scroll_area.length) {
        return;
    }

    var open_pickers = [];

    $(document).on('show.daterangepicker', function (e, picker) {
        if (picker && $.inArray(picker, open_pickers) === -1) {
            open_pickers.push(picker);
        }
    });

    $(document).on('hide.daterangepicker', function (e, picker) {
        open_pickers = $.grep(open_pickers, function (open_picker) {
            return open_picker !== picker;
        });
    });

    $scroll_area.on('scroll', function () {
        if (!open_pickers.length) {
            return;
        }

        //remove() destroys a picker without firing hide or clearing isShowing,
        //so drop the ones whose input or calendar has left the page
        open_pickers = $.grep(open_pickers, function (picker) {
            return picker.element && $.contains(document.documentElement, picker.element[0])
                && picker.container && $.contains(document.documentElement, picker.container[0]);
        });

        var area_rect = $scroll_area[0].getBoundingClientRect();

        $.each(open_pickers, function (index, picker) {
            if (!picker.isShowing) {
                return;
            }

            var input_rect = picker.element[0].getBoundingClientRect();
            //Where the top of the calendar lands once it follows the input
            var calendar_top = picker.drops == 'up'
                ? input_rect.top - picker.container.outerHeight()
                : input_rect.bottom;

            //The top navbar is a static block, so the calendar (absolute, z-index
            //3001) paints straight over it. Close instead of letting it ride up.
            if (calendar_top < area_rect.top || input_rect.top > area_rect.bottom) {
                picker.hide();

                return;
            }

            picker.move();
        });
    });
});

/*
 * Image compression before upload.
 * Turned on/off in Superadmin > Settings (APP.IMAGE_COMPRESS_ENABLED).
 * Library: browser-image-compression, loaded from CDN in layouts/partials/javascripts.blade.php.
 *
 * - Width and height never change. The image is re-encoded once at QUALITY.
 * - JPG and WebP keep their type. PNG and BMP without transparency become JPG.
 * - Transparent images, GIF, SVG and files under MIN_SIZE are uploaded as they are.
 * - The compressed file is used only if it is at least 10% smaller.
 */
(function () {
    var QUALITY = 0.85;          // visually lossless for photos
    var MIN_SIZE = 100 * 1024;   // 100 KB
    var TYPES = ['image/jpeg', 'image/webp', 'image/png', 'image/bmp'];

    function canCompress(file) {
        return file.size >= MIN_SIZE && TYPES.indexOf(file.type) !== -1;
    }

    // True if the image has any transparent pixel (checked on a 64x64 copy).
    function hasTransparency(canvas) {
        var small = document.createElement('canvas');
        small.width = small.height = 64;
        var ctx = small.getContext('2d');
        ctx.drawImage(canvas, 0, 0, 64, 64);
        var pixels = ctx.getImageData(0, 0, 64, 64).data;
        for (var i = 3; i < pixels.length; i += 4) {
            if (pixels[i] < 255) return true;
        }
        return false;
    }

    // Returns the compressed file, or the original when compression is not useful.
    async function compressFile(file) {
        var drawn = await imageCompression.drawFileInCanvas(file);
        var image = drawn[0];
        var canvas = drawn[1];

        // The browser shrank a very large image to fit its canvas limit: keep the original.
        if (canvas.width !== image.width || canvas.height !== image.height) return file;

        var type = file.type;
        if (type === 'image/png' || type === 'image/bmp') {
            if (hasTransparency(canvas)) return file;
            type = 'image/jpeg';
        }

        var blob = await imageCompression.canvasToFile(canvas, type, file.name, file.lastModified, QUALITY);
        if (blob.size > file.size * 0.9) return file;

        var name = type === file.type ? file.name : file.name.replace(/\.[^.]+$/, '') + '.jpg';
        return new File([blob], name, { type: type, lastModified: file.lastModified });
    }

    // Full-screen "Compressing image..." message.
    function showOverlay(show) {
        $('#image-compress-overlay').remove();
        if (!show) return;

        var text = (typeof LANG !== 'undefined' && LANG.compressing_image) || 'Compressing image...';
        $('body').append(
            '<div id="image-compress-overlay" style="position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;">' +
                '<div style="background:#fff;border-radius:8px;padding:24px 32px;text-align:center;font-size:18px;font-weight:600;color:#333;">' +
                    '<i class="fas fa-spinner fa-spin" style="display:block;font-size:32px;margin-bottom:12px;color:#3c8dbc;"></i>' + text +
                '</div>' +
            '</div>'
        );
    }

    // Replace the selected files with compressed ones before the form is submitted.
    $(document).on('change', 'input[type="file"]', async function () {
        var input = this;
        var files = Array.prototype.slice.call(input.files || []);

        var enabled = typeof APP !== 'undefined' && !!APP.IMAGE_COMPRESS_ENABLED && typeof imageCompression === 'function';
        if (!enabled || !files.some(canCompress)) return;

        showOverlay(true);

        try {
            var result = new DataTransfer();
            for (var i = 0; i < files.length; i++) {
                var file = files[i];
                if (canCompress(file)) {
                    try {
                        file = await compressFile(file);
                    } catch (e) {
                        console.warn('Image compression failed, uploading original', e);
                    }
                }
                result.items.add(file);
            }
            input.files = result.files;
        } finally {
            showOverlay(false);
        }
    });
})();
