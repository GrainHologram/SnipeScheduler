<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/opening_hours.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$config = load_config();
$appTz  = app_get_timezone();

$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// -------------------------------------------------------
// Handle POST actions
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Default schedule ---
    if ($action === 'save_default') {
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            $closed = isset($_POST['default_closed_' . $d]);
            $days[$d] = [
                'is_closed' => $closed,
                'open_time' => $closed ? null : ($_POST['default_open_' . $d] ?? null),
                'close_time' => $closed ? null : ($_POST['default_close_' . $d] ?? null),
            ];
        }
        oh_save_default_schedule($days);
        $_SESSION['oh_message'] = 'Default weekly hours saved.';
        header('Location: opening_hours.php');
        exit;
    }

    // --- Create schedule override ---
    if ($action === 'create_schedule') {
        $name = trim($_POST['sched_name'] ?? '');
        $startDate = trim($_POST['sched_start'] ?? '');
        $endDate = trim($_POST['sched_end'] ?? '');
        if ($name === '' || $startDate === '' || $endDate === '') {
            $_SESSION['oh_error'] = 'Name, start date and end date are required.';
            header('Location: opening_hours.php');
            exit;
        }
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            $closed = isset($_POST['sched_closed_' . $d]);
            $days[$d] = [
                'is_closed' => $closed,
                'open_time' => $closed ? null : ($_POST['sched_open_' . $d] ?? null),
                'close_time' => $closed ? null : ($_POST['sched_close_' . $d] ?? null),
            ];
        }
        oh_create_schedule($name, $startDate, $endDate, $days);
        $_SESSION['oh_message'] = 'Schedule override created.';
        header('Location: opening_hours.php');
        exit;
    }

    // --- Update schedule override ---
    if ($action === 'update_schedule') {
        $id = (int)($_POST['sched_id'] ?? 0);
        $name = trim($_POST['sched_name'] ?? '');
        $startDate = trim($_POST['sched_start'] ?? '');
        $endDate = trim($_POST['sched_end'] ?? '');
        if ($id <= 0 || $name === '' || $startDate === '' || $endDate === '') {
            $_SESSION['oh_error'] = 'Invalid schedule data.';
            header('Location: opening_hours.php');
            exit;
        }
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            $closed = isset($_POST['sched_closed_' . $d]);
            $days[$d] = [
                'is_closed' => $closed,
                'open_time' => $closed ? null : ($_POST['sched_open_' . $d] ?? null),
                'close_time' => $closed ? null : ($_POST['sched_close_' . $d] ?? null),
            ];
        }
        oh_update_schedule($id, $name, $startDate, $endDate, $days);
        $_SESSION['oh_message'] = 'Schedule override updated.';
        header('Location: opening_hours.php');
        exit;
    }

    // --- Delete schedule override ---
    if ($action === 'delete_schedule') {
        $id = (int)($_POST['sched_id'] ?? 0);
        if ($id > 0) {
            oh_delete_schedule($id);
            $_SESSION['oh_message'] = 'Schedule override deleted.';
        }
        header('Location: opening_hours.php');
        exit;
    }

    // --- Create one-off override ---
    if ($action === 'create_override') {
        $label = trim($_POST['ovr_label'] ?? '');
        $startRaw = trim($_POST['ovr_start'] ?? '');
        $endRaw = trim($_POST['ovr_end'] ?? '');
        $type = ($_POST['ovr_type'] ?? 'closed') === 'open' ? 'open' : 'closed';
        if ($startRaw === '' || $endRaw === '') {
            $_SESSION['oh_error'] = 'Start and end datetime are required.';
            header('Location: opening_hours.php');
            exit;
        }
        // Form values are in app timezone — convert to UTC for storage
        $startDt = new DateTime($startRaw, $appTz);
        $endDt = new DateTime($endRaw, $appTz);
        $startDt->setTimezone(new DateTimeZone('UTC'));
        $endDt->setTimezone(new DateTimeZone('UTC'));
        oh_create_override($label, $startDt->format('Y-m-d H:i:s'), $endDt->format('Y-m-d H:i:s'), $type);
        $_SESSION['oh_message'] = 'One-off override created.';
        header('Location: opening_hours.php');
        exit;
    }

    // --- Update one-off override ---
    if ($action === 'update_override') {
        $id = (int)($_POST['ovr_id'] ?? 0);
        $label = trim($_POST['ovr_label'] ?? '');
        $startRaw = trim($_POST['ovr_start'] ?? '');
        $endRaw = trim($_POST['ovr_end'] ?? '');
        $type = ($_POST['ovr_type'] ?? 'closed') === 'open' ? 'open' : 'closed';
        if ($id <= 0 || $startRaw === '' || $endRaw === '') {
            $_SESSION['oh_error'] = 'Invalid override data.';
            header('Location: opening_hours.php');
            exit;
        }
        $startDt = new DateTime($startRaw, $appTz);
        $endDt = new DateTime($endRaw, $appTz);
        $startDt->setTimezone(new DateTimeZone('UTC'));
        $endDt->setTimezone(new DateTimeZone('UTC'));
        oh_update_override($id, $label, $startDt->format('Y-m-d H:i:s'), $endDt->format('Y-m-d H:i:s'), $type);
        $_SESSION['oh_message'] = 'One-off override updated.';
        header('Location: opening_hours.php');
        exit;
    }

    // --- Delete one-off override ---
    if ($action === 'delete_override') {
        $id = (int)($_POST['ovr_id'] ?? 0);
        if ($id > 0) {
            oh_delete_override($id);
            $_SESSION['oh_message'] = 'One-off override deleted.';
        }
        header('Location: opening_hours.php');
        exit;
    }
}

