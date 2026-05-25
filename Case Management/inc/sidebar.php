<?php
// inc/sidebar.php
// A reusable sidebar include. Adjust links to match your pages.
// This file is designed to be included from pages located in `pages/`.
// Usage inside a page file (for example `pages/dashboard.php`):
// <?php include __DIR__ . '/../inc/sidebar.php'; ?>
?>

<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3" id="sidenav-main">
  <div class="sidenav-header">
    <a class="navbar-brand m-0" href="../pages/dashboard.php">
      <img src="../assets/img/logo-ct.png" class="navbar-brand-img h-100" alt="logo">
      <span class="ms-1 font-weight-bold">Case Management</span>
    </a>
  </div>
  <hr class="horizontal dark mt-0">
  <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" href="../pages/dashboard.php">
          <span class="nav-link-text ms-1">Dashboard</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../pages/clients.php">
          <span class="nav-link-text ms-1">Clients</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../pages/appointments.php">
          <span class="nav-link-text ms-1">Appointments</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../pages/case-new.php">
          <span class="nav-link-text ms-1">New Case</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../pages/tables.php">
          <span class="nav-link-text ms-1">Cases</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../pages/documents.php">
          <span class="nav-link-text ms-1">Documents</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../pages/billing.php">
          <span class="nav-link-text ms-1">Billing</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../pages/staff.php">
          <span class="nav-link-text ms-1">Staff</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="../pages/settings.php">
          <span class="nav-link-text ms-1">Settings</span>
        </a>
      </li>
    </ul>
  </div>
  <div class="sidenav-footer">
    <div class="mx-3">
      <small class="text-muted">Logged in as <strong>admin</strong></small>
    </div>
  </div>
</aside>