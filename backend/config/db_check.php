<?php
require_once __DIR__ . '/Database.php';

$status = Database::testConnection();

// Simple UI for the developer
echo "<html><body style='font-family:sans-serif; display:flex; justify-content:center; align-items:center; height:100vh; margin:0; background:#f0f2f5;'>";
echo "<div style='padding:2rem; background:white; border-radius:12px; shadow: 0 4px 6px rgba(0,0,0,0.1); border: 2px solid " . ($status['success'] ? '#4ade80' : '#f87171') . ";'>";
echo "<h2>EduCore DB Status</h2>";
echo "<p style='font-size:1.2rem; color:#334155;'>" . $status['message'] . "</p>";
echo "<small style='color:#94a3b8;'>Check backend/config/Database.php if this is incorrect.</small>";
echo "</div></body></html>";