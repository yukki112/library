<?php
/**
 * Endpoint for students and non‑staff to request new book acquisitions.
 * When a user submits a request for a book that is not found in the
 * catalogue, this API will record the request by inserting a new
 * notification for administrators.  The notification type is
 * `book_request` and the message includes the requested title.  The
 * optional URL field allows patrons to provide a link to an online
 * resource or reference for the requested book.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';

start_app_session();

// Only logged‑in users may request books.
if (!is_logged_in()) {
    json_response(['error' => 'Unauthorized'], 401);
}

$u = current_user();
$role = $u['role'] ?? '';
// Permit book requests from students, non‑staff and teachers.  Staff roles
// (admin, librarian, assistant) already have the ability to add books via
// the CRUD interface and therefore do not need to use this endpoint.
if (!in_array($role, ['student','non_staff','teacher'], true)) {
    json_response(['error' => 'Forbidden'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$title = trim((string)($body['title'] ?? ''));
$url   = trim((string)($body['url'] ?? ''));

if ($title === '') {
    json_response(['error' => 'title is required'], 400);
}

// Compose a message for the notification.  Include the requesting
// user's name to assist admins in following up.  The message body
// intentionally excludes the URL to avoid cluttering the notification
// list; the URL is stored in the meta JSON for administrators to access.
$message = sprintf('%s requested a new book: %s', $u['name'] ?: $u['username'], $title);
$meta    = ['url' => $url, 'requester_id' => $u['id'], 'title' => $title];

// Record the request as a notification targeted at administrative roles.
// Setting user_id to NULL and role_target to 'admin' ensures that all
// administrators see the request.  Assistants and librarians may
// optionally process requests by reading notifications of type
// `book_request`.
notify_user(null, 'admin', 'book_request', $message, $meta);

json_response(['ok' => true]);