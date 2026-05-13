(function () {

    // ── Three-state responsive sidebar ───────────────────────────────────────

    var mobileQuery    = window.matchMedia('(max-width: 599px)');
    var collapsedQuery = window.matchMedia('(min-width: 600px) and (max-width: 959px)');

    function getFocusables(container) {
        return Array.from(container.querySelectorAll(
            'a[href], button:not([disabled]), details > summary, [tabindex]:not([tabindex="-1"])'
        ));
    }

    function initNav(nav) {
        if (!nav || nav.dataset.navInit === '1') return;
        nav.dataset.navInit = '1';

        if (!nav.id) nav.id = 'app-nav';

        var COLLAPSED_KEY = 'v2NavCollapsed';

        // Restore user-collapsed preference from localStorage
        try {
            if (localStorage.getItem(COLLAPSED_KEY) === '1') {
                document.body.classList.add('nav-is-collapsed');
            }
        } catch (e) {}

        // ── Collapsed mode: in-sidebar hamburger expands nav as overlay ──

        var sidebarBtn = nav.querySelector('.app-nav-hamburger');

        function collapseSidebar() {
            nav.classList.remove('app-nav--expanded');
            if (sidebarBtn) {
                sidebarBtn.setAttribute('aria-expanded', 'false');
                sidebarBtn.setAttribute('aria-label', 'Expand navigation');
            }
            // Reset topbar button when it was used to open the overlay at 600–959px
            if (topbarBtn && collapsedQuery.matches) {
                topbarBtn.setAttribute('aria-expanded', 'false');
                topbarBtn.setAttribute('aria-label', 'Expand navigation');
            }
        }

        if (sidebarBtn) {
            sidebarBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!mobileQuery.matches && !collapsedQuery.matches) {
                    // Desktop (≥960px): in-sidebar hamburger restores the full sidebar
                    var isCollapsed = document.body.classList.toggle('nav-is-collapsed');
                    try { localStorage.setItem(COLLAPSED_KEY, isCollapsed ? '1' : '0'); } catch (e2) {}
                    if (topbarBtn) {
                        topbarBtn.setAttribute('aria-label', isCollapsed ? 'Expand navigation' : 'Collapse navigation');
                        topbarBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                    }
                } else {
                    // Tablet (600–959px): expand as overlay
                    var isExpanded = nav.classList.toggle('app-nav--expanded');
                    sidebarBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                    sidebarBtn.setAttribute('aria-label', isExpanded ? 'Collapse navigation' : 'Expand navigation');
                    if (isExpanded) {
                        var firstLink = nav.querySelector('a.app-nav-link');
                        if (firstLink) firstLink.focus();
                    }
                }
            });
        }

        // Collapse on outside click (but not when clicking the topbar hamburger itself)
        document.addEventListener('click', function (e) {
            if (nav.classList.contains('app-nav--expanded') && !nav.contains(e.target) && !(topbarBtn && topbarBtn.contains(e.target))) {
                collapseSidebar();
            }
        });

        // Handle keydown inside nav (Escape + Tab-out collapse)
        nav.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (nav.classList.contains('app-nav--expanded')) {
                    collapseSidebar();
                    if (collapsedQuery.matches && topbarBtn) {
                        topbarBtn.focus();
                    } else if (sidebarBtn) {
                        sidebarBtn.focus();
                    }
                }
                return;
            }
            // Collapse expanded overlay when Tab leaves the last focusable item
            if (e.key === 'Tab' && !e.shiftKey && nav.classList.contains('app-nav--expanded') && collapsedQuery.matches) {
                var focusables = getFocusables(nav);
                var last = focusables[focusables.length - 1];
                if (document.activeElement === last) {
                    collapseSidebar();
                    // let focus continue naturally to the next page element
                }
            }
        });

        // ── Mobile mode: topbar hamburger opens drawer overlay ──

        var topbarBtn = document.querySelector('.app-topbar-hamburger');
        var backdrop  = null;
        var trapFocusFn = null;

        function ensureBackdrop() {
            if (backdrop) return;
            backdrop = document.createElement('div');
            backdrop.className = 'app-nav-backdrop';
            backdrop.setAttribute('aria-hidden', 'true');
            document.body.appendChild(backdrop);
            backdrop.addEventListener('click', closeDrawer);
        }

        function openDrawer() {
            ensureBackdrop();
            nav.classList.add('app-nav--open');
            nav.removeAttribute('aria-hidden');
            nav.removeAttribute('inert');
            backdrop.classList.add('is-visible');
            if (topbarBtn) {
                topbarBtn.setAttribute('aria-expanded', 'true');
                topbarBtn.setAttribute('aria-label', 'Close navigation menu');
            }
            var firstFocus = nav.querySelector('a.app-nav-brand, a.app-nav-link');
            if (firstFocus) firstFocus.focus();

            // Install focus trap
            var focusables = getFocusables(nav);
            trapFocusFn = function (e) {
                if (e.key !== 'Tab') return;
                var first = focusables[0];
                var last  = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            };
            nav.addEventListener('keydown', trapFocusFn);
        }

        function closeDrawer() {
            nav.classList.remove('app-nav--open');
            nav.setAttribute('aria-hidden', 'true');
            nav.setAttribute('inert', '');
            if (backdrop) backdrop.classList.remove('is-visible');
            if (topbarBtn) {
                topbarBtn.setAttribute('aria-expanded', 'false');
                topbarBtn.setAttribute('aria-label', 'Open navigation menu');
                topbarBtn.focus();
            }
            // Remove focus trap
            if (trapFocusFn) {
                nav.removeEventListener('keydown', trapFocusFn);
                trapFocusFn = null;
            }
        }

        if (topbarBtn) {
            topbarBtn.addEventListener('click', function () {
                if (mobileQuery.matches) {
                    // Mobile (≤599px): toggle drawer
                    if (nav.classList.contains('app-nav--open')) {
                        closeDrawer();
                    } else {
                        openDrawer();
                    }
                } else if (collapsedQuery.matches) {
                    // Tablet (600–959px): toggle sidebar overlay expand
                    var isExpanded = nav.classList.toggle('app-nav--expanded');
                    topbarBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                    topbarBtn.setAttribute('aria-label', isExpanded ? 'Collapse navigation' : 'Expand navigation');
                    if (isExpanded) {
                        var firstLink = nav.querySelector('a.app-nav-link');
                        if (firstLink) firstLink.focus();
                    }
                } else {
                    // Desktop (≥960px): toggle sidebar collapsed state
                    var isCollapsed = document.body.classList.toggle('nav-is-collapsed');
                    try { localStorage.setItem(COLLAPSED_KEY, isCollapsed ? '1' : '0'); } catch (e) {}
                    topbarBtn.setAttribute('aria-label', isCollapsed ? 'Expand navigation' : 'Collapse navigation');
                    topbarBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                }
            });
        }

        nav.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && nav.classList.contains('app-nav--open')) {
                closeDrawer();
            }
        });

        // Close drawer when a link is clicked on mobile
        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (mobileQuery.matches && nav.classList.contains('app-nav--open')) {
                    closeDrawer();
                }
            });
        });

        // ── Sync aria-hidden/inert and clean up state on viewport changes ──

        function hideNav() {
            nav.setAttribute('aria-hidden', 'true');
            nav.setAttribute('inert', '');
        }

        function showNav() {
            nav.removeAttribute('aria-hidden');
            nav.removeAttribute('inert');
        }

        function syncState() {
            if (mobileQuery.matches) {
                // Mobile: nav hidden by default, shown only when drawer is open
                if (!nav.classList.contains('app-nav--open')) {
                    hideNav();
                }
                // Clean up collapsed-mode state
                nav.classList.remove('app-nav--expanded');
                if (sidebarBtn) {
                    sidebarBtn.setAttribute('aria-expanded', 'false');
                    sidebarBtn.setAttribute('aria-label', 'Expand navigation');
                }
                // Update topbar button for mobile drawer mode
                if (topbarBtn && !nav.classList.contains('app-nav--open')) {
                    topbarBtn.setAttribute('aria-expanded', 'false');
                    topbarBtn.setAttribute('aria-label', 'Open navigation menu');
                }
            } else {
                // Non-mobile: nav always accessible
                showNav();
                // Clean up mobile-mode state
                if (nav.classList.contains('app-nav--open')) {
                    nav.classList.remove('app-nav--open');
                    if (backdrop) backdrop.classList.remove('is-visible');
                    if (trapFocusFn) {
                        nav.removeEventListener('keydown', trapFocusFn);
                        trapFocusFn = null;
                    }
                    if (topbarBtn) {
                        topbarBtn.setAttribute('aria-expanded', 'false');
                        topbarBtn.setAttribute('aria-label', 'Open navigation menu');
                    }
                }
                // Sync topbar button aria to current breakpoint state
                if (collapsedQuery.matches) {
                    // 600–959px: topbar button controls overlay expand
                    if (topbarBtn) {
                        var isExpanded = nav.classList.contains('app-nav--expanded');
                        topbarBtn.setAttribute('aria-label', isExpanded ? 'Collapse navigation' : 'Expand navigation');
                        topbarBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                    }
                } else {
                    // ≥960px: clean up overlay state, topbar button controls collapse
                    nav.classList.remove('app-nav--expanded');
                    if (sidebarBtn) {
                        sidebarBtn.setAttribute('aria-expanded', 'false');
                        sidebarBtn.setAttribute('aria-label', 'Expand navigation');
                    }
                    if (topbarBtn) {
                        var isCollapsed = document.body.classList.contains('nav-is-collapsed');
                        topbarBtn.setAttribute('aria-label', isCollapsed ? 'Expand navigation' : 'Collapse navigation');
                        topbarBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                    }
                }
            }
        }

        var handleChange = function () { syncState(); };

        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', handleChange);
            collapsedQuery.addEventListener('change', handleChange);
        } else if (mobileQuery.addListener) {
            mobileQuery.addListener(handleChange);
            collapsedQuery.addListener(handleChange);
        }

        syncState();
    }

    // ── Topbar breadcrumb API ─────────────────────────────────────────────────
    //
    // TopbarCrumbs manages a stack of JS-pushed crumbs appended after the
    // server-rendered tab crumb (#app-topbar-crumb-1).
    //
    // Usage:
    //   TopbarCrumbs.push('Item Name', backFn)   — push a crumb; backFn is called
    //     when the user clicks the parent crumb to navigate back.
    //   TopbarCrumbs.pop()                        — remove the last crumb.
    //   TopbarCrumbs.popAll()                     — remove all pushed crumbs.
    //
    // The stack supports arbitrary nesting. At depth N:
    //   Tab crumb (#app-topbar-crumb-1) → onclick calls _stack[0].onBack
    //   Stack[i] (not last)             → onclick calls _stack[i+1].onBack
    //   Stack[last]                     → static text (current page)

    var TopbarCrumbs = (function () {
        var _stack = []; // [{label: string, onBack: function|null}]

        function _render() {
            var container = document.getElementById('app-topbar-crumbs');
            if (!container) return;
            var anchor = document.getElementById('app-topbar-crumb-1');
            if (!anchor) return;

            // Remove all JS-inserted nodes after the anchor
            while (anchor.nextSibling) {
                container.removeChild(anchor.nextSibling);
            }

            if (_stack.length > 0) {
                // Make tab crumb clickable — calls first item's onBack
                anchor.classList.add('app-topbar-crumb--link');
                anchor.setAttribute('role', 'button');
                anchor.setAttribute('tabindex', '0');
                anchor.onclick = function () { if (_stack[0]) _stack[0].onBack(); };
                anchor.onkeydown = function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (_stack[0]) _stack[0].onBack(); }
                };
            } else {
                anchor.classList.remove('app-topbar-crumb--link');
                anchor.removeAttribute('role');
                anchor.removeAttribute('tabindex');
                anchor.onclick = null;
                anchor.onkeydown = null;
            }

            _stack.forEach(function (crumb, i) {
                var sep = document.createElement('span');
                sep.className = 'app-topbar-sep';
                sep.setAttribute('aria-hidden', 'true');
                sep.textContent = '\u203a'; // ›
                container.appendChild(sep);

                var isLast = (i === _stack.length - 1);
                var node;

                if (!isLast) {
                    // Intermediate crumb — native button for full keyboard/AT support
                    node = document.createElement('button');
                    node.type = 'button';
                    node.className = 'app-topbar-subtitle app-topbar-crumb--link';
                    node.textContent = crumb.label;
                    (function (nextIdx) {
                        node.onclick = function () { if (_stack[nextIdx]) _stack[nextIdx].onBack(); };
                    })(i + 1);
                } else {
                    // Leaf crumb — current position, not interactive
                    node = document.createElement('span');
                    node.className = 'app-topbar-subtitle';
                    node.setAttribute('aria-current', 'true');
                    node.textContent = crumb.label;
                }
                container.appendChild(node);
            });
        }

        return {
            push: function (label, onBack) {
                _stack.push({ label: label, onBack: onBack });
                _render();
            },
            pop: function () {
                _stack.pop();
                _render();
            },
            popAll: function () {
                _stack = [];
                _render();
            }
        };
    })();

    // ── Catalogue inline detail view ──────────────────────────────────────────
    //
    // CatalogueDetail.open(modelId, modelName) replaces .catalogue-scroll-area
    // content with the model detail view loaded from ajax_model_history.php.
    // CatalogueDetail.back() restores the original grid content.
    //
    // Helper functions (esc, checkoutStatusBadge, reservationStatusBadge) are
    // also defined by the v1 modal JS — guard to avoid re-definition conflicts.

    if (typeof window.esc === 'undefined') {
        window.esc = function (s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(String(s)));
            return d.innerHTML;
        };
    }

    if (typeof window.checkoutStatusBadge === 'undefined') {
        window.checkoutStatusBadge = function (status) {
            var map = {
                'open':    '<span class="badge status-badge-checked-out">Checked Out</span>',
                'partial': '<span class="badge bg-warning text-dark">Partial Return</span>',
                'closed':  '<span class="badge bg-success">Returned</span>'
            };
            return map[status] || '<span class="badge bg-secondary">' + esc(status) + '</span>';
        };
    }

    if (typeof window.reservationStatusBadge === 'undefined') {
        window.reservationStatusBadge = function (status) {
            var map = {
                'pending':   '<span class="badge bg-warning text-dark">Pending</span>',
                'confirmed': '<span class="badge bg-info text-dark">Confirmed</span>',
                'fulfilled': '<span class="badge bg-success">Fulfilled</span>',
                'cancelled': '<span class="badge bg-secondary">Cancelled</span>',
                'missed':    '<span class="badge bg-danger">Missed</span>'
            };
            return map[status] || '<span class="badge bg-secondary">' + esc(status) + '</span>';
        };
    }

    var CatalogueDetail = (function () {
        var _savedHTML   = null;
        var _savedScroll = 0;
        var _savedFocus  = null;

        function _scrollArea() {
            return document.querySelector('.catalogue-scroll-area');
        }

        function _mhToggleOnclick() {
            var panel = this.nextElementSibling;
            var open  = panel.classList.toggle('mh-open');
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function _buildDetailHTML(data, modelId) {
            var html = '';
            var isStaff = !!window._modelDetailIsStaff;
            var authReqs = data.auth_requirements || { certs: [], access_levels: [] };
            var available = data.available_count || 0;
            var requestable = data.requestable_count || 0;
            var certs = authReqs.certs || [];
            var accessLevels = authReqs.access_levels || [];
            var pulledCount = data.pulled_for_repair_count || 0;
            var hasTags = data.category || certs.length || accessLevels.length || pulledCount > 0;

            // Header: left col (image + tags) | right col (name, manufacturer, divider, counts)
            html += '<div class="cat-detail-header">';

            // Left column: image then tag chips directly below
            html += '<div class="cat-detail-left">';
            html += '<div class="cat-detail-img">';
            if (data.model_image) {
                html += '<img src="' + esc(data.model_image) + '" alt="' + esc(data.model_name) + '">';
            } else {
                html += '<div class="md-image-placeholder"></div>';
            }
            html += '</div>';
            if (hasTags) {
                html += '<div class="model-card-tags cat-detail-tags">';
                if (data.category) {
                    html += '<span class="model-meta-category">' + esc(data.category) + '</span>';
                }
                certs.forEach(function (c) {
                    html += '<span class="model-cert-tag">Requires: ' + esc(c) + '</span>';
                });
                accessLevels.forEach(function (al) {
                    html += '<span class="model-access-tag">' + esc(al) + '</span>';
                });
                if (pulledCount > 0) {
                    html += '<span class="model-unavailable-tag">' + pulledCount + ' pulled for repair</span>';
                }
                html += '</div>';
            }
            html += '</div>'; // end cat-detail-left

            // Right column: name, manufacturer, divider, counts
            html += '<div class="cat-detail-title-info">';
            html += '<div class="cat-detail-model-name">' + esc(data.model_name) + '</div>';
            if (data.manufacturer) {
                html += '<div class="cat-detail-manufacturer">' + esc(data.manufacturer) + '</div>';
            }
            html += '<hr class="cat-detail-divider">';
            var availDotClass = available <= 0 ? 'model-avail-dot--red'
                              : available < requestable ? 'model-avail-dot--yellow'
                              : 'model-avail-dot--green';
            html += '<div class="cat-detail-counts">';
            html += '<span class="model-meta-requestable"><span class="model-avail-dot model-avail-dot--green"></span><strong>Requestable:</strong> ' + requestable + '</span>';
            html += '<span class="model-meta-available"><span class="model-avail-dot ' + availDotClass + '"></span><strong>Available:</strong> ' + available + '</span>';
            html += '</div>';
            html += '</div>'; // end cat-detail-title-info

            html += '</div>'; // end cat-detail-header

            // Action buttons: Add to Basket first, then qty selector
            html += '<div class="cat-detail-actions">';
            html += '<form action="basket_add.php" method="POST" class="add-to-basket-form cat-detail-basket-form d-flex align-items-center gap-2">';
            html += '<input type="hidden" name="model_id" value="' + parseInt(modelId, 10) + '">';
            if (available > 0) {
                html += '<button type="submit" class="btn btn-primary btn-sm">Add to Basket</button>';
                html += '<select name="quantity" class="form-select form-select-sm cat-detail-qty" style="width:auto;">';
                for (var q = 1; q <= available; q++) {
                    html += '<option value="' + q + '">' + q + '</option>';
                }
                html += '</select>';
            } else {
                html += '<button type="button" class="btn btn-secondary btn-sm" disabled>No units available</button>';
            }
            html += '</form>';
            html += '</div>';

            // Tabs
            var defaultTab = isStaff ? 'activity' : 'inventory';
            html += '<ul class="cat-detail-tabs-nav" role="tablist">';
            if (isStaff) {
                html += '<li role="presentation"><button type="button" class="cat-detail-tab-btn active" data-tab="activity" role="tab">Activity</button></li>';
            }
            html += '<li role="presentation"><button type="button" class="cat-detail-tab-btn' + (isStaff ? '' : ' active') + '" data-tab="inventory" role="tab">Inventory</button></li>';
            html += '</ul>';

            html += '<div class="cat-detail-tab-content">';

            // Activity tab (staff only)
            if (isStaff) {
                html += '<div class="cat-detail-tab-pane active" data-pane="activity">';

                html += '<h6 class="mb-2">Currently Checked Out</h6>';
                if (data.currently_out && data.currently_out.length > 0) {
                    html += '<div class="table-responsive mb-3"><table class="table table-sm table-striped align-middle mb-0">';
                    html += '<thead class="table-warning"><tr>'
                          + '<th scope="col">Asset Tag</th><th scope="col">Asset Name</th>'
                          + '<th scope="col">Checked Out To</th><th scope="col">Last Checkout</th>'
                          + '<th scope="col">Expected Return</th>'
                          + '</tr></thead><tbody>';
                    data.currently_out.forEach(function (a) {
                        var user = a.assigned_to_name || a.assigned_to_email || '';
                        if (a.assigned_to_email && a.assigned_to_name && a.assigned_to_name !== a.assigned_to_email) {
                            user = a.assigned_to_name + ' (' + a.assigned_to_email + ')';
                        }
                        html += '<tr><td>' + esc(a.asset_tag) + '</td><td>' + esc(a.asset_name) + '</td><td>' + esc(user) + '</td><td>' + esc(a.last_checkout) + '</td><td>' + esc(a.expected_checkin) + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<p class="text-muted mb-3">None currently checked out.</p>';
                }

                html += '<h6 class="mb-2">Recent Checkouts</h6>';
                if (data.recent_checkouts && data.recent_checkouts.length > 0) {
                    data.recent_checkouts.forEach(function (co) {
                        var badge = checkoutStatusBadge(co.status);
                        var user = co.user_name || co.user_email || 'Unknown';
                        var header = '#' + co.checkout_id + ' &mdash; ' + esc(user) + ' ' + badge;
                        var dates = esc(co.start_datetime) + ' &rarr; ' + esc(co.end_datetime);
                        html += '<div class="card mb-2">';
                        html += '<button type="button" class="card-header py-2 px-3 mh-toggle w-100 text-start" aria-expanded="false">';
                        html += header + '</button>';
                        html += '<div class="mh-panel"><div class="card-body p-2">';
                        html += '<div class="small text-muted mb-2">' + dates + '</div>';
                        if (co.items && co.items.length > 0) {
                            html += '<table class="table table-sm table-striped align-middle mb-0">'
                                  + '<thead><tr><th scope="col">Asset Tag</th><th scope="col">Asset Name</th>'
                                  + '<th scope="col">Checked Out</th><th scope="col">Returned</th>'
                                  + '</tr></thead><tbody>';
                            co.items.forEach(function (ci) {
                                var returned = ci.checked_in_at ? esc(ci.checked_in_at) : '<span class="badge bg-warning text-dark">Out</span>';
                                var rowClass = ci.checked_in_at ? 'table-success' : '';
                                html += '<tr class="' + rowClass + '"><td>' + esc(ci.asset_tag) + '</td><td>' + esc(ci.asset_name) + '</td><td>' + esc(ci.checked_out_at) + '</td><td>' + returned + '</td></tr>';
                            });
                            html += '</tbody></table>';
                        } else {
                            html += '<p class="text-muted mb-0">No item details.</p>';
                        }
                        html += '</div></div></div>';
                    });
                } else {
                    html += '<p class="text-muted mb-3">No recent checkout history.</p>';
                }

                html += '<h6 class="mb-2">Recent Reservations</h6>';
                if (data.recent_reservations && data.recent_reservations.length > 0) {
                    data.recent_reservations.forEach(function (res) {
                        var badge = reservationStatusBadge(res.status);
                        var user = res.user_name || res.user_email || 'Unknown';
                        var header = '#' + res.reservation_id + ' &mdash; ' + esc(user) + ' ' + badge;
                        if (res.name) header += ' <small class="text-muted">(' + esc(res.name) + ')</small>';
                        var dates = esc(res.start_datetime) + ' &rarr; ' + esc(res.end_datetime);
                        html += '<div class="card mb-2">';
                        html += '<button type="button" class="card-header py-2 px-3 mh-toggle w-100 text-start" aria-expanded="false">';
                        html += header + '</button>';
                        html += '<div class="mh-panel"><div class="card-body p-2">';
                        html += '<div class="small text-muted mb-2">' + dates + '</div>';
                        if (res.items && res.items.length > 0) {
                            html += '<table class="table table-sm table-striped align-middle mb-0">'
                                  + '<thead><tr><th scope="col">Model</th><th scope="col">Qty</th></tr></thead><tbody>';
                            res.items.forEach(function (ri) {
                                html += '<tr><td>' + esc(ri.model_name) + '</td><td>' + ri.quantity + '</td></tr>';
                            });
                            html += '</tbody></table>';
                        }
                        html += '</div></div></div>';
                    });
                } else {
                    html += '<p class="text-muted mb-3">No recent reservations.</p>';
                }

                html += '<div id="modelDetailNoteForm" class="md-note-form mt-3" style="display:none;"></div>';
                html += '</div>'; // end activity pane
            }

            // Inventory tab
            html += '<div class="cat-detail-tab-pane' + (isStaff ? '' : ' active') + '" data-pane="inventory">';
            if (data.assets && data.assets.length > 0) {
                html += '<div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">';
                html += '<thead><tr><th scope="col">Asset Tag</th><th scope="col">Name</th><th scope="col">Status</th>';
                if (isStaff) {
                    html += '<th scope="col">Assigned To</th><th scope="col" style="width:50px;"><span class="visually-hidden">Actions</span></th>';
                }
                html += '</tr></thead><tbody>';
                data.assets.forEach(function (a) {
                    var statusClass = '';
                    if (a.status_meta === 'deployed') statusClass = ' class="md-asset-status-deployed"';
                    else if (a.status_meta === 'undeployable' || a.status_meta === 'archived') statusClass = ' class="md-asset-status-undeployable"';
                    var statusText = a.status_meta === 'deployed' ? 'Checked Out' : a.status;
                    html += '<tr>';
                    html += '<td>' + esc(a.asset_tag) + '</td>';
                    html += '<td>' + esc(a.asset_name) + '</td>';
                    html += '<td' + statusClass + '>' + esc(statusText) + '</td>';
                    if (isStaff) {
                        html += '<td>' + esc(a.assigned_to || '') + '</td>';
                        html += '<td><button type="button" class="btn btn-sm btn-outline-secondary cat-detail-note-btn"'
                              + ' data-asset-id="' + a.asset_id + '" data-asset-tag="' + esc(a.asset_tag) + '"'
                              + ' aria-label="Add note for ' + esc(a.asset_tag) + '">'
                              + '<span aria-hidden="true">&#9998;</span></button></td>';
                    }
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            } else {
                html += '<p class="text-muted mb-0">No assets found.</p>';
            }
            html += '</div>'; // end inventory pane

            html += '</div>'; // end tab-content

            if (data.notes) {
                html += '<div class="md-notes mt-3">' + esc(data.notes) + '</div>';
            }

            return html;
        }

        function _wireTabs(container) {
            var buttons = container.querySelectorAll('.cat-detail-tab-btn');
            var panes   = container.querySelectorAll('.cat-detail-tab-pane');
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    buttons.forEach(function (b) { b.classList.remove('active'); });
                    panes.forEach(function (p) { p.classList.remove('active'); });
                    btn.classList.add('active');
                    var pane = container.querySelector('.cat-detail-tab-pane[data-pane="' + btn.dataset.tab + '"]');
                    if (pane) pane.classList.add('active');
                });
            });
        }

        function _switchTab(container, tabName) {
            var buttons = container.querySelectorAll('.cat-detail-tab-btn');
            var panes   = container.querySelectorAll('.cat-detail-tab-pane');
            buttons.forEach(function (b) { b.classList.remove('active'); });
            panes.forEach(function (p) { p.classList.remove('active'); });
            var btn  = container.querySelector('.cat-detail-tab-btn[data-tab="' + tabName + '"]');
            var pane = container.querySelector('.cat-detail-tab-pane[data-pane="' + tabName + '"]');
            if (btn) btn.classList.add('active');
            if (pane) pane.classList.add('active');
        }

        function _wireBasketForm(container) {
            var form = container.querySelector('.cat-detail-basket-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(function (r) {
                    var ct = r.headers.get('Content-Type') || '';
                    return ct.indexOf('application/json') !== -1 ? r.json() : null;
                })
                .then(function (data) {
                    if (data && typeof data.basket_count !== 'undefined') {
                        var viewBtn = document.getElementById('view-basket-btn');
                        if (viewBtn) {
                            var count = parseInt(data.basket_count, 10) || 0;
                            viewBtn.textContent = count > 0 ? 'View basket (' + count + ')' : 'View basket';
                        }
                    }
                })
                .catch(function () { form.submit(); });
            });
        }

        // Wire note buttons inside a rendered detail container (staff only)
        function _wireNoteButtons(container) {
            if (!window._modelDetailIsStaff) return;
            // Per-asset pencil buttons in inventory tab — switch to activity tab then open note form
            container.querySelectorAll('.cat-detail-note-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    _switchTab(container, 'activity');
                    _openNote(parseInt(btn.dataset.assetId, 10), btn.dataset.assetTag);
                });
            });
        }

        // --- Staff note form ---
        var _noteAssetId = null;

        function _openNote(assetId, assetTag) {
            _noteAssetId = assetId;
            var form = document.getElementById('modelDetailNoteForm');
            if (!form) return;
            form.style.display = 'block';
            form.innerHTML = '<h6 class="mb-2">Add Note to ' + esc(assetTag) + '</h6>'
                + '<label for="modelDetailNoteText" class="form-label">Note</label>'
                + '<textarea id="modelDetailNoteText" class="form-control mb-2" rows="3" placeholder="e.g. Lens scratched, missing cable..."></textarea>'
                + '<div class="mb-2"><div class="form-check">'
                + '<input class="form-check-input" type="checkbox" id="mdNoteCreateMaint" onchange="CatalogueDetail._togglePull()">'
                + '<label class="form-check-label" for="mdNoteCreateMaint">Create maintenance request (Repair)</label>'
                + '</div></div>'
                + '<div class="mb-3"><div class="form-check">'
                + '<input class="form-check-input" type="checkbox" id="mdNotePullRepair" disabled>'
                + '<label class="form-check-label text-muted" for="mdNotePullRepair" id="mdNotePullLabel">Change status to Pulled for Repair/Replace</label>'
                + '</div></div>'
                + '<div><button type="button" class="btn btn-sm btn-primary me-2" onclick="CatalogueDetail._submitNote()">Save</button>'
                + '<button type="button" class="btn btn-sm btn-secondary" onclick="CatalogueDetail._closeNote()">Cancel</button></div>'
                + '<div id="modelDetailNoteMsg" class="mt-2"></div>';
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            var ta = document.getElementById('modelDetailNoteText');
            if (ta) ta.focus();
        }

        function _togglePull() {
            var maint = document.getElementById('mdNoteCreateMaint');
            var pull  = document.getElementById('mdNotePullRepair');
            var label = document.getElementById('mdNotePullLabel');
            if (!maint || !pull) return;
            pull.disabled = !maint.checked;
            if (!maint.checked) pull.checked = false;
            if (label) label.classList.toggle('text-muted', !maint.checked);
        }

        function _submitNote() {
            if (!_noteAssetId) return;
            var textarea    = document.getElementById('modelDetailNoteText');
            var maintCb     = document.getElementById('mdNoteCreateMaint');
            var pullCb      = document.getElementById('mdNotePullRepair');
            var msg         = document.getElementById('modelDetailNoteMsg');
            var note        = (textarea ? textarea.value : '').trim();
            var createMaint = maintCb ? maintCb.checked : false;
            var pullRepair  = pullCb  ? pullCb.checked  : false;
            if (!note && !createMaint) {
                if (msg) msg.innerHTML = '<div class="alert alert-warning py-1 px-2 mb-0 small">Please enter a note or select an action.</div>';
                return;
            }
            if (msg) msg.innerHTML = '<div class="text-muted small">Saving...</div>';
            fetch('ajax_model_history.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add_note', asset_id: _noteAssetId, note: note, create_maintenance: createMaint, pull_for_repair: pullRepair })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var successMsg = 'Saved successfully.';
                    if (data.warnings && data.warnings.length > 0) {
                        successMsg += ' Warnings: ' + data.warnings.join('; ');
                        if (msg) msg.innerHTML = '<div class="alert alert-warning py-1 px-2 mb-0 small">' + esc(successMsg) + '</div>';
                    } else {
                        if (msg) msg.innerHTML = '<div class="alert alert-success py-1 px-2 mb-0 small">' + esc(successMsg) + '</div>';
                    }
                    if (textarea) textarea.value = '';
                    if (maintCb) maintCb.checked = false;
                    if (pullCb) { pullCb.checked = false; pullCb.disabled = true; }
                    var label = document.getElementById('mdNotePullLabel');
                    if (label) label.classList.add('text-muted');
                } else {
                    if (msg) msg.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">' + esc(data.error || 'Failed to save.') + '</div>';
                }
            })
            .catch(function () {
                if (msg) msg.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">Network error.</div>';
            });
        }

        function _closeNote() {
            _noteAssetId = null;
            var form = document.getElementById('modelDetailNoteForm');
            if (form) { form.style.display = 'none'; form.innerHTML = ''; }
        }

        function back() {
            var sa = _scrollArea();
            if (!sa || _savedHTML === null) return;
            sa.innerHTML = _savedHTML;
            _savedHTML = null;
            document.body.removeAttribute('data-detail-open');
            TopbarCrumbs.popAll();
            requestAnimationFrame(function () {
                sa.scrollTop = _savedScroll;
                if (_savedFocus && typeof _savedFocus.focus === 'function') {
                    _savedFocus.focus();
                }
                _savedFocus = null;
            });
        }

        return {
            open: function (modelId, modelName) {
                var sa = _scrollArea();
                if (!sa) return;
                _savedFocus  = document.activeElement;
                _savedScroll = sa.scrollTop;
                _savedHTML   = sa.innerHTML;
                sa.innerHTML = '<div class="cat-detail-loading"><div class="spinner-border" role="status">'
                             + '<span class="visually-hidden">Loading\u2026</span></div></div>';
                sa.scrollTop = 0;
                document.body.setAttribute('data-detail-open', '1');
                history.pushState({ catalogueDetail: true }, '');
                TopbarCrumbs.push(modelName, back);
                fetch('ajax_model_history.php?model_id=' + encodeURIComponent(modelId))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var html = '<div class="cat-detail-view">' + _buildDetailHTML(data, modelId) + '</div>';
                        sa.innerHTML = html;
                        sa.scrollTop = 0;
                        sa.querySelectorAll('.mh-toggle').forEach(function (el) {
                            el.addEventListener('click', _mhToggleOnclick);
                        });
                        _wireTabs(sa);
                        _wireBasketForm(sa);
                        _wireNoteButtons(sa);
                    })
                    .catch(function () {
                        sa.innerHTML = '<div class="alert alert-danger m-3">Failed to load model details.</div>';
                    });
            },
            back: back,
            isOpen: function () { return _savedHTML !== null; },
            // Exposed for inline onclick in note form HTML
            _togglePull: _togglePull,
            _submitNote: _submitNote,
            _closeNote:  _closeNote
        };
    })();

    // Global entry point called from card onclick attributes
    window.openModelDetail = function (modelId, modelName) {
        CatalogueDetail.open(modelId, modelName);
    };

    // ── Entry point ───────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.app-nav').forEach(function (nav) {
            initNav(nav);
        });

        // ── Account panel ─────────────────────────────────────────────────────────

        var accountTrigger  = document.getElementById('accountPanelTrigger');
        var accountPanel    = document.getElementById('accountPanel');
        var accountBackdrop = document.getElementById('accountPanelBackdrop');
        var accountClose    = document.getElementById('accountPanelClose');
        var accountBody     = document.getElementById('accountPanelBody');

        if (accountPanel && accountTrigger) {
            var panelContentLoaded = false;
            var panelTrapFn = null;

            function installPanelTrap() {
                if (panelTrapFn) {
                    document.removeEventListener('keydown', panelTrapFn);
                    panelTrapFn = null;
                }
                var focusables = getFocusables(accountPanel);
                if (focusables.length === 0) return;
                var first = focusables[0];
                var last  = focusables[focusables.length - 1];
                panelTrapFn = function (e) {
                    if (e.key !== 'Tab') return;
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                };
                document.addEventListener('keydown', panelTrapFn);
            }

            function openAccountPanel() {
                accountPanel.classList.add('is-open');
                accountBackdrop.classList.add('is-open');
                accountTrigger.setAttribute('aria-expanded', 'true');
                if (accountClose) accountClose.focus();
                if (!panelContentLoaded) loadPanelContent();
                document.addEventListener('keydown', onPanelKeydown);
                installPanelTrap();
            }

            function closeAccountPanel() {
                accountPanel.classList.remove('is-open');
                accountBackdrop.classList.remove('is-open');
                accountTrigger.setAttribute('aria-expanded', 'false');
                document.removeEventListener('keydown', onPanelKeydown);
                if (panelTrapFn) {
                    document.removeEventListener('keydown', panelTrapFn);
                    panelTrapFn = null;
                }
                accountTrigger.focus();
            }

            function onPanelKeydown(e) {
                if (e.key === 'Escape') closeAccountPanel();
            }

            function loadPanelContent() {
                if (accountBody) accountBody.innerHTML = '<p class="text-muted small">Loading\u2026</p>';
                fetch('ajax_account_panel.php', { credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        if (accountBody) {
                            accountBody.innerHTML = html;
                            panelContentLoaded = true;
                            wirePanelForms();
                            installPanelTrap();
                        }
                    })
                    .catch(function () {
                        if (accountBody) {
                            accountBody.innerHTML = '<div class="alert alert-danger small">Failed to load account settings.</div>';
                        }
                    });
            }

            function wirePanelForms() {
                if (!accountBody) return;
                accountBody.querySelectorAll('form[data-panel-form]').forEach(function (form) {
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        var btn   = form.querySelector('[type="submit"]');
                        var msgEl = form.querySelector('.panel-form-msg');
                        if (btn) btn.disabled = true;
                        fetch('ajax_account_panel.php', {
                            method: 'POST',
                            body: new FormData(form),
                            credentials: 'same-origin'
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.success) {
                                panelContentLoaded = false;
                                loadPanelContent();
                            } else if (msgEl) {
                                msgEl.textContent = data.error || 'An error occurred.';
                                msgEl.classList.remove('d-none');
                            }
                        })
                        .catch(function () {
                            if (msgEl) {
                                msgEl.textContent = 'Network error. Please try again.';
                                msgEl.classList.remove('d-none');
                            }
                        })
                        .finally(function () {
                            if (btn) btn.disabled = false;
                        });
                    });
                });
            }

            accountTrigger.addEventListener('click', openAccountPanel);
            if (accountClose) accountClose.addEventListener('click', closeAccountPanel);
            if (accountBackdrop) accountBackdrop.addEventListener('click', closeAccountPanel);
        }

        // ESC closes the catalogue detail view if open
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && CatalogueDetail.isOpen()) {
                CatalogueDetail.back();
            }
        });

        // Browser back button closes the detail view instead of leaving the catalogue
        window.addEventListener('popstate', function (e) {
            if (CatalogueDetail.isOpen()) {
                CatalogueDetail.back();
            }
        });

        // Sticky scan bar — toggle .stuck class when sentinel scrolls out of view
        document.querySelectorAll('.sticky-scan-sentinel').forEach(function (sentinel) {
            var bar = sentinel.nextElementSibling;
            if (!bar || !bar.classList.contains('sticky-scan-bar')) return;
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    bar.classList.toggle('stuck', !entry.isIntersecting);
                });
            }, { threshold: 0 });
            observer.observe(sentinel);
        });
    });

})();
