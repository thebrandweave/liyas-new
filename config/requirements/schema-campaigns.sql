-- CREATE DATABASE liyas_campaigns;
-- USE liyas_campaigns;

-- 1. CAMPAIGNS TABLE 
-- Linked to liyas_main.admins for auditing
-- Updated Campaigns Table with Timeline Support
CREATE TABLE campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description VARCHAR(455) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    status ENUM('active', 'inactive', 'archived') DEFAULT 'active',
    
    -- New Date Fields
    start_date DATE NOT NULL,          -- Every campaign must have a start point
    end_date DATE NULL DEFAULT NULL,   -- NULL indicates an 'Ongoing' campaign
    
    created_by INT,                    -- References admin_id in the main DB
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Performance Indexes
    INDEX (slug),
    INDEX idx_timeline (status, start_date, end_date) -- Optimized for "Current Active" queries
);
CREATE TABLE campaign_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type ENUM('image', 'pdf') NOT NULL,
    label VARCHAR(100) DEFAULT 'Main Poster', -- e.g., "Contest Rules", "Promo Banner"
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);
-- 2. DYNAMIC QUESTIONS TABLE
CREATE TABLE campaign_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    question_label VARCHAR(255) NOT NULL,
    field_type ENUM('text', 'number', 'dropdown', 'image_upload', 'video_upload') DEFAULT 'text',
    is_required BOOLEAN DEFAULT FALSE,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);

-- 3. SUBMISSIONS TABLE
CREATE TABLE submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    UNIQUE KEY one_entry_per_campaign (campaign_id, email),
    INDEX (email),
    INDEX (phone_number)
);`

-- 4. SUBMISSION ANSWERS
CREATE TABLE submission_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id INT NOT NULL,
    answer_value TEXT NOT NULL,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES campaign_questions(id) ON DELETE CASCADE
);

-- 5. SUBMISSION MEDIA
CREATE TABLE submission_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id INT NOT NULL,
    media_url VARCHAR(512) NOT NULL,
    media_type ENUM('image', 'video') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES campaign_questions(id) ON DELETE CASCADE
);