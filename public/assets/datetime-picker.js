/**
 * datetime-picker.js
 * Auto-enhances all <input type="datetime-local"> and <input type="date">
 * with Flatpickr. Loaded globally via layout_footer(). No per-page setup needed.
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof flatpickr === 'undefined') return;

    // Datetime inputs — calendar + time picker
    var datetimeInputs = document.querySelectorAll('input[type="datetime-local"]');
    datetimeInputs.forEach(function (input) {
        flatpickr(input, {
            enableTime: true,
            dateFormat: 'Y-m-d\\TH:i',
            altInput: true,
            altFormat: 'M j, Y  h:i K',
            allowInput: true,
            minuteIncrement: 15,
            time_24hr: false,
            onClose: function (selectedDates, dateStr) {
                // Dispatch a native change event when the picker closes so
                // existing page JS (auto-submit, normalize) continues to work.
                // We only fire on close — not onChange — to avoid premature
                // form submissions while the user is still picking date+time.
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });

    // Date-only inputs — calendar picker, no time
    var dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(function (input) {
        flatpickr(input, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'M j, Y',
            allowInput: true,
            onClose: function (selectedDates, dateStr) {
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });
});
