<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';

$db = getCampaignDB();

/* Fetch single campaign */
$stmt = $db->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM submissions s WHERE s.campaign_id = c.id) AS entry_count,
           ca.file_path, ca.file_type
    FROM campaigns c
    LEFT JOIN campaign_assets ca ON ca.campaign_id = c.id
    ORDER BY c.created_at DESC
    LIMIT 1
");

$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

$current_page = "campaigns";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Campaign Configuration - Liyas Admin</title>

<link rel="stylesheet" href="../assets/css/prody-admin.css">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
.form-card {
    background:#fff;
    border:1px solid var(--border-light);
    border-radius:18px;
    padding:2rem;
    max-width:900px;
}
.form-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}
.readonly {
    background:#f8fafc;
    border:1px solid #e5e7eb;
    padding:10px 12px;
    border-radius:8px;
    font-size:14px;
}
.label {
    font-size:13px;
    font-weight:500;
    color:#475569;
    margin-bottom:6px;
}
.actions {
    display:flex;
    gap:10px;
    justify-content:flex-end;
    margin-top:2rem;
}
.actions a,
.actions button {
    padding:10px 16px;
    border-radius:8px;
    font-size:14px;
    font-weight:500;
    border:none;
    cursor:pointer;
}
.btn-view { background:#10b981; color:#fff; }
.btn-edit { background:#3b82f6; color:#fff; }
.btn-delete { background:#ef4444; color:#fff; }
</style>
</head>

<body>
<div class="container">
<?php include '../includes/sidebar.php'; 
/* Fetch single campaign and questions */
$stmt = $db->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM submissions s WHERE s.campaign_id = c.id) AS entry_count,
           ca.file_path, ca.file_type
    FROM campaigns c
    LEFT JOIN campaign_assets ca ON ca.campaign_id = c.id
    ORDER BY c.created_at DESC
    LIMIT 1
");
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

$questions = [];
if($campaign) {
    $q_stmt = $db->prepare("SELECT * FROM campaign_questions WHERE campaign_id = ? ORDER BY sort_order");
    $q_stmt->execute([$campaign['id']]);
    $questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>


<div class="main-content">
<div class="header">
    <div class="breadcrumb">
        <span>Campaigns / Configuration</span>
    </div>
</div>

<div class="content-area">

<?php if(!$campaign): ?>

    <div class="form-card">
        <h2>No Campaign Configured</h2>
        <p style="color:#64748b;margin:1rem 0;">
            No active campaign exists. Create one to get started.
        </p>
        <a href="create.php" class="table-btn" style="background:var(--blue);color:white;">
            + Create Campaign
        </a>
    </div>

<?php else: ?>

<form class="form-card">
    <h2 style="margin-bottom:1.5rem;">Active Campaign</h2>

    <div class="form-grid">

        <div>
            <div class="label">Campaign Title</div>
            <div class="readonly"><?= htmlspecialchars($campaign['title']) ?></div>
        </div>

        <div>
            <div class="label">Slug</div>
            <div class="readonly">/<?= htmlspecialchars($campaign['slug']) ?></div>
        </div>

        <div>
            <div class="label">Start Date</div>
            <div class="readonly"><?= date('d M Y', strtotime($campaign['start_date'])) ?></div>
        </div>

        <div>
            <div class="label">End Date</div>
            <div class="readonly">
                <?= $campaign['end_date'] ? date('d M Y', strtotime($campaign['end_date'])) : 'Ongoing' ?>
            </div>
        </div>

        <div>
            <div class="label">Status</div>
            <div class="readonly"><?= ucfirst($campaign['status']) ?></div>
        </div>

        <div>
            <div class="label">Total Entries</div>
            <div class="readonly"><?= number_format($campaign['entry_count']) ?></div>
        </div>

        <div>
            <div class="label">Description</div>
            <div class="readonly"><?= htmlspecialchars($campaign['description']) ?></div>
        </div>

      
  <div style="grid-column: 1 / -1;">
    <div class="label" style="margin-bottom: 8px;">Form Questions</div>
    <div class="readonly" style="padding: 0; border: none; background: transparent;">
        <?php if (!empty($questions)): ?>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($questions as $index => $q): ?>
                    <div style="background: #f8fafc; padding: 10px 15px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #e2e8f0;">
                        <span>
                            <strong style="color: #64748b;"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?>.</strong> 
                            <?= htmlspecialchars($q['question_label']) ?>
                        </span>
                        <div style="display: flex; gap: 10px;">
                            <span style="font-size: 11px; text-transform: uppercase; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                                <?= str_replace('_', ' ', $q['field_type']) ?>
                            </span>
                            <?php if ($q['is_required']): ?>
                                <span style="font-size: 11px; text-transform: uppercase; background: #fee2e2; color: #b91c1c; padding: 2px 8px; border-radius: 4px; font-weight: 600;">Required</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="readonly">No questions configured.</div>
        <?php endif; ?>
    </div>
</div>



        <div style="grid-column:1/-1;">
            <div class="label">Promotional Asset</div>
            <?php if($campaign['file_path']): ?>
                <a href="../../<?= $campaign['file_path'] ?>" target="_blank" class="readonly" style="display:inline-block;color:#2563eb;">
                    View <?= strtoupper($campaign['file_type']) ?>
                </a>
            <?php else: ?>
                <div class="readonly">No asset uploaded</div>
            <?php endif; ?>
        </div>

    </div>

    <div class="actions">
        <a href="view.php?id=<?= $campaign['id'] ?>" class="btn-view">
            <i class='bx bx-show'></i> View
        </a>
        <a href="edit.php?id=<?= $campaign['id'] ?>" class="btn-edit">
            <i class='bx bx-edit'></i> Edit
        </a>
        <a href="delete.php?id=<?= $campaign['id'] ?>" 
           onclick="return confirm('Delete this campaign and all submissions?')"
           class="btn-delete">
            <i class='bx bx-trash'></i> Delete
        </a>
    </div>
</form>

<?php endif; ?>

</div>
</div>
</div>
</body>
</html>
<?php if (isset($_GET['deleted'])): ?>
<div style="margin-bottom:1rem;color:#16a34a;">
    Campaign deleted successfully.
</div>
<?php endif; ?>
