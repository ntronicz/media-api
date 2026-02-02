<?php
require_once 'config.php';

// Set headers for API response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get action from query parameter
$action = $_GET['action'] ?? '';

// Function to download file from URL
function downloadFile($url) {
    try {
        $filename = UPLOAD_DIR . uniqid() . '_' . basename(parse_url($url, PHP_URL_PATH));
        
        // Use cURL for better error handling
        $ch = curl_init($url);
        $fp = fopen($filename, 'wb');
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        fclose($fp);
        
        if (!$success || $httpCode !== 200) {
            if (file_exists($filename)) unlink($filename);
            throw new Exception("Failed to download file from URL: $url (HTTP $httpCode)");
        }
        
        if (!file_exists($filename) || filesize($filename) < 100) {
            throw new Exception("Downloaded file is too small or doesn't exist");
        }
        
        writeLog("Downloaded file: $url -> $filename (" . filesize($filename) . " bytes)", 'PROCESS');
        return $filename;
        
    } catch (Exception $e) {
        throw new Exception("Download error: " . $e->getMessage());
    }
}

// Function to handle file upload
function handleUpload($fieldName) {
    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
        $filename = UPLOAD_DIR . uniqid() . '_' . basename($_FILES[$fieldName]['name']);
        move_uploaded_file($_FILES[$fieldName]['tmp_name'], $filename);
        writeLog("Uploaded file: $fieldName -> $filename", 'PROCESS');
        return $filename;
    }
    return null;
}

// Function to get file (from upload or URL)
function getFile($fieldName) {
    // Check if it's a file upload
    if (isset($_FILES[$fieldName])) {
        return handleUpload($fieldName);
    }
    
    // Check if it's a URL in POST data
    if (isset($_POST[$fieldName]) && filter_var($_POST[$fieldName], FILTER_VALIDATE_URL)) {
        return downloadFile($_POST[$fieldName]);
    }
    
    // Check JSON input
    $json = json_decode(file_get_contents('php://input'), true);
    if (isset($json[$fieldName])) {
        if (filter_var($json[$fieldName], FILTER_VALIDATE_URL)) {
            return downloadFile($json[$fieldName]);
        }
    }
    
    return null;
}