// -------------------------------------------------------
// Load data for display
// -------------------------------------------------------
$defaults  = oh_get_default_schedule();
$schedules = oh_get_schedules();
$overrides = oh_get_overrides();

$flashMessage = $_SESSION['oh_message'] ?? '';
$flashError   = $_SESSION['oh_error'] ?? '';
unset($_SESSION['oh_message'], $_SESSION['oh_error']);

// Editing states
$editScheduleId = isset($_GET['edit_schedule']) ? (int)$_GET['edit_schedule'] : 0;
$editSchedule   = $editScheduleId > 0 ? oh_get_schedule($editScheduleId) : null;

$editOverrideId = isset($_GET['edit_override']) ? (int)$_GET['edit_override'] : 0;
$editOverride   = null;
if ($editOverrideId > 0) {
    foreach ($overrides as $ov) {
        if ((int)$ov['id'] === $editOverrideId) {
            $editOverride = $ov;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opening Hours – SnipeScheduler</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag($config) ?>
        <div class="page-header">
            <h1>Opening Hours</h1>
            <div class="page-subtitle">
                Configure when the facility is open for equipment collection and return.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="opening_hours.php">Opening Hours</a>
            </li>
        </ul>

        <?php if ($flashMessage): ?>
            <div class="alert alert-success"><?= h($flashMessage) ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert alert-danger"><?= h($flashError) ?></div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- Card 1: Default Weekly Hours                                 -->
        <!-- ============================================================ -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-1">Default Weekly Hours</h5>
                <p class="text-muted small mb-3">Set the standard opening hours for each day of the week. These apply unless overridden by a schedule or one-off override below.</p>
                <form method="post" action="opening_hours.php">
                    <input type="hidden" name="action" value="save_default">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th style="width:100px">Closed</th>
                                    <th>Open</th>
                                    <th>Close</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($d = 1; $d <= 7; $d++): ?>
                                    <?php
                                        $row = $defaults[$d] ?? ['is_closed' => 1, 'open_time' => null, 'close_time' => null];
                                        $isClosed = (bool)$row['is_closed'];
                                        $openVal  = $row['open_time'] ? substr($row['open_time'], 0, 5) : '09:00';
                                        $closeVal = $row['close_time'] ? substr($row['close_time'], 0, 5) : '17:00';
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h($dayNames[$d]) ?></td>
                                        <td>
                                            <input type="checkbox"
                                                   class="form-check-input oh-closed-toggle"
                                                   name="default_closed_<?= $d ?>"
                                                   id="default_closed_<?= $d ?>"
                                                   data-row="<?= $d ?>"
                                                   <?= $isClosed ? 'checked' : '' ?>>
                                        </td>
                                        <td>
                                            <input type="time" step="900"
                                                   class="form-control form-control-sm oh-time-input"
                                                   name="default_open_<?= $d ?>"
                                                   id="default_open_<?= $d ?>"
                                                   value="<?= h($openVal) ?>"
                                                   <?= $isClosed ? 'disabled' : '' ?>>
                                        </td>
                                        <td>
                                            <input type="time" step="900"
                                                   class="form-control form-control-sm oh-time-input"
                                                   name="default_close_<?= $d ?>"
                                                   id="default_close_<?= $d ?>"
                                                   value="<?= h($closeVal) ?>"
                                                   <?= $isClosed ? 'disabled' : '' ?>>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save default hours</button>
                </form>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- Card 2: Schedule Overrides                                   -->
        <!-- ============================================================ -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-1">Schedule Overrides</h5>
                <p class="text-muted small mb-3">Define recurring weekly schedules that override the defaults for a date range (e.g. summer hours, term-time hours).</p>

                <?php if (!empty($schedules)): ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Days</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedules as $sched): ?>
                                    <tr>
                                        <td><?= h($sched['name']) ?></td>
                                        <td><?= h($sched['start_date']) ?></td>
                                        <td><?= h($sched['end_date']) ?></td>
                                        <td>
                                            <?php
                                            $daySummary = [];
                                            foreach ($sched['days'] as $sd) {
                                                $dn = $dayNames[(int)$sd['day_of_week']] ?? '?';
                                                if ($sd['is_closed']) {
                                                    $daySummary[] = substr($dn, 0, 3) . ': Closed';
                                                } else {
                                                    $daySummary[] = substr($dn, 0, 3) . ': ' . substr($sd['open_time'], 0, 5) . '-' . substr($sd['close_time'], 0, 5);
                                                }
                                            }
                                            echo h(implode(', ', $daySummary));
                                            ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="opening_hours.php?edit_schedule=<?= (int)$sched['id'] ?>#schedule-form" class="btn btn-sm btn-outline-primary">Edit</a>
                                            <form method="post" action="opening_hours.php" class="d-inline" onsubmit="return confirm('Delete this schedule override?');">
                                                <input type="hidden" name="action" value="delete_schedule">
                                                <input type="hidden" name="sched_id" value="<?= (int)$sched['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small">No schedule overrides defined.</p>
                <?php endif; ?>

                <div class="border rounded p-3" id="schedule-form">
                    <h6 class="fw-semibold mb-2"><?= $editSchedule ? 'Edit' : 'Add' ?> schedule override</h6>
                    <form method="post" action="opening_hours.php">
                        <input type="hidden" name="action" value="<?= $editSchedule ? 'update_schedule' : 'create_schedule' ?>">
                        <?php if ($editSchedule): ?>
                            <input type="hidden" name="sched_id" value="<?= (int)$editSchedule['id'] ?>">
                        <?php endif; ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Name</label>
                                <input type="text" name="sched_name" class="form-control form-control-sm" required
                                       value="<?= h($editSchedule['name'] ?? '') ?>"
                                       placeholder="e.g. Summer Hours">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Start date</label>
                                <input type="date" name="sched_start" class="form-control form-control-sm" required
                                       value="<?= h($editSchedule['start_date'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">End date</label>
                                <input type="date" name="sched_end" class="form-control form-control-sm" required
                                       value="<?= h($editSchedule['end_date'] ?? '') ?>">
                            </div>
                        </div>
                        <?php
                            $schedDays = [];
                            if ($editSchedule && !empty($editSchedule['days'])) {
                                foreach ($editSchedule['days'] as $sd) {
                                    $schedDays[(int)$sd['day_of_week']] = $sd;
                                }
                            }
                        ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th style="width:100px">Closed</th>
                                        <th>Open</th>
                                        <th>Close</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($d = 1; $d <= 7; $d++): ?>
                                        <?php
                                            $sd = $schedDays[$d] ?? null;
                                            $sClosed = $sd ? (bool)$sd['is_closed'] : ($d >= 6);
                                            $sOpen  = $sd && $sd['open_time'] ? substr($sd['open_time'], 0, 5) : '09:00';
                                            $sClose = $sd && $sd['close_time'] ? substr($sd['close_time'], 0, 5) : '17:00';
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?= h($dayNames[$d]) ?></td>
                                            <td>
                                                <input type="checkbox"
                                                       class="form-check-input oh-closed-toggle"
                                                       name="sched_closed_<?= $d ?>"
                                                       data-row="sched_<?= $d ?>"
                                                       <?= $sClosed ? 'checked' : '' ?>>
                                            </td>
                                            <td>
                                                <input type="time" step="900"
                                                       class="form-control form-control-sm oh-time-input"
                                                       name="sched_open_<?= $d ?>"
                                                       id="sched_open_<?= $d ?>"
                                                       value="<?= h($sOpen) ?>"
                                                       <?= $sClosed ? 'disabled' : '' ?>>
                                            </td>
                                            <td>
                                                <input type="time" step="900"
                                                       class="form-control form-control-sm oh-time-input"
                                                       name="sched_close_<?= $d ?>"
                                                       id="sched_close_<?= $d ?>"
                                                       value="<?= h($sClose) ?>"
                                                       <?= $sClosed ? 'disabled' : '' ?>>
                                            </td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm"><?= $editSchedule ? 'Update' : 'Add' ?> schedule override</button>
                        <?php if ($editSchedule): ?>
                            <a href="opening_hours.php" class="btn btn-outline-secondary btn-sm ms-2">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- Card 3: One-Off Overrides                                    -->
        <!-- ============================================================ -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-1">One-Off Overrides</h5>
                <p class="text-muted small mb-3">Add specific date/time overrides (e.g. holiday closures, special openings). These take priority over everything else.</p>

                <?php if (!empty($overrides)): ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Label</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Type</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($overrides as $ov): ?>
                                    <tr>
                                        <td><?= h($ov['label'] ?: '(no label)') ?></td>
                                        <td><?= h(app_format_datetime($ov['start_datetime'])) ?></td>
                                        <td><?= h(app_format_datetime($ov['end_datetime'])) ?></td>
                                        <td>
                                            <?php if ($ov['override_type'] === 'closed'): ?>
                                                <span class="badge bg-danger">Closed</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Open</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="opening_hours.php?edit_override=<?= (int)$ov['id'] ?>#override-form" class="btn btn-sm btn-outline-primary">Edit</a>
                                            <form method="post" action="opening_hours.php" class="d-inline" onsubmit="return confirm('Delete this override?');">
                                                <input type="hidden" name="action" value="delete_override">
                                                <input type="hidden" name="ovr_id" value="<?= (int)$ov['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small">No one-off overrides defined.</p>
                <?php endif; ?>

                <?php
                    // Convert UTC override datetimes to app-tz for the edit form
                    $editOvrStart = '';
                    $editOvrEnd   = '';
                    if ($editOverride) {
                        $osDt = new DateTime($editOverride['start_datetime'], new DateTimeZone('UTC'));
                        $oeDt = new DateTime($editOverride['end_datetime'], new DateTimeZone('UTC'));
                        $osDt->setTimezone($appTz);
                        $oeDt->setTimezone($appTz);
                        $editOvrStart = $osDt->format('Y-m-d\TH:i');
                        $editOvrEnd   = $oeDt->format('Y-m-d\TH:i');
                    }
                ?>
                <div class="border rounded p-3" id="override-form">
                    <h6 class="fw-semibold mb-2"><?= $editOverride ? 'Edit' : 'Add' ?> one-off override</h6>
                    <form method="post" action="opening_hours.php">
                        <input type="hidden" name="action" value="<?= $editOverride ? 'update_override' : 'create_override' ?>">
                        <?php if ($editOverride): ?>
                            <input type="hidden" name="ovr_id" value="<?= (int)$editOverride['id'] ?>">
                        <?php endif; ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Label</label>
                                <input type="text" name="ovr_label" class="form-control form-control-sm"
                                       value="<?= h($editOverride['label'] ?? '') ?>"
                                       placeholder="e.g. Christmas closure">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Start</label>
                                <input type="datetime-local" step="900" name="ovr_start" class="form-control form-control-sm" required
                                       value="<?= h($editOvrStart) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">End</label>
                                <input type="datetime-local" step="900" name="ovr_end" class="form-control form-control-sm" required
                                       value="<?= h($editOvrEnd) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Type</label>
                                <select name="ovr_type" class="form-select form-select-sm">
                                    <option value="closed" <?= ($editOverride['override_type'] ?? 'closed') === 'closed' ? 'selected' : '' ?>>Closed</option>
                                    <option value="open" <?= ($editOverride['override_type'] ?? 'closed') === 'open' ? 'selected' : '' ?>>Open</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm"><?= $editOverride ? 'Update' : 'Add' ?> one-off override</button>
                        <?php if ($editOverride): ?>
                            <a href="opening_hours.php" class="btn btn-outline-secondary btn-sm ms-2">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>
<?php layout_footer(); ?>
<script>
(function () {
    // Toggle time inputs when "Closed" checkbox changes
    document.querySelectorAll('.oh-closed-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var row = cb.closest('tr');
            if (!row) return;
            var inputs = row.querySelectorAll('.oh-time-input');
            inputs.forEach(function (inp) {
                inp.disabled = cb.checked;
            });
        });
    });
})();
</script>
</body>
</html>
