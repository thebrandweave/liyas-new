<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Let's create a log file to trace execution.
$log_file = __DIR__ . '/debug_submit_log.txt'; // Changed log file name for clarity
// Clear the log file for each new request.
file_put_contents($log_file, "Execution Log for " . date('Y-m-d H:i:s') . "\n\n");

function write_log($message) {
    global $log_file;
    file_put_contents($log_file, $message . "\n", FILE_APPEND);
}

write_log("Script start (submit.php).");

write_log("Attempting to include config.php...");
require_once __DIR__ . '/../config/config.php';
write_log("config.php included successfully.");

write_log("Attempting to call getCampaignDB()...");
$db = getCampaignDB();
if ($db) {
    write_log("getCampaignDB() successful. \$db is an object.");
} else {
    write_log("FATAL: getCampaignDB() failed. \$db is NULL.");
    // This die will show the actual error to the browser if it fails here.
    die("Database connection (getCampaignDB) could not be established. Check config.php for errors.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    write_log("Request method is not POST. Exiting.");
    header("Location: index.php");
    exit;
}
write_log("Request method is POST.");
write_log("POST data: " . json_encode($_POST));
write_log("FILES data: " . json_encode($_FILES));


try {
    write_log("Inside try block. Preparing to begin transaction.");
    $db->beginTransaction();
    write_log("Transaction started.");

    /* ======================
       INSERT SUBMISSION
    ====================== */
    write_log("Preparing to insert into submissions table.");
    $stmt = $db->prepare("
        INSERT INTO submissions 
        (campaign_id, full_name, email, phone_number, submitted_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $_POST['campaign_id'],
        trim($_POST['full_name']),
        trim($_POST['email']),
        trim($_POST['phone_number'])
    ]);
    write_log("Submission INSERT executed.");

    $submission_id = $db->lastInsertId();
    write_log("New submission ID: " . $submission_id);
    if (empty($submission_id) || $submission_id == 0) {
        write_log("CRITICAL: lastInsertId() returned 0 or empty. Rolling back.");
        $db->rollBack();
        die("Failed to create a submission ID.");
    }


    /* ======================
       INSERT ANSWERS
    ====================== */
    if (!empty($_POST['answers'])) {
        write_log("Answers found in POST data. Preparing to insert answers.");
        $stmtA = $db->prepare("
            INSERT INTO submission_answers
            (submission_id, question_id, answer_value)
            VALUES (?, ?, ?)
        ");

        foreach ($_POST['answers'] as $qid => $ans) {
            $valueToInsert = (trim($ans) === '') ? null : trim($ans);
            write_log("Inserting answer for question ID $qid with value: " . ($valueToInsert === null ? 'NULL' : $valueToInsert));
            $stmtA->execute([$submission_id, $qid, $valueToInsert]);
        }
        write_log("Finished inserting answers.");
    } else {
        write_log("No 'answers' found in POST data.");
    }

    /* ======================
       HANDLE FILE UPLOADS
    ====================== */
    if (!empty($_FILES['media']) && !empty($_FILES['media']['name']) && is_array($_FILES['media']['name'])) {
        write_log("File uploads found. Preparing to process files.");
        $stmtF = $db->prepare("
            INSERT INTO submission_media
            (submission_id, question_id, media_url, media_type)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($_FILES['media']['name'] as $qid => $name) {
            if (empty($name)) continue;

            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $fileName = "submission_{$submission_id}_{$qid}_" . time() . "." . $ext;
            
            $uploadDir = "../uploads/submissions/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $path = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['media']['tmp_name'][$qid], $path)) {
                $storedPath = "uploads/submissions/" . $fileName;
                // Determine media type (e.g., from MIME type)
                $mediaType = $_FILES['media']['type'][$qid]; // Or derive from $ext if preferred
                $stmtF->execute([$submission_id, $qid, $storedPath, $mediaType]);
            } else {
                write_log("ERROR: move_uploaded_file failed for question ID " . $qid);
            }
        }
        write_log("File uploads handled.");
    } else {
        write_log("No files to upload.");
    }


    write_log("Preparing to commit transaction.");
    $db->commit();
    write_log("Transaction committed successfully!");

    // Redirect on success
    header("Location: index.php?success=1");
    exit;

} catch (Exception $e) {
    write_log("!!!!!!!!!! EXCEPTION CAUGHT !!!!!!!!!!");
    write_log("Error message: " . $e->getMessage());
    write_log("Error trace: " . $e->getTraceAsString());
    if ($db->inTransaction()) {
        $db->rollBack();
        write_log("Transaction rolled back due to exception.");
    }
    // Die to show a generic error to the user
header("Location: https://liyasinternational.com/index-temp");
    exit();
}

