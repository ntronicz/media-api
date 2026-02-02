<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

function downloadFile($url) {
    try {
        $filename = UPLOAD_DIR . uniqid() . '_' . basename(parse_url($url, PHP_URL_PATH));
        
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
        
        writeLog("Downloaded: $url (" . filesize($filename) . " bytes)", 'PROCESS');
        return $filename;
        
    } catch (Exception $e) {
        throw new Exception("Download error: " . $e->getMessage());
    }
}

function handleUpload($fieldName) {
    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
        $filename = UPLOAD_DIR . uniqid() . '_' . basename($_FILES[$fieldName]['name']);
        move_uploaded_file($_FILES[$fieldName]['tmp_name'], $filename);
        writeLog("Uploaded: $fieldName -> $filename", 'PROCESS');
        return $filename;
    }
    return null;
}

function getFile($fieldName) {
    if (isset($_FILES[$fieldName])) {
        return handleUpload($fieldName);
    }
    
    if (isset($_POST[$fieldName]) && filter_var($_POST[$fieldName], FILTER_VALIDATE_URL)) {
        return downloadFile($_POST[$fieldName]);
    }
    
    $json = json_decode(file_get_contents('php://input'), true);
    if (isset($json[$fieldName])) {
        if (filter_var($json[$fieldName], FILTER_VALIDATE_URL)) {
            return downloadFile($json[$fieldName]);
        }
    }
    
    return null;
}

