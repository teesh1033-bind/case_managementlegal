<?php
/**
 * Standard client portal top navbar (matches client-appointments.php).
 */

function legalpro_render_client_page_navbar(
    string $pageTitle,
    string $breadcrumbActive = '',
    string $searchPlaceholder = 'Search…',
    array $options = []
): string {
    if ($breadcrumbActive === '') {
        $breadcrumbActive = $pageTitle;
    }

    $parentLabel = (string) ($options['parent_label'] ?? 'Client');
    $parentUrl = (string) ($options['parent_url'] ?? 'client-dashboard.php');
    $parentLinkClass = (string) ($options['parent_link_class'] ?? 'opacity-6');
    $titleTag = (string) ($options['title_tag'] ?? 'h5');
    if (!in_array($titleTag, ['h5', 'h6'], true)) {
        $titleTag = 'h5';
    }

    $welcomeName = (string) ($options['client_name'] ?? '');
    if ($welcomeName === '' || $welcomeName === '{CLIENT_NAME}') {
        $welcomeName = isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client';
    }
    $welcomeName = htmlspecialchars($welcomeName, ENT_QUOTES, 'UTF-8');

    $includeSearch = (bool) ($options['include_search'] ?? ($searchPlaceholder !== ''));
    $searchValue = htmlspecialchars((string) ($options['search_value'] ?? ''), ENT_QUOTES, 'UTF-8');
    $searchPlaceholderEsc = htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8');

    $pageTitleEsc = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    $breadcrumbEsc = htmlspecialchars($breadcrumbActive, ENT_QUOTES, 'UTF-8');
    $parentLabelEsc = htmlspecialchars($parentLabel, ENT_QUOTES, 'UTF-8');
    $parentUrlEsc = htmlspecialchars($parentUrl, ENT_QUOTES, 'UTF-8');

    $searchHtml = '';
    if ($includeSearch) {
        $searchHtml = '
                    <form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="search" name="q" class="form-control" placeholder="' . $searchPlaceholderEsc . '" value="' . $searchValue . '" autocomplete="off" maxlength="200" aria-label="Search">
                        </div>
                    </form>';
    }

    return '
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="' . htmlspecialchars($parentLinkClass, ENT_QUOTES, 'UTF-8') . ' text-white" href="' . $parentUrlEsc . '">' . $parentLabelEsc . '</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">' . $breadcrumbEsc . '</li>
                    </ol>
                    <' . $titleTag . ' class="font-weight-bolder mb-0 text-white">' . $pageTitleEsc . '</' . $titleTag . '>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">' . $searchHtml . '
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-white font-weight-bold px-0">
                                <i class="fa fa-user me-sm-1"></i>
                                <span class="d-sm-inline d-none">Welcome, ' . $welcomeName . '</span>
                            </a>
                        </li>
                        <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav">
                                <div class="sidenav-toggler-inner">
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                </div>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>';
}
