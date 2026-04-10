(function () {

    // ── V1 legacy: collapsible top-nav on mobile ──────────────────────────────

    var v1MobileQuery = window.matchMedia('(max-width: 768px)');

    function v1CloseMenu(wrapper, toggle, labelEl) {
        wrapper.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        labelEl.textContent = 'Menu';
    }

    function v1EnhanceNav(nav) {
        if (!nav || nav.dataset.navInit === '1') return;
        nav.dataset.navInit = '1';

        var wrapper = document.createElement('div');
        wrapper.className = 'app-nav-shell has-toggle';

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'app-nav-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', nav.id || 'app-nav');

        var icon = document.createElement('span');
        icon.className = 'app-nav-toggle-icon';
        icon.setAttribute('aria-hidden', 'true');

        var label = document.createElement('span');
        label.className = 'app-nav-toggle-label';
        label.textContent = 'Menu';

        toggle.appendChild(icon);
        toggle.appendChild(label);

        nav.parentNode.insertBefore(wrapper, nav);
        wrapper.appendChild(toggle);
        wrapper.appendChild(nav);

        toggle.addEventListener('click', function () {
            var isOpen = wrapper.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            label.textContent = isOpen ? 'Close menu' : 'Menu';
        });

        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (v1MobileQuery.matches) {
                    v1CloseMenu(wrapper, toggle, label);
                }
            });
        });

        var handleChange = function () {
            if (!v1MobileQuery.matches) {
                v1CloseMenu(wrapper, toggle, label);
            }
        };

        if (v1MobileQuery.addEventListener) {
            v1MobileQuery.addEventListener('change', handleChange);
        } else if (v1MobileQuery.addListener) {
            v1MobileQuery.addListener(handleChange);
        }
    }

    // ── V2: three-state responsive sidebar ───────────────────────────────────

    var mobileQuery    = window.matchMedia('(max-width: 599px)');
    var collapsedQuery = window.matchMedia('(min-width: 600px) and (max-width: 959px)');

    function getFocusables(container) {
        return Array.from(container.querySelectorAll(
            'a[href], button:not([disabled]), details > summary, [tabindex]:not([tabindex="-1"])'
        ));
    }

    function v2InitNav(nav) {
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

    // ── Entry point ───────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.app-nav').forEach(function (nav) {
            var version = parseInt(nav.dataset.designVersion || '1', 10);
            if (version >= 2) {
                v2InitNav(nav);
            } else {
                v1EnhanceNav(nav);
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
