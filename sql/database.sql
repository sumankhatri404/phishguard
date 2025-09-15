CREATE DATABASE IF NOT EXISTS phishguard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE phishguard;
CREATE TABLE IF NOT EXISTS users(
 id INT AUTO_INCREMENT PRIMARY KEY,
 first_name VARCHAR(50) NOT NULL,
 last_name VARCHAR(50) NOT NULL,
 username VARCHAR(32) NOT NULL UNIQUE,
 email VARCHAR(120) NULL,
 password_hash VARCHAR(255) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Training modules (static content)
CREATE TABLE training_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    level ENUM('Beginner','Intermediate','Advanced') NOT NULL,
    duration_minutes INT NOT NULL,
    image_path VARCHAR(255) NOT NULL
);

-- User progress
CREATE TABLE user_training_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module_id INT NOT NULL,
    progress_percent INT DEFAULT 0,
    status ENUM('Not started','In progress','Completed') DEFAULT 'Not started',
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES training_modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Steps/pages inside a module
CREATE TABLE IF NOT EXISTS training_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module_id INT NOT NULL,
  step_no INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  is_quiz TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY (module_id, step_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Multiple choice questions for the quiz step of a module
CREATE TABLE IF NOT EXISTS training_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module_id INT NOT NULL,
  step_no INT NOT NULL,           -- which step the quiz belongs to
  question TEXT NOT NULL,
  opt_a VARCHAR(255) NOT NULL,
  opt_b VARCHAR(255) NOT NULL,
  opt_c VARCHAR(255) NOT NULL,
  opt_d VARCHAR(255) NOT NULL,
  correct ENUM('A','B','C','D') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- 3) Optional, if you want to track XP quickly (or reuse your own table)
CREATE TABLE IF NOT EXISTS user_points (
  user_id INT PRIMARY KEY,
  points INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO user_points (user_id, points) SELECT user_id, 0 FROM (SELECT 0 AS user_id) s
ON DUPLICATE KEY UPDATE points = points;



CREATE TABLE leaderboard (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    points INT DEFAULT 0
);


-- Per-module points
CREATE TABLE IF NOT EXISTS user_xp (
  user_id INT NOT NULL,
  module_id INT NOT NULL,
  points INT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




-- 2) The “forwarded email” content the learner inspects
CREATE TABLE IF NOT EXISTS phish_forwarded_emails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  brand VARCHAR(120) NOT NULL,
  from_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(120) NOT NULL,
  to_email VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  mailed_by VARCHAR(120) DEFAULT 'gmail.com',
  signed_by VARCHAR(120) DEFAULT 'gmail.com',
  header_tip VARCHAR(255) DEFAULT 'Hover sender and links to verify.',
  main_html MEDIUMTEXT NOT NULL,
  FOREIGN KEY (case_id) REFERENCES phish_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) The correct answer for the reply “fill-in” (single-rule set for the case)
CREATE TABLE IF NOT EXISTS phish_expected_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  verdict_phish TINYINT(1) NOT NULL DEFAULT 1, -- 1 = "is" a phishing email, 0 = "is not"
  reason1_left ENUM('the sender','the message\'s') NOT NULL,
  reason1_right ENUM(
    'is known and trustworthy',
    'is not known or is not trustworthy',
    'content'
  ) NOT NULL,
  joiner ENUM('and','or','') NOT NULL DEFAULT 'and',
  reason2_left ENUM('the sender','the message\'s') NOT NULL,
  reason2_right ENUM(
    'contains hyperlinks with suspicious or malicious URLs',
    'contains legitimate links',
    'uses an urgent tone'
  ) NOT NULL,
  points INT NOT NULL DEFAULT 8,
  feedback_correct TEXT DEFAULT 'Nice work! You spotted the right clues.',
  feedback_partial TEXT DEFAULT 'Mostly correct — you missed one clue.',
  feedback_wrong TEXT DEFAULT 'Not quite. Review the clues and try again.',
  FOREIGN KEY (case_id) REFERENCES phish_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Log user submissions + points
