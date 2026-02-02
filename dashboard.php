<?php
require_once 'config.php';
checkAuth();

$stats = [
    'total_processes' => substr_count(file_get_contents(LOG_FILE), '[PROCESS]'),
    'audio_merges' => substr_count(file_get_contents(LOG_FILE), 'Audio merge'),
    'video_merges' => substr_count(file_get_contents(LOG_FILE), 'Video merge'),
    'errors' => substr_count(file_get_contents(LOG_FILE), '[ERROR]')
];
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
        }
        .header h1 { font-size: 24px; }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
        }
        .logout-btn:hover { background: rgba(255,255,255,0.3); }
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
        }
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .api-info {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .api-info h2 {
            margin-bottom: 15px;
            color: #333;
        }
        .endpoint {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #667eea;
        }
        .endpoint code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
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
        }
        .refresh-btn {
            background: #667eea;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .logs {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 5px;
            max-height: 500px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
        }
        .log-line {
            margin-bottom: 5px;
            word-wrap: break-word;
        }
        .log-INFO { color: #4ec9b0; }
        .log-ERROR { color: #f48771; }
        .log-PROCESS { color: #dcdcaa; }
        .log-AUTH { color: #569cd6; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎵 Media Processing Dashboard</h1>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <div class="stats">
        <div class="stat-card">
            <h3>Total Processes</h3>
            <div class="number"><?php echo $stats['total_processes']; ?></div>
        </div>
        <div class="stat-card">
            <h3>Audio Merges</h3>
            <div class="number"><?php echo $stats['audio_merges']; ?></div>
        </div>
        <div class="stat-card">
            <h3>Video Merges</h3>
            <div class="number"><?php echo $stats['video_merges']; ?></div>
        </div>
        <div class="stat-card">
            <h3>Errors</h3>
            <div class="number"><?php echo $stats['errors']; ?></div>
        </div>
    </div>

    <div class="api-info">
        <h2>API Endpoints</h2>
        <div class="endpoint">
            <strong>Merge Two Audios:</strong><br>
            <code>POST <?php echo $_SERVER['HTTP_HOST']; ?>/api/process.php?action=merge_audio</code><br>
            Parameters: <code>audio1</code> (file/URL), <code>audio2</code> (file/URL)
        </div>
        <div class="endpoint">
            <strong>Merge Video + Audio:</strong><br>
            <code>POST <?php echo $_SERVER['HTTP_HOST']; ?>/api/process.php?action=merge_video</code><br>
            Parameters: <code>video</code> (file/URL), <code>audio</code> (file/URL)
        </div>
    </div>

    <div class="logs-container">
        <h2>
            Live Process Logs
            <button class="refresh-btn" onclick="location.reload()">🔄 Refresh</button>
        </h2>
        <div class="logs">
            <?php
            $logs = getRecentLogs(100);
            if (empty($logs)) {
                echo '<div class="log-line">No logs yet...</div>';
            } else {
                foreach ($logs as $log) {
                    $log = htmlspecialchars($log);
                    $class = 'log-INFO';
                    if (strpos($log, 'ERROR') !== false) $class = 'log-ERROR';
                    elseif (strpos($log, 'PROCESS') !== false) $class = 'log-PROCESS';
                    elseif (strpos($log, 'AUTH') !== false) $class = 'log-AUTH';
                    
                    echo '<div class="log-line ' . $class . '">' . $log . '</div>';
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
    </script>
</body>
</html>
