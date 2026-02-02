<?php
require_once 'config.php';

// Set headers for API response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Get action from query parameter
$action = $_GET['action'] ?? '';

// Function to download file from URL
function downloadFile($url) {
    $filename = UPLOAD_DIR . uniqid() . '_' . basename(parse_url($url, PHP_URL_PATH));
    $file = @file_get_contents($url);
    if ($file === false) {
        throw new Exception("Failed to download file from URL: $url");
    }
    file_put_contents($filename, $file);
    return $filename;
}

// Function to handle file upload
function handleUpload($fieldName) {
    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
        $filename = UPLOAD_DIR . uniqid() . '_' . basename($_FILES[$fieldName]['name']);
        move_uploaded_file($_FILES[$fieldName]['tmp_name'], $filename);
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
    if (isset($json[$fieldName]) && filter_var($json[$fieldName], FILTER_VALIDATE_URL)) {
        return downloadFile($json[$fieldName]);
    }
    
    return null;
}

try {
    writeLog("API request received: action=$action", 'PROCESS');
    
    switch ($action) {
        case 'merge_audio':
            // Merge two audio files
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
            
            if ($returnCode !== 0) {
                writeLog("FFmpeg error: " . implode("\n", $output), 'ERROR');
                throw new Exception("Audio merge failed");
            }
            
            // Clean up input files
            unlink($audio1);
            unlink($audio2);
            
            writeLog("Audio merge completed: $outputFile", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Audio files merged successfully',
                'output_file' => basename($outputFile),
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'merge_video':
            // Merge video and audio
            $video = getFile('video');
            $audio = getFile('audio');
            
            if (!$video || !$audio) {
                throw new Exception("Both video and audio are required");
            }
            
            writeLog("Video+Audio merge started: $video + $audio", 'PROCESS');
            
            $outputFile = OUTPUT_DIR . 'merged_video_audio_' . time() . '.mp4';
            
            // FFmpeg command to merge video and audio
            $command = sprintf(
                '%s -i %s -i %s -c:v copy -c:a aac -strict experimental -map 0:v:0 -map 1:a:0 %s 2>&1',
                FFMPEG_PATH,
                escapeshellarg($video),
                escapeshellarg($audio),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                writeLog("FFmpeg error: " . implode("\n", $output), 'ERROR');
                throw new Exception("Video+Audio merge failed");
            }
            
            // Clean up input files
            unlink($video);
            unlink($audio);
            
            writeLog("Video+Audio merge completed: $outputFile", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Video and audio merged successfully',
                'output_file' => basename($outputFile),
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'merge_videos':
            // Merge two video files sequentially
            $video1 = getFile('video1');
            $video2 = getFile('video2');
            
            if (!$video1 || !$video2) {
                throw new Exception("Both video1 and video2 are required");
            }
            
            writeLog("Video merge started: $video1 + $video2", 'PROCESS');
            
            // Create a temporary file list for FFmpeg concat
            $listFile = UPLOAD_DIR . 'videos_' . time() . '.txt';
            $fileList = "file '" . realpath($video1) . "'\nfile '" . realpath($video2) . "'";
            file_put_contents($listFile, $fileList);
            
            $outputFile = OUTPUT_DIR . 'merged_videos_' . time() . '.mp4';
            
            // Method 1: Try simple concat first (fast, no re-encoding)
            $command = sprintf(
                '%s -f concat -safe 0 -i %s -c copy %s 2>&1',
                FFMPEG_PATH,
                escapeshellarg($listFile),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            // If simple concat fails, try re-encoding (works for different formats)
            if ($returnCode !== 0) {
                writeLog("Simple concat failed, trying with re-encoding", 'PROCESS');
                $output = []; // Clear previous output
                
                $command = sprintf(
                    '%s -f concat -safe 0 -i %s -c:v libx264 -preset fast -c:a aac -b:a 192k %s 2>&1',
                    FFMPEG_PATH,
                    escapeshellarg($listFile),
                    escapeshellarg($outputFile)
                );
                
                exec($command, $output, $returnCode);
            }
            
            // Clean up temporary files
            unlink($listFile);
            unlink($video1);
            unlink($video2);
            
            if ($returnCode !== 0) {
                writeLog("FFmpeg error: " . implode("\n", $output), 'ERROR');
                throw new Exception("Video merge failed: " . implode(" ", array_slice($output, -3)));
            }
            
            writeLog("Video merge completed: $outputFile", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Videos merged successfully',
                'output_file' => basename($outputFile),
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            throw new Exception("Invalid action. Use: merge_audio, merge_video, or merge_videos");
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