CREATE TABLE IF NOT EXISTS phish_user_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  case_id INT NOT NULL,
  is_correct TINYINT(1) NOT NULL,
  earned_points INT NOT NULL DEFAULT 0,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ====== SEED: Netflix example for module_id = 1 ======

SET @CASE_ID := LAST_INSERT_ID();

INSERT INTO phish_forwarded_emails
(case_id, brand, from_email, to_name, to_email, subject, mailed_by, signed_by, header_tip, main_html)
VALUES
(@CASE_ID, 'Netflix', 'netflìx@gmail.com', 'Amy', 'amy@yourcompany.com', 'Urgent! A new device is using your account',
 'gmail.com','gmail.com','Hover the sender and links to verify domain.',
 CONCAT(
  '<div style="font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;max-width:820px">',
  '<h1>Urgent! A new device is using your account</h1>',
  '<p>Hi Amy!</p>',
  '<p>We detected a new device using your account. Please verify now to avoid suspension. ',
  '<a href="http://verify-security-netflix.example.co/login" title="Suspicious link">Login here</a></p>',
  '<p><img alt="Netflix" src="assets/img/brands/netflix.png" style="height:42px"/></p>',
  '</div>'
 ));

-- Table of training email cases (one row per “message” in a module)
CREATE TABLE IF NOT EXISTS training_mail_cases (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  module_id             INT NOT NULL,
  from_name             VARCHAR(100) NOT NULL,
  from_avatar           VARCHAR(255) DEFAULT NULL,
  subject               VARCHAR(255) NOT NULL,
  snippet               VARCHAR(255) DEFAULT NULL,
  days_ago              INT DEFAULT 0,

  requester_email_html  MEDIUMTEXT NOT NULL,   -- the short message from the requester (what you see first)
  forwarded_email_html  MEDIUMTEXT NOT NULL,   -- the forwarded mail content the user can open

  -- for scoring (optional, keep as-is if you already have your own logic)
  correct_is_phish      TINYINT(1) DEFAULT 1,  -- 1 = is phishing, 0 = is not
  correct_sender        VARCHAR(64) DEFAULT NULL,
  correct_content       VARCHAR(64) DEFAULT NULL,
  correct_extra         VARCHAR(64) DEFAULT NULL,
  points                INT DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the Netflix case for a module_id = 1
INSERT INTO training_mail_cases
(module_id, from_name, from_avatar, subject, snippet, days_ago,
 requester_email_html, forwarded_email_html,
 correct_is_phish, correct_sender, correct_content, correct_extra, points)
VALUES
(1, 'Amy', 'assets/img/avatars/amy.png',
 'Can you take a look at this email from Netflix?',
 'Netflix just notified me about this…', 2,

 -- What you see first (requester message)
 '<p>Hi! Netflix just notified me about this… Do you think it could be phishing? Thank you!</p>',

 -- The forwarded “Netflix” email (what opens when user clicks View forwarded email)
 '<article class="mail-like">
    <header class="mail-head">
      <div class="logo">N</div>
      <div class="fromto">
        <div class="from-line">
          <span class="from-name tt" data-tt="Display name can be spoofed">Netflix</span>
          <span class="from-mail tt" data-tt="Notice the accent over í: netflíx@gmail.com is not the real brand domain">
           &lt;netflíx@gmail.com&gt;</span>
        </div>
        <div class="to-line">to <strong>Amy</strong></div>
      </div>
      <div class="age">3 days ago</div>
    </header>
    <h2 class="mail-subject">Urgent! A new device is using your account</h2>
    <div class="mail-body">
      <p>Hi Amy!</p>
      <p>
        We detected a new device using your account. Please verify now to avoid suspension.
        <a href="#" class="tt" data-tt="The visible text looks normal, but the link actually points to http://verify-login-secure.com (not netflix.com)">Login here</a>
      </p>
      <div class="details">
        <span class="pill tt" data-tt="Signed by gmail.com, not the service''s domain">mailed-by: gmail.com</span>
        <span class="pill tt" data-tt="Signed by gmail.com, not netflix.com">signed-by: gmail.com</span>
      </div>
    </div>
  </article>',

  -- Correct clues (optional for scoring)
  1, 'unknown_untrustworthy', 'suspicious_links', 'brand_impersonation', 10
);



