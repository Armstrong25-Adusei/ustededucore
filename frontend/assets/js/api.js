<?php
/**
 * EduCore — api.php  v9.0
 * ─────────────────────────────────────────────────────────────────────────────
 * Single entry point.  All routes below are handled here.
 *
 * LECTURER AUTH
 *   POST  auth/register
 *   POST  auth/login
 *   POST  auth/logout
 *   GET   auth/me
 *   GET   auth/verify-email?token=…
 *   POST  auth/resend-verification
 *   POST  auth/forgot-password
 *   POST  auth/reset-password
 *
 * INSTITUTIONS (public)
 *   GET   institutions/search?q=…
 *
 * STUDENT AUTH
 *   POST  student/auth/register
 *   POST  student/auth/login
 *   GET   student/auth/verify-email?token=…
 *   POST  student/auth/resend-verification
 *   POST  student/auth/forgot-password
 *   POST  student/auth/reset-password
 *
 * STUDENT PROFILE  (JWT required)
 *   GET   student/me
 *   GET   student/profile
 *   PATCH student/profile
 *   GET   student/security
 *   POST  student/device/remove
 *   POST  student/device/unbind-request
 *   GET   student/audit
 *   POST  student/photo               — upload profile photo (multipart/form-data)
 *   DELETE student/photo              — remove profile photo
 *
 * STUDENT DATA  (JWT required)
 *   GET   student/session/active
 *   GET   student/session/{id}/geofence-map
 *   GET   student/session/{id}/qr-current
 *   POST  student/checkin
 *   GET   student/checkin/history
 *   GET   student/checkin/receipt/{id}
 *   GET   student/classes
 *   GET   student/classes/{id}
 *   GET   student/classes/{id}/attendance
 *   GET   student/attendance
 *   GET   student/attendance/stats
 *   GET   student/attendance/streak
 *   GET   student/attendance/risk
 *   POST  student/override/request
 *   GET   student/override/history
 *   GET   student/notifications
 *   GET   student/notifications/unread-count
 *   POST  student/notifications/{id}/read
 *   POST  student/notifications/read-all
 *   GET   student/geofence/logs
 *
 * LECTURER PROFILE & PHOTO  (lecturer JWT required)
 *   GET   lecturer/profile            — fetch editable profile fields
 *   PATCH lecturer/profile            — update full_name, email, phone, title, bio
 *   POST  lecturer/photo               — upload profile photo (multipart/form-data)
 *                                        validates MIME+size, re-encodes via GD, strips EXIF
 *                                        returns { photo_url, photo_updated_at }
 *   DELETE lecturer/photo              — remove current profile photo
 *                                        deletes file from disk, NULLs profile_photo in DB
 *
 * LECTURER DASHBOARD / CLASSES / STUDENTS / MASTER-LIST / ATTENDANCE
 * SESSIONS / OVERRIDE / SYNC / REPORTS / SETTINGS / AUDIT / NOTIFICATIONS
 * ACTIVITIES  — (lecturer-scoped routes)
 *
 * SESSIONS (lecturer) — additional list endpoint
 *   GET   sessions                     — list all sessions (filter: ?class_id=)
 *
 * COURSE MESSAGES  (lecturer JWT required)
 *   GET   messages/unread-counts       — { channels: [{ class_id, unread }] }
 *   GET   messages/{class_id}          — paginated (?since=lastId&limit=40)
 *   POST  messages/{class_id}          — send { body, is_broadcast, parent_id }
 *   POST  messages/{class_id}/read     — mark read { up_to_message_id }
 *   POST  messages/{msg_id}/react      — toggle emoji reaction { emoji }
 *   DELETE messages/{msg_id}           — soft-delete (lecturer only)
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

// ── Load .env ──────────────────────────────────────────────────────────────
// Try .env in current dir first, then parent dirs
$_envPaths = [__DIR__.'/.env', __DIR__.'/../.env', __DIR__.'/../../.env'];
foreach ($_envPaths as $_envFile) {
    if (file_exists($_envFile)) {
        foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
            if (str_starts_with(trim($_line), '#') || !str_contains($_line, '=')) continue;
            [$_key, $_val] = explode('=', $_line, 2) + ['', ''];
            $_ENV[trim($_key)] = trim($_val);
        }
        break;
    }
}
unset($_envPaths, $_envFile, $_line, $_key, $_val);

// ── CORS ───────────────────────────────────────────────────────────────────
// Always send CORS headers — required for Vercel (cross-origin) frontend
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Allowed origins: InfinityFree + Vercel deployments
$allowedOrigins = array_filter(array_map('trim', explode(',', $_ENV['CORS_ORIGINS'] ?? '*')));

$isAllowed = in_array('*', $allowedOrigins, true)
    || in_array($origin, $allowedOrigins, true)
    || str_ends_with($origin, '.vercel.app')        // allow any Vercel deployment
    || str_ends_with($origin, '.rf.gd')              // allow InfinityFree subdomains
    || $origin === 'https://ustededucore.vercel.app'; // explicit Vercel URL

if ($isAllowed && $origin) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
} else {
    // Fallback: allow all (safe for testing, tighten in production)
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Access-Control-Expose-Headers: Content-Type');

// Handle preflight OPTIONS request immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


header('Content-Type: application/json; charset=UTF-8');

// ── Shared dependencies ────────────────────────────────────────────────────
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Config.php';
require_once __DIR__ . '/config/jwt.php';
require_once __DIR__ . '/utils/JWT.php';
require_once __DIR__ . '/config/TriggerCompat.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

// ── Parse route ────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Fallback to PATH_INFO if REQUEST_URI isn't giving us the path info
if (!$uri || $uri === '/www.educore.com/backend/api.php') {
    $uri = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'];
}

$path = trim(preg_replace([
    '#^.*?api\.php#',
    '#^/?api/?#',
], '', $uri), '/');

$parts = explode('/', $path);  // e.g. ['student', 'session', 'active']

function loadCtrl(string $name): void {
    require_once __DIR__ . "/controllers/{$name}.php";
}

try {

    // ══════════════════════════════════════════════════════════════════
    // PING (health check — no auth required)
    // ══════════════════════════════════════════════════════════════════
    if ($parts[0] === 'ping') {
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════
    // INSTITUTIONS (public)
    // ══════════════════════════════════════════════════════════════════
    if ($parts[0] === 'institutions') {
        loadCtrl('InstitutionController');
        $ctrl = new InstitutionController();
        $sub  = $parts[1] ?? '';
        match (true) {
            $method === 'GET' && $sub === 'search' => $ctrl->search(),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // LECTURER AUTH  (auth/*)
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'auth') {
        loadCtrl('AuthController');
        $ctrl = new AuthController();
        $sub  = $parts[1] ?? '';
        match (true) {
            $method === 'POST' && $sub === 'register'            => $ctrl->register(),
            $method === 'POST' && $sub === 'login'               => $ctrl->login(),
            $method === 'POST' && $sub === 'logout'              => $ctrl->logout(),
            $method === 'GET'  && $sub === 'me'                  => $ctrl->me(),
            $method === 'GET'  && $sub === 'verify-email'        => $ctrl->verifyEmail(),
            $method === 'POST' && $sub === 'resend-verification' => $ctrl->resendVerification(),
            $method === 'POST' && $sub === 'forgot-password'     => $ctrl->forgotPassword(),
            $method === 'POST' && $sub === 'reset-password'      => $ctrl->resetPassword(),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // LECTURER  (lecturer/*)
    //
    // POST   lecturer/photo    — multipart upload; validates MIME+size, re-encodes via GD,
    //                            strips EXIF, stores safe filename, updates lecturers table.
    //                            Returns { photo_url, photo_updated_at }
    // DELETE lecturer/photo    — deletes file from disk, NULLs profile_photo + _updated_at
    //
    // PATCH  lecturer/profile  — update editable profile fields (full_name, email,
    //                            phone, title, bio).  staff_id, institution_id,
    //                            department_id are read-only here.
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'lecturer') {
        $lecSub = $parts[1] ?? '';

        if ($lecSub === 'photo') {
            loadCtrl('LecturerPhotoController');
            $ctrl = new LecturerPhotoController();
            match ($method) {
                'POST'   => $ctrl->upload(),
                'DELETE' => $ctrl->remove(),
                default  => notFound(),
            };
        } elseif ($lecSub === 'profile') {
            // PATCH lecturer/profile — update name / email / phone / title / bio
            loadCtrl('LecturerProfileController');
            $ctrl = new LecturerProfileController();
            match ($method) {
                'GET'   => $ctrl->show(),
                'PATCH' => $ctrl->update(),
                default => notFound(),
            };
        } elseif ($lecSub === 'messages') {
            // Lecturer DM helpers used by lec-messages.html
            // Routes:
            //   GET  lecturer/messages/dms
            //   GET  lecturer/messages/students
            //   POST lecturer/messages/dm/start
            //   GET  lecturer/messages/dm/{dm_id}
            //   POST lecturer/messages/dm/{dm_id}/send
            loadCtrl('CourseMessageController');
            $ctrl = new CourseMessageController();
            $p2   = $parts[2] ?? '';
            $p3   = $parts[3] ?? '';
            $p4   = $parts[4] ?? '';
            match (true) {
                $method === 'GET'  && $p2 === 'dms'                              => $ctrl->getDirectMessages(),
                $method === 'GET'  && $p2 === 'students'                         => $ctrl->getDmStudents(),
                $method === 'POST' && $p2 === 'dm' && $p3 === 'start'            => $ctrl->startDm(),
                $method === 'GET'  && $p2 === 'dm' && is_numeric($p3)            => $ctrl->getDmThread((int)$p3),
                $method === 'POST' && $p2 === 'dm' && is_numeric($p3) && $p4 === 'send'
                                                                                => $ctrl->sendDm((int)$p3),
                default => notFound(),
            };
        } else {
            notFound();
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ALL STUDENT ROUTES  (student/*)
    // parts: [0]=>'student', [1]=>sub, [2]=>id_or_action, [3]=>action
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'student') {
        $sub    = $parts[1] ?? '';   // 'auth' | 'me' | 'profile' | 'security' | 'device' |
                                     // 'session' | 'checkin' | 'classes' | 'attendance' |
                                     // 'override' | 'notifications' | 'geofence' | 'audit'
        $p2     = $parts[2] ?? '';   // numeric id, 'active', 'stats', etc.
        $p3     = $parts[3] ?? '';   // 'attendance', 'read', etc.
        $id     = is_numeric($p2) ? (int)$p2 : null;

        // ── student/auth/* ────────────────────────────────────────────
        if ($sub === 'auth') {
            loadCtrl('StudentAuthController');
            $ctrl   = new StudentAuthController();
            $action = $p2; // register | login | verify-email | resend-verification | forgot-password | reset-password
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

        // ── student/me  ───────────────────────────────────────────────
        elseif ($sub === 'me' && $method === 'GET') {
            loadCtrl('StudentProfileController');
            (new StudentProfileController())->getMe();
        }

        // ── student/profile  ──────────────────────────────────────────
        elseif ($sub === 'profile') {
            loadCtrl('StudentProfileController');
            $ctrl = new StudentProfileController();
            match ($method) {
                'GET'   => $ctrl->getMe(),
                'PATCH' => $ctrl->updateProfile(),
                default => notFound(),
            };
        }

        // ── student/security  ─────────────────────────────────────────
        elseif ($sub === 'security' && $method === 'GET') {
            loadCtrl('StudentProfileController');
            (new StudentProfileController())->getSecurity();
        }

        // ── student/audit  ────────────────────────────────────────────
        elseif ($sub === 'audit' && $method === 'GET') {
            loadCtrl('StudentProfileController');
            (new StudentProfileController())->getAudit();
        }

        // ── student/device/*  ─────────────────────────────────────────
        elseif ($sub === 'device') {
            loadCtrl('StudentProfileController');
            $ctrl   = new StudentProfileController();
            $action = $p2; // 'remove' | 'unbind-request'
            match (true) {
                $method === 'POST' && $action === 'remove'         => $ctrl->removeDevice(),
                $method === 'POST' && $action === 'unbind-request' => $ctrl->requestUnbind(),
                default => notFound(),
            };
        }

        // ── student/photo  ────────────────────────────────────────────
        elseif ($sub === 'photo') {
            loadCtrl('StudentPhotoController');
            $ctrl = new StudentPhotoController();
            match ($method) {
                'POST'   => $ctrl->upload(),
                'DELETE' => $ctrl->remove(),
                default  => notFound(),
            };
        }

        // ── student/session/*  ───────────────────────────────────────
        elseif ($sub === 'session') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            // p2 = 'active' OR numeric session_id
            // p3 = 'geofence-map' | 'qr-current'
            match (true) {
                $method === 'GET' && $p2 === 'active'                  => $ctrl->getActiveSession(),
                $method === 'GET' && $id && $p3 === 'geofence-map'     => $ctrl->getGeofenceMap($id),
                $method === 'GET' && $id && $p3 === 'qr-current'       => $ctrl->getSessionQR($id),
                default => notFound(),
            };
        }

        // ── student/checkin/*  ───────────────────────────────────────
        elseif ($sub === 'checkin') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            // POST checkin  /  GET checkin/history  /  GET checkin/receipt/{id}
            $p4   = $parts[4] ?? ''; // receipt id if needed
            match (true) {
                $method === 'POST' && $p2 === ''          => $ctrl->checkin(),
                $method === 'GET'  && $p2 === 'history'   => $ctrl->getHistory(),
                $method === 'GET'  && $p2 === 'receipt' && is_numeric($p3)
                                                          => $ctrl->getReceipt((int)$p3),
                default => notFound(),
            };
        }

        // ── student/classes/*  ───────────────────────────────────────
        elseif ($sub === 'classes') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'GET' && !$id                          => $ctrl->getClasses(),
                $method === 'GET' && $id && $p3 === ''             => $ctrl->getClass($id),
                $method === 'GET' && $id && $p3 === 'attendance'   => $ctrl->getClassAttendance($id),
                default => notFound(),
            };
        }

        // ── student/attendance/*  ────────────────────────────────────
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

        // ── student/override/*  ──────────────────────────────────────
        elseif ($sub === 'override') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'POST' && $p2 === 'request' => $ctrl->submitOverride(),
                $method === 'GET'  && $p2 === 'history' => $ctrl->getOverrideHistory(),
                default => notFound(),
            };
        }

        // ── student/notifications/*  ─────────────────────────────────
        elseif ($sub === 'notifications') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'GET'  && $p2 === ''               => $ctrl->getNotifications(),
                $method === 'GET'  && $p2 === 'unread-count'   => $ctrl->unreadCount(),
                $method === 'POST' && $p2 === 'read-all'       => $ctrl->markAllRead(),
                $method === 'POST' && $id  && $p3 === 'read'   => $ctrl->markRead($id),
                default => notFound(),
            };
        }

        // ── student/geofence/*  ──────────────────────────────────────
        elseif ($sub === 'geofence') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            match (true) {
                $method === 'GET' && $p2 === 'logs' => $ctrl->getGeofenceLogs(),
                default => notFound(),
            };
        }

        // ── student/messages/*  ──────────────────────────────────────
        // Route map:
        //   GET  student/messages                   → getMessageChannels()  [unified list]
        //   GET  student/messages/dms               → getDirectMessages()
        //   GET  student/messages/members           → getMessageMembers()
        //   POST student/messages/react             → reactToMessage()       [NEW]
        //   POST student/messages/delete            → deleteMessage()
        //   POST student/messages/dm/start          → startDirectMessage()
        //   GET  student/messages/dm/{id}           → getDirectMessageThread(id)
        //   POST student/messages/dm/{id}/send      → sendDirectMessage(id)
        //   GET  student/messages/{class_id}        → getMessages(id)
        //   POST student/messages/{class_id}        → sendMessage(id)
        //   POST student/messages/{class_id}/read   → markChannelRead(id)   [NEW]
        elseif ($sub === 'messages') {
            loadCtrl('StudentDataController');
            $ctrl = new StudentDataController();
            $p4 = $parts[4] ?? '';
            match (true) {
                // Unified list (channels + DMs merged, sorted by last_time)
                $method === 'GET'  && $p2 === ''
                    => $ctrl->getMessageChannels(),

                // DM thread list
                $method === 'GET'  && $p2 === 'dms'
                    => $ctrl->getDirectMessages(),

                // Members for DM picker
                $method === 'GET'  && $p2 === 'members'
                    => $ctrl->getMessageMembers(),

                // Toggle emoji reaction — POST student/messages/react
                // Body: { message_id, emoji, context_type: 'channel'|'dm' }
                // Returns: { success, emoji, count, reacted_by_me }
                $method === 'POST' && $p2 === 'react'
                    => $ctrl->reactToMessage(),

                // Soft-delete — POST student/messages/delete
                // Body: { message_id, context_type: 'channel'|'dm' }
                $method === 'POST' && $p2 === 'delete'
                    => $ctrl->deleteMessage(),

                // Start or retrieve DM thread
                $method === 'POST' && $p2 === 'dm' && $p3 === 'start'
                    => $ctrl->startDirectMessage(),

                // DM thread messages — GET student/messages/dm/{thread_id}?since={lastId}
                $method === 'GET'  && $p2 === 'dm' && is_numeric($p3)
                    => $ctrl->getDirectMessageThread((int)$p3),

                // Send DM — POST student/messages/dm/{thread_id}/send
                $method === 'POST' && $p2 === 'dm' && is_numeric($p3) && $p4 === 'send'
                    => $ctrl->sendDirectMessage((int)$p3),

                // Channel messages — GET student/messages/{class_id}?since={lastId}
                $method === 'GET'  && $id
                    => $ctrl->getMessages($id),

                // Send to channel — POST student/messages/{class_id}
                $method === 'POST' && $id && $p3 === ''
                    => $ctrl->sendMessage($id),

                // Mark channel read — POST student/messages/{class_id}/read
                // Body: { up_to_message_id }  — clears unread badge
                $method === 'POST' && $id && $p3 === 'read'
                    => $ctrl->markChannelRead($id),

                default => notFound(),
            };
        }

        else { notFound(); }
    }
    

    // ══════════════════════════════════════════════════════════════════
    // LECTURER DASHBOARD
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'dashboard') {
        loadCtrl('DashboardController');
        if ($method === 'GET') (new DashboardController())->index();
        else notFound();
    }

    // ══════════════════════════════════════════════════════════════════
    // CLASSES
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'classes') {
        loadCtrl('ClassController');
        $ctrl = new ClassController();
        $id   = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
        $sub  = $parts[2] ?? null;
        match (true) {
            $method === 'GET'    && !$id                        => $ctrl->index(),
            $method === 'POST'   && !$id                        => $ctrl->create(),
            $method === 'GET'    && $id && !$sub                => $ctrl->show($id),
            $method === 'PUT'    && $id && !$sub                => $ctrl->update($id),
            $method === 'DELETE' && $id && !$sub                => $ctrl->delete($id),
            $method === 'POST'   && $id && $sub === 'regen'     => $ctrl->regenJoinCode($id),
            $method === 'GET'    && $id && $sub === 'students'  => $ctrl->students($id),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // STUDENTS (lecturer-scoped roster)
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'students') {
        loadCtrl('StudentController');
        $ctrl = new StudentController();
        $id   = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
        $slug = $parts[1] ?? null;
        $sub  = $parts[2] ?? null;
        $act  = $parts[3] ?? null;
        match (true) {
            $method === 'GET'    && !$id && $slug !== 'lookup'                         => $ctrl->index(),
            $method === 'GET'    && $slug === 'lookup'                                 => $ctrl->lookup(),
            $method === 'GET'    && $id && !$sub                                       => $ctrl->show($id),
            $method === 'PATCH'  && $id && !$sub                                       => $ctrl->updateStatus($id),
            $method === 'GET'    && $id && $sub === 'biometrics' && !$act              => $ctrl->biometricStatus($id),
            $method === 'POST'   && $id && $sub === 'biometrics' && $act === 'fingerprint' => $ctrl->enrollFingerprint($id),
            $method === 'POST'   && $id && $sub === 'biometrics' && $act === 'face'    => $ctrl->enrollFace($id),
            $method === 'DELETE' && $id && $sub === 'biometrics'                       => $ctrl->deleteBiometrics($id),
            // Lecturer device reset for a student
            $method === 'POST'   && $id && $sub === 'devices' && $act === 'reset'      => $ctrl->resetStudentDevices($id),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // MASTER-LIST
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'master-list') {
        loadCtrl('MasterListController');
        $ctrl = new MasterListController();
        $id   = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
        $sub  = $parts[1] ?? null;
        match (true) {
            $method === 'GET'    && !$id             => $ctrl->index(),
            $method === 'POST'   && $sub === 'upload' => $ctrl->upload(),
            $method === 'DELETE' && $id              => $ctrl->delete($id),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // ATTENDANCE (lecturer-scoped)
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'attendance') {
        loadCtrl('AttendanceController');
        $ctrl   = new AttendanceController();
        $sub    = $parts[1] ?? '';
        $action = $parts[2] ?? null;
        $id     = is_numeric($action ?? '') ? (int)$action : null;
        match (true) {
            $method === 'POST' && $sub === 'session' && $action === 'open'                    => $ctrl->openSession(),
            $method === 'POST' && $sub === 'session' && $action === 'close' && isset($parts[3]) => $ctrl->closeSession((int)$parts[3]),
            $method === 'GET'  && $sub === 'session' && $action === 'open'                    => $ctrl->getOpenSessions(),
            $method === 'GET'  && $sub === 'session' && $id                                   => $ctrl->sessionDetail($id),
            $method === 'POST' && $sub === 'record'  && !$id                                  => $ctrl->recordAttendance(),
            $method === 'PATCH'&& $sub === 'record'  && $id                                   => $ctrl->editRecord($id),
            $method === 'GET'  && $sub === 'summary'                                          => $ctrl->summary(),
            $method === 'GET'  && $sub === 'heatmap'                                          => $ctrl->heatmap(),
            $method === 'GET'  && $sub === 'sync' && $action === 'pending'                    => $ctrl->pendingSync(),
            $method === 'POST' && $sub === 'sync' && $action === 'resolve'                    => $ctrl->resolveSync(),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // SESSIONS (live — SessionController)
    // GET  sessions            — list sessions; optional ?class_id= filter
    // GET  sessions/{id}       — single session detail
    // POST sessions/{id}/close — end a session
    // ... (other sub-actions below)
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'sessions') {
        loadCtrl('SessionController');
        $ctrl = new SessionController();
        $id   = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
        $sub  = $parts[2] ?? null;
        // GET /sessions or GET /sessions/{id}/*  — all other verbs require an id
        if (!$id && $method !== 'GET') notFound();
        match (true) {
            $method === 'GET'   && !$id                         => $ctrl->index(),
            $method === 'GET'   && $id && !$sub                 => $ctrl->show($id),
            $method === 'POST'  && $id && $sub === 'close'      => $ctrl->close($id),
            $method === 'POST'  && $id && $sub === 'extend'     => $ctrl->extend($id),
            $method === 'POST'  && $id && $sub === 'refresh-geofence'
                                                                => $ctrl->refreshGeofence($id, json_decode(file_get_contents('php://input'), true) ?? []),
            $method === 'PATCH' && $id && !$sub                 => $ctrl->update($id, json_decode(file_get_contents('php://input'), true) ?? []),
            $method === 'GET'   && $id && $sub === 'current-code'     => $ctrl->currentCode($id),
            $method === 'GET'   && $id && $sub === 'recent-checkins'  => $ctrl->recentCheckins($id),
            $method === 'GET'   && $id && $sub === 'live-stats'       => $ctrl->liveStats($id),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // OVERRIDE (lecturer)
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'override') {
        loadCtrl('OverrideController');
        $ctrl   = new OverrideController();
        $sub    = $parts[1] ?? '';
        $id     = is_numeric($sub) ? (int)$sub : null;
        $action = $parts[2] ?? null;
        $sessId = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : null;
        match (true) {
            $method === 'POST' && $sub === 'request'                    => $ctrl->submitRequest(),
            $method === 'GET'  && $sub === 'pending'                    => $ctrl->getPending(),
            $method === 'POST' && $id && $action === 'approve'          => $ctrl->approve($id),
            $method === 'POST' && $id && $action === 'reject'           => $ctrl->reject($id),
            $method === 'GET'  && $sub === 'session' && $sessId         => $ctrl->sessionHistory($sessId),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // SYNC
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'sync') {
        loadCtrl('SyncController');
        $ctrl = new SyncController();
        $id   = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
        $sub  = $parts[1] ?? null;
        $act  = $parts[2] ?? null;
        match (true) {
            $method === 'GET'  && !$id && $sub !== 'clear-failed' => $ctrl->index(),
            $method === 'POST' && !$id && $sub !== 'clear-failed' => $ctrl->push(),
            $method === 'POST' && $id  && $act === 'retry'        => $ctrl->retry($id),
            $method === 'POST' && $sub === 'clear-failed'         => $ctrl->clearFailed(),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // REPORTS
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'reports') {
        loadCtrl('ReportController');
        $ctrl = new ReportController();
        $sub  = $parts[1] ?? '';
        match (true) {
            $method === 'GET' && $sub === 'summary' => $ctrl->summary(),
            $method === 'GET' && $sub === 'heatmap' => $ctrl->heatmap(),
            $method === 'GET' && $sub === 'risk'    => $ctrl->riskStudents(),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // SETTINGS
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'settings') {
        loadCtrl('SettingsController');
        $ctrl = new SettingsController();
        match ($method) {
            'GET'  => $ctrl->index(),
            'POST' => $ctrl->save(),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // AUDIT
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'audit') {
        loadCtrl('AuditController');
        if ($method === 'GET') (new AuditController())->index();
        else notFound();
    }

    // ══════════════════════════════════════════════════════════════════
    // NOTIFICATIONS (lecturer)
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'notifications') {
        loadCtrl('NotificationController');
        $ctrl = new NotificationController();
        $id   = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
        $sub  = $parts[1] ?? null;
        $act  = $parts[2] ?? null;
        match (true) {
            $method === 'GET'  && $sub === 'unread-count'       => $ctrl->unreadCount(),
            $method === 'GET'  && !$id && $sub !== 'read-all' => $ctrl->index(),
            $method === 'POST' && $id  && $act === 'read'     => $ctrl->markRead($id),
            $method === 'POST' && $sub === 'read-all'         => $ctrl->markAllRead(),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTIVITIES
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'activities') {
        loadCtrl('ActivityController');
        $ctrl = new ActivityController();
        $id   = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
        match (true) {
            $method === 'GET'    && !$id => $ctrl->index(),
            $method === 'POST'   && !$id => $ctrl->create(),
            $method === 'GET'    && $id  => $ctrl->show($id),
            $method === 'PUT'    && $id  => $ctrl->update($id),
            $method === 'DELETE' && $id  => $ctrl->delete($id),
            default => notFound(),
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // COURSE MESSAGES  (lecturer JWT required)
    // ══════════════════════════════════════════════════════════════════
    // Route layout:
    //   GET    messages/unread-counts              → unread badge data for all channels
    //   GET    messages/{class_id}?since=&limit=   → paginated message history
    //   POST   messages/{class_id}                 → send a message
    //   POST   messages/{class_id}/read            → mark read up to message_id
    //   POST   messages/{msg_id}/react             → toggle emoji reaction
    //   DELETE messages/{msg_id}                   → soft-delete (lecturer only)
    //
    // Ambiguity resolution: parts[1] is EITHER a class_id (numeric, no sub)
    // or a message_id (numeric, sub = 'react').
    // 'unread-counts' is a string sentinel handled first.
    // ══════════════════════════════════════════════════════════════════
    elseif ($parts[0] === 'messages') {
        loadCtrl('CourseMessageController');
        $ctrl   = new CourseMessageController();
        $p1     = $parts[1] ?? '';          // class_id | msg_id | 'unread-counts'
        $p2     = $parts[2] ?? '';          // 'read' | 'react' | ''
        $numId  = is_numeric($p1) ? (int)$p1 : null;

        match (true) {
            // GET messages/unread-counts
            $method === 'GET'    && $p1 === 'unread-counts'
                => $ctrl->unreadCounts(),

            // GET messages/{class_id}?since=&limit=
            $method === 'GET'    && $numId && $p2 === ''
                => $ctrl->index($numId),

            // POST messages/{class_id}   — send a message
            $method === 'POST'   && $numId && $p2 === ''
                => $ctrl->send($numId),

            // POST messages/{class_id}/read   — batch mark read
            $method === 'POST'   && $numId && $p2 === 'read'
                => $ctrl->markRead($numId),

            // POST messages/{msg_id}/react   — toggle reaction
            $method === 'POST'   && $numId && $p2 === 'react'
                => $ctrl->react($numId),

            // DELETE messages/{msg_id}   — soft-delete
            $method === 'DELETE' && $numId && $p2 === ''
                => $ctrl->delete($numId),

            default => notFound(),
        };
    }

    else { notFound(); }

} catch (Throwable $e) {
    error_log('[EduCore API] Unhandled: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected server error occurred.']);
}

function notFound(): never {
    http_response_code(404);
    echo json_encode(['error' => 'API endpoint not found.']);
    exit;
}
