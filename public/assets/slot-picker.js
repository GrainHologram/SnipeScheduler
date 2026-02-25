/**
 * SlotPicker — date + time slot picker component.
 *
 * Two-click flow: pick a date from the calendar, then pick a time slot.
 * Talks to ajax_slot_data.php for month/slot data.
 *
 * No external dependencies — vanilla JS only.
 */
class SlotPicker {
    /**
     * @param {Object} opts
     * @param {HTMLElement} opts.container   - Element to render into
     * @param {HTMLInputElement} opts.hiddenInput - Hidden input for YYYY-MM-DDTHH:MM value
     * @param {string} opts.type            - 'start' or 'end'
     * @param {number} opts.intervalMinutes - Slot interval (e.g. 30)
     * @param {boolean} opts.isStaff
     * @param {boolean} opts.isAdmin
     * @param {Function} [opts.onSelect]    - Callback when a slot is chosen: (datetimeStr) => void
     * @param {string} [opts.dateFormat]    - PHP-style date format (e.g. 'd/m/Y')
     * @param {string} [opts.timeFormat]    - PHP-style time format (e.g. 'H:i' or 'h:i A')
     */
    constructor(opts) {
        this.container = opts.container;
        this.hiddenInput = opts.hiddenInput;
        this.type = opts.type || 'start';
        this.intervalMinutes = opts.intervalMinutes || 30;
        this.isStaff = !!opts.isStaff;
        this.isAdmin = !!opts.isAdmin;
        this.onSelect = opts.onSelect || null;
        this.dateFormat = opts.dateFormat || 'd/m/Y';
        this.timeFormat = opts.timeFormat || 'H:i';

        // State
        this.currentMonth = null; // 'YYYY-MM'
        this.selectedDate = null; // 'YYYY-MM-DD'
        this.selectedTime = null; // 'HH:MM'
        this.monthData = {};      // { 'YYYY-MM-DD': { closed: bool } }
        this.slotsData = null;    // [ { time: 'HH:MM', remaining: N } ] or null
        this.bypassCapacity = false;
        this.bypassClosed = false;

        this._loading = false;
        this._slotsLoading = false;
        this._error = null;
        this._slotsError = null;
        this._collapsed = false;

        // Determine the earliest allowed month (current month)
        var now = new Date();
        this._todayStr = this._dateToStr(now);
        this._earliestMonth = this._todayStr.substring(0, 7);

        // Start on current month
        this.currentMonth = this._earliestMonth;

        this._render();
        this.fetchMonthData(this.currentMonth);
    }

    /* ---------------------------------------------------------------
       Public methods
    --------------------------------------------------------------- */

