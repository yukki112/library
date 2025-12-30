<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Write an entry to the audit log.  This function automatically records the
 * approximate device type (phone, tablet or laptop/desktop) of the user based
 * on their HTTP user agent and stores that as the details field.  The
 * `$details` parameter is ignored and retained for backwards compatibility so
 * existing calls to this function do not need to be updated.
 *
 * @param string $action   The action performed (e.g. create, update).
 * @param string $entity   The name of the entity affected (e.g. users, books).
 * @param int|null $entity_id Optional ID of the entity affected.
 * @param array $details   Ignored – kept for backwards compatibility.
 */
function audit(string $action, string $entity, ?int $entity_id = null, array $details = []): void {
    try {
        $user = current_user();
        $pdo = DB::conn();

        // Determine the device type from the HTTP user agent.  We lower‑case
        // everything to make the detection case‑insensitive.  If the user
        // agent contains "mobile", "android" or "iphone", we assume a phone.
        // If it contains "tablet" or "ipad", we assume a tablet.  Otherwise
        // we default to a laptop/desktop classification.  This heuristic is
        // simple but sufficient for rough categorisation.
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $agentLower = strtolower($agent);
        $deviceType = 'Laptop/Desktop';
        if (strpos($agentLower, 'mobile') !== false || strpos($agentLower, 'android') !== false || strpos($agentLower, 'iphone') !== false) {
            $deviceType = 'Phone';
        } elseif (strpos($agentLower, 'tablet') !== false || strpos($agentLower, 'ipad') !== false) {
            $deviceType = 'Tablet';
        }

        // Insert the audit record.  Store only the device type in the
        // `details` column.  Although the function accepts an array for
        // `$details`, it is intentionally ignored here because the audit log
        // requirements specify that the details should contain only the
        // device type (e.g. Phone, Laptop/Desktop, Tablet).
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, details) VALUES (:uid,:act,:ent,:eid,:det)'
        );
        $stmt->execute([
            ':uid' => $user['id'] ?? null,
            ':act' => $action,
            ':ent' => $entity,
            ':eid' => $entity_id,
            ':det' => $deviceType
        ]);
    } catch (Throwable $e) {
        // fail‑safe: swallow any errors to avoid interrupting the main flow
        // of the application.  Audit logging should never block primary
        // operations.
    }
}
?>

