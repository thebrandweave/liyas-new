<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';

$db = getCampaignDB();
$current_page = "entries";

/* Fetch entries with basic info */
$stmt = $db->query("
    SELECT 
        s.id,
        s.full_name,
        s.email,
        s.phone_number,
        s.submitted_at,
        c.title AS campaign_title
    FROM submissions s
    JOIN campaigns c ON c.id = s.campaign_id
    ORDER BY s.submitted_at DESC
");
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalEntries = count($entries);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Entries / Leads - Liyas Admin</title>
<link rel="stylesheet" href="../assets/css/prody-admin.css">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<style>
    /* ... Your existing styles ... */
    body { background:#f8fafc; margin:0; font-family: 'Inter', sans-serif; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:28px; }
    table { width:100%; border-collapse:collapse; margin-top:16px; }
    thead th { font-size:12px; text-transform:uppercase; color:#64748b; background:#f8fafc; padding:14px; text-align:left; }
    tbody td { padding:16px 14px; font-size:14px; color:#0f172a; border-top:1px solid #e5e7eb; }
    .btn-view { padding:8px 14px; font-size:13px; border-radius:8px; background:#10b981; color:#fff; border:none; cursor:pointer; }
    .table-wrapper{
    max-height: 70vh;
    overflow-y: auto;
    overflow-x: auto;
    margin-top: 16px;
}

.table-wrapper table{
    width: 100%;
}
.content-area,
.main-content,
.card {
    overflow: visible !important;
    max-height: none !important;
    height: auto !important;
}
    /* MODAL STYLES */
    .modal-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,0.5);
        display: none; align-items: center; justify-content: center; z-index: 1000;
    }
    .modal-content {
        background: #fff; width: 600px; max-width: 90%; border-radius: 20px;
        padding: 30px; position: relative; max-height: 80vh; overflow-y: auto;
    }
    .modal-close { position: absolute; top: 20px; right: 20px; font-size: 24px; cursor: pointer; color: #64748b; }
    .detail-group { margin-bottom: 20px; }
    .detail-label { font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 5px; }
    .detail-value { font-size: 16px; color: #1a1f36; font-weight: 500; }
    .media-preview { width: 100%; border-radius: 10px; margin-top: 10px; border: 1px solid #e5e7eb; }
</style>
</head>
<body>

<div class="container">
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="card">
            <div class="header">
    <div class="breadcrumb">
        <span>Campaigns / Entries</span>
    </div>
</div>

<div class="content-area">
            <div class="card-top">
                <div>
                    <h2>Entries / Leads</h2>
                    <div class="meta">Total <?= $totalEntries ?> Submissions</div>
                </div>
            </div>
</div>
           <div class="table-wrapper">
<table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Campaign</th>
                        <th>Submitted</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $i => $e): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <strong><?= htmlspecialchars($e['full_name']) ?></strong><br>
                            <small><?= htmlspecialchars($e['email']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($e['campaign_title']) ?></td>
                        <td><?= date('d M Y', strtotime($e['submitted_at'])) ?></td>
                        <td>
                            <button class="btn-view" onclick="viewEntry(<?= $e['id'] ?>)">View Details</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
                    </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="entryModal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <h2 style="margin-bottom:25px;">Entry Details</h2>
        <div id="modalBody">
            <p>Loading details...</p>
        </div>
    </div>
</div>

<script>
async function viewEntry(id) {
    const modal = document.getElementById('entryModal');
    const body = document.getElementById('modalBody');
    modal.style.display = 'flex';
    body.innerHTML = '<p>Loading...</p>';

    try {
        const response = await fetch(`get_entry_details.php?id=${id}`);
        const data = await response.text();
        body.innerHTML = data;
    } catch (err) {
        body.innerHTML = '<p style="color:red">Error loading details.</p>';
    }
}

function closeModal() {
    document.getElementById('entryModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('entryModal')) closeModal();
}
</script>
</body>
</html>