    /**
     * Fetch month-level data (open/closed days).
     * @param {string} month - 'YYYY-MM'
     */
    fetchMonthData(month) {
        var self = this;
        self._loading = true;
        self._error = null;
        self._renderCalendar();

        var params = new URLSearchParams({
            month: month
        });
        if (self.bypassClosed) {
            params.set('bypass_closed', '1');
        }

        fetch('ajax_slot_data.php?' + params.toString())
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self._loading = false;
                if (data.error) {
                    self._error = data.error;
                } else {
                    self.monthData = data.days || {};
                }
                self._renderCalendar();
            })
            .catch(function (err) {
                self._loading = false;
                self._error = 'Failed to load calendar data.';
                self._renderCalendar();
            });
    }

    /**
     * Fetch time slots for a specific date.
     * @param {string} date - 'YYYY-MM-DD'
     */
    fetchSlotData(date) {
        var self = this;
        self._slotsLoading = true;
        self._slotsError = null;
        self.slotsData = null;
        self._renderSlots();

        var params = new URLSearchParams({
            date: date,
            type: self.type
        });
        if (self.bypassCapacity) {
            params.set('bypass_capacity', '1');
        }
        if (self.bypassClosed) {
            params.set('bypass_closed', '1');
        }

        fetch('ajax_slot_data.php?' + params.toString())
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self._slotsLoading = false;
                if (data.error) {
                    self._slotsError = data.error;
                    self.slotsData = null;
                } else {
                    self.slotsData = data.slots || [];
                }
                self._renderSlots();
            })
            .catch(function (err) {
                self._slotsLoading = false;
                self._slotsError = 'Failed to load time slots.';
                self._renderSlots();
            });
    }

    /**
     * Programmatically select a date — highlights it and fetches slots.
     * @param {string} dateStr - 'YYYY-MM-DD'
     */
    selectDate(dateStr) {
        this.selectedDate = dateStr;
        this.selectedTime = null;
        this._updateHiddenInput();
        this._renderCalendar();
        this.fetchSlotData(dateStr);
    }

    /**
     * Programmatically select a time slot — commits the selection.
     * @param {string} timeStr - 'HH:MM'
     */
    selectSlot(timeStr) {
        this.selectedTime = timeStr;
        this._updateHiddenInput();
        this._collapse();

        if (this.onSelect) {
            this.onSelect(this.getSelectedDatetime());
        }
    }

    /**
     * Programmatically set the full datetime value.
     * @param {string} datetimeStr - 'YYYY-MM-DDTHH:MM'
     */
    setValue(datetimeStr) {
        if (!datetimeStr || datetimeStr.length < 16) return;
        var parts = datetimeStr.split('T');
        var dateStr = parts[0];
        var timeStr = parts[1].substring(0, 5);
        var month = dateStr.substring(0, 7);

        this.selectedDate = dateStr;
        this.selectedTime = timeStr;
        this.currentMonth = month;

        this._updateHiddenInput();
        this._collapse();
    }

    /**
     * Toggle a bypass flag and re-fetch data.
     * @param {string} type - 'capacity' or 'closed'
     * @param {boolean} enabled
     */
    setBypass(type, enabled) {
        if (type === 'capacity') {
            this.bypassCapacity = !!enabled;
            // Re-fetch slots if a date is selected
            if (this.selectedDate) {
                this.fetchSlotData(this.selectedDate);
            }
        } else if (type === 'closed') {
            this.bypassClosed = !!enabled;
            // Re-fetch month data + slots
            this.fetchMonthData(this.currentMonth);
            if (this.selectedDate) {
                this.fetchSlotData(this.selectedDate);
            }
        }
    }

    /**
     * Get the current selection as 'YYYY-MM-DDTHH:MM' or null.
     * @returns {string|null}
     */
    getSelectedDatetime() {
        if (this.selectedDate && this.selectedTime) {
            return this.selectedDate + 'T' + this.selectedTime;
        }
        return null;
    }

    /* ---------------------------------------------------------------
       Rendering
    --------------------------------------------------------------- */

    /** Build the initial DOM skeleton. */
    _render() {
        this.container.innerHTML = '';
        this.container.classList.add('slot-picker');

        this._calendarEl = document.createElement('div');
        this._slotsEl = document.createElement('div');
        this._selectionEl = document.createElement('div');

        this.container.appendChild(this._calendarEl);
        this.container.appendChild(this._slotsEl);
        this.container.appendChild(this._selectionEl);

        this._renderCalendar();
        this._renderSlots();
        this._renderSelection();
    }

    /** Render the calendar month view. */
    _renderCalendar() {
        var el = this._calendarEl;
        if (!el) return;
        el.innerHTML = '';

        // Month navigation header
        var header = document.createElement('div');
        header.className = 'sp-month-header';

        var prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'sp-month-btn';
        prevBtn.textContent = '\u2039'; // left single angle
        prevBtn.setAttribute('aria-label', 'Previous month');
        prevBtn.disabled = (this.currentMonth <= this._earliestMonth);
        var self = this;
        prevBtn.addEventListener('click', function () {
            self._navigateMonth(-1);
        });

        var nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'sp-month-btn';
        nextBtn.textContent = '\u203A'; // right single angle
        nextBtn.setAttribute('aria-label', 'Next month');
        nextBtn.addEventListener('click', function () {
            self._navigateMonth(1);
        });

        var label = document.createElement('span');
        label.className = 'sp-month-label';
        label.textContent = this._formatMonthLabel(this.currentMonth);

        header.appendChild(prevBtn);
        header.appendChild(label);
        header.appendChild(nextBtn);
        el.appendChild(header);

        // Loading state
        if (this._loading) {
            el.appendChild(this._createLoading('Loading calendar...'));
            return;
        }

        // Error state
        if (this._error) {
            var errEl = document.createElement('div');
            errEl.className = 'sp-error';
            errEl.textContent = this._error;
            el.appendChild(errEl);
            return;
        }

        // Build calendar table
        var table = document.createElement('table');
        table.className = 'sp-calendar';

        // Day-of-week header
        var thead = document.createElement('thead');
        var headRow = document.createElement('tr');
        var dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        for (var d = 0; d < 7; d++) {
            var th = document.createElement('th');
            th.textContent = dayNames[d];
            headRow.appendChild(th);
        }
        thead.appendChild(headRow);
        table.appendChild(thead);

        // Calendar body
        var tbody = document.createElement('tbody');
        var year = parseInt(this.currentMonth.substring(0, 4), 10);
        var month = parseInt(this.currentMonth.substring(5, 7), 10) - 1; // JS months 0-indexed
        var firstDay = new Date(year, month, 1);
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var startDow = firstDay.getDay(); // 0 = Sunday

        var row = document.createElement('tr');

        // Empty cells before first day
        for (var i = 0; i < startDow; i++) {
            var td = document.createElement('td');
            var emptyBtn = document.createElement('button');
            emptyBtn.type = 'button';
            emptyBtn.className = 'sp-day sp-day-empty';
            emptyBtn.disabled = true;
            td.appendChild(emptyBtn);
            row.appendChild(td);
        }

        for (var day = 1; day <= daysInMonth; day++) {
            var dateStr = this.currentMonth + '-' + String(day).padStart(2, '0');
            var dayInfo = this.monthData[dateStr] || {};
            var isPast = dateStr < this._todayStr;
            var isClosed = !!dayInfo.is_closed;
            var isToday = dateStr === this._todayStr;
            var isSelected = dateStr === this.selectedDate;

            var td2 = document.createElement('td');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = String(day);
            btn.setAttribute('data-date', dateStr);

            var classes = ['sp-day'];
            if (isPast) classes.push('sp-day-past');
            if (isClosed && !isPast) {
                classes.push('sp-day-closed');
                if (this.bypassClosed) {
                    classes.push('sp-bypass-active');
                }
            }
            if (isToday) classes.push('sp-day-today');
            if (isSelected) classes.push('sp-day-selected');

            btn.className = classes.join(' ');

            if (!isPast && (!isClosed || this.bypassClosed)) {
                btn.addEventListener('click', (function (ds) {
                    return function () { self.selectDate(ds); };
                })(dateStr));
            } else {
                btn.disabled = true;
            }

            td2.appendChild(btn);
            row.appendChild(td2);

            // New row after Saturday
            if ((startDow + day) % 7 === 0) {
                tbody.appendChild(row);
                row = document.createElement('tr');
            }
        }

        // Pad remaining cells in last row
        var remaining = (startDow + daysInMonth) % 7;
        if (remaining > 0) {
            for (var j = remaining; j < 7; j++) {
                var td3 = document.createElement('td');
                var emptyBtn2 = document.createElement('button');
                emptyBtn2.type = 'button';
                emptyBtn2.className = 'sp-day sp-day-empty';
                emptyBtn2.disabled = true;
                td3.appendChild(emptyBtn2);
                row.appendChild(td3);
            }
            tbody.appendChild(row);
        }

        table.appendChild(tbody);
        el.appendChild(table);
    }

    /** Render the time slots panel. */
    _renderSlots() {
        var el = this._slotsEl;
        if (!el) return;
        el.innerHTML = '';

        if (!this.selectedDate) return;

        var panel = document.createElement('div');
        panel.className = 'sp-slots-panel';

        var label = document.createElement('div');
        label.className = 'sp-slots-label';
        label.textContent = 'Select a time';
        panel.appendChild(label);

        // Loading
        if (this._slotsLoading) {
            panel.appendChild(this._createLoading('Loading slots...'));
            el.appendChild(panel);
            return;
        }

        // Error
        if (this._slotsError) {
            var errEl = document.createElement('div');
            errEl.className = 'sp-error';
            errEl.textContent = this._slotsError;
            panel.appendChild(errEl);
            el.appendChild(panel);
            return;
        }

        // No slots
        if (!this.slotsData || this.slotsData.length === 0) {
            var emptyEl = document.createElement('div');
            emptyEl.className = 'sp-empty';
            emptyEl.textContent = 'No available time slots for this date.';
            panel.appendChild(emptyEl);
            el.appendChild(panel);
            return;
        }

        // Render slot grid
        var grid = document.createElement('div');
        grid.className = 'sp-slots-grid';
        var self = this;

        for (var i = 0; i < this.slotsData.length; i++) {
            var slot = this.slotsData[i];
            var timeStr = slot.time; // 'HH:MM'
            var rem = slot.remaining;
            var isFull = (typeof rem === 'number' && rem <= 0);
            var isSelected = (timeStr === this.selectedTime);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('data-time', timeStr);

            var classes = ['sp-slot'];
            if (isFull) {
                classes.push('sp-slot-full');
                if (this.bypassCapacity) {
                    classes.push('sp-bypass-active');
                }
            }
            if (isSelected) classes.push('sp-slot-selected');

            btn.className = classes.join(' ');

            // Format time display
            var timeDisplay = this._formatTime(timeStr);
            var timeSpan = document.createElement('span');
            timeSpan.textContent = timeDisplay;
            btn.appendChild(timeSpan);

            // Remaining count
            if (typeof rem === 'number') {
                var remSpan = document.createElement('span');
                remSpan.className = 'sp-slot-remaining';
                remSpan.textContent = '(' + rem + ' left)';
                btn.appendChild(remSpan);
            }

            if (!isFull || this.bypassCapacity) {
                btn.addEventListener('click', (function (ts) {
                    return function () { self.selectSlot(ts); };
                })(timeStr));
            } else {
                btn.disabled = true;
            }

            grid.appendChild(btn);
        }

        panel.appendChild(grid);
        el.appendChild(panel);
    }

    /** Render the selected datetime summary. */
    _renderSelection() {
        var el = this._selectionEl;
        if (!el) return;
        el.innerHTML = '';

        var dt = this.getSelectedDatetime();
        if (!dt) return;

        var div = document.createElement('div');
        div.className = 'sp-selection';

        var labelText = (this.type === 'start') ? 'Pickup: ' : 'Return: ';
        var labelSpan = document.createElement('span');
        labelSpan.textContent = labelText;

        var valueSpan = document.createElement('span');
        valueSpan.className = 'sp-selection-value';
        valueSpan.textContent = this._formatDate(this.selectedDate) + ' ' + this._formatTime(this.selectedTime);

        div.appendChild(labelSpan);
        div.appendChild(valueSpan);

        if (this._collapsed) {
            var changeLink = document.createElement('a');
            changeLink.href = '#';
            changeLink.className = 'sp-change-link';
            changeLink.textContent = 'Change';
            var self = this;
            changeLink.addEventListener('click', function (e) {
                e.preventDefault();
                self._expand();
            });
            div.appendChild(changeLink);
        }

        el.appendChild(div);
    }

    /* ---------------------------------------------------------------
       Collapse / expand
    --------------------------------------------------------------- */

    /** Collapse to just the selection summary. */
    _collapse() {
        this._collapsed = true;
        this._calendarEl.style.display = 'none';
        this._slotsEl.style.display = 'none';
        this._renderSelection();
    }

    /** Expand to show the full calendar and re-fetch data. */
    _expand() {
        this._collapsed = false;
        this._calendarEl.style.display = '';
        this._slotsEl.style.display = '';
        this.fetchMonthData(this.currentMonth);
        if (this.selectedDate) {
            this.fetchSlotData(this.selectedDate);
        }
        this._renderSelection();
    }

    /* ---------------------------------------------------------------
       Helpers
    --------------------------------------------------------------- */

    /** Navigate month by offset (-1 or +1). */
    _navigateMonth(offset) {
        var parts = this.currentMonth.split('-');
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10) - 1; // JS 0-indexed
        var d = new Date(y, m + offset, 1);
        var newMonth = this._dateToStr(d).substring(0, 7);

        if (newMonth < this._earliestMonth) return;

        this.currentMonth = newMonth;
        this.slotsData = null;
        this._renderSlots();
        this.fetchMonthData(newMonth);
    }

    /** Update the hidden input with the current selection. */
    _updateHiddenInput() {
        if (!this.hiddenInput) return;
        var val = this.getSelectedDatetime() || '';
        this.hiddenInput.value = val;
        // Dispatch input event so other code can react
        this.hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /** Create a loading indicator element. */
    _createLoading(text) {
        var div = document.createElement('div');
        div.className = 'sp-loading';
        var spinner = document.createElement('div');
        spinner.className = 'sp-loading-spinner';
        var span = document.createElement('span');
        span.textContent = text || 'Loading...';
        div.appendChild(spinner);
        div.appendChild(span);
        return div;
    }

    /** Format a Date object to 'YYYY-MM-DD'. */
    _dateToStr(d) {
        return d.getFullYear() +
            '-' + String(d.getMonth() + 1).padStart(2, '0') +
            '-' + String(d.getDate()).padStart(2, '0');
    }

    /** Format month string for display label. */
    _formatMonthLabel(monthStr) {
        var parts = monthStr.split('-');
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10) - 1;
        var d = new Date(y, m, 1);
        var months = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];
        return months[d.getMonth()] + ' ' + d.getFullYear();
    }

    /**
     * Format a time string ('HH:MM') using the configured PHP-style time format.
     * Supports: H, h, i, A, a, g, G
     */
    _formatTime(timeStr) {
        var parts = timeStr.split(':');
        var h24 = parseInt(parts[0], 10);
        var min = parseInt(parts[1], 10);
        var h12 = h24 % 12 || 12;
        var ampm = h24 < 12 ? 'AM' : 'PM';

        var fmt = this.timeFormat;
        var result = '';
        for (var i = 0; i < fmt.length; i++) {
            var c = fmt[i];
            switch (c) {
                case 'H': result += String(h24).padStart(2, '0'); break;
                case 'G': result += String(h24); break;
                case 'h': result += String(h12).padStart(2, '0'); break;
                case 'g': result += String(h12); break;
                case 'i': result += String(min).padStart(2, '0'); break;
                case 'A': result += ampm; break;
                case 'a': result += ampm.toLowerCase(); break;
                default:  result += c; break;
            }
        }
        return result;
    }

    /**
     * Format a date string ('YYYY-MM-DD') using the configured PHP-style date format.
     * Supports: d, j, m, n, Y, y, D, l, M, F
     */
    _formatDate(dateStr) {
        var parts = dateStr.split('-');
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10) - 1;
        var d = parseInt(parts[2], 10);
        var dt = new Date(y, m, d);

        var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var dayShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        var monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];
        var monthShort = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        var fmt = this.dateFormat;
        var result = '';
        for (var i = 0; i < fmt.length; i++) {
            var c = fmt[i];
            switch (c) {
                case 'd': result += String(d).padStart(2, '0'); break;
                case 'j': result += String(d); break;
                case 'm': result += String(m + 1).padStart(2, '0'); break;
                case 'n': result += String(m + 1); break;
                case 'Y': result += String(y); break;
                case 'y': result += String(y).slice(-2); break;
                case 'D': result += dayShort[dt.getDay()]; break;
                case 'l': result += dayNames[dt.getDay()]; break;
                case 'M': result += monthShort[m]; break;
                case 'F': result += monthNames[m]; break;
                default:  result += c; break;
            }
        }
        return result;
    }
}
