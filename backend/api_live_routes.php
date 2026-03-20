<?php
/**
 * EduCore — api_live_routes.php
 *
 * ─────────────────────────────────────────────────────────────────────────
 * INSTRUCTIONS — How to integrate into your existing api.php
 * ─────────────────────────────────────────────────────────────────────────
 *
 * 1. Copy StudentLiveController.php into your backend/controllers/ directory
 *    alongside StudentDataController.php and StudentAuthController.php.
 *
 * 2. Find the STUDENT ROUTES section in api.php
 *    (the `elseif ($parts[0] === 'student')` block).
 *
 * 3. Inside that block, ADD the new route handlers shown below.
 *
 *    A. In the student/session/* branch — add two new endpoints:
 *       stats and recent-checkins.
 *
 *    B. In the student/checkin/* branch — add status endpoint.
 *
 *    C. Add the new student/biometrics/* branch entirely.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * FULL UPDATED ROUTING BLOCKS — paste these to replace the existing ones
 * ─────────────────────────────────────────────────────────────────────────
 */

// ════════════════════════════════════════════════════════════════════════
// REPLACEMENT: student/session/* branch
// ════════════════════════════════════════════════════════════════════════

/*
Replace your existing student/session/* block with this:

        // ── student/session/*  ───────────────────────────────────────
        elseif ($sub === 'session') {
            loadCtrl('StudentLiveController');
            $ctrl = new StudentLiveController();
            // p2 = 'active' | numeric session_id
            // p3 = 'geofence-map' | 'qr-current' | 'stats' | 'recent-checkins'
            match (true) {
                $method === 'GET' && $p2 === 'active'                          => $ctrl->getActiveSession(),
                $method === 'GET' && $id && $p3 === 'geofence-map'             => $ctrl->getGeofenceMap($id),
                $method === 'GET' && $id && $p3 === 'qr-current'               => $ctrl->getSessionQR($id),
                $method === 'GET' && $id && $p3 === 'stats'                    => $ctrl->getSessionStats($id),
                $method === 'GET' && $id && $p3 === 'recent-checkins'          => $ctrl->getRecentCheckins($id),
                default => notFound(),
            };
        }
*/

// ════════════════════════════════════════════════════════════════════════
// REPLACEMENT: student/checkin/* branch
// ════════════════════════════════════════════════════════════════════════

/*
Replace your existing student/checkin/* block with this:

        // ── student/checkin/*  ───────────────────────────────────────
        elseif ($sub === 'checkin') {
            loadCtrl('StudentLiveController');
            $ctrl = new StudentLiveController();
            // POST  student/checkin
            // GET   student/checkin/history
            // GET   student/checkin/receipt/{id}
            // GET   student/checkin/status?session_id={id}
            $p4 = $parts[4] ?? '';
            match (true) {
                $method === 'POST' && $p2 === ''                                => $ctrl->checkin(),
                $method === 'GET'  && $p2 === 'history'                         => (function() { loadCtrl('StudentDataController'); (new StudentDataController())->getHistory(); })(),
                $method === 'GET'  && $p2 === 'receipt' && is_numeric($p3)      => (function() use ($p3) { loadCtrl('StudentDataController'); (new StudentDataController())->getReceipt((int)$p3); })(),
                $method === 'GET'  && $p2 === 'status'                          => $ctrl->getCheckinStatus(),
                default => notFound(),
            };
        }

NOTE: getHistory() and getReceipt() remain in StudentDataController.
      Only checkin() and getCheckinStatus() move to StudentLiveController.
      You may prefer to keep checkin() in StudentDataController and
      add only the new methods to StudentLiveController — both work.
*/

// ════════════════════════════════════════════════════════════════════════
// NEW BLOCK: student/biometrics/* branch
// ════════════════════════════════════════════════════════════════════════

