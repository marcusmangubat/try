<?php

/** Profile-icon dropdown for the admin portal. Uses $admin_name, $admin_email, $initials when set. */
$am_name  = $admin_name ?? ($_SESSION['admin_name'] ?? 'Admin');
$am_email = $admin_email ?? ($_SESSION['user_email'] ?? '');
if (!empty($initials)) {
    $am_init = $initials;
} else {
    $parts   = preg_split('/\s+/', trim((string) $am_name));
    $am_init = strtoupper(
        (substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1))
    );
    if ($am_init === '') {
        $am_init = 'AD';
    }
}
?>
<style>
    #avatarMenuWrap {
        position: relative;
    }

    #avatarMenuBtn {
        font-family: inherit;
    }

    #avatarMenu.hidden {
        display: none !important;
    }

    #avatarMenu {
        position: absolute;
        right: 0;
        top: calc(100% + 12px);
        width: 248px;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        box-shadow: 0 16px 40px rgba(11, 31, 75, .18);
        z-index: 700;
        color: var(--text1);
    }

    #avatarMenu .am-head {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        background: var(--surface);
    }

    #avatarMenu .am-name {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--text1);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    #avatarMenu .am-email {
        font-size: 10.5px;
        color: var(--text3);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-top: 1px;
    }

    #avatarMenu .am-nav {
        padding: 6px 0;
    }

    #avatarMenu .am-item {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 8px 16px;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--text2);
        text-decoration: none;
        background: transparent;
        border: 0;
        cursor: pointer;
        text-align: left;
        font-family: inherit;
        box-sizing: border-box;
        transition: background .15s ease;
    }

    #avatarMenu .am-item:hover {
        background: var(--surface);
        color: var(--text1);
    }

    #avatarMenu .am-item i {
        width: 16px;
        text-align: center;
        color: var(--blue-lt);
    }

    #avatarMenu .am-foot {
        border-top: 1px solid var(--border);
        padding: 6px 0;
    }

    #avatarMenu .am-item.am-logout {
        color: #dc2626;
    }

    #avatarMenu .am-item.am-logout i {
        color: #dc2626;
    }

    #avatarMenu .am-item.am-logout:hover {
        background: #fef2f2;
    }
</style>
<div id="avatarMenuWrap">
    <button type="button" id="avatarMenuBtn"
        class="topbar-avatar"
        aria-haspopup="menu" aria-expanded="false" aria-controls="avatarMenu" aria-label="Account menu">
        <?= htmlspecialchars($am_init) ?>
    </button>
    <div id="avatarMenu" class="hidden" role="menu">
        <div class="am-head">
            <div class="am-name" id="avatarMenuName"><?= htmlspecialchars($am_name) ?></div>
            <div class="am-email" id="avatarMenuEmail"><?= htmlspecialchars($am_email) ?></div>
        </div>
        <div class="am-nav">
            <a href="dashboard.php" role="menuitem" class="am-item">
                <i class="fas fa-house"></i> Dashboard
            </a>
            <a href="applications.php" role="menuitem" class="am-item">
                <i class="fas fa-list-check"></i> Applications
            </a>
            <a href="job-orders.php" role="menuitem" class="am-item">
                <i class="fas fa-briefcase"></i> Job Orders
            </a>
            <a href="reports.php" role="menuitem" class="am-item">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="user.php" role="menuitem" class="am-item">
                <i class="fas fa-users"></i> Users
            </a>
            <a href="settings.php" role="menuitem" class="am-item">
                <i class="fas fa-user-gear"></i> Profile &amp; Settings
            </a>
        </div>
        <div class="am-foot">
            <button type="button" role="menuitem" class="am-item am-logout" onclick="logout('Admin')">
                <i class="fas fa-arrow-right-from-bracket"></i> Logout
            </button>
        </div>
    </div>
</div>