try {
    writeLog("API request: action=$action from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'PROCESS');
    
    switch ($action) {
        
        case 'merge_audio':
            $audio1 = getFile('audio1');
            $audio2 = getFile('audio2');
            
            if (!$audio1 || !$audio2) {
                throw new Exception("Both audio1 and audio2 are required");
            }
            
            writeLog("Audio merge started", 'PROCESS');
            
            $outputFile = OUTPUT_DIR . 'merged_audio_' . time() . '.mp3';
            
            $command = sprintf(
                '%s -i %s -i %s -filter_complex "[0:a][1:a]concat=n=2:v=0:a=1[out]" -map "[out]" %s 2>&1',
                FFMPEG_PATH,
                escapeshellarg($audio1),
                escapeshellarg($audio2),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            if (file_exists($audio1)) unlink($audio1);
            if (file_exists($audio2)) unlink($audio2);
            
            if ($returnCode !== 0 || !file_exists($outputFile)) {
                writeLog("FFmpeg error: " . implode("\n", $output), 'ERROR');
                throw new Exception("Audio merge failed");
            }
            
            $fileSize = filesize($outputFile);
            writeLog("Audio merge completed ($fileSize bytes)", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Audio files merged successfully',
                'output_file' => basename($outputFile),
                'file_size' => $fileSize,
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        case 'merge_video':
            $video = getFile('video');
            $audio = getFile('audio');
            
            if (!$video || !$audio) {
                throw new Exception("Both video and audio are required");
            }
            
            writeLog("Video+Audio merge started", 'PROCESS');
            
            $outputFile = OUTPUT_DIR . 'merged_video_audio_' . time() . '.mp4';
            
            $command = sprintf(
                '%s -i %s -i %s -c:v copy -c:a aac -strict experimental -map 0:v:0 -map 1:a:0 -shortest %s 2>&1',
                FFMPEG_PATH,
                escapeshellarg($video),
                escapeshellarg($audio),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            if (file_exists($video)) unlink($video);
            if (file_exists($audio)) unlink($audio);
            
            if ($returnCode !== 0 || !file_exists($outputFile)) {
                writeLog("FFmpeg error: " . implode("\n", $output), 'ERROR');
                throw new Exception("Video+Audio merge failed");
            }
            
            $fileSize = filesize($outputFile);
            writeLog("Video+Audio merge completed ($fileSize bytes)", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Video and audio merged successfully',
                'output_file' => basename($outputFile),
                'file_size' => $fileSize,
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        case 'merge_videos':
            $video1 = getFile('video1');
            $video2 = getFile('video2');
            
            if (!$video1 || !$video2) {
                throw new Exception("Both video1 and video2 are required");
            }
            
            writeLog("Video merge started", 'PROCESS');
            
            $outputFile = OUTPUT_DIR . 'merged_videos_' . time() . '.mp4';
            
            $command = sprintf(
                '%s -i %s -i %s -filter_complex "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30,setpts=PTS-STARTPTS[v0];[0:a]aresample=44100,aformat=channel_layouts=stereo,asetpts=PTS-STARTPTS[a0];[1:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30,setpts=PTS-STARTPTS[v1];[1:a]aresample=44100,aformat=channel_layouts=stereo,asetpts=PTS-STARTPTS[a1];[v0][a0][v1][a1]concat=n=2:v=1:a=1[outv][outa]" -map "[outv]" -map "[outa]" -c:v libx264 -preset ultrafast -crf 23 -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
                FFMPEG_PATH,
                escapeshellarg($video1),
                escapeshellarg($video2),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            if (file_exists($video1)) unlink($video1);
            if (file_exists($video2)) unlink($video2);
            
            if ($returnCode !== 0 || !file_exists($outputFile) || filesize($outputFile) < 1000) {
                writeLog("FFmpeg error: " . implode("\n", array_slice($output, -15)), 'ERROR');
                throw new Exception("Video merge failed. Check logs.");
            }
            
            $fileSize = filesize($outputFile);
            writeLog("Video merge completed ($fileSize bytes)", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Videos merged successfully',
                'output_file' => basename($outputFile),
                'file_size' => $fileSize,
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . basename($outputFile),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        case 'upload_audio':
            writeLog("Audio upload request received", 'PROCESS');
            
            $audioFile = null;
            $originalName = 'audio.mp3';
            
            if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
                $audioFile = $_FILES['audio']['tmp_name'];
                $originalName = basename($_FILES['audio']['name']);
                writeLog("Received direct file upload", 'PROCESS');
            } else {
                $url = null;
                if (isset($_POST['audio_url'])) {
                    $url = $_POST['audio_url'];
                } else {
                    $json = json_decode(file_get_contents('php://input'), true);
                    if (isset($json['audio_url'])) {
                        $url = $json['audio_url'];
                    }
                }
                
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    writeLog("Downloading audio from URL: $url", 'PROCESS');
                    $audioFile = downloadFile($url);
                    $originalName = basename(parse_url($url, PHP_URL_PATH));
                }
            }
            
            if (!$audioFile || !file_exists($audioFile)) {
                throw new Exception("No audio file provided. Use 'audio' for file upload or 'audio_url' for URL");
            }
            
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            if (empty($extension) || !in_array(strtolower($extension), ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'])) {
                $extension = 'mp3';
            }
            
            $filename = 'audio_' . time() . '_' . uniqid() . '.' . $extension;
            $outputPath = OUTPUT_DIR . $filename;
            
            if (isset($_FILES['audio'])) {
                move_uploaded_file($audioFile, $outputPath);
            } else {
                rename($audioFile, $outputPath);
            }
            
            if (!file_exists($outputPath)) {
                throw new Exception("Failed to save audio file");
            }
            
            $fileSize = filesize($outputPath);
            writeLog("Audio uploaded successfully: $filename ($fileSize bytes)", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Audio uploaded successfully',
                'filename' => $filename,
                'file_size' => $fileSize,
                'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . $filename,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        case 'delete_file':
            writeLog("Delete file request received", 'PROCESS');
            
            $filename = null;
            
            if (isset($_POST['filename'])) {
                $filename = $_POST['filename'];
            } else {
                $json = json_decode(file_get_contents('php://input'), true);
                if (isset($json['filename'])) {
                    $filename = $json['filename'];
                }
            }
            
            if (!$filename) {
                throw new Exception("Filename is required");
            }
            
            // Security: Only allow deleting from outputs folder
            $filename = basename($filename);
            $filePath = OUTPUT_DIR . $filename;
            
            if (!file_exists($filePath)) {
                throw new Exception("File not found: $filename");
            }
            
            $fileSize = filesize($filePath);
            
            if (!unlink($filePath)) {
                throw new Exception("Failed to delete file: $filename");
            }
            
            writeLog("File deleted: $filename ($fileSize bytes freed)", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => 'File deleted successfully',
                'filename' => $filename,
                'size_freed' => $fileSize,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        case 'delete_multiple':
            writeLog("Delete multiple files request received", 'PROCESS');
            
            $filenames = null;
            
            if (isset($_POST['filenames'])) {
                $filenames = is_array($_POST['filenames']) ? $_POST['filenames'] : json_decode($_POST['filenames'], true);
            } else {
                $json = json_decode(file_get_contents('php://input'), true);
                if (isset($json['filenames'])) {
                    $filenames = $json['filenames'];
                }
            }
            
            if (!$filenames || !is_array($filenames)) {
                throw new Exception("Filenames array is required");
            }
            
            $deleted = [];
            $failed = [];
            $totalFreed = 0;
            
            foreach ($filenames as $filename) {
                $filename = basename($filename);
                $filePath = OUTPUT_DIR . $filename;
                
                if (file_exists($filePath)) {
                    $size = filesize($filePath);
                    if (unlink($filePath)) {
                        $deleted[] = $filename;
                        $totalFreed += $size;
                        writeLog("File deleted: $filename", 'PROCESS');
                    } else {
                        $failed[] = $filename;
                        writeLog("Failed to delete: $filename", 'ERROR');
                    }
                } else {
                    $failed[] = $filename . " (not found)";
                }
            }
            
            writeLog("Batch delete: " . count($deleted) . " deleted, " . count($failed) . " failed", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'message' => count($deleted) . ' file(s) deleted, ' . count($failed) . ' failed',
                'deleted' => $deleted,
                'failed' => $failed,
                'size_freed' => $totalFreed,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        case 'list_files':
            writeLog("List files request received", 'PROCESS');
            
            $files = [];
            $totalSize = 0;
            
            if (is_dir(OUTPUT_DIR)) {
                $allFiles = scandir(OUTPUT_DIR);
                
                foreach ($allFiles as $file) {
                    if ($file === '.' || $file === '..') continue;
                    
                    $filePath = OUTPUT_DIR . $file;
                    if (is_file($filePath)) {
                        $size = filesize($filePath);
                        $totalSize += $size;
                        
                        $files[] = [
                            'filename' => $file,
                            'size' => $size,
                            'size_mb' => round($size / 1024 / 1024, 2),
                            'created' => date('Y-m-d H:i:s', filemtime($filePath)),
                            'download_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/outputs/' . $file
                        ];
                    }
                }
            }
            
            usort($files, function($a, $b) {
                return strtotime($b['created']) - strtotime($a['created']);
            });
            
            writeLog("Listed " . count($files) . " files (Total: " . round($totalSize/1024/1024, 2) . " MB)", 'PROCESS');
            
            echo json_encode([
                'success' => true,
                'total_files' => count($files),
                'total_size' => $totalSize,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'files' => $files,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
        
        default:
            throw new Exception("Invalid action. Available: merge_audio, merge_video, merge_videos, upload_audio, delete_file, delete_multiple, list_files");
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