/*
Add this BEFORE the `else { notFound(); }` at the end of the student block:

        // ── student/biometrics/* ─────────────────────────────────────
        // Powers biometric-setup.html and the WebAuthn identity layer
        // in stu-live.html / checkin.html.
        //
        // Routes:
        //   GET    student/biometrics/webauthn/status
        //   POST   student/biometrics/webauthn/challenge
        //   POST   student/biometrics/webauthn/register/challenge
        //   POST   student/biometrics/webauthn/register/complete
        //   DELETE student/biometrics/webauthn/{credential_id}
        elseif ($sub === 'biometrics') {
            loadCtrl('StudentLiveController');
            $ctrl = new StudentLiveController();

            // p2 = 'webauthn'
            // p3 = 'status' | 'challenge' | 'register' | numeric credential_id
            // p4 = 'challenge' | 'complete'   (when p3 === 'register')
            $p4 = $parts[4] ?? '';

            match (true) {
                // GET  student/biometrics/webauthn/status
                $method === 'GET'    && $p2 === 'webauthn' && $p3 === 'status'
                    => $ctrl->webauthnStatus(),

                // POST student/biometrics/webauthn/challenge  (authentication)
                $method === 'POST'   && $p2 === 'webauthn' && $p3 === 'challenge'
                    => $ctrl->webauthnChallenge(),

                // POST student/biometrics/webauthn/register/challenge
                $method === 'POST'   && $p2 === 'webauthn' && $p3 === 'register' && $p4 === 'challenge'
                    => $ctrl->webauthnRegisterChallenge(),

                // POST student/biometrics/webauthn/register/complete
                $method === 'POST'   && $p2 === 'webauthn' && $p3 === 'register' && $p4 === 'complete'
                    => $ctrl->webauthnRegisterComplete(),

                // DELETE student/biometrics/webauthn/{credential_id}
                $method === 'DELETE' && $p2 === 'webauthn' && is_numeric($p3)
                    => $ctrl->webauthnRevoke((int)$p3),

                default => notFound(),
            };
        }
*/

// ════════════════════════════════════════════════════════════════════════
// COMPLETE STUDENT BLOCK — copy-paste ready
// Replace the entire  elseif ($parts[0] === 'student') { ... }
// block in your api.php with this.
// ════════════════════════════════════════════════════════════════════════

