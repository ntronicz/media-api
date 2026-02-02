<?php
require_once 'config.php';
checkAuth();

$logContent = '';
if (file_exists(LOG_FILE)) {
    $logContent = file_get_contents(LOG_FILE);
}

$stats = [
    'total_processes' => substr_count($logContent, '[PROCESS]'),
    'audio_merges' => substr_count($logContent, 'Audio merge started'),
    'video_audio_merges' => substr_count($logContent, 'Video+Audio merge started'),
    'video_merges' => substr_count($logContent, 'Video merge started'),
    'audio_uploads' => substr_count($logContent, 'Audio uploaded successfully'),
    'file_deletions' => substr_count($logContent, 'File deleted'),
    'errors' => substr_count($logContent, '[ERROR]')
];

// Get storage info
$totalFiles = 0;
$totalSize = 0;
if (is_dir(OUTPUT_DIR)) {
    $files = array_diff(scandir(OUTPUT_DIR), ['.', '..']);
    $totalFiles = count($files);
    foreach ($files as $file) {
        $filePath = OUTPUT_DIR . $file;
        if (is_file($filePath)) {
            $totalSize += filesize($filePath);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Media Processing API</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .header h1 { 
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-info {
            text-align: right;
            font-size: 14px;
            opacity: 0.9;
        }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            margin-top: 5px;
        }
        .logout-btn:hover { 
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .stat-card h3 {
            color: #666;
            font-size: 13px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-card.storage .number {
            color: #f093fb;
        }
        .stat-card.errors .number {
            color: #f48771;
        }
        .api-info {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .api-info h2 {
            margin-bottom: 20px;
            color: #333;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .endpoints-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 15px;
        }
        .endpoint {
            background: #f8f9fa;
            padding: 18px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            transition: all 0.3s;
        }
        .endpoint:hover {
            background: #e9ecef;
            border-left-color: #764ba2;
        }
        .endpoint strong {
            color: #333;
            font-size: 15px;
            display: block;
            margin-bottom: 8px;
        }
        .endpoint code {
            background: #e9ecef;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #495057;
            word-break: break-all;
        }
        .endpoint .params {
            margin-top: 8px;
            font-size: 13px;
            color: #6c757d;
        }
        .logs-container {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .logs-container h2 {
            margin-bottom: 15px;
            color: #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 22px;
        }
        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .refresh-btn {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        .refresh-btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        .logs {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 8px;
            max-height: 500px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.8;
        }
        .log-line {
            margin-bottom: 5px;
            word-wrap: break-word;
            padding: 2px 0;
        }
        .log-INFO { color: #4ec9b0; }
        .log-ERROR { 
            color: #f48771; 
            background: rgba(244, 135, 113, 0.1);
            padding: 4px 8px;
            border-radius: 3px;
        }
        .log-PROCESS { color: #dcdcaa; }
        .log-AUTH { color: #569cd6; }
        
        .storage-info {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }
        .storage-item {
            text-align: center;
        }
        .storage-item .label {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        .storage-item .value {
            font-size: 24px;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .stats {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            .endpoints-grid {
                grid-template-columns: 1fr;
            }
            .header h1 {
                font-size: 20px;
            }
        }
        
        /* Auto-refresh indicator */
        .refresh-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #4ec9b0;
            border-radius: 50%;
            animation: pulse 2s infinite;
            margin-left: 10px;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>
                🎵 Media Processing API
                <span class="refresh-indicator"></span>
            </h1>
        </div>
        <div class="header-info">
            <div>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></div>
            <a href="logout.php" class="logout-btn">🚪 Logout</a>
        </div>
    </div>

    <div class="storage-info">
        <div class="storage-item">
            <div class="label">📁 Total Files</div>
            <div class="value"><?php echo $totalFiles; ?></div>
        </div>
        <div class="storage-item">
            <div class="label">💾 Storage Used</div>
            <div class="value"><?php echo round($totalSize / 1024 / 1024, 2); ?> MB</div>
        </div>
        <div class="storage-item">
            <div class="label">📊 Total Processes</div>
            <div class="value"><?php echo $stats['total_processes']; ?></div>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <h3>🎵 Audio Merges</h3>
            <div class="number"><?php echo $stats['audio_merges']; ?></div>
        </div>
        <div class="stat-card">
            <h3>🎬 Video+Audio</h3>
            <div class="number"><?php echo $stats['video_audio_merges']; ?></div>
        </div>
        <div class="stat-card">
            <h3>🎥 Video Merges</h3>
            <div class="number"><?php echo $stats['video_merges']; ?></div>
        </div>
        <div class="stat-card">
            <h3>📤 Audio Uploads</h3>
            <div class="number"><?php echo $stats['audio_uploads']; ?></div>
        </div>
        <div class="stat-card">
            <h3>🗑️ File Deletions</h3>
            <div class="number"><?php echo $stats['file_deletions']; ?></div>
        </div>
        <div class="stat-card errors">
            <h3>⚠️ Errors</h3>
            <div class="number"><?php echo $stats['errors']; ?></div>
        </div>
    </div>

    <div class="api-info">
        <h2>🔌 API Endpoints</h2>
        <div class="endpoints-grid">
            <div class="endpoint">
                <strong>1️⃣ Merge Two Audios</strong><br>
                <code>POST /process.php?action=merge_audio</code>
                <div class="params">📋 Parameters: <code>audio1</code>, <code>audio2</code> (file/URL)</div>
            </div>
            
            <div class="endpoint">
                <strong>2️⃣ Merge Video + Audio</strong><br>
                <code>POST /process.php?action=merge_video</code>
                <div class="params">📋 Parameters: <code>video</code>, <code>audio</code> (file/URL)</div>
            </div>
            
            <div class="endpoint">
                <strong>3️⃣ Merge Two Videos</strong><br>
                <code>POST /process.php?action=merge_videos</code>
                <div class="params">📋 Parameters: <code>video1</code>, <code>video2</code> (file/URL)</div>
            </div>
            
            <div class="endpoint">
                <strong>4️⃣ Upload Audio File</strong><br>
                <code>POST /process.php?action=upload_audio</code>
                <div class="params">📋 Parameters: <code>audio</code> (file) OR <code>audio_url</code> (URL)</div>
            </div>
            
            <div class="endpoint">
                <strong>5️⃣ Delete Single File</strong><br>
                <code>POST /process.php?action=delete_file</code>
                <div class="params">📋 Parameters: <code>filename</code> (string)</div>
            </div>
            
            <div class="endpoint">
                <strong>6️⃣ Delete Multiple Files</strong><br>
                <code>POST /process.php?action=delete_multiple</code>
                <div class="params">📋 Parameters: <code>filenames</code> (array)</div>
            </div>
            
            <div class="endpoint">
                <strong>7️⃣ List All Files</strong><br>
                <code>GET/POST /process.php?action=list_files</code>
                <div class="params">📋 No parameters required</div>
            </div>
        </div>
    </div>

    <div class="logs-container">
        <div class="logs-header">
            <h2>📜 Live Process Logs</h2>
            <button class="refresh-btn" onclick="location.reload()">
                🔄 Refresh Logs
            </button>
        </div>
        <div class="logs">
            <?php
            $logs = getRecentLogs(150);
            if (empty($logs)) {
                echo '<div class="log-line log-INFO">🎉 No logs yet. API is ready to process requests!</div>';
            } else {
                foreach ($logs as $log) {
                    $log = htmlspecialchars($log);
                    $class = 'log-INFO';
                    
                    if (strpos($log, 'ERROR') !== false) {
                        $class = 'log-ERROR';
                        $icon = '❌ ';
                    } elseif (strpos($log, 'PROCESS') !== false) {
                        $class = 'log-PROCESS';
                        $icon = '⚙️ ';
                    } elseif (strpos($log, 'AUTH') !== false) {
                        $class = 'log-AUTH';
                        $icon = '🔐 ';
                    } else {
                        $icon = 'ℹ️ ';
                    }
                    
                    echo '<div class="log-line ' . $class . '">' . $icon . $log . '</div>';
                }
            }
            ?>
        </div>
    </div>

    <script>
        // Auto-refresh every 5 seconds
        setTimeout(function() {
            location.reload();
        }, 5000);
        
        // Smooth scroll to bottom of logs on load
        window.addEventListener('load', function() {
            const logsDiv = document.querySelector('.logs');
            logsDiv.scrollTop = logsDiv.scrollHeight;
        });
    </script>
</body>
</html>