// Main try-catch block
try {
    writeLog("API request received: action=$action from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'PROCESS');
    
    switch ($action) {
        
        // ==================== MERGE AUDIO ====================
        case 'merge_audio':
            $audio1 = getFile('audio1');
            $audio2 = getFile('audio2');
            
            if (!$audio1 || !$audio2) {
                throw new Exception("Both audio1 and audio2 are required");
            }
            
            writeLog("Audio merge started: $audio1 + $audio2", 'PROCESS');
            
            $outputFile = OUTPUT_DIR . 'merged_audio_' . time() . '.mp3';
            
            // FFmpeg command to merge two audio files
            $command = sprintf(
                '%s -i %s -i %s -filter_complex "[0:a][1:a]concat=n=2:v=0:a=1[out]" -map "[out]" %s 2>&1',
                FFMPEG_PATH,
                escapeshellarg($audio1),
                escapeshellarg($audio2),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            // Clean up input files
            if (file_exists($audio1)) unlink($audio1);
            if (file_exists($audio2)) unlink($audio2);
            
            if ($returnCode !== 0 || !file_exists($outputFile)) {
                writeLog("FFmpeg error: " . implode("\n", $output), 'ERROR');
                throw new Exception("Audio merge failed: " . implode(" ", array_slice($output, -3)));
            }
            
            $fileSize = filesize($outputFile);
            writeLog("Audio merge completed: $outputFile ($fileSize bytes)", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Audio files merged successfully',
                'output_file' => basename($outputFile),
                'file_size' => $fileSize,
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        // ==================== MERGE VIDEO + AUDIO ====================
        case 'merge_video':
            $video = getFile('video');
            $audio = getFile('audio');
            
            if (!$video || !$audio) {
                throw new Exception("Both video and audio are required");
            }
            
            writeLog("Video+Audio merge started: $video + $audio", 'PROCESS');
            
            $outputFile = OUTPUT_DIR . 'merged_video_audio_' . time() . '.mp4';
            
            // FFmpeg command to merge video and audio
            $command = sprintf(
                '%s -i %s -i %s -c:v copy -c:a aac -strict experimental -map 0:v:0 -map 1:a:0 -shortest %s 2>&1',
                FFMPEG_PATH,
                escapeshellarg($video),
                escapeshellarg($audio),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            // Clean up input files
            if (file_exists($video)) unlink($video);
            if (file_exists($audio)) unlink($audio);
            
            if ($returnCode !== 0 || !file_exists($outputFile)) {
                writeLog("FFmpeg error: " . implode("\n", $output), 'ERROR');
                throw new Exception("Video+Audio merge failed: " . implode(" ", array_slice($output, -3)));
            }
            
            $fileSize = filesize($outputFile);
            writeLog("Video+Audio merge completed: $outputFile ($fileSize bytes)", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Video and audio merged successfully',
                'output_file' => basename($outputFile),
                'file_size' => $fileSize,
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        // ==================== MERGE TWO VIDEOS ====================
        case 'merge_videos':
            $video1 = getFile('video1');
            $video2 = getFile('video2');
            
            if (!$video1 || !$video2) {
                throw new Exception("Both video1 and video2 are required");
            }
            
            writeLog("Video merge started: $video1 + $video2", 'PROCESS');
            writeLog("Video1 size: " . filesize($video1) . " bytes", 'PROCESS');
            writeLog("Video2 size: " . filesize($video2) . " bytes", 'PROCESS');
            
            $outputFile = OUTPUT_DIR . 'merged_videos_' . time() . '.mp4';
            $success = false;
            $method_used = '';
            
            // ========== METHOD 1: Simple Concat (Fastest) ==========
            writeLog("Attempting Method 1: Simple concat", 'PROCESS');
            
            $listFile = UPLOAD_DIR . 'videos_list_' . time() . '.txt';
            $fileList = "file '" . str_replace("'", "'\\''", realpath($video1)) . "'\n";
            $fileList .= "file '" . str_replace("'", "'\\''", realpath($video2)) . "'";
            file_put_contents($listFile, $fileList);
            
            $command = sprintf(
                '%s -f concat -safe 0 -i %s -c copy %s 2>&1',
                FFMPEG_PATH,
                escapeshellarg($listFile),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputFile) && filesize($outputFile) > 1000) {
                $success = true;
                $method_used = 'Method 1: Simple concat (no re-encoding)';
                writeLog("✓ Method 1 succeeded", 'PROCESS');
            } else {
                writeLog("✗ Method 1 failed, trying Method 2", 'PROCESS');
                
                // ========== METHOD 2: Re-encode with Scaling ==========
                $output = [];
                if (file_exists($outputFile)) unlink($outputFile);
                
                $command = sprintf(
                    '%s -f concat -safe 0 -i %s -vf "scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1" -c:v libx264 -preset ultrafast -crf 23 -c:a aac -b:a 128k %s 2>&1',
                    FFMPEG_PATH,
                    escapeshellarg($listFile),
                    escapeshellarg($outputFile)
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0 && file_exists($outputFile) && filesize($outputFile) > 1000) {
                    $success = true;
                    $method_used = 'Method 2: Re-encode with scaling';
                    writeLog("✓ Method 2 succeeded", 'PROCESS');
                } else {
                    writeLog("✗ Method 2 failed, trying Method 3", 'PROCESS');
                    
                    // ========== METHOD 3: Filter Complex (Most Reliable) ==========
                    $output = [];
                    if (file_exists($outputFile)) unlink($outputFile);
                    
                    $command = sprintf(
                        '%s -i %s -i %s -filter_complex "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[v0];[1:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[v1];[v0][0:a:0][v1][1:a:0]concat=n=2:v=1:a=1[outv][outa]" -map "[outv]" -map "[outa]" -c:v libx264 -preset ultrafast -crf 28 -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
                        FFMPEG_PATH,
                        escapeshellarg($video1),
                        escapeshellarg($video2),
                        escapeshellarg($outputFile)
                    );
                    
                    exec($command, $output, $returnCode);
                    
                    if ($returnCode === 0 && file_exists($outputFile) && filesize($outputFile) > 1000) {
                        $success = true;
                        $method_used = 'Method 3: Filter complex with normalization';
                        writeLog("✓ Method 3 succeeded", 'PROCESS');
                    } else {
                        writeLog("✗ All methods failed", 'ERROR');
                        writeLog("Last FFmpeg output: " . implode("\n", array_slice($output, -15)), 'ERROR');
                    }
                }
            }
            
            // Clean up temporary files
            if (file_exists($listFile)) unlink($listFile);
            if (file_exists($video1)) unlink($video1);
            if (file_exists($video2)) unlink($video2);
            
            if (!$success || !file_exists($outputFile)) {
                throw new Exception("Video merge failed after trying all methods. Check logs for details.");
            }
            
            $fileSize = filesize($outputFile);
            writeLog("Video merge completed successfully: $outputFile ($fileSize bytes) using $method_used", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Videos merged successfully',
                'method' => $method_used,
                'output_file' => basename($outputFile),
                'file_size' => $fileSize,
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        // ==================== INVALID ACTION ====================
        default:
            throw new Exception("Invalid action. Available actions: merge_audio, merge_video, merge_videos");
    }
    
} catch (Exception $e) {
    writeLog("Error: " . $e->getMessage(), 'ERROR');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