/*
    elseif ($parts[0] === 'student') {
        $sub = $parts[1] ?? '';
        $p2  = $parts[2] ?? '';
        $p3  = $parts[3] ?? '';
        $p4  = $parts[4] ?? '';
        $id  = is_numeric($p2) ? (int)$p2 : null;

        // ── student/auth/* ────────────────────────────────────────────
        if ($sub === 'auth') {
            loadCtrl('StudentAuthController');
            $ctrl   = new StudentAuthController();
            $action = $p2;
            match (true) {
                $method === 'POST' && $action === 'register'            => $ctrl->register(),
                $method === 'POST' && $action === 'login'               => $ctrl->login(),
                $method === 'GET'  && $action === 'verify-email'        => $ctrl->verifyEmail(),
                $method === 'POST' && $action === 'resend-verification' => $ctrl->resendVerification(),
                $method === 'POST' && $action === 'forgot-password'     => $ctrl->forgotPassword(),
                $method === 'POST' && $action === 'reset-password'      => $ctrl->resetPassword(),
                default => notFound(),
            };
        }

        // ── student/me ───────────────────────────────────────────────
        elseif ($sub === 'me' && $method === 'GET') {
            loadCtrl('StudentProfileController');
            (new StudentProfileController())->getMe();
        }

        // ── student/profile ──────────────────────────────────────────
        elseif ($sub === 'profile') {
            loadCtrl('StudentProfileController');
            $ctrl = new StudentProfileController();
            match ($method) {
                'GET'   => $ctrl->getMe(),
                'PATCH' => $ctrl->updateProfile(),
                default => notFound(),
            };
        }

        // ── student/photo ────────────────────────────────────────────
        elseif ($sub === 'photo') {
            loadCtrl('StudentPhotoController');
            $ctrl = new StudentPhotoController();
            match ($method) {
                'POST'   => $ctrl->upload(),
                'DELETE' => $ctrl->remove(),
                default  => notFound(),
            };
        }

        // ── student/security ─────────────────────────────────────────
        elseif ($sub === 'security' && $method === 'GET') {
            loadCtrl('StudentProfileController');
            (new StudentProfileController())->getSecurity();
        }

        // ── student/audit ────────────────────────────────────────────
        elseif ($sub === 'audit' && $method === 'GET') {
            loadCtrl('StudentProfileController');
            (new StudentProfileController())->getAudit();
        }

        // ── student/device/* ─────────────────────────────────────────
        elseif ($sub === 'device') {
            loadCtrl('StudentProfileController');
            $ctrl   = new StudentProfileController();
            $action = $p2;
            match (true) {
                $method === 'POST' && $action === 'remove'         => $ctrl->removeDevice(),
                $method === 'POST' && $action === 'unbind-request' => $ctrl->requestUnbind(),
                default => notFound(),
            };
        }

        // ── student/session/* ────────────────────────────────────────
        elseif ($sub === 'session') {
            loadCtrl('StudentLiveController');
            $ctrl = new StudentLiveController();
            match (true) {
                $method === 'GET' && $p2 === 'active'                 => $ctrl->getActiveSession(),
                $method === 'GET' && $id && $p3 === 'geofence-map'    => $ctrl->getGeofenceMap($id),
                $method === 'GET' && $id && $p3 === 'qr-current'      => $ctrl->getSessionQR($id),
                $method === 'GET' && $id && $p3 === 'stats'           => $ctrl->getSessionStats($id),
                $method === 'GET' && $id && $p3 === 'recent-checkins' => $ctrl->getRecentCheckins($id),
                default => notFound(),
            };
        }

        // ── student/checkin/* ────────────────────────────────────────
        elseif ($sub === 'checkin') {
            loadCtrl('StudentLiveController');
            $live = new StudentLiveController();
            loadCtrl('StudentDataController');
            $data = new StudentDataController();
            match (true) {
                $method === 'POST' && $p2 === ''                            => $live->checkin(),
                $method === 'GET'  && $p2 === 'history'                     => $data->getHistory(),
                $method === 'GET'  && $p2 === 'receipt' && is_numeric($p3)  => $data->getReceipt((int)$p3),
                $method === 'GET'  && $p2 === 'status'                      => $live->getCheckinStatus(),
                default => notFound(),
            };
        }

        // ── student/classes/* ────────────────────────────────────────
        elseif ($sub === 'classes') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'POST' && $p2 === 'join'              => $ctrl->joinClass(),
                $method === 'GET'  && $p2 === 'preview'           => $ctrl->previewClass(),
                $method === 'GET'  && !$id                        => $ctrl->getClasses(),
                $method === 'GET'  && $id && $p3 === ''           => $ctrl->getClass($id),
                $method === 'GET'  && $id && $p3 === 'attendance' => $ctrl->getClassAttendance($id),
                default => notFound(),
            };
        }

        // ── student/attendance/* ─────────────────────────────────────
        elseif ($sub === 'attendance') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'GET' && $p2 === ''       => $ctrl->getAttendance(),
                $method === 'GET' && $p2 === 'stats'  => $ctrl->getStats(),
                $method === 'GET' && $p2 === 'streak' => $ctrl->getStreak(),
                $method === 'GET' && $p2 === 'risk'   => $ctrl->getRisk(),
                default => notFound(),
            };
        }

        // ── student/override/* ───────────────────────────────────────
        elseif ($sub === 'override') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'POST' && $p2 === 'request' => $ctrl->submitOverride(),
                $method === 'GET'  && $p2 === 'history' => $ctrl->getOverrideHistory(),
                default => notFound(),
            };
        }

        // ── student/notifications/* ──────────────────────────────────
        elseif ($sub === 'notifications') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'GET'  && $p2 === ''             => $ctrl->getNotifications(),
                $method === 'GET'  && $p2 === 'unread-count' => $ctrl->unreadCount(),
                $method === 'POST' && $p2 === 'read-all'     => $ctrl->markAllRead(),
                $method === 'POST' && $id  && $p3 === 'read' => $ctrl->markRead($id),
                default => notFound(),
            };
        }

        // ── student/geofence/* ───────────────────────────────────────
        elseif ($sub === 'geofence') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'GET' && $p2 === 'logs' => $ctrl->getGeofenceLogs(),
                default => notFound(),
            };
        }

        // ── student/biometrics/* ─────────────────────────────────────
        elseif ($sub === 'biometrics') {
            loadCtrl('StudentLiveController');
            $ctrl = new StudentLiveController();
            match (true) {
                $method === 'GET'    && $p2 === 'webauthn' && $p3 === 'status'
                    => $ctrl->webauthnStatus(),
                $method === 'POST'   && $p2 === 'webauthn' && $p3 === 'challenge'
                    => $ctrl->webauthnChallenge(),
                $method === 'POST'   && $p2 === 'webauthn' && $p3 === 'register' && $p4 === 'challenge'
                    => $ctrl->webauthnRegisterChallenge(),
                $method === 'POST'   && $p2 === 'webauthn' && $p3 === 'register' && $p4 === 'complete'
                    => $ctrl->webauthnRegisterComplete(),
                $method === 'DELETE' && $p2 === 'webauthn' && is_numeric($p3)
                    => $ctrl->webauthnRevoke((int)$p3),
                default => notFound(),
            };
        }

        else { notFound(); }
    }
*/
