var ums_available_dates;

/**
 * startup when the datepicker is loaded in the browser.
 * Called in the JS embedded in the page (frontend.inc.php)
 * @param {type} defaultdate
 * @returns {undefined}
 */
function ums_datepicker_ready(defaultdate, field_id) {
    var field_id_fix = '#' + field_id;
    jQuery(field_id_fix).datepicker({
        dateFormat: 'yy-mm-dd', // this is four digit year
        defaultDate: defaultdate,
        beforeShowDay: ums_datepicker_available,
        onSelect: ums_datepicker_select
    });
}

/**
 * Checks if a given date is available for media upload.
  * this happens just after the datepicker is clicked and before it is displayed
 * @param {Date} date - The date to check.
 * @returns {Array} - An array containing three elements:
 *  - A boolean indicating whether the date is available or not.
 *  - A formatted string representing the current date.
 *  - A string indicating whether there is media available on the date or not.
 */
function ums_datepicker_available(date) {
    off = date.getTimezoneOffset();
    // adjust for timezone, otherwise it displays the wrong dates
    off_inv = off * -1;
    var newDateObj = new Date(date.getTime() + off_inv*60000);
    iso = newDateObj.toISOString();
    ymd = iso.substring(0, 10);
    if (jQuery.inArray(ymd, ums_available_dates) !== -1) {
        return [true, ums_formatCurrentDate(ymd), ymd + " has media"];
    } else {
        return [false, "dateunavailable", "No media on " + ymd];
    }
}

/**
 * controls what happens when you select a date in the datepicker
 * @param {type} dateText
 * @param {type} inst
 * @returns {undefined}
 */
function ums_datepicker_select(dateText, inst) {
    document.getElementById('ums_datepicker_form').submit();
}

/**
 * this parses the current iterated date and checks if it's the current displayed
 * @param {type} dateYmd
 * @returns {String}
 */
function ums_formatCurrentDate(dateYmd) {
    var query = window.location.search.substring(1);
    if (query.search(dateYmd) > 0) {
        return "dateavailable dateShown";
    } else {
        return "dateavailable";
    }